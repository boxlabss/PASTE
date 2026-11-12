/*
 * Paste https://github.com/boxlabss/PASTE
 *
 * Image and video uploads: queue, drag and drop, clipboard paste, metadata
 * removal, resizing, optional encryption in the browser and upload progress.
 * Also decrypts browser-encrypted uploads on the view page, and fills in the
 * uploads grid from the links and keys remembered in this browser.
 *
 * Uploads are stored encrypted by the server with their link name as the key.
 * "Encrypt in my browser" uses the same format as client-encrypted pastes:
 * salt (16) | iv (12) | AES-256-GCM ciphertext, with the key derived from
 * the passphrase by PBKDF2-SHA256. A salt starting C5 9E carries the round
 * count in thousands in its next two bytes (600000 now); other salts are
 * from before and use 100000. The passphrase is only ever in the link
 * fragment (#pass=...) and in this browser.
 */
(function () {
  'use strict';

  var OVERHEAD    = 44;
  var ITERATIONS  = 600000;
  var OLD_ITERATIONS = 100000;
  var KEY_STORE   = 'paste.img.keys';
  var THUMB_MAX   = 480;
  var THUMB_BYTES = 524288 - OVERHEAD;
  var MIN_PASS    = 12;
  var TITLE_MAX   = 120;

  var cryptoOk = !!(window.crypto && window.crypto.subtle && window.TextEncoder);

  /* ================= Helpers ================= */

  function formatBytes(n) {
    if (n >= 1048576) return (n / 1048576).toFixed(1).replace(/\.0$/, '') + ' MB';
    if (n >= 1024) return Math.round(n / 1024) + ' KB';
    return n + ' bytes';
  }

  function ascii(b, offset, len) {
    var s = '';
    for (var i = offset; i < offset + len && i < b.length; i++) s += String.fromCharCode(b[i]);
    return s;
  }

  function concat(parts) {
    var total = 0;
    parts.forEach(function (p) { total += p.length; });
    var out = new Uint8Array(total);
    var pos = 0;
    parts.forEach(function (p) { out.set(p, pos); pos += p.length; });
    return out;
  }

  function bytesToBase64(bytes) {
    var s = '';
    for (var i = 0; i < bytes.length; i += 0x8000) {
      s += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
    }
    return btoa(s);
  }

  function base64ToBytes(b64) {
    var bin = atob(b64);
    var out = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out;
  }

  function hex(bytes) {
    var s = '';
    for (var i = 0; i < bytes.length; i++) s += (bytes[i] < 16 ? '0' : '') + bytes[i].toString(16);
    return s;
  }

  function readBytes(blob) {
    return blob.arrayBuffer().then(function (buf) { return new Uint8Array(buf); });
  }

  function toBlob(canvas, type, quality) {
    return new Promise(function (resolve) {
      try { canvas.toBlob(resolve, type, quality); } catch (err) { resolve(null); }
    });
  }

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function icon(name) {
    return el('i', 'bi ' + name);
  }

  function cleanName(name, ext) {
    name = String(name || '').split(/[\\/]/).pop().replace(/[\x00-\x1f\x7f"]/g, '').trim();
    if (!name || name === '.' || name === '..') name = 'upload.' + ext;
    return name.length > 120 ? name.slice(0, 120) : name;
  }

  function rename(name, ext) {
    return name.replace(/\.[^.]*$/, '') + '.' + ext;
  }

  // One line, at most TITLE_MAX characters
  function cleanTitle(title) {
    var t = String(title || '').replace(/[\x00-\x1f\x7f\u200B-\u200F\u202A-\u202E\u2066-\u2069]+/g, ' ')
      .replace(/\s+/g, ' ').trim();
    return Array.from(t).slice(0, TITLE_MAX).join('').trim();
  }

  // Title if the uploader gave one, else the original file name
  function displayName(meta) {
    return (meta && (meta.title || meta.n)) || '';
  }

  // Download file name: the title with the file's extension, else the original file name
  function downloadName(meta) {
    var base = String(meta.title || '').replace(/[\x00-\x1f\x7f"\\\/:*?<>|]+/g, ' ').replace(/\s+/g, ' ')
      .replace(/^[\s.]+|[\s.]+$/g, '');
    if (!base) return meta.n || 'upload';
    var m = /\.([A-Za-z0-9]{1,8})$/.exec(meta.n || '');
    var ext = m ? m[1] : (LABELS[meta.t] || '').toLowerCase();
    return ext && base.toLowerCase().slice(-ext.length - 1) !== '.' + ext.toLowerCase() ? base + '.' + ext : base;
  }

  /* ================= Crypto ================= */

  var keyCache = {};

  // New random salt carrying the PBKDF2 round count
  function newSalt() {
    var salt = crypto.getRandomValues(new Uint8Array(16));
    var k = ITERATIONS / 1000;
    salt[0] = 0xC5; salt[1] = 0x9E; salt[2] = k >> 8; salt[3] = k & 255;
    return salt;
  }

  // PBKDF2 rounds for a salt: from its header, or the old count
  function saltRounds(salt, legacy) {
    if (!legacy && salt[0] === 0xC5 && salt[1] === 0x9E) {
      var k = (salt[2] << 8) | salt[3];
      if (k > 0) return k * 1000;
    }
    return OLD_ITERATIONS;
  }

  // AES-GCM key for a passphrase and salt; cached so a file, its preview and details derive it once
  function deriveKey(pass, salt, legacy) {
    var rounds = saltRounds(salt, legacy);
    var id = hex(salt) + ':' + rounds + ':' + pass;
    if (!keyCache[id]) {
      keyCache[id] = crypto.subtle.importKey('raw', new TextEncoder().encode(pass), 'PBKDF2', false, ['deriveKey'])
        .then(function (material) {
          return crypto.subtle.deriveKey(
            { name: 'PBKDF2', salt: salt, iterations: rounds, hash: 'SHA-256' },
            material, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
        });
      keyCache[id].catch(function () { delete keyCache[id]; });
    }
    return keyCache[id];
  }

  // salt | iv | ciphertext
  function encryptWith(key, salt, data) {
    var iv = crypto.getRandomValues(new Uint8Array(12));
    return crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv }, key, data).then(function (ct) {
      var out = new Uint8Array(28 + ct.byteLength);
      out.set(salt, 0);
      out.set(iv, 16);
      out.set(new Uint8Array(ct), 28);
      return out;
    });
  }

  // Plain bytes of salt | iv | ciphertext; rejects when the passphrase is wrong
  function decryptWith(pass, data) {
    var bytes = data instanceof Uint8Array ? data : new Uint8Array(data);
    if (bytes.length < OVERHEAD) return Promise.reject(new Error('The encrypted data is damaged.'));
    var salt = bytes.slice(0, 16);
    var open = function (legacy) {
      return deriveKey(pass, salt, legacy).then(function (key) {
        return crypto.subtle.decrypt({ name: 'AES-GCM', iv: bytes.subarray(16, 28) }, key, bytes.subarray(28));
      });
    };
    // An old random salt can start like a header; the old round count is tried then
    return saltRounds(salt) === OLD_ITERATIONS ? open(true) : open(false).catch(function () { return open(true); });
  }

  function decryptMeta(pass, b64) {
    return decryptWith(pass, base64ToBytes(b64)).then(function (buf) {
      var meta = JSON.parse(new TextDecoder().decode(buf));
      if (!meta || typeof meta !== 'object') throw new Error('bad details');
      return meta;
    });
  }

  function randomKey() {
    return bytesToBase64(crypto.getRandomValues(new Uint8Array(16)))
      .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  // Leading bytes the server treats as a plain media file
  function looksLikeMedia(b) {
    var head = ascii(b, 0, 12);
    return /^(\xFF\xD8\xFF|\x89PNG|GIF8|\x1A\x45\xDF\xA3|OggS|ID3|%PDF|<svg|<\?xml|\xEF\xBB\xBF|\x00\x00[\x01\x02]\x00|\x00\x00\x00\x0CJXL )/.test(head)
      || head.slice(4, 8) === 'ftyp'
      || (head.slice(0, 4) === 'RIFF' && /^(WEBP|AVI |WAVE)$/.test(head.slice(8, 12)));
  }

  /* ================= Keys kept in this browser ================= */

  function readKeys() {
    try {
      var v = JSON.parse(localStorage.getItem(KEY_STORE) || '{}');
      return v && typeof v === 'object' ? v : {};
    } catch (err) { return {}; }
  }

  // A browser-encrypted upload's passphrase, or {k, e, n} (link name, extension, file name) for the others
  function storeKey(slug, value) {
    try {
      var keys = readKeys();
      keys[slug] = value;
      localStorage.setItem(KEY_STORE, JSON.stringify(keys));
    } catch (err) { /* storage unavailable */ }
  }

  function forgetKeys() {
    try { localStorage.removeItem(KEY_STORE); } catch (err) { /* storage unavailable */ }
  }

  function hashPass() {
    try { return new URLSearchParams(location.hash.slice(1)).get('pass') || ''; } catch (err) { return ''; }
  }

  function withKey(url, pass) {
    return url.split('#')[0] + '#pass=' + encodeURIComponent(pass);
  }

  /* ================= Copy buttons ================= */

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) return;
    var input = document.querySelector(btn.getAttribute('data-copy'));
    if (!input) return;
    var done = function () {
      var i = btn.querySelector('i');
      if (!i) return;
      i.className = 'bi bi-check2';
      setTimeout(function () { i.className = 'bi bi-clipboard'; }, 1500);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(input.value).then(done, function () { input.select(); });
    } else {
      input.select();
      try { document.execCommand('copy'); done(); } catch (err) { /* user can copy manually */ }
    }
  });

  /* ================= File types ================= */

  // [kind, mime, ext] from the file's first bytes, or null when unsupported
  function sniff(b) {
    if (b.length < 12) return null;
    if (b[0] === 0xFF && b[1] === 0xD8 && b[2] === 0xFF) return ['image', 'image/jpeg', 'jpg'];
    if (ascii(b, 0, 4) === '\x89PNG') return ['image', 'image/png', 'png'];
    if (ascii(b, 0, 4) === 'GIF8') return ['image', 'image/gif', 'gif'];
    if (ascii(b, 0, 4) === 'RIFF' && ascii(b, 8, 4) === 'WEBP') return ['image', 'image/webp', 'webp'];
    if (ascii(b, 0, 4) === 'RIFF' && ascii(b, 8, 4) === 'AVI ') return ['video', 'video/x-msvideo', 'avi'];
    if (ascii(b, 4, 4) === 'ftyp') {
      var brand = ascii(b, 8, 4);
      if (/^(heic|heix|hevc|hevx|heim|heis|mif1|msf1|avif|avis)$/.test(brand)) return null;
      if (brand === 'qt  ') return ['video', 'video/quicktime', 'mov'];
      if (/^M4V/.test(brand)) return ['video', 'video/x-m4v', 'm4v'];
      return ['video', 'video/mp4', 'mp4'];
    }
    if (b[0] === 0x1A && b[1] === 0x45 && b[2] === 0xDF && b[3] === 0xA3) {
      return ascii(b, 0, Math.min(64, b.length)).indexOf('webm') !== -1
        ? ['video', 'video/webm', 'webm'] : ['video', 'video/x-matroska', 'mkv'];
    }
    return null;
  }

  function sniffFile(file) {
    return readBytes(file.slice(0, 64)).then(sniff, function () { return null; });
  }

  var LABELS = {
    'image/jpeg': 'JPG', 'image/png': 'PNG', 'image/gif': 'GIF', 'image/webp': 'WEBP',
    'video/mp4': 'MP4', 'video/x-m4v': 'M4V', 'video/webm': 'WEBM', 'video/quicktime': 'MOV',
    'video/x-msvideo': 'AVI', 'video/x-matroska': 'MKV'
  };

  /* ================= Metadata removal (lossless) ================= */

  // JPEG: drop APP1-APP15 (except APP14) and COM segments
  function stripJpeg(b) {
    if (b.length < 4 || b[0] !== 0xFF || b[1] !== 0xD8) return null;
    var parts = [b.subarray(0, 2)];
    var pos = 2;
    while (pos + 4 <= b.length) {
      if (b[pos] !== 0xFF) return null;
      var m = b[pos + 1];
      if (m === 0xD8 || (m >= 0xD0 && m <= 0xD7) || m === 0x01) {
        parts.push(b.subarray(pos, pos + 2));
        pos += 2;
        continue;
      }
      if (m === 0xDA) {
        parts.push(b.subarray(pos));
        return concat(parts);
      }
      var len = (b[pos + 2] << 8) | b[pos + 3];
      if (len < 2 || pos + 2 + len > b.length) return null;
      var drop = (m >= 0xE1 && m <= 0xEF && m !== 0xEE) || m === 0xFE;
      if (!drop) parts.push(b.subarray(pos, pos + 2 + len));
      pos += 2 + len;
    }
    return null;
  }

  // PNG: drop text, EXIF, time and signature chunks
  var PNG_DROP = { eXIf: 1, tEXt: 1, zTXt: 1, iTXt: 1, tIME: 1, dSIG: 1 };
  function stripPng(b) {
    if (b.length < 8 || ascii(b, 0, 4) !== '\x89PNG') return null;
    var dv = new DataView(b.buffer, b.byteOffset, b.byteLength);
    var parts = [b.subarray(0, 8)];
    var pos = 8;
    while (pos + 12 <= b.length) {
      var total = 12 + dv.getUint32(pos);
      var type = ascii(b, pos + 4, 4);
      if (pos + total > b.length) return null;
      if (!PNG_DROP[type]) parts.push(b.subarray(pos, pos + total));
      pos += total;
      if (type === 'IEND') return concat(parts);
    }
    return null;
  }

  // WebP: drop EXIF and XMP chunks and clear their VP8X flags
  function stripWebp(b) {
    if (b.length < 12 || ascii(b, 0, 4) !== 'RIFF' || ascii(b, 8, 4) !== 'WEBP') return null;
    var dv = new DataView(b.buffer, b.byteOffset, b.byteLength);
    var parts = [];
    var pos = 12;
    while (pos + 8 <= b.length) {
      var type = ascii(b, pos, 4);
      var len = dv.getUint32(pos + 4, true);
      var padded = len + (len & 1);
      if (pos + 8 + padded > b.length) return null;
      var chunk = b.subarray(pos, pos + 8 + padded);
      if (type === 'VP8X' && len >= 1) {
        chunk = chunk.slice();
        chunk[8] &= ~0x0C;
        parts.push(chunk);
      } else if (type !== 'EXIF' && type !== 'XMP ') {
        parts.push(chunk);
      }
      pos += 8 + padded;
    }
    var body = concat(parts);
    var out = new Uint8Array(12 + body.length);
    out.set(b.subarray(0, 4), 0);
    new DataView(out.buffer).setUint32(4, 4 + body.length, true);
    out.set(b.subarray(8, 12), 8);
    out.set(body, 12);
    return out;
  }

  // MP4/MOV/M4V: turn udta, meta and XMP uuid boxes into 'free' boxes of the same length
  var XMP_UUID = [0xBE, 0x7A, 0xCF, 0xCB, 0x97, 0xA9, 0x42, 0xE8, 0x9C, 0x71, 0x99, 0x94, 0x91, 0xE3, 0xAF, 0xAC];
  function stripMp4(b) {
    if (b.length < 16 || ascii(b, 4, 4) !== 'ftyp') return b;
    var dv = new DataView(b.buffer, b.byteOffset, b.byteLength);
    var isXmp = function (o) {
      for (var i = 0; i < 16; i++) if (b[o + i] !== XMP_UUID[i]) return false;
      return true;
    };
    var walk = function (start, end, depth) {
      var pos = start;
      while (pos + 8 <= end) {
        var size = dv.getUint32(pos);
        var type = ascii(b, pos + 4, 4);
        var header = 8;
        if (size === 1) {
          if (pos + 16 > end) return;
          size = dv.getUint32(pos + 8) * 4294967296 + dv.getUint32(pos + 12);
          header = 16;
        } else if (size === 0) {
          size = end - pos;
        }
        if (size < header || pos + size > end) return;
        if (type === 'udta' || type === 'meta' || (type === 'uuid' && pos + header + 16 <= end && isXmp(pos + header))) {
          b.set([0x66, 0x72, 0x65, 0x65], pos + 4);
          b.fill(0, pos + header, pos + size);
        } else if ((type === 'moov' || type === 'trak') && depth < 3) {
          walk(pos + header, pos + size, depth + 1);
        }
        pos += size;
      }
    };
    walk(0, b.length, 0);
    return b;
  }

  function stripImage(bytes, type) {
    var out = null;
    if (type === 'image/jpeg') out = stripJpeg(bytes);
    else if (type === 'image/png') out = stripPng(bytes);
    else if (type === 'image/webp') out = stripWebp(bytes);
    return out || bytes;
  }

  // EXIF orientation of a JPEG (1 when absent)
  function jpegOrientation(b) {
    var dv = new DataView(b.buffer, b.byteOffset, b.byteLength);
    if (b.length < 4 || dv.getUint16(0) !== 0xFFD8) return 1;
    var pos = 2;
    try {
      while (pos + 4 <= b.length) {
        var marker = dv.getUint16(pos);
        if ((marker & 0xFF00) !== 0xFF00 || marker === 0xFFDA) break;
        var len = dv.getUint16(pos + 2);
        if (marker === 0xFFE1 && dv.getUint32(pos + 4) === 0x45786966) {
          var t = pos + 10;
          var le = dv.getUint16(t) === 0x4949;
          var ifd = t + dv.getUint32(t + 4, le);
          var count = dv.getUint16(ifd, le);
          for (var i = 0; i < count; i++) {
            var entry = ifd + 2 + i * 12;
            if (dv.getUint16(entry, le) === 0x0112) {
              var o = dv.getUint16(entry + 8, le);
              return o >= 1 && o <= 8 ? o : 1;
            }
          }
          return 1;
        }
        pos += 2 + len;
      }
    } catch (err) { /* truncated EXIF */ }
    return 1;
  }

  /* ================= Decoding and drawing ================= */

  // Decoded image with EXIF orientation applied
  function decode(blob) {
    if (window.createImageBitmap) {
      return createImageBitmap(blob, { imageOrientation: 'from-image' }).catch(function () { return decodeWithImg(blob); });
    }
    return decodeWithImg(blob);
  }

  function decodeWithImg(blob) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(blob);
      var img = new Image();
      img.onload = function () {
        URL.revokeObjectURL(url);
        resolve(img);
      };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('Could not read image')); };
      img.src = url;
    });
  }

  function release(img) {
    if (img && img.close) img.close();
  }

  // Width and height to resize to, or null to keep the image as it is
  function targetSize(w, h, o) {
    if (!o.resize || (!o.w && !o.h)) return null;
    if (o.locked) {
      var s = Math.min(o.w ? o.w / w : Infinity, o.h ? o.h / h : Infinity);
      if (s >= 1) return null;
      return [Math.max(1, Math.round(w * s)), Math.max(1, Math.round(h * s))];
    }
    var nw = o.w || w, nh = o.h || h;
    return nw === w && nh === h ? null : [nw, nh];
  }

  // Re-encode at a size; drops all metadata. Resolves to null when the browser cannot.
  function reencode(img, size, type, quality) {
    var canvas = document.createElement('canvas');
    canvas.width = size[0];
    canvas.height = size[1];
    var ctx = canvas.getContext('2d');
    if (!ctx) return Promise.resolve(null);
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(img, 0, 0, size[0], size[1]);
    return toBlob(canvas, type, quality).then(function (blob) {
      if (blob && blob.type === type) return [blob, null];
      return toBlob(canvas, 'image/png').then(function (png) { return png ? [png, 'png'] : null; });
    }).then(function (r) {
      if (!r) return null;
      return readBytes(r[0]).then(function (bytes) {
        return { bytes: bytes, type: r[0].type, ext: r[1], w: size[0], h: size[1] };
      });
    });
  }

  // Small preview, re-encoded so it carries no metadata
  function makeThumb(source, w, h) {
    if (!w || !h) return Promise.resolve(null);
    var s = Math.min(1, THUMB_MAX / Math.max(w, h));
    var canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(w * s));
    canvas.height = Math.max(1, Math.round(h * s));
    var ctx = canvas.getContext('2d');
    if (!ctx) return Promise.resolve(null);
    try {
      ctx.fillStyle = '#0b0d12';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(source, 0, 0, canvas.width, canvas.height);
    } catch (err) {
      return Promise.resolve(null);
    }
    return toBlob(canvas, 'image/webp', 0.8).then(function (b) {
      if (b && b.type === 'image/webp' && b.size <= THUMB_BYTES) return b;
      return toBlob(canvas, 'image/jpeg', 0.8);
    }).then(function (b) {
      if (!b || b.size > THUMB_BYTES) return null;
      return readBytes(b).then(function (bytes) { return { bytes: bytes, type: b.type }; });
    }).catch(function () { return null; });
  }

  // Dimensions and a frame of a video, or null when this browser cannot play it
  function videoFrame(blob) {
    return new Promise(function (resolve) {
      var v = document.createElement('video');
      var url = URL.createObjectURL(blob);
      var dims = null;
      var settled = false;
      var timer = setTimeout(function () { done(null); }, 8000);
      var done = function (canvas) {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        v.removeAttribute('src');
        try { v.load(); } catch (err) { /* already released */ }
        URL.revokeObjectURL(url);
        resolve(dims ? { width: dims[0], height: dims[1], canvas: canvas } : null);
      };
      v.muted = true;
      v.playsInline = true;
      v.preload = 'auto';
      v.addEventListener('loadedmetadata', function () {
        if (!v.videoWidth) return done(null);
        dims = [v.videoWidth, v.videoHeight];
        var d = v.duration;
        v.currentTime = isFinite(d) && d > 0 ? Math.min(1, d / 3) : 0.1;
      });
      v.addEventListener('seeked', function () {
        var canvas = document.createElement('canvas');
        canvas.width = dims[0];
        canvas.height = dims[1];
        try {
          canvas.getContext('2d').drawImage(v, 0, 0);
          done(canvas);
        } catch (err) {
          done(null);
        }
      });
      v.addEventListener('error', function () { done(null); });
      v.src = url;
    });
  }

  /* ================= Preparing a file ================= */

  // Bytes to upload, with metadata removed, resized, and a preview for encrypted uploads
  function prepare(item, o) {
    var kind = item.info[0], type = item.info[1];
    return readBytes(item.file).then(function (bytes) {
      var out = { bytes: bytes, type: type, kind: kind, name: cleanName(item.file.name, item.info[2]),
                  title: cleanTitle(item.title) || cleanTitle(o.title), width: 0, height: 0, thumb: null, thumbType: '' };

      if (kind === 'video') {
        if (o.strip && /^video\/(mp4|x-m4v|quicktime)$/.test(type)) out.bytes = stripMp4(bytes);
        if (!o.encrypt) return out;
        return videoFrame(new Blob([out.bytes], { type: type })).then(function (v) {
          if (!v) return out;
          out.width = v.width;
          out.height = v.height;
          if (!v.canvas) return out;
          return makeThumb(v.canvas, v.width, v.height).then(function (t) {
            if (t) { out.thumb = t.bytes; out.thumbType = t.type; }
            return out;
          });
        });
      }

      var gif = type === 'image/gif';
      var bake = o.strip && type === 'image/jpeg' && jpegOrientation(bytes) > 1;
      var mayResize = !gif && o.resize && (o.w || o.h);
      if (!bake && !mayResize && !o.encrypt) {
        if (o.strip) out.bytes = stripImage(bytes, type);
        return out;
      }

      return decode(new Blob([bytes], { type: type })).then(function (img) {
        var w = img.width, h = img.height;
        out.width = w;
        out.height = h;
        var size = mayResize ? targetSize(w, h, o) : null;
        var step = (size || bake) && !gif
          ? reencode(img, size || [w, h], type, o.quality)
          : Promise.resolve(null);
        return step.then(function (res) {
          if (res) {
            out.bytes = res.bytes;
            out.type = res.type;
            out.width = res.w;
            out.height = res.h;
            if (res.ext) out.name = rename(out.name, res.ext);
          } else if (o.strip) {
            out.bytes = stripImage(bytes, type);
          }
          if (!o.encrypt) return out;
          return makeThumb(img, w, h).then(function (t) {
            if (t) { out.thumb = t.bytes; out.thumbType = t.type; }
            return out;
          });
        }).then(function (result) {
          release(img);
          return result;
        });
      }, function () {
        if (o.strip) out.bytes = stripImage(bytes, type);
        return out;
      });
    });
  }

  // Encrypted file, preview and details, all under one passphrase and salt
  function encryptPrepared(prep, pass) {
    var meta = { n: prep.name, t: prep.type, s: prep.bytes.length, k: prep.kind };
    if (prep.title) meta.title = prep.title;
    if (prep.width) { meta.w = prep.width; meta.h = prep.height; }
    if (prep.thumbType) meta.tt = prep.thumbType;
    var metaBytes = new TextEncoder().encode(JSON.stringify(meta));

    var attempt = function (n) {
      var salt = newSalt();
      return deriveKey(pass, salt).then(function (key) {
        return Promise.all([
          encryptWith(key, salt, prep.bytes),
          prep.thumb ? encryptWith(key, salt, prep.thumb) : null,
          encryptWith(key, salt, metaBytes)
        ]);
      }).then(function (r) {
        if (n < 3 && looksLikeMedia(r[0])) return attempt(n + 1);
        return { file: r[0], thumb: r[1], meta: bytesToBase64(r[2]) };
      });
    };
    return attempt(0);
  }

  /* ================= Uploads grid ================= */

  var tiles = document.querySelectorAll('.img-tile[data-encrypted="1"]');
  var linkTiles = document.querySelectorAll('.img-tile[data-mode="link"]');
  var grid = document.querySelector('.img-grid[data-page-url]');
  var forgetBtn = document.getElementById('imgForget');

  function linkUrl(pattern, name, ext) {
    return pattern.replace('KEYNAME', encodeURIComponent(name)).replace('EXTNAME', encodeURIComponent(ext || ''));
  }

  function unlockLinkTile(tile, saved) {
    var link = tile.querySelector('a.preview');
    var nameEl = tile.querySelector('.caption .name');
    link.href = linkUrl(grid.dataset.pageUrl, saved.k);
    nameEl.textContent = saved.n || 'Upload';
    nameEl.title = saved.n || '';
    if (tile.dataset.kind === 'image') {
      var img = new Image();
      img.loading = 'lazy';
      img.alt = saved.n || '';
      img.src = linkUrl(grid.dataset.fileUrl, saved.k, tile.dataset.ext);
      link.replaceChildren(img);
    } else {
      link.replaceChildren(icon(tile.dataset.kind === 'video' ? 'bi-film' : 'bi-file-earmark'));
    }
  }

  if (linkTiles.length && grid) {
    var savedLinks = readKeys();
    var toKeep = [];
    Array.prototype.forEach.call(linkTiles, function (tile) {
      var saved = savedLinks[tile.dataset.slug];
      if (saved && typeof saved === 'object' && saved.k) {
        unlockLinkTile(tile, saved);
        if (tile.dataset.kept === '0') toKeep.push(saved.k);
      }
    });
    keepInAccount(toKeep);
  }

  // Signed in: links this browser remembers for uploads the account has no copy of yet are kept in the account
  function keepInAccount(names) {
    var token = document.querySelector('input[name="csrf_token"]');
    if (!names.length || !token || !document.getElementById('imgKeep')) return;
    var fd = new FormData();
    fd.append('csrf_token', token.value);
    fd.append('action', 'keep');
    fd.append('ajax', '1');
    names.forEach(function (n) { fd.append('names[]', n); });
    fetch(imgHome(), { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .catch(function () { /* tried again next visit */ });
  }

  function imgHome() {
    var f = document.getElementById('imgForm');
    return f ? f.action : location.pathname;
  }

  function unlockTile(tile, pass) {
    var link = tile.querySelector('a.preview');
    var nameEl = tile.querySelector('.caption .name');
    return decryptMeta(pass, tile.dataset.meta).then(function (meta) {
      if (link) link.href = withKey(link.href, pass);
      nameEl.textContent = displayName(meta) || 'Encrypted';
      nameEl.title = displayName(meta);
      var thumbUrl = tile.dataset.thumb;
      if (!thumbUrl) {
        link.replaceChildren(icon(meta.k === 'video' ? 'bi-film' : 'bi-image'));
        return null;
      }
      return fetch(thumbUrl, { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error('missing'); return r.arrayBuffer(); })
        .then(function (buf) { return decryptWith(pass, buf); })
        .then(function (plain) {
          var img = new Image();
          img.alt = displayName(meta);
          img.src = URL.createObjectURL(new Blob([plain], { type: meta.tt || 'image/jpeg' }));
          link.replaceChildren(img);
        });
    }).catch(function () { /* wrong or missing key: stays locked */ });
  }

  if (tiles.length && cryptoOk) {
    var keys = readKeys();
    var chain = Promise.resolve();
    Array.prototype.forEach.call(tiles, function (tile) {
      var pass = keys[tile.dataset.slug];
      if (typeof pass === 'string' && pass) chain = chain.then(function () { return unlockTile(tile, pass); });
    });
  }

  if (forgetBtn) {
    forgetBtn.addEventListener('click', function () {
      if (!confirm('Forget the links and keys saved in this browser? You will need the links to open your uploads again.')) return;
      forgetKeys();
      location.reload();
    });
  }

  /* ================= View page (encrypted) ================= */

  var stage = document.getElementById('imgStage');
  var unlockedPass = '';

  if (stage) viewEncrypted(stage);

  function viewEncrypted(stage) {
    var busy     = document.getElementById('imgBusy');
    var busyText = document.getElementById('imgBusyText');
    var keyForm  = document.getElementById('imgKeyForm');
    var keyInput = document.getElementById('imgKeyInput');
    var keyError = document.getElementById('imgKeyError');
    var slug     = stage.dataset.slug;

    var showBusy = function (text) {
      keyForm.classList.add('d-none');
      busy.classList.remove('d-none');
      busyText.textContent = text;
    };
    var showPrompt = function (error) {
      busy.classList.add('d-none');
      keyForm.classList.remove('d-none');
      keyError.textContent = error || '';
      keyError.classList.toggle('d-none', !error);
      keyInput.focus();
    };
    var showError = function (text) {
      stage.replaceChildren(el('div', 'text-center p-4 text-danger', text));
    };

    if (!cryptoOk) {
      showError('This browser cannot decrypt uploads here. Open the link over HTTPS in a current browser.');
      return;
    }

    var fetchFile = function (url, total) {
      return new Promise(function (resolve, reject) {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', url);
        xhr.responseType = 'arraybuffer';
        xhr.onprogress = function (e) {
          var size = e.lengthComputable ? e.total : total;
          if (size) busyText.textContent = 'Downloading… ' + Math.round(e.loaded / size * 100) + '%';
        };
        xhr.onload = function () {
          if (xhr.status === 200) resolve(xhr.response);
          else reject(new Error('The file could not be downloaded (' + xhr.status + ').'));
        };
        xhr.onerror = function () { reject(new Error('Network error while downloading.')); };
        xhr.send();
      });
    };

    var fillDetails = function (meta, pass) {
      var name = displayName(meta) || 'upload';
      var title = document.getElementById('imgName');
      title.querySelector('span').textContent = name;
      title.title = name;
      document.title = document.title.replace('Encrypted upload', name);
      document.getElementById('imgDetType').textContent = LABELS[meta.t] || 'File';
      if (meta.s) document.getElementById('imgDetSize').textContent = formatBytes(meta.s);
      if (meta.w && meta.h) {
        var row = document.getElementById('imgDetDimsRow');
        document.getElementById('imgDetDims').textContent = meta.w + ' × ' + meta.h;
        row.classList.remove('d-none');
        row.classList.add('d-flex');
      }
      document.getElementById('imgLinkKey').value = withKey(location.href, pass);
      document.getElementById('imgKeyOnly').value = pass;
      document.getElementById('imgKeyLinks').classList.remove('d-none');
    };

    var show = function (meta, plain) {
      var type = meta.t || 'application/octet-stream';
      var url = URL.createObjectURL(new Blob([plain], { type: type }));
      var name = downloadName(meta);
      var media;
      if (meta.k === 'image') {
        media = new Image();
        media.alt = displayName(meta) || name;
        media.src = url;
      } else if (meta.k === 'video' && document.createElement('video').canPlayType(type)) {
        media = document.createElement('video');
        media.controls = true;
        media.playsInline = true;
        media.src = url;
      } else {
        media = el('div', 'text-center p-5');
        media.appendChild(icon('bi-film d-block mb-2')).style.fontSize = '3rem';
        media.appendChild(el('p', 'mb-3', 'Browsers can\'t play ' + (LABELS[type] || 'these') + ' videos. Download it to watch.'));
        var dl = el('a', 'btn btn-primary');
        dl.href = url;
        dl.download = name;
        dl.appendChild(icon('bi-download me-1'));
        dl.appendChild(document.createTextNode('Download'));
        media.appendChild(dl);
      }
      stage.replaceChildren(media);
      var download = document.getElementById('imgDownload');
      download.href = url;
      download.download = name;
      download.classList.remove('disabled');
      download.removeAttribute('aria-disabled');
    };

    var open = function (pass, fromLink) {
      showBusy('Checking the key…');
      decryptMeta(pass, stage.dataset.meta).then(function (meta) {
        unlockedPass = pass;
        fillDetails(meta, pass);
        showBusy('Downloading…');
        return fetchFile(stage.dataset.file, parseInt(stage.dataset.size, 10) || 0)
          .then(function (buf) {
            showBusy('Decrypting…');
            return decryptWith(pass, buf).catch(function () {
              throw new Error('The file could not be decrypted. It may be damaged.');
            });
          })
          .then(function (plain) { show(meta, plain); });
      }, function () {
        showPrompt(fromLink ? 'The key in the link does not open this upload.' : 'That key does not open this upload.');
      }).catch(function (err) {
        showError(err.message);
      });
    };

    keyForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var pass = keyInput.value.trim();
      if (pass) open(pass, false);
    });

    window.addEventListener('hashchange', function () {
      var pass = hashPass();
      if (pass && !unlockedPass) open(pass, true);
    });

    var stored = readKeys()[slug];
    var initial = hashPass() || (typeof stored === 'string' ? stored : '');
    if (initial) open(initial, true);
    else showPrompt('');
  }

  /* ================= Report form ================= */

  var reportToggle = document.getElementById('imgReportToggle');
  var reportForm = document.getElementById('imgReportForm');
  if (reportToggle && reportForm) {
    var reportStatus = document.getElementById('imgReportStatus');
    reportToggle.addEventListener('click', function () {
      reportForm.classList.toggle('d-none');
      if (!reportForm.classList.contains('d-none')) reportForm.querySelector('textarea').focus();
    });
    reportForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(reportForm);
      var withPass = document.getElementById('imgReportKey');
      if (withPass && withPass.checked && unlockedPass) fd.append('pass', unlockedPass);
      var btn = reportForm.querySelector('button[type="submit"]');
      btn.disabled = true;
      fetch(reportForm.action, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || !data.success) throw new Error((data && data.error) || 'The report could not be sent.');
          reportStatus.className = 'small mb-2 text-success';
          reportStatus.textContent = 'Thanks. A moderator will take a look.';
          reportForm.querySelector('textarea').disabled = true;
        })
        .catch(function (err) {
          btn.disabled = false;
          reportStatus.className = 'small mb-2 text-danger';
          reportStatus.textContent = err.message || 'The report could not be sent.';
        });
    });
  }

  /* ================= Upload form ================= */

  var form = document.getElementById('imgForm');
  if (!form) return;

  var fileInput  = document.getElementById('imgFile');
  var drop       = document.getElementById('imgDrop');
  var queueEl    = document.getElementById('imgQueue');
  var submitBtn  = document.getElementById('imgSubmit');
  var countEl    = document.getElementById('imgCount');
  var statusEl   = document.getElementById('imgStatus');
  var encryptEl  = document.getElementById('imgEncrypt');
  var stripEl    = document.getElementById('imgStrip');
  var resizeOnEl = document.getElementById('imgResizeOn');
  var resizePane = document.getElementById('imgResizePanel');
  var widthEl    = document.getElementById('imgW');
  var heightEl   = document.getElementById('imgH');
  var lockBtn    = document.getElementById('imgLock');
  var qualityEl  = document.getElementById('imgQuality');
  var qualityLbl = document.getElementById('imgQualityLbl');
  var passCol    = document.getElementById('imgPassCol');
  var passEl     = document.getElementById('imgPass');
  var rememberEl = document.getElementById('imgRemember');
  var expiryEl   = document.getElementById('imgExpiry');
  var titleEl    = document.getElementById('imgTitle');
  var titleHelp  = document.getElementById('imgTitleHelp');
  var resultsBox = document.getElementById('imgResultsCard');
  var resultsEl  = document.getElementById('imgResults');
  var keepEl     = document.getElementById('imgKeep');
  var albumBox   = document.getElementById('imgAlbumBox');
  var albumOnEl  = document.getElementById('imgAlbumOn');
  var albumDescW = document.getElementById('imgAlbumDescWrap');
  var albumDesc  = document.getElementById('imgAlbumDesc');
  var titleLabel = document.getElementById('imgTitleLabel');
  var albumNote  = document.getElementById('imgAlbumNote');
  var albumWarn  = document.getElementById('imgAlbumWarn');
  var albumTouched = false;
  var albumOffered = false;

  var MAX_IMAGE = parseInt(form.dataset.maxImage, 10) || 0;
  var MAX_VIDEO = parseInt(form.dataset.maxVideo, 10) || 0;
  var CAPTCHA   = form.dataset.captcha || 'none';
  var SITEKEY   = form.dataset.sitekey || '';
  var MAX_FILES = 20;
  var ALBUM_MAX  = parseInt(form.dataset.albumMax, 10) || 0;
  var ALBUM_ADD  = form.dataset.albumAdd || '';
  var ALBUM_LEFT = parseInt(form.dataset.albumLeft, 10) || 0;
  var ALBUM_GUEST = form.dataset.albumGuest === '1';
  if (ALBUM_ADD) MAX_FILES = Math.max(1, ALBUM_LEFT);

  var queue = [];
  var busy = false;
  var locked = true;
  var aspect = 0;

  // The script uploads itself, so the theme's captcha submit handlers never see this form
  submitBtn.type = 'button';
  form.addEventListener('submit', function (e) { e.preventDefault(); uploadAll(); });

  function setStatus(text, cls) {
    statusEl.className = 'small mb-2 ' + (cls || '');
    statusEl.textContent = text;
  }

  function albumMode() {
    return !!(albumOnEl && albumOffered && albumOnEl.checked && !albumOnEl.disabled && !encryptEl.checked);
  }

  function options() {
    var album = albumMode();
    return {
      album:      album,
      albumTitle: album && titleEl ? titleEl.value : '',
      albumDesc:  album && albumDesc ? albumDesc.value : '',
      keep:       !!(keepEl && keepEl.checked),
      encrypt:  encryptEl.checked,
      strip:    stripEl.checked,
      resize:   resizeOnEl.checked,
      w:        parseInt(widthEl.value, 10) || 0,
      h:        parseInt(heightEl.value, 10) || 0,
      locked:   locked,
      quality:  (parseInt(qualityEl.value, 10) || 92) / 100,
      pass:     passEl.value.trim(),
      remember: rememberEl.checked,
      expiry:   expiryEl.value,
      title:    titleEl && !album ? titleEl.value : ''
    };
  }

  /* ---------- Options ---------- */

  encryptEl.addEventListener('change', function () {
    passCol.classList.toggle('d-none', !encryptEl.checked);
    renderQueue();
  });

  // Adding to an album: uploads must be readable through the album's links
  if (ALBUM_ADD) {
    encryptEl.checked = false;
    encryptEl.disabled = true;
    passCol.classList.add('d-none');
  }

  if (albumOnEl) {
    albumOnEl.addEventListener('change', function () { albumTouched = true; renderQueue(); });
  }

  // Two or more files go into an album unless switched off; the title then names the album.
  // Not offered for files encrypted in the browser, or for more files than an album holds.
  function syncAlbum(waiting) {
    if (!albumBox) return;
    albumBox.classList.toggle('d-none', waiting < 2);
    var why = '';
    if (encryptEl.checked) {
      why = 'Albums are not available for files encrypted in your browser: each file gets its own link.';
    } else if (waiting > ALBUM_MAX) {
      why = 'An album holds up to ' + ALBUM_MAX + ' files' + (ALBUM_GUEST ? ' for guests (log in for more)' : '')
        + ', so these will get separate links. Remove ' + (waiting - ALBUM_MAX) + ' to make an album.';
    }
    albumOnEl.disabled = why !== '';
    if (why) albumOnEl.checked = false;
    else if (!albumTouched) albumOnEl.checked = true;
    albumWarn.textContent = why;
    albumWarn.classList.toggle('d-none', why === '');
    albumNote.classList.toggle('d-none', why !== '');
    var offer = waiting > 1 && !why;
    albumOffered = offer;
    var on = offer && albumOnEl.checked;
    albumDescW.classList.toggle('d-none', !on);
    if (titleLabel) titleLabel.textContent = on ? 'Album title' : 'Title';
    if (titleEl) titleEl.placeholder = on ? 'Shown at the top of the album' : 'Shown instead of the file name';
  }

  resizeOnEl.addEventListener('change', function () {
    resizePane.classList.toggle('d-none', !resizeOnEl.checked);
    validateQueue();
  });

  lockBtn.addEventListener('click', function () {
    locked = !locked;
    lockBtn.classList.toggle('on', locked);
    lockBtn.querySelector('i').className = locked ? 'bi bi-link-45deg' : 'bi bi-link';
    lockBtn.title = locked ? 'Keep aspect ratio' : 'Stretch to exactly this size';
  });

  widthEl.addEventListener('input', function () {
    if (locked && aspect && widthEl.value) heightEl.value = Math.max(1, Math.round(widthEl.value / aspect));
  });
  heightEl.addEventListener('input', function () {
    if (locked && aspect && heightEl.value) widthEl.value = Math.max(1, Math.round(heightEl.value * aspect));
  });

  qualityEl.addEventListener('input', function () { qualityLbl.textContent = qualityEl.value; });

  if (titleEl) {
    titleEl.addEventListener('input', function () {
      Array.prototype.forEach.call(queueEl.querySelectorAll('input.title'), function (i) { i.placeholder = itemPlaceholder(); });
    });
  }

  /* ---------- Queue ---------- */

  function check(item) {
    if (item.done) return null;
    if (!item.info) return 'Unsupported file type';
    if (item.info[0] === 'video' && MAX_VIDEO && item.file.size > MAX_VIDEO) return 'Larger than ' + formatBytes(MAX_VIDEO);
    if (item.info[0] === 'image' && MAX_IMAGE && item.file.size > MAX_IMAGE && !resizeOnEl.checked) {
      return 'Larger than ' + formatBytes(MAX_IMAGE) + ' (try resizing)';
    }
    return null;
  }

  function validateQueue() {
    queue.forEach(function (item) { if (!item.done) item.error = check(item); });
    renderQueue();
  }

  function addFiles(list) {
    var files = Array.prototype.slice.call(list, 0, Math.max(0, MAX_FILES - queue.length));
    Promise.all(files.map(sniffFile)).then(function (infos) {
      files.forEach(function (file, i) {
        var item = { file: file, info: infos[i], error: null, done: false, title: '' };
        item.error = check(item);
        queue.push(item);
        if (!aspect && item.info && item.info[0] === 'image') learnAspect(file);
      });
      renderQueue();
    });
  }

  function learnAspect(file) {
    decodeWithImg(file).then(function (img) {
      if (!aspect && img.naturalWidth && img.naturalHeight) aspect = img.naturalWidth / img.naturalHeight;
    }, function () { /* not decodable here */ });
  }

  function itemPlaceholder() {
    var main = titleEl && !albumMode() ? cleanTitle(titleEl.value) : '';
    return main ? main + ' (or type another)' : 'Title for this file (optional)';
  }

  function renderQueue() {
    queueEl.replaceChildren();
    var pending = 0;
    var waiting = queue.filter(function (q) { return q.info && !q.error && !q.done; }).length;
    syncAlbum(waiting);
    if (titleHelp) titleHelp.classList.toggle('d-none', waiting < 2 || albumMode());
    queue.forEach(function (item, i) {
      if (!item.error && !item.done) pending++;
      var row = el('div', 'img-queue-item' + (item.done ? ' done' : '') + (item.error ? ' error' : ''));

      var thumb = el('div', 'thumb');
      if (item.info && item.info[0] === 'image') {
        if (!item.preview) item.preview = URL.createObjectURL(item.file);
        thumb.style.backgroundImage = 'url("' + item.preview + '")';
      } else {
        thumb.appendChild(icon(item.info ? 'bi-film' : 'bi-file-earmark-x'));
      }

      var meta = el('div', 'meta');
      meta.appendChild(el('div', 'name', item.done && item.sentTitle ? item.sentTitle : (item.file.name || 'pasted-image')));
      if (waiting > 1 && item.info && !item.error && !item.done) {
        var own = el('input', 'form-control form-control-sm title');
        own.type = 'text';
        own.maxLength = TITLE_MAX;
        own.placeholder = itemPlaceholder();
        own.value = item.title || '';
        own.disabled = busy;
        own.setAttribute('aria-label', 'Title for ' + (item.file.name || 'this file'));
        own.addEventListener('input', function () { item.title = own.value; });
        meta.appendChild(own);
      }
      var info = el('div', 'small ' + (item.error ? 'text-danger' : 'text-muted'),
        item.error || item.message || formatBytes(item.file.size));
      meta.appendChild(info);
      var barWrap = el('div', 'bar');
      var bar = el('div');
      barWrap.appendChild(bar);
      meta.appendChild(barWrap);

      var remove = el('button', 'btn btn-sm btn-link text-muted');
      remove.type = 'button';
      remove.title = 'Remove';
      remove.appendChild(icon('bi-x-lg'));
      remove.disabled = busy;
      remove.addEventListener('click', function () {
        if (item.preview) URL.revokeObjectURL(item.preview);
        queue.splice(i, 1);
        renderQueue();
      });

      row.appendChild(thumb);
      row.appendChild(meta);
      row.appendChild(remove);
      item.row = row;
      item.infoEl = info;
      item.barEl = bar;
      queueEl.appendChild(row);
    });
    countEl.textContent = pending ? pending + (pending === 1 ? ' file' : ' files') : '';
    countEl.classList.toggle('d-none', !pending);
  }

  fileInput.addEventListener('change', function () {
    addFiles(fileInput.files);
    fileInput.value = '';
  });

  ['dragenter', 'dragover'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
  });
  drop.addEventListener('drop', function (e) {
    if (e.dataTransfer && e.dataTransfer.files) addFiles(e.dataTransfer.files);
  });

  document.addEventListener('paste', function (e) {
    if (e.target && /^(INPUT|TEXTAREA)$/.test(e.target.tagName) && e.target.type !== 'file') return;
    var files = (e.clipboardData && e.clipboardData.files) || [];
    if (!files.length) return;
    e.preventDefault();
    addFiles(Array.prototype.map.call(files, function (f, i) {
      if (f.name && f.name !== 'image.png') return f;
      var ext = (f.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
      return new File([f], 'pasted-' + Date.now() + (i ? '-' + i : '') + '.' + ext, { type: f.type });
    }));
    setStatus('Pasted from the clipboard.', 'text-muted');
  });

  /* ---------- Captcha ---------- */

  var captchaDone = CAPTCHA === 'none';

  function captchaFields() {
    if (captchaDone) return Promise.resolve({});
    if (CAPTCHA === 'recaptcha_v3') {
      if (!window.grecaptcha) return Promise.reject(new Error('reCAPTCHA did not load. Reload the page.'));
      return new Promise(function (resolve, reject) {
        grecaptcha.ready(function () {
          grecaptcha.execute(SITEKEY, { action: 'upload' }).then(function (t) {
            resolve({ 'g-recaptcha-response': t });
          }, reject);
        });
      });
    }
    if (CAPTCHA === 'recaptcha') {
      var t = window.grecaptcha ? grecaptcha.getResponse() : '';
      return t ? Promise.resolve({ 'g-recaptcha-response': t }) : Promise.reject(new Error('Please complete the captcha.'));
    }
    if (CAPTCHA === 'turnstile') {
      var widget = document.getElementById('imgTurnstile');
      var tok = '';
      try { tok = window.turnstile && widget ? turnstile.getResponse(widget) : ''; } catch (err) { tok = ''; }
      if (!tok) {
        var input = form.querySelector('input[name="cf-turnstile-response"]');
        tok = input ? input.value : '';
      }
      return tok ? Promise.resolve({ 'cf-turnstile-response': tok }) : Promise.reject(new Error('Please wait for the captcha check to finish.'));
    }
    if (CAPTCHA === 'internal') {
      var code = form.querySelector('input[name="scode"]');
      return code && code.value.trim() ? Promise.resolve({ scode: code.value }) : Promise.reject(new Error('Enter the captcha code.'));
    }
    return Promise.resolve({});
  }

  function resetCaptcha() {
    try {
      if (CAPTCHA === 'recaptcha' && window.grecaptcha) grecaptcha.reset();
      if (CAPTCHA === 'turnstile' && window.turnstile) turnstile.reset(document.getElementById('imgTurnstile'));
    } catch (err) { /* widget not ready */ }
  }

  /* ---------- Upload ---------- */

  function send(fields, files, onProgress) {
    return new Promise(function (resolve, reject) {
      var fd = new FormData();
      fd.append('csrf_token', form.querySelector('input[name="csrf_token"]').value);
      fd.append('ajax', '1');
      Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
      files.forEach(function (f) { fd.append(f[0], f[1], f[2]); });

      var xhr = new XMLHttpRequest();
      xhr.open('POST', form.action);
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.upload.onprogress = function (e) { if (e.lengthComputable) onProgress(e.loaded / e.total); };
      xhr.onload = function () {
        var data = null;
        try { data = JSON.parse(xhr.responseText); } catch (err) { /* not JSON */ }
        if (data && data.success) return resolve(data);
        var msg = (data && data.error) || (xhr.status === 413 ? 'That file is too large for the server.' : 'Upload failed (' + xhr.status + ').');
        var error = new Error(msg);
        error.status = xhr.status;
        reject(error);
      };
      xhr.onerror = function () { reject(new Error('Network error while uploading.')); };
      xhr.send(fd);
    });
  }

  function sendFields(fields, pairs) {
    return fetch(form.action, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      body: (function () {
        var fd = new FormData();
        fd.append('csrf_token', form.querySelector('input[name="csrf_token"]').value);
        fd.append('ajax', '1');
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        pairs.forEach(function (p) { fd.append(p[0], p[1]); });
        return fd;
      })()
    }).then(function (r) {
      return r.json().catch(function () { return null; }).then(function (data) {
        if (data && data.success) return data;
        throw new Error((data && data.error) || 'The server answered ' + r.status + '.');
      });
    });
  }

  function uploadOne(item, o, label) {
    var limit = item.info[0] === 'video' ? MAX_VIDEO : MAX_IMAGE;
    var say = function (text) { setStatus(label + text + ' ' + (item.file.name || 'file') + '…', 'text-muted'); };
    var progress = function (p) { item.barEl.style.width = Math.round(p * 100) + '%'; };
    var pass = o.encrypt ? (o.pass || randomKey()) : '';

    item.row.classList.add('busy');
    say('Preparing');
    return prepare(item, o).then(function (prep) {
      if (limit && prep.bytes.length > limit) throw new Error('Larger than ' + formatBytes(limit));
      if (!o.encrypt) {
        var fields = { expiry: o.expiry };
        if (o.strip) fields.strip_exif = '1';
        if (o.keep) fields.keep = '1';
        if (prep.title) fields.title = prep.title;
        return captchaFields().then(function (cap) {
          say('Uploading');
          return send(Object.assign(fields, cap), [['file', new Blob([prep.bytes], { type: prep.type }), prep.name]], progress);
        }).then(function (data) {
          if (o.remember && data.key) storeKey(data.slug, { k: data.key, e: data.ext, n: data.title || data.name || prep.name });
          return { name: prep.title || prep.name, title: prep.title, link: data.url, data: data };
        });
      }
      say('Encrypting');
      return encryptPrepared(prep, pass).then(function (enc) {
        var files = [['file', new Blob([enc.file]), 'encrypted.bin']];
        if (enc.thumb) files.push(['thumb', new Blob([enc.thumb]), 'encrypted.thumb']);
        return captchaFields().then(function (cap) {
          say('Uploading');
          var fields = { encrypted: '1', kind: prep.kind, meta: enc.meta, expiry: o.expiry };
          return send(Object.assign(fields, cap), files, progress);
        });
      }).then(function (data) {
        if (o.remember) storeKey(data.slug, pass);
        return { name: prep.title || prep.name, title: prep.title, link: withKey(data.url, pass), data: data };
      });
    });
  }

  function resultRow(result, error, name) {
    var row = el('div', 'item ' + (error ? 'fail' : 'ok'));
    row.appendChild(icon(error ? 'bi-x-circle-fill text-danger mt-1' : 'bi-check-circle-fill text-success mt-1'));
    var body = el('div', 'flex-grow-1');
    body.style.minWidth = '0';
    body.appendChild(el('div', 'fw-semibold text-truncate', name));
    if (error) {
      body.appendChild(el('div', 'small', error));
    } else {
      var id = 'imgResult' + resultsEl.children.length;
      var group = el('div', 'input-group input-group-sm mt-1');
      var input = el('input', 'form-control font-monospace');
      input.id = id;
      input.readOnly = true;
      input.value = result.link;
      var copy = el('button', 'btn btn-outline-secondary');
      copy.type = 'button';
      copy.title = 'Copy';
      copy.setAttribute('data-copy', '#' + id);
      copy.appendChild(icon('bi-clipboard'));
      var openLink = el('a', 'btn btn-outline-secondary');
      openLink.href = result.link;
      openLink.title = 'Open';
      openLink.appendChild(icon('bi-box-arrow-up-right'));
      group.appendChild(input);
      group.appendChild(copy);
      group.appendChild(openLink);
      body.appendChild(group);
    }
    row.appendChild(body);
    resultsEl.appendChild(row);
    resultsBox.classList.remove('d-none');
  }

  // After separate uploads: a button that puts the ones with link keys into a new album
  function offerAlbum(results, o) {
    if (results.length < 2 || !ALBUM_MAX || ALBUM_ADD) return;
    var row = el('div', 'item');
    row.appendChild(icon('bi-collection text-primary mt-1'));
    var body = el('div', 'flex-grow-1');
    var btn = el('button', 'btn btn-sm btn-primary', 'Make an album from these ' + results.length);
    btn.type = 'button';
    body.appendChild(btn);
    var note = el('div', 'small text-muted mt-1', 'One page and one link for all of them. Each file keeps its own link too.');
    if (results.length > ALBUM_MAX) {
      btn.disabled = true;
      note.textContent = 'An album holds up to ' + ALBUM_MAX + ' files' + (ALBUM_GUEST ? ' for guests.' : '.');
    }
    body.appendChild(note);
    row.appendChild(body);
    resultsEl.insertBefore(row, resultsEl.firstChild);
    resultsBox.classList.remove('d-none');
    btn.addEventListener('click', function () {
      btn.disabled = true;
      setStatus('Making the album…', 'text-muted');
      var fields = { action: 'album_create', expiry: o.expiry };
      if (o.keep) fields.keep = '1';
      sendFields(fields, results.map(function (r) { return ['names[]', r.data.key]; })).then(function (data) {
        window.location.href = data.url;
      }, function (err) {
        btn.disabled = false;
        setStatus('The album could not be made: ' + err.message, 'text-danger');
      });
    });
  }

  function uploadAll() {
    if (busy) return;
    var o = options();
    var todo = queue.filter(function (q) { return !q.error && !q.done; });
    if (!todo.length) {
      setStatus(queue.length ? 'Nothing left to upload.' : 'Choose a file to upload.', 'text-warning');
      return;
    }
    if (o.encrypt && !cryptoOk) {
      setStatus('This browser cannot encrypt here. Use HTTPS and a current browser, or untick "Encrypt".', 'text-danger');
      return;
    }
    if (o.album && todo.length > ALBUM_MAX) {
      setStatus('An album can hold up to ' + ALBUM_MAX + ' uploads. Remove some files, or turn the album off.', 'text-danger');
      return;
    }
    if (ALBUM_ADD && todo.length > ALBUM_LEFT) {
      setStatus('The album has room for ' + ALBUM_LEFT + ' more ' + (ALBUM_LEFT === 1 ? 'upload.' : 'uploads.'), 'text-danger');
      return;
    }
    if (o.encrypt && o.pass && o.pass.length < MIN_PASS) {
      setStatus('Use a passphrase of at least ' + MIN_PASS + ' characters, or leave it empty for a random key.', 'text-danger');
      passEl.focus();
      return;
    }

    busy = true;
    submitBtn.disabled = true;
    renderQueue();
    var done = [];
    var index = 0;

    var finish = function (stopMessage) {
      if ((o.album || ALBUM_ADD) && done.length && !stopMessage) return makeAlbum();
      busy = false;
      submitBtn.disabled = false;
      renderQueue();
      if (done.length === 1 && todo.length === 1) {
        window.location.href = done[0].link;
        return;
      }
      if (stopMessage) {
        setStatus(stopMessage, 'text-danger');
      } else {
        var failed = todo.length - done.length;
        setStatus(done.length + ' uploaded' + (failed ? ', ' + failed + ' failed' : '') + '.', failed ? 'text-warning' : 'text-success');
      }
      offerAlbum(done.filter(function (r) { return r.data && r.data.key; }), o);
    };

    // Put the uploaded files in a new album, or in the album being added to, then open it
    var makeAlbum = function () {
      setStatus(ALBUM_ADD ? 'Adding to the album…' : 'Making the album…', 'text-muted');
      var fields = { action: ALBUM_ADD ? 'album_add' : 'album_create', expiry: o.expiry };
      if (ALBUM_ADD) fields.album = ALBUM_ADD;
      if (o.albumTitle) fields.title = o.albumTitle;
      if (o.albumDesc) fields.desc = o.albumDesc;
      if (o.keep) fields.keep = '1';
      var fd = done.map(function (r) { return ['names[]', r.data.key]; });
      return sendFields(fields, fd).then(function (data) {
        busy = false;
        submitBtn.disabled = false;
        if (done.length === todo.length) {
          window.location.href = data.url;
          return;
        }
        renderQueue();
        resultRow({ link: data.url }, null, 'Album');
        setStatus(done.length + ' uploaded and put in the album, ' + (todo.length - done.length) + ' failed.', 'text-warning');
      }, function (err) {
        busy = false;
        submitBtn.disabled = false;
        renderQueue();
        setStatus('Uploaded, but the album could not be saved: ' + err.message, 'text-danger');
      });
    };

    var next = function () {
      if (index >= todo.length) return finish();
      var item = todo[index];
      var label = todo.length > 1 ? '(' + (index + 1) + '/' + todo.length + ') ' : '';
      return uploadOne(item, o, label).then(function (result) {
        captchaDone = true;
        item.done = true;
        item.sentTitle = result.title;
        item.message = 'Uploaded';
        done.push(result);
        if (todo.length > 1) resultRow(result, null, result.name);
        index++;
        return next();
      }).catch(function (err) {
        item.error = err.message;
        if (todo.length > 1) resultRow(null, err.message, item.file.name || 'file');
        if (err.status === 403 || err.status === 429) {
          resetCaptcha();
          return finish(err.message);
        }
        index++;
        return next();
      });
    };

    next();
  }

  submitBtn.addEventListener('click', uploadAll);
})();
