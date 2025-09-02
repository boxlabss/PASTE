// --- fixes for older iOS/Android ---
(function () {
  var E = Element.prototype;
  if (!E.matches) {
    E.matches = E.msMatchesSelector || E.webkitMatchesSelector || function (sel) {
      var n = (this.document || this.ownerDocument).querySelectorAll(sel), i = n.length;
      while (--i >= 0 && n.item(i) !== this) {}
      return i > -1;
    };
  }
  if (!E.closest) {
    E.closest = function (sel) {
      var el = this;
      while (el) {
        if (el.matches && el.matches(sel)) return el;
        el = el.parentElement || el.parentNode;
      }
      return null;
    };
  }
})();

(function () {
  'use strict';

  // ========= tiny utils =====================================================
  function lineStart(value, i){ while (i > 0 && value.charCodeAt(i - 1) !== 10) i--; return i }
  function lineEnd(value, i){ while (i < value.length && value.charCodeAt(i) !== 10) i++; return i }
  function triggerInput(el){ try { el.dispatchEvent(new Event('input', { bubbles: true })) } catch(_) {} }
  function countLinesFast(str){ let n = 1; for (let i=0; i<str.length; i++) if (str.charCodeAt(i) === 10) n++; return n }
  function digitsOf(n){ return Math.max(1, (n|0).toString().length) }

  // ========= lightweight editor (textarea + virtualized line-number gutter) =
  function initLiteEditor(ta, opts){
    if (!ta || ta.dataset.liteInit === '1') return;
    const readOnly = !!(opts && opts.readOnly);

    // ---- DOM scaffold -------------------------------------------------------
    const wrap = document.createElement('div');
    wrap.className = 'editor-wrap';

    const gutter = document.createElement('div');
    gutter.className = 'editor-gutter';
    gutter.setAttribute('aria-hidden','true');

    const rail = document.createElement('div');
    rail.className = 'editor-gutter-inner';
    gutter.appendChild(rail);

    ta.parentNode.insertBefore(wrap, ta);
    wrap.appendChild(gutter);
    wrap.appendChild(ta);

    ta.classList.add('editor-ta', 'form-control');
    ta.setAttribute('wrap','off');
    ta.style.overflowX = 'auto';
    ta.style.overflowY = 'auto';
    ta.dataset.liteInit = '1';

    // ---- metrics ------------------------------------------------------------
    const csTA = getComputedStyle(ta);
    const fs   = parseFloat(csTA.fontSize) || 14;
	const lhPx = (csTA.lineHeight && csTA.lineHeight !== 'normal')
	  ? parseFloat(csTA.lineHeight)
	  : Math.round(fs * 1.5);
	ta.style.lineHeight = lhPx + 'px';

    gutter.style.fontFamily = csTA.fontFamily;
    gutter.style.fontSize   = csTA.fontSize;
    gutter.style.lineHeight = lhPx + 'px';
    gutter.style.paddingTop    = csTA.paddingTop;
    gutter.style.paddingBottom = csTA.paddingBottom;

    const csG = getComputedStyle(gutter);
    const csR = getComputedStyle(rail);
    const padTopTA = parseFloat(csTA.paddingTop)        || 0;
    const bTopTA   = parseFloat(csTA.borderTopWidth)    || 0;
    const padTopGU = parseFloat(csG.paddingTop)         || 0;
    const bTopGU   = parseFloat(csG.borderTopWidth)     || 0;
    const padTopRL = parseFloat(csR.paddingTop)         || 0;

    // base delta from computed paddings/borders; tiny runtime nudge may be added
    const TOP_DELTA_BASE = (padTopTA + bTopTA) - (padTopGU + bTopGU + padTopRL);
    let deltaAdj = 0; // small calibration nudge (±2px)
    const dpr = Math.max(1, window.devicePixelRatio || 1);
    const snap = (y) => Math.round(y * dpr) / dpr; // snap to device pixel grid

    // lock initial height (avoid huge pastes auto-expanding)
    (function lockHeightOnce(){
      const rows   = parseInt(ta.getAttribute('rows') || '0', 10);
      const padTop    = parseFloat(csTA.paddingTop)        || 0;
      const padBottom = parseFloat(csTA.paddingBottom)     || 0;
      const bTop      = parseFloat(csTA.borderTopWidth)    || 0;
      const bBottom   = parseFloat(csTA.borderBottomWidth) || 0;
      const h = rows > 0
        ? Math.round(rows * lhPx + padTop + padBottom + bTop + bBottom)
        : ta.offsetHeight;
      if (h > 0) { ta.style.height = h + 'px'; ta.style.minHeight = h + 'px'; }
    })();

    ta.style.boxShadow = 'none';
    ta.style.outline   = '0';
    ta.addEventListener('focus', function(){
      ta.style.boxShadow = 'none';
      ta.style.outline   = '0';
    });

    // ---- state for virtual gutter ------------------------------------------
    let totalLines   = countLinesFast(ta.value);
    let renderStart  = 1;
    let renderEnd    = 0;
    let rafId        = 0;

    function visibleCount(){ return Math.max(1, Math.ceil(ta.clientHeight / lhPx) + 1); }
    function bufferSize(){ const v = visibleCount(); return Math.min(200, Math.max(20, v)); }

    // cache digits → update gutter width only when digit count changes
    let lastDigitWidth = digitsOf(totalLines);
    function ensureGutterWidthMaybe(){
      const d = digitsOf(totalLines);
      if (d !== lastDigitWidth) {
        gutter.style.minWidth = (d + 2) + 'ch';
        lastDigitWidth = d;
      }
    }
    ensureGutterWidthMaybe();

    function firstVisibleLine(){ return Math.max(1, Math.floor(ta.scrollTop / lhPx) + 1); }

    function buildNumbers(start, end){
      const len = end - start + 1;
      const out = new Array(len);
      for (let i = 0; i < len; i++) out[i] = (start + i) + '';
      return out.join('\n') + '\n';
    }

    function positionRail(){
      const offsetPx = ((renderStart - 1) * lhPx) - ta.scrollTop + TOP_DELTA_BASE + deltaAdj;
      rail.style.transform = 'translate3d(0,' + snap(offsetPx) + 'px,0)';
    }

    // Measure & nudge the gutter to perfectly align with the textarea lines
    function calibrate(){
      const taRect   = ta.getBoundingClientRect();
      const railRect = rail.getBoundingClientRect();

      const textTopForRenderStart =
        taRect.top + padTopTA + bTopTA + ((renderStart - 1) * lhPx) - ta.scrollTop;

      const currentRailTop = railRect.top;
      const needed = textTopForRenderStart - currentRailTop;

      if (isFinite(needed)) {
        // clamp: if layout hasn't settled, ignore big diffs
        const clamped = Math.max(-3, Math.min(3, needed));
        deltaAdj += clamped;
        positionRail();
      }
    }

    function update(){
      rafId = 0;
      const firstVis = firstVisibleLine();
      const buf = bufferSize();
      const visCnt = visibleCount();

      const needStart = Math.max(1, firstVis - buf);
      const needEnd   = Math.min(totalLines, firstVis + visCnt + buf);

      if (needStart !== renderStart || needEnd !== renderEnd) {
        rail.textContent = buildNumbers(needStart, needEnd);
        renderStart = needStart;
        renderEnd   = needEnd;
        ensureGutterWidthMaybe();
      }

      positionRail();
      const h = ta.offsetHeight;
      if (gutter._h !== h) { gutter.style.height = h + 'px'; gutter._h = h; }

      // one-shot calibration after first paint (double-rAF so fonts/layout settle)
      if (!ta._didCal) {
        ta._didCal = true;
        requestAnimationFrame(() => requestAnimationFrame(calibrate));
        // also when fonts are ready (some themes swap fonts late)
        if (document.fonts && document.fonts.ready) {
          document.fonts.ready.then(() => { requestAnimationFrame(calibrate); });
        }
      }
    }
    function schedule(){ if (!rafId) rafId = requestAnimationFrame(update) }

    // ---- events -------------------------------------------------------------
    ta.addEventListener('scroll', schedule, { passive: true });

    const onContent = function(){
      const newTotal = countLinesFast(ta.value);
      if (newTotal !== totalLines) { totalLines = newTotal; }
      schedule();
    };
    ta.addEventListener('input',  onContent);
    ta.addEventListener('change', onContent);
    ta.addEventListener('cut',    onContent);
    ta.addEventListener('paste',  onContent);

    if (!readOnly){
      ta.addEventListener('keydown', function(e){
        if (e.key !== 'Tab') return;
        e.preventDefault();

        const start = ta.selectionStart, end = ta.selectionEnd;
        const v = ta.value;

        if (start === end) {
          ta.value = v.slice(0,start) + '    ' + v.slice(start);
          ta.setSelectionRange(start + 4, start + 4);
          totalLines = countLinesFast(ta.value);
          return schedule();
        }

        const ls = lineStart(v, start);
        const le = lineEnd(v, end);
        const before = v.slice(0, ls);
        const middle = v.slice(ls, le);
        const after  = v.slice(le);

        let out = [];
        if (e.shiftKey){
          let i = 0, addedTotal = 0, firstLineRemoved = 0, atLineStart = true, lineIndex = 0;
          while (i < middle.length) {
            if (atLineStart) {
              let removed = 0;
              const ch0 = middle.charCodeAt(i);
              if (ch0 === 9) { i += 1; removed = 1; }
              else {
                let k = i, s = 0;
                while (k < middle.length && s < 4 && middle.charCodeAt(k) === 32) { k++; s++; }
                i = k; removed = s;
              }
              if (lineIndex === 0) firstLineRemoved = removed;
              addedTotal += removed;
              atLineStart = false;
            }
            const nl = middle.indexOf('\n', i);
            if (nl === -1) { out.push(middle.slice(i)); break; }
            out.push(middle.slice(i, nl + 1));
            i = nl + 1; atLineStart = true; lineIndex++;
          }
          const newMiddle = out.join('');
          ta.value = before + newMiddle + after;
          const newStart = Math.max(ls, start - firstLineRemoved);
          const newEnd   = le - addedTotal;
          ta.setSelectionRange(newStart, newEnd);
        } else {
          let i = 0, addedTotal = 0, atLineStart = true, firstLineAdded = 4, lineIndex = 0;
          while (i < middle.length) {
            if (atLineStart) {
              out.push('    ');
              addedTotal += 4;
              if (lineIndex === 0) firstLineAdded = 4;
              atLineStart = false;
            }
            const nl = middle.indexOf('\n', i);
            if (nl === -1) { out.push(middle.slice(i)); break; }
            out.push(middle.slice(i, nl + 1));
            i = nl + 1; atLineStart = true; lineIndex++;
          }
          const newMiddle = out.join('');
          ta.value = before + newMiddle + after;
          ta.setSelectionRange(start + firstLineAdded, end + addedTotal);
        }

        totalLines = countLinesFast(ta.value);
        schedule();
      });
    } else {
      ta.setAttribute('readonly','readonly');
    }

    // Keep things aligned when the box resizes
    let ro = null;
    if ('ResizeObserver' in window){
      ro = new ResizeObserver(() => { schedule(); requestAnimationFrame(calibrate); });
      ro.observe(ta);
    } else {
      window.addEventListener('resize', () => { schedule(); requestAnimationFrame(calibrate); });
    }

    // Recalibrate when coming back to the tab (fonts/layout may snap)
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible') {
        schedule();
        requestAnimationFrame(calibrate);
      }
    });

    // Modern cleanup (no unload)
    let didClean = false;
    const cleanup = () => {
      if (didClean) return;
      didClean = true;
      if (ro) { try { ro.disconnect(); } catch(_) {} }
    };
    window.addEventListener('pagehide', cleanup, { once: true });
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') cleanup();
    }, { once: true });

    // Initial paint
    schedule();
  }

  // ========= notifications ==================================================
  function showNotification(message, isError = false, fadeOut = true) {
    const notification = document.getElementById('notification');
    if (!notification) return;
    notification.textContent = message;
    notification.className = 'notification' + (isError ? ' error' : '');
    notification.style.display = 'block';
    if (fadeOut) {
      const hide = () => {
        notification.classList.add('fade-out');
        setTimeout(() => {
          notification.style.display = 'none';
          notification.classList.remove('fade-out');
          notification.textContent = '';
        }, 500);
      };
      setTimeout(hide, 3000);
    } else {
      if (!notification.querySelector('.close-btn')) {
        const closeBtn = document.createElement('button');
        closeBtn.textContent = '×';
        closeBtn.className = 'close-btn';
        closeBtn.addEventListener('click', () => {
          notification.style.display = 'none';
          notification.textContent = '';
        }, { once: true });
        notification.appendChild(closeBtn);
      }
    }
  }

  // ========= tools  =====================================
  window.togglev = function () {
    const block = document.querySelector('.code-content');
    if (block) {
      block.classList.toggle('no-line-numbers');
      try { localStorage.setItem('paste_ln_hidden', block.classList.contains('no-line-numbers') ? '1' : '0'); } catch (_) {}
      return;
    }
    const olElement = document.querySelector('pre ol, .geshi ol, ol');
    if (!olElement) { showNotification('Code list element not found.', true); return; }
    const currentStyle = olElement.style.listStyle || getComputedStyle(olElement).listStyle;
    olElement.style.listStyle = (currentStyle.startsWith('none')) ? 'decimal' : 'none';
  };

