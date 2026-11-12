<?php
/*
 * Paste https://github.com/boxlabss/PASTE
 *
 * Encryption at rest for stored uploads, keyed by the name in the file's link.
 * The server keeps the ciphertext and an id derived from the name, never the
 * name itself, so a stored file can only be read by someone who has its link.
 *
 * Format: "PFE1" | 8-byte nonce prefix | chunks. Each chunk holds up to 64 KB
 * of plaintext sealed with AES-256-GCM: nonce = prefix | chunk number, and the
 * header, chunk number and a last-chunk flag are authenticated with it. Ranges
 * are served by decrypting only the chunks they cover.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */
declare(strict_types=1);

const FILECRYPT_MAGIC  = 'PFE1';
const FILECRYPT_HEADER = 12;
const FILECRYPT_CHUNK  = 65536;
const FILECRYPT_TAG    = 16;
const FILECRYPT_NAME_LENGTH = 22;

/** Value of filehost_files.encrypted for files sealed with their link name. */
const FILECRYPT_MODE = 2;

/** New random link name (22 letters and digits, about 131 bits). */
function filecrypt_new_name(): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $name = '';
    for ($i = 0; $i < FILECRYPT_NAME_LENGTH; $i++) {
        $name .= $chars[random_int(0, 61)];
    }
    return $name;
}

/** Storage id for a link name (what filehost_files.slug holds). */
function filecrypt_id(string $name): string
{
    return substr(hash_hmac('sha256', $name, 'paste file id v1'), 0, 32);
}

/** AES key for a link name. */
function filecrypt_key(string $name): string
{
    return hash_hkdf('sha256', $name, 32, 'paste file key v1');
}

/** Plaintext length of a sealed file of $sealedLength bytes, or null when the length is impossible. */
function filecrypt_plain_size(int $sealedLength): ?int
{
    $body = $sealedLength - FILECRYPT_HEADER;
    if ($body < FILECRYPT_TAG) return null;
    $full = intdiv($body, FILECRYPT_CHUNK + FILECRYPT_TAG);
    $rest = $body % (FILECRYPT_CHUNK + FILECRYPT_TAG);
    if ($rest === 0) return $full * FILECRYPT_CHUNK;
    if ($rest < FILECRYPT_TAG) return null;
    return $full * FILECRYPT_CHUNK + $rest - FILECRYPT_TAG;
}

function filecrypt_chunk_count(int $plainSize): int
{
    return max(1, intdiv($plainSize + FILECRYPT_CHUNK - 1, FILECRYPT_CHUNK));
}

function filecrypt_aad(string $header, int $i, bool $last): string
{
    return $header . pack('N', $i) . ($last ? "\1" : "\0");
}

/** Seal $body with the key for $name. */
function filecrypt_seal(string $body, string $name): string
{
    $key = filecrypt_key($name);
    $header = FILECRYPT_MAGIC . random_bytes(8);
    $out = $header;
    $n = filecrypt_chunk_count(strlen($body));
    for ($i = 0; $i < $n; $i++) {
        $tag = '';
        $ct = openssl_encrypt((string)substr($body, $i * FILECRYPT_CHUNK, FILECRYPT_CHUNK), 'aes-256-gcm', $key,
            OPENSSL_RAW_DATA, substr($header, 4) . pack('N', $i), $tag, filecrypt_aad($header, $i, $i === $n - 1), FILECRYPT_TAG);
        if ($ct === false) {
            throw new RuntimeException('encryption failed');
        }
        $out .= $ct . $tag;
    }
    return $out;
}

/**
 * Open a sealed file for reading: [handle, header, key, plaintext size].
 * Null when the file is missing or not in this format.
 */
function filecrypt_open(string $path, string $name): ?array
{
    clearstatcache(true, $path);
    $size = is_file($path) ? filesize($path) : false;
    $plain = $size === false ? null : filecrypt_plain_size((int)$size);
    if ($plain === null) return null;
    $fp = fopen($path, 'rb');
    if ($fp === false) return null;
    $header = (string)fread($fp, FILECRYPT_HEADER);
    if (strlen($header) !== FILECRYPT_HEADER || !str_starts_with($header, FILECRYPT_MAGIC)) {
        fclose($fp);
        return null;
    }
    return [$fp, $header, filecrypt_key($name), $plain];
}

/** Plaintext of chunk $i, or null when it does not authenticate. */
function filecrypt_read_chunk(array $f, int $i): ?string
{
    [$fp, $header, $key, $plain] = $f;
    $n = filecrypt_chunk_count($plain);
    if ($i < 0 || $i >= $n) return null;
    $len = ($i < $n - 1 ? FILECRYPT_CHUNK : $plain - $i * FILECRYPT_CHUNK) + FILECRYPT_TAG;
    if (fseek($fp, FILECRYPT_HEADER + $i * (FILECRYPT_CHUNK + FILECRYPT_TAG)) !== 0) return null;
    $sealed = '';
    while (strlen($sealed) < $len && !feof($fp)) {
        $part = fread($fp, $len - strlen($sealed));
        if ($part === false || $part === '') break;
        $sealed .= $part;
    }
    if (strlen($sealed) !== $len) return null;
    $pt = openssl_decrypt(substr($sealed, 0, -FILECRYPT_TAG), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
        substr($header, 4) . pack('N', $i), substr($sealed, -FILECRYPT_TAG), filecrypt_aad($header, $i, $i === $n - 1));
    return $pt === false ? null : $pt;
}

