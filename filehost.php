<?php
/*
 * FILEHOST endpoint for IRC networks (IRCv3 draft/FILEHOST + draft/authtoken)
 *
 *   OPTIONS /filehost            capabilities (Accept-Post) and CORS preflight
 *   POST    /filehost            upload; Authorization: Bearer <authtoken JWT>
 *   GET     /filehost/<slug>.<ext>   serve a stored file (HEAD too)
 *
 * The IRC server advertises `draft/FILEHOST=<url of this endpoint>`.  A
 * client asks the ircd for a token (`TOKEN GENERATE FILEHOST`), which is an
 * ES256 JWT signed with the network's key, and POSTs the file with it as a
 * Bearer token.  We verify the signature with the network's public key, so
 * no IRC password ever reaches this site and no connection to the IRC
 * server is needed.  Text uploads become unlisted pastes owned by the
 * configured service user; everything else is stored under FILEHOST_DIR.
 *
 * Configuration: see docs/filehost.md and docs/config.example.php
 * (FILEHOST_* constants).  Schema: upgrade/2.1-to-2.2-filehost.sql.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/filehost_jwt.php';
require_once __DIR__ . '/includes/filehost_store.php';
require_once __DIR__ . '/includes/filehost_exif.php';

date_default_timezone_set('UTC');

/* ---- configuration with defaults ---- */
$fh_enabled  = defined('FILEHOST_ENABLED') ? (bool)FILEHOST_ENABLED : false;
$fh_url      = defined('FILEHOST_URL') ? (string)FILEHOST_URL : '';
$fh_pubkey   = defined('FILEHOST_PUBKEY') ? (string)FILEHOST_PUBKEY : '';
$fh_issuer   = defined('FILEHOST_ISSUER') ? (string)FILEHOST_ISSUER : '';
$fh_dir      = defined('FILEHOST_DIR') ? (string)FILEHOST_DIR : __DIR__ . '/../filehost_files';
$fh_max      = defined('FILEHOST_MAX_BYTES') ? (int)FILEHOST_MAX_BYTES : 10 * 1024 * 1024;
$fh_accept   = defined('FILEHOST_ACCEPT') ? (string)FILEHOST_ACCEPT : 'image/*, video/*, audio/*, text/*';
$fh_member   = defined('FILEHOST_MEMBER') ? (string)FILEHOST_MEMBER : 'irc';
$fh_expiry   = defined('FILEHOST_EXPIRY') ? (string)FILEHOST_EXPIRY : 'M';
$fh_perhour  = defined('FILEHOST_PER_HOUR') ? (int)FILEHOST_PER_HOUR : 30;
$fh_retain   = defined('FILEHOST_RETAIN_DAYS') ? (int)FILEHOST_RETAIN_DAYS : 30;
$fh_strip    = defined('FILEHOST_STRIP_METADATA') ? (bool)FILEHOST_STRIP_METADATA : true;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$now = time();

function fh_cors(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Content-Disposition, Content-Length');
    header('Access-Control-Expose-Headers: Location, Content-Type, Content-Length');
    header('Access-Control-Max-Age: 86400');
}