window.toggleFullScreen = function(){
  const modalElement = document.getElementById('fullscreenModal');
  if (!modalElement) { showNotification('Fullscreen modal not available.', true); return; }
  bootstrap.Modal.getOrCreateInstance(modalElement).show();
};

  window.copyToClipboard = function(){
    const ta = document.getElementById('code');
    const text = ta ? ta.value : '';
    if (!text) { showNotification('No code to copy.', true); return; }
    navigator.clipboard.writeText(text).then(
      () => showNotification('Copied to clipboard!'),
      () => showNotification('Failed to copy.', true)
    );
  };

  window.showEmbedCode = function(embedCode){
    if (embedCode) showNotification('Embed code: ' + embedCode, false, false);
    else showNotification('Could not generate embed code.', true);
  };

  // Insert "!highlight!" at selected lines in the main editor (allocation-light)
  window.highlightLine = function (e) {
    if (e && e.preventDefault) e.preventDefault();
    const ta = document.getElementById('edit-code'); if (!ta) return;

    const prefix = '!highlight!';
    const value  = ta.value;
    const start  = ta.selectionStart || 0;
    const end    = ta.selectionEnd   || start;
    const keepScroll = ta.scrollTop;

    const ls = lineStart(value, start);
    const le = lineEnd(value, end);

    const before = value.slice(0, ls);
    const middle = value.slice(ls, le);
    const after  = value.slice(le);

    let out = [];
    let i = 0, addedTotal = 0, firstLineAdded = 0, atLineStart = true, lineIndex = 0;

    while (i < middle.length) {
      if (atLineStart) {
        if (middle.substr(i, prefix.length) !== prefix) {
          out.push(prefix);
          addedTotal += prefix.length;
          if (lineIndex === 0) firstLineAdded = prefix.length;
        }
        atLineStart = false;
      }
      const nl = middle.indexOf('\n', i);
      if (nl === -1) { out.push(middle.slice(i)); break; }
      out.push(middle.slice(i, nl + 1));
      i = nl + 1; atLineStart = true; lineIndex++;
    }

    const newMiddle = out.join('');
    ta.value = before + newMiddle + after;

    if (start === end) {
      const caret = start + firstLineAdded;
      ta.setSelectionRange(caret, caret);
    } else {
      ta.setSelectionRange(ls, le + addedTotal);
    }

    ta.scrollTop = keepScroll;
    triggerInput(ta);
    ta.focus();
  };

  // ========= boot ===========================================================
  document.addEventListener('DOMContentLoaded', function(){
    // Init editor only for the edit box
    const edit = document.getElementById('edit-code'); if (edit) initLiteEditor(edit, { readOnly:false });

    // Delegated clicks
    document.addEventListener('click', function (ev) {
      const t = ev.target;
      if (t.closest && t.closest('.highlight-line'))   { ev.preventDefault(); window.highlightLine(ev); }
      if (t.closest && t.closest('.toggle-fullscreen')){ ev.preventDefault(); window.toggleFullScreen(); }
      if (t.closest && t.closest('.copy-clipboard'))   { ev.preventDefault(); window.copyToClipboard(); }
    }, { capture: true });

    // ---- Move .code-content into fullscreen modal on demand ----------------
    const modalEl = document.getElementById('fullscreenModal');
    const host    = document.getElementById('fullscreen-host');
    const home    = document.getElementById('code-content-home');
    const codeDom = document.getElementById('code-content');

    if (modalEl && host && home && codeDom && window.bootstrap && bootstrap.Modal) {
      bootstrap.Modal.getOrCreateInstance(modalEl);

      modalEl.addEventListener('show.bs.modal', () => {
        if (codeDom.parentNode !== host) host.appendChild(codeDom);
      });

      modalEl.addEventListener('hidden.bs.modal', () => {
        if (codeDom.parentNode !== home.parentNode) {
          home.insertAdjacentElement('beforebegin', codeDom);
        }
      });
    }

    // ---- Lazy-load raw paste into textarea (deferred editor init) ----------
    const rawBlock = document.getElementById('raw-block');
    if (rawBlock) {
      const btn  = document.getElementById('load-raw');
      const ta   = document.getElementById('code');
      const url  = rawBlock.getAttribute('data-raw-url');

      if (btn && ta && url) {
        const load = async () => {
          btn.disabled = true;
          try {
            const res = await fetch(url, { credentials: 'same-origin' });
            const text = await res.text();
            ta.value = text;
            ta.classList.remove('d-none');
            // now that it's visible and has content, init the gutter
            initLiteEditor(ta, { readOnly: true });
            // kick a measurement update just in case
            triggerInput(ta);
            btn.remove();
          } catch (e) {
            console.error('Raw fetch failed:', e);
            btn.disabled = false;
            showNotification('Failed to load raw paste.', true);
          }
        };
        btn.addEventListener('click', load, { once: true });
      }
    }
  });

})();