/** First chunk of plaintext (enough for image dimensions), or null. */
function filecrypt_read_head(string $path, string $name): ?string
{
    $f = filecrypt_open($path, $name);
    if ($f === null) return null;
    $pt = filecrypt_read_chunk($f, 0);
    fclose($f[0]);
    return $pt;
}

/** Encrypted details (such as the original file name) as base64. */
function filecrypt_seal_meta(array $meta, string $name): string
{
    $nonce = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt((string)json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'aes-256-gcm',
        filecrypt_key($name), OPENSSL_RAW_DATA, $nonce, $tag, 'meta', FILECRYPT_TAG);
    return base64_encode($nonce . $ct . $tag);
}

/** Details sealed by filecrypt_seal_meta(); [] when absent or unreadable. */
function filecrypt_open_meta(?string $b64, string $name): array
{
    $raw = $b64 !== null && $b64 !== '' ? base64_decode($b64, true) : false;
    if ($raw === false || strlen($raw) < 12 + FILECRYPT_TAG) return [];
    $pt = openssl_decrypt(substr($raw, 12, -FILECRYPT_TAG), 'aes-256-gcm', filecrypt_key($name), OPENSSL_RAW_DATA,
        substr($raw, 0, 12), substr($raw, -FILECRYPT_TAG), 'meta');
    $meta = $pt === false ? null : json_decode($pt, true);
    return is_array($meta) ? $meta : [];
}

/** Write a sealed file atomically as <dir>/<id>.enc. */
function filecrypt_write(string $dir, string $id, string $sealed): void
{
    filehost_store_file($dir, $sealed, $id, 'enc');
}

/**
 * Seal and store an upload and insert its row. $cols holds the other
 * filehost_files columns (account, network, mime, ext, ip, expires, paste_id).
 * The original file name and an optional title are sealed into its details.
 * Returns the link name; the stored row has slug = filecrypt_id(name).
 */
function filecrypt_store(PDO $pdo, string $dir, string $body, array $cols, ?string $filename, ?string $title = null): string
{
    $check = $pdo->prepare('SELECT 1 FROM filehost_files WHERE slug = ?');
    do {
        $name = filecrypt_new_name();
        $id = filecrypt_id($name);
        $check->execute([$id]);
    } while ($check->fetchColumn());

    filecrypt_write($dir, $id, filecrypt_seal($body, $name));
    $meta = array_filter(['n' => $filename, 'title' => $title], static fn($v) => $v !== null && $v !== '');
    $cols = [
        'slug'      => $id,
        'size'      => strlen($body),
        'filename'  => null,
        'encrypted' => FILECRYPT_MODE,
        'meta'      => $meta ? filecrypt_seal_meta($meta, $name) : null,
    ] + $cols + ['paste_id' => null, 'expires' => null];
    $names = array_keys($cols);
    try {
        $pdo->prepare('INSERT INTO filehost_files (' . implode(',', $names) . ', created) VALUES ('
                      . implode(',', array_fill(0, count($names), '?')) . ', NOW())')
            ->execute(array_values($cols));
    } catch (Throwable $e) {
        @unlink($dir . '/' . $id . '.enc');
        throw $e;
    }
    return $name;
}

/** Content-Security-Policy for a served file: no scripts; video and audio may load themselves when opened directly. */
function filecrypt_csp(string $mime): string
{
    $kind = strtolower(explode('/', $mime, 2)[0]);
    return match ($kind) {
        'video', 'audio' => "default-src 'none'; media-src 'self'; sandbox allow-same-origin",
        'image'          => "default-src 'none'; img-src 'self'; sandbox",
        default          => "default-src 'none'; sandbox",
    };
}

/** Content-Disposition value, with an ASCII fallback and the UTF-8 name for browsers that read it. */
function filecrypt_disposition(?string $filename): string
{
    $filename = $filename !== null ? trim((string)preg_replace('/[\x00-\x1f\x7f]/u', '', $filename)) : '';
    if ($filename === '') return 'inline';
    $ascii = (string)preg_replace('/[^\x20-\x7e]/u', '_', $filename);
    $out = 'inline; filename="' . addcslashes($ascii, '"\\') . '"';
    if ($ascii !== $filename) {
        $out .= "; filename*=UTF-8''" . rawurlencode($filename);
    }
    return $out;
}

/**
 * Stream a sealed file (or one byte range of it) with safe headers, then exit.
 * Responds 404 when the name does not open the file.
 */