function fh_json(int $status, array $data, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    foreach ($headers as $h) {
        header($h);
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit;
}

function fh_error(int $status, string $code, string $message, array $headers = []): never
{
    fh_json($status, ['success' => false, 'error' => $code, 'message' => $message], $headers);
}

/* CLI cron: php filehost.php --cron */
if (PHP_SAPI === 'cli') {
    if (in_array('--cron', $argv ?? [], true)) {
        filehost_sweep($pdo, $fh_dir, $now, 10000);
        echo "filehost: sweep done\n";
    } else {
        echo "usage: php filehost.php --cron\n";
    }
    exit;
}

fh_cors();

if (!$fh_enabled) {
    fh_error(404, 'disabled', 'FILEHOST is not enabled on this site');
}

/* ---- OPTIONS: capabilities + CORS preflight ---- */
if ($method === 'OPTIONS') {
    http_response_code(204);
    header('Allow: OPTIONS, POST');
    header('Accept-Post: ' . $fh_accept);
    header('X-Filehost-Max-Bytes: ' . $fh_max);
    exit;
}

/* ---- GET / HEAD: serve a stored file ---- */
if ($method === 'GET' || $method === 'HEAD') {
    $f = (string)($_GET['f'] ?? '');
    if (!preg_match('/^([A-Za-z0-9]{6,32})\.([a-z0-9]{1,8})$/', $f, $m)) {
        fh_error(404, 'not_found', 'No such file');
    }
    $st = $pdo->prepare('SELECT slug, ext, mime, size, filename, expires FROM filehost_files WHERE slug = ? AND paste_id IS NULL');
    $st->execute([$m[1]]);
    $row = $st->fetch();
    if (!$row || $row['ext'] !== $m[2]
        || ($row['expires'] !== null && strtotime($row['expires']) < $now)) {
        fh_error(404, 'not_found', 'No such file');
    }
    $path = $fh_dir . '/' . $row['slug'] . '.' . $row['ext'];
    if (!is_file($path)) {
        fh_error(404, 'not_found', 'No such file');
    }
    $size = (int)$row['size'];
    $mime = $row['mime'] ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: bytes');
    header('Cache-Control: public, max-age=86400, immutable');
    header('Content-Security-Policy: default-src \'none\'; sandbox');
    $dl = $row['filename'] ? '; filename="' . addcslashes((string)$row['filename'], '"\\') . '"' : '';
    header('Content-Disposition: inline' . $dl);

    /* Single byte range so browsers can seek video/audio. */
    $start = 0;
    $end = $size - 1;
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $r) && ($r[1] !== '' || $r[2] !== '')) {
        if ($r[1] === '') {                       /* suffix: last N bytes */
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
    header('Content-Length: ' . ($end - $start + 1));
    if ($method === 'HEAD') {
        exit;
    }
    $fp = fopen($path, 'rb');
    if ($fp === false) {
        exit;
    }
    fseek($fp, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fp)) {
        $chunk = fread($fp, min(65536, $left));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $left -= strlen($chunk);
    }
    fclose($fp);
    exit;
}

if ($method !== 'POST') {
    fh_error(405, 'method_not_allowed', 'Use OPTIONS, POST, GET or HEAD', ['Allow: OPTIONS, POST']);
}