// ===== Comments ==================================
document.addEventListener('DOMContentLoaded', function () {
  // tiny $$ helper w/o NodeList.forEach dependency
  function $all(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  // helper: update remaining characters (mobile-safe)
  function updateRemaining(ta) {
    if (!ta) return;
    var maxAttr = ta.getAttribute('maxlength');
    var max = parseInt(maxAttr || '4000', 10);
    if (!isFinite(max) || max <= 0) max = 4000;

    var left = Math.max(0, max - ((ta.value || '').length));

    var display;
    if (ta.id === 'comment-body-main') {
      display = document.getElementById('c-remaining');
    } else {
      var form = ta.closest('form');
      display = form ? form.querySelector('.c-remaining') : null;
    }
    if (display) display.textContent = left;
  }

  // open/close inline reply form
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('.comment-reply') : null;
    if (!btn) return;
    ev.preventDefault();
    var targetSel = btn.getAttribute('data-target');
    var target = targetSel ? document.querySelector(targetSel) : null;
    if (!target) return;
    var isHidden = target.classList.contains('d-none');
    if (isHidden) target.classList.remove('d-none'); else target.classList.add('d-none');
    if (!isHidden) {
      var ta = target.querySelector('textarea[name="comment_body"]');
      if (ta) { updateRemaining(ta); try { ta.focus(); } catch(_){} }
    }
  }, true);

  // cancel inline reply
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('.reply-cancel') : null;
    if (!btn) return;
    ev.preventDefault();
    var targetSel = btn.getAttribute('data-target');
    var target = targetSel ? document.querySelector(targetSel) : (btn.closest ? btn.closest('.mt-2') : null);
    if (target && !target.classList.contains('d-none')) target.classList.add('d-none');
  }, true);

  // show/hide collapsed reply tail
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('.comment-expand') : null;
    if (!btn) return;
    ev.preventDefault();
    var listSel = btn.getAttribute('data-target');
    var list = listSel ? document.querySelector(listSel) : null;
    if (!list) return;

    if (!btn.dataset.showHtml) btn.dataset.showHtml = btn.innerHTML;
    if (!btn.dataset.hideHtml) btn.dataset.hideHtml = '<i class="bi bi-chevron-up"></i> Hide replies';

    var nowHidden = list.classList.toggle('d-none'); // true if hidden after toggle
    btn.innerHTML = nowHidden ? btn.dataset.showHtml : btn.dataset.hideHtml;
  }, true);

  // live character counters (main + inline replies)
  function counterHandler(ev) {
    var ta = ev.target;
    if (ta && ta.tagName === 'TEXTAREA' && ta.name === 'comment_body') updateRemaining(ta);
  }
  document.addEventListener('input', counterHandler, true);
  document.addEventListener('keyup',  counterHandler, true); // helps older mobiles

  $all('#comment-body-main, form textarea[name="comment_body"]').forEach(updateRemaining);

  // optional AJAX delete (fallback to normal submit if fetch not available)
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form || !form.matches || !form.matches('form')) return;

    var isDelete = form.querySelector('input[name="action"][value="delete_comment"]');
    if (!isDelete) return;

    // if fetch isn't supported, allow default submission
    if (!window.fetch) return;

    ev.preventDefault();

    var li = form.closest ? form.closest('.comment-item') : null;
    var fd = new FormData(form);
    fd.set('ajax', '1');

    fetch(form.action, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd,
      credentials: 'same-origin'
    })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
      .then(function (j) {
        if (j && j.success) {
          if (li && li.parentNode) li.parentNode.removeChild(li);
          var badge = document.getElementById('comments-count');
          if (badge) {
            var n = parseInt(badge.textContent || '0', 10) || 0;
            badge.textContent = Math.max(0, n - 1);
          }
        } else {
          showNotification((j && j.message) ? j.message : 'Delete failed.', true);
        }
      })
      .catch(function () { showNotification('Delete failed.', true); });
  }, true);
});