function filecrypt_send(string $path, string $name, string $mime, ?string $filename, bool $headOnly): never
{
    $f = filecrypt_open($path, $name);
    $first = $f !== null ? filecrypt_read_chunk($f, 0) : null;
    if ($f === null || $first === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Not found\n");
    }
    $size = $f[3];

    header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=86400');
    header('Referrer-Policy: no-referrer');
    header('Content-Security-Policy: ' . filecrypt_csp($mime));
    header('Content-Disposition: ' . filecrypt_disposition($filename));

    $start = 0;
    $end = $size - 1;
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $r) && ($r[1] !== '' || $r[2] !== '')) {
        if ($r[1] === '') {
            $start = max(0, $size - (int)$r[2]);
        } else {
            $start = (int)$r[1];
            if ($r[2] !== '') {
                $end = min($size - 1, (int)$r[2]);
            }
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    } else {
        http_response_code(200);
    }
    header('Content-Length: ' . max(0, $end - $start + 1));
    if ($headOnly || $size === 0) {
        exit;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $firstChunk = intdiv($start, FILECRYPT_CHUNK);
    $lastChunk = intdiv($end, FILECRYPT_CHUNK);
    for ($i = $firstChunk; $i <= $lastChunk; $i++) {
        $pt = $i === 0 ? $first : filecrypt_read_chunk($f, $i);
        if ($pt === null) {
            break;
        }
        $from = $i === $firstChunk ? $start - $i * FILECRYPT_CHUNK : 0;
        $to = $i === $lastChunk ? $end - $i * FILECRYPT_CHUNK : strlen($pt) - 1;
        echo substr($pt, $from, $to - $from + 1);
        flush();
    }
    fclose($f[0]);
    exit;
}

/** Path of an upload row's stored file. */
function filecrypt_stored_path(string $dir, array $row): string
{
    return $dir . '/' . $row['slug'] . '.' . ((int)$row['encrypted'] === FILECRYPT_MODE ? 'enc' : $row['ext']);
}

/** True when the storage folder can be read and holds at least one file. */
function filecrypt_dir_has_files(string $dir): bool
{
    $h = is_dir($dir) ? @opendir($dir) : false;
    if ($h === false) return false;
    while (($f = readdir($h)) !== false) {
        if ($f[0] !== '.' && is_file($dir . '/' . $f)) {
            closedir($h);
            return true;
        }
    }
    closedir($h);
    return false;
}

/** SQL condition limiting upload rows to a source: 'web', 'irc', or null for both. */
function filecrypt_source_sql(?string $source): string
{
    return match ($source) {
        'web'   => " AND network = 'web'",
        'irc'   => " AND network <> 'web'",
        default => '',
    };
}

/** Upload rows whose stored file is missing; [] when the folder itself cannot be read. */
function filecrypt_missing(PDO $pdo, string $dir, ?string $source = null): array
{
    clearstatcache();
    if (!is_dir($dir) || !is_readable($dir)) return [];
    $rows = $pdo->query('SELECT id, slug, ext, encrypted, network FROM filehost_files WHERE paste_id IS NULL' . filecrypt_source_sql($source))
                ->fetchAll(PDO::FETCH_ASSOC);
    return array_values(array_filter($rows, static fn(array $r): bool => !is_file(filecrypt_stored_path($dir, $r))));
}

/**
 * Remove the rows (and reports and previews) of uploads whose stored file is
 * missing, so they stop being listed. Unless $evenIfEmpty, nothing is removed
 * when the folder holds no files at all, which usually means a wrong or
 * unmounted FILEHOST_DIR rather than lost files. $source limits it to 'web'
 * or 'irc' uploads.
 * Returns ['missing' => n, 'removed' => n, 'held' => bool].
 */
function filecrypt_prune_missing(PDO $pdo, string $dir, bool $evenIfEmpty = false, ?string $source = null): array
{
    $missing = filecrypt_missing($pdo, $dir, $source);
    $res = ['missing' => count($missing), 'removed' => 0, 'held' => false];
    if (!$missing) return $res;
    if (!$evenIfEmpty && !filecrypt_dir_has_files($dir)) {
        $res['held'] = true;
        return $res;
    }
    $del = $pdo->prepare('DELETE FROM filehost_files WHERE id = ?');
    foreach ($missing as $r) {
        $del->execute([$r['id']]);
        @unlink($dir . '/' . $r['slug'] . '.thumb');
        try {
            $pdo->prepare('DELETE FROM img_reports WHERE slug = ?')->execute([$r['slug']]);
        } catch (PDOException $e) {
            // reports table not created yet
        }
        $res['removed']++;
    }
    return $res;
}

/**
 * The link name in a pasted link or key (/filehost/<name>.jpg, /img/<name>,
 * ?f=<name>.jpg, ?v=<name>, a #pass= link, or the bare name), or null.
 */
function filecrypt_name_from_link(string $link): ?string
{
    $link = trim($link);
    $link = (string)preg_replace('/#.*$/s', '', $link);
    if (preg_match('/[?&](?:f|v)=([A-Za-z0-9]{6,32})(?:\.[a-z0-9]{1,8})?(?:&|$)/', $link, $m)) {
        return $m[1];
    }
    $link = (string)preg_replace('/\?.*$/s', '', $link);
    $last = basename(rtrim($link, '/'));
    if (preg_match('/^([A-Za-z0-9]{6,32})(?:\.[a-z0-9]{1,8})?$/', $last, $m)) {
        return $m[1];
    }
    return null;
}