/* ---- POST: upload ---- */
if ($fh_pubkey === '' || $fh_url === '' || $fh_issuer === '') {
    fh_error(503, 'misconfigured', 'FILEHOST_PUBKEY, FILEHOST_URL and FILEHOST_ISSUER must be set');
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($auth === '' && function_exists('apache_request_headers')) {
    $h = apache_request_headers();
    $auth = $h['Authorization'] ?? $h['authorization'] ?? '';
}
$challenge = 'WWW-Authenticate: Bearer realm="filehost", error="invalid_token"';
if (!preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m)) {
    /* Basic is refused on purpose: the whole point of authtoken is that an
     * IRC password never reaches this site. */
    fh_error(401, 'unauthorized', 'Send an IRC authtoken as Authorization: Bearer <token> (TOKEN GENERATE FILEHOST)',
             ['WWW-Authenticate: Bearer realm="filehost"']);
}
$claims = filehost_verify_jwt($m[1], $fh_pubkey, $fh_issuer, $fh_url, $now);
if (!is_array($claims)) {
    fh_error(401, 'invalid_token', 'Token rejected: ' . $claims, [$challenge]);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (function_exists('is_banned') && is_banned($pdo, $ip)) {
    fh_error(403, 'banned', 'Uploads from this address are not accepted');
}

/* Size and type, from the headers first so we can refuse before reading. */
$declared_len = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : -1;
if ($declared_len > $fh_max) {
    fh_error(413, 'too_large', 'Maximum upload size is ' . $fh_max . ' bytes');
}
$mime = filehost_mime_normalize($_SERVER['CONTENT_TYPE'] ?? '');
if ($mime === '') {
    $mime = 'application/octet-stream';
}
if (!filehost_mime_accepted($mime, $fh_accept)) {
    fh_error(415, 'unsupported_type', 'Accepted types: ' . $fh_accept, ['Accept-Post: ' . $fh_accept]);
}

/* Single use: the jti goes in before anything is stored; a duplicate key is
 * a replay.  Rows are swept an hour after exp. */
try {
    $st = $pdo->prepare('INSERT INTO filehost_jti (jti, exp, account, created) VALUES (?, ?, ?, NOW())');
    $st->execute([$claims['jti'], $claims['exp'], $claims['sub']]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        fh_error(401, 'replayed_token', 'This token has already been used', [$challenge]);
    }
    error_log('filehost: jti insert failed: ' . $e->getMessage());
    fh_error(500, 'storage_error', 'Could not record the token');
}

/* Per-account rate limit. */
if ($fh_perhour > 0) {
    $st = $pdo->prepare('SELECT COUNT(*) FROM filehost_files WHERE account = ? AND created > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
    $st->execute([$claims['sub']]);
    if ((int)$st->fetchColumn() >= $fh_perhour) {
        fh_error(429, 'rate_limited', 'Upload limit reached for this account; try again later', ['Retry-After: 600']);
    }
}

/* Read the body, bounded. */
$in = fopen('php://input', 'rb');
$body = '';
if ($in !== false) {
    while (!feof($in)) {
        $chunk = fread($in, 65536);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
        if (strlen($body) > $fh_max) {
            fclose($in);
            fh_error(413, 'too_large', 'Maximum upload size is ' . $fh_max . ' bytes');
        }
    }
    fclose($in);
}
$size = strlen($body);
if ($size === 0) {
    fh_error(400, 'empty', 'Empty upload');
}

/* Sniff binaries: the declared major type must agree with the content. */
$is_text = str_starts_with($mime, 'text/') || in_array($mime, ['application/json', 'application/xml'], true);
if (!$is_text && class_exists('finfo')) {
    $sniffed = (new finfo(FILEINFO_MIME_TYPE))->buffer($body) ?: '';
    $want = explode('/', $mime, 2)[0];
    $got = explode('/', $sniffed, 2)[0];
    if (in_array($want, ['image', 'video', 'audio'], true) && $got !== $want) {
        fh_error(415, 'type_mismatch', 'Content does not look like ' . $mime . ' (' . $sniffed . ')');
    }
    if (in_array($sniffed, FILEHOST_REFUSE, true)) {
        fh_error(415, 'unsupported_type', 'Refused content type ' . $sniffed);
    }
}
if ($is_text && !mb_check_encoding($body, 'UTF-8')) {
    fh_error(415, 'unsupported_type', 'Text uploads must be UTF-8');
}

$filename = filehost_disposition_filename($_SERVER['HTTP_CONTENT_DISPOSITION'] ?? '');

/* Site URL bits, as api.php does. */
$siteInfo = $pdo->query('SELECT baseurl FROM site_info WHERE id = 1')->fetch() ?: [];
$baseurl = rtrim((string)($siteInfo['baseurl'] ?? ''), '/') . '/';
$rewrite = ((string)($mod_rewrite ?? '0') === '1');

try {
    filehost_sweep($pdo, $fh_dir, $now);
    if ($is_text) {
        [$location, $paste_id] = filehost_store_text($pdo, $body, $filename, $fh_member, $fh_expiry, $baseurl, $rewrite, $ip);
        $st = $pdo->prepare('INSERT INTO filehost_files (slug, account, network, mime, size, ext, filename, paste_id, ip, created, expires)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NULL)');
        $st->execute([filehost_new_slug($pdo), $claims['sub'], $claims['iss'], $mime, $size, 'txt', $filename, $paste_id, $ip]);
        $expires = null;
    } else {
        $stripped = false;
        if ($fh_strip) {
            [$body, $stripped] = filehost_strip_metadata($body, $mime);
            $size = strlen($body);
        }
        header('X-Filehost-Metadata: ' . ($stripped ? 'stripped' : 'kept'));
        $ext = FILEHOST_EXT[$mime] ?? 'bin';
        $slug = filehost_new_slug($pdo);
        filehost_store_file($fh_dir, $body, $slug, $ext);
        $expires = $fh_retain > 0 ? date('Y-m-d H:i:s', $now + $fh_retain * 86400) : null;
        $st = $pdo->prepare('INSERT INTO filehost_files (slug, account, network, mime, size, ext, filename, paste_id, ip, created, expires)
                             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, NOW(), ?)');
        $st->execute([$slug, $claims['sub'], $claims['iss'], $mime, $size, $ext, $filename, $ip, $expires]);
        $location = $rewrite ? $baseurl . 'filehost/' . $slug . '.' . $ext
                             : $baseurl . 'filehost.php?f=' . $slug . '.' . $ext;
    }
} catch (Throwable $e) {
    error_log('filehost: store failed: ' . $e->getMessage());
    fh_error(500, 'storage_error', 'Could not store the upload');
}

fh_json(201, [
    'success' => true,
    'url' => $location,
    'type' => $mime,
    'size' => $size,
    'expires' => $expires,
], ['Location: ' . $location]);
