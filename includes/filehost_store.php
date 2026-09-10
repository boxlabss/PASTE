<?php
/*
 * FILEHOST: storage for uploads (text -> paste rows, binaries -> files).
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */
declare(strict_types=1);

/** Declared MIME type -> stored extension.  Anything not listed within an
 *  accepted major type is stored as .bin and served as
 *  application/octet-stream; SVG and HTML never get served inline. */
const FILEHOST_EXT = [
    'image/jpeg' => 'jpg',  'image/png' => 'png',   'image/gif' => 'gif',
    'image/webp' => 'webp', 'image/avif' => 'avif', 'image/heic' => 'heic',
    'image/bmp' => 'bmp',
    'video/mp4' => 'mp4',   'video/webm' => 'webm', 'video/quicktime' => 'mov',
    'video/x-matroska' => 'mkv', 'video/ogg' => 'ogv',
    'audio/mpeg' => 'mp3',  'audio/ogg' => 'ogg',   'audio/wav' => 'wav',
    'audio/x-wav' => 'wav', 'audio/flac' => 'flac', 'audio/mp4' => 'm4a',
    'audio/webm' => 'weba', 'audio/aac' => 'aac',
    'application/pdf' => 'pdf', 'application/zip' => 'zip',
    'application/gzip' => 'gz', 'application/x-tar' => 'tar',
    'application/octet-stream' => 'bin',
];

/** Types we refuse even when their major type is accepted. */
const FILEHOST_REFUSE = ['image/svg+xml', 'text/html', 'application/xhtml+xml',
                         'text/javascript', 'application/javascript'];

/** File extension -> paste syntax name (GeSHi / highlight.php names). */
const FILEHOST_SYNTAX = [
    'php' => 'php', 'py' => 'python', 'js' => 'javascript', 'ts' => 'typescript',
    'c' => 'c', 'h' => 'c', 'cpp' => 'cpp', 'cc' => 'cpp', 'hpp' => 'cpp',
    'java' => 'java', 'go' => 'go', 'rs' => 'rust', 'rb' => 'ruby', 'pl' => 'perl',
    'sh' => 'bash', 'bash' => 'bash', 'json' => 'json', 'yml' => 'yaml',
    'yaml' => 'yaml', 'xml' => 'xml', 'html' => 'html5', 'css' => 'css',
    'sql' => 'sql', 'md' => 'markdown', 'diff' => 'diff', 'patch' => 'diff',
    'ini' => 'ini', 'conf' => 'ini', 'log' => 'text', 'txt' => 'text',
];

/**
 * Split "type/subtype; params" into a lower-case "type/subtype".
 */
function filehost_mime_normalize(string $ct): string
{
    $ct = strtolower(trim(explode(';', $ct, 2)[0]));
    return preg_match('#^[a-z0-9!\#$&^_.+-]+/[a-z0-9!\#$&^_.+-]+$#', $ct) ? $ct : '';
}

/**
 * Does $mime match the Accept-Post list ("image/*, text/*, application/pdf")?
 */
function filehost_mime_accepted(string $mime, string $accept): bool
{
    if (in_array($mime, FILEHOST_REFUSE, true)) {
        return false;
    }
    foreach (array_map('trim', explode(',', strtolower($accept))) as $pat) {
        if ($pat === '' ) {
            continue;
        }
        if ($pat === '*/*' || $pat === $mime) {
            return true;
        }
        if (str_ends_with($pat, '/*') && str_starts_with($mime, substr($pat, 0, -1))) {
            return true;
        }
    }
    return false;
}

/**
 * Filename from a Content-Disposition header, sanitised; null when absent.
 */
function filehost_disposition_filename(string $cd): ?string
{
    $name = null;
    if (preg_match("/filename\\*=(?:UTF-8|utf-8)''([^;]+)/", $cd, $m)) {
        $name = rawurldecode($m[1]);
    } elseif (preg_match('/filename="((?:[^"\\\\]|\\\\.)*)"/', $cd, $m)) {
        $name = stripcslashes($m[1]);
    } elseif (preg_match('/filename=([^;\s]+)/', $cd, $m)) {
        $name = $m[1];
    }
    if ($name === null) {
        return null;
    }
    $name = basename(str_replace('\\', '/', trim($name)));
    $name = preg_replace('/[\x00-\x1f\x7f"]/', '', $name) ?? '';
    $name = mb_substr($name, 0, 120);
    return $name === '' || $name === '.' || $name === '..' ? null : $name;
}

/**
 * Random URL slug for a stored file, unique in filehost_files.
 */
function filehost_new_slug(PDO $pdo, int $length = 12): string
{
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $slug = '';
        for ($i = 0; $i < $length; $i++) {
            $slug .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $st = $pdo->prepare('SELECT 1 FROM filehost_files WHERE slug = ?');
        $st->execute([$slug]);
        if (!$st->fetchColumn()) {
            return $slug;
        }
    }
    throw new RuntimeException('could not allocate a slug');
}

/**
 * Store a text upload as a paste.  Returns [raw_url, paste_id].
 */
function filehost_store_text(PDO $pdo, string $body, ?string $filename, string $member,
                             string $expiry, string $baseurl, bool $mod_rewrite,
                             string $ip): array
{
    $title = $filename ?? 'IRC upload';
    $ext = $filename ? strtolower(pathinfo($filename, PATHINFO_EXTENSION)) : '';
    $syntax = FILEHOST_SYNTAX[$ext] ?? 'text';
    if ($syntax === 'text' && function_exists('paste_detect_shebang')) {
        $syntax = paste_detect_shebang($body) ?? 'text';
    }

    $slug = null;
    if (function_exists('getPasteUrlMode') && getPasteUrlMode($pdo) === 'slug') {
        $slug = generateUniquePasteSlug($pdo, getPasteSlugLength($pdo));
    }
    $now = date('Y-m-d H:i:s');
    if ($slug !== null) {
        $st = $pdo->prepare('INSERT INTO pastes (slug, title, content, visible, code, expiry, password, encrypt, member, date, ip, now_time, s_date)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP(), ?)');
        $st->execute([$slug, $title, $body, '1', $syntax, $expiry, 'NONE', '0', $member, $now, $ip, $now]);
    } else {
        $st = $pdo->prepare('INSERT INTO pastes (title, content, visible, code, expiry, password, encrypt, member, date, ip, now_time, s_date)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP(), ?)');
        $st->execute([$title, $body, '1', $syntax, $expiry, 'NONE', '0', $member, $now, $ip, $now]);
    }
    $id = (int)$pdo->lastInsertId();
    $ident = $slug ?? (string)$id;
    $raw = $mod_rewrite ? $baseurl . 'raw/' . $ident : $baseurl . 'paste.php?raw&id=' . $ident;
    return [$raw, $id];
}

/**
 * Store a binary upload under FILEHOST_DIR.  Returns [slug, ext].
 */
function filehost_store_file(string $dir, string $body, string $slug, string $ext): void
{
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('storage directory unavailable');
    }
    $path = $dir . '/' . $slug . '.' . $ext;
    $tmp = $path . '.part';
    if (file_put_contents($tmp, $body, LOCK_EX) !== strlen($body) || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('write failed');
    }
    chmod($path, 0640);
}

/**
 * Remove expired jti rows and expired files (a few per call; cheap enough
 * to run on every POST, or all of them from the CLI cron).
 */
function filehost_sweep(PDO $pdo, string $dir, int $now, int $limit = 20): void
{
    $pdo->prepare('DELETE FROM filehost_jti WHERE exp < ?')->execute([$now - 3600]);
    $st = $pdo->prepare('SELECT id, slug, ext FROM filehost_files WHERE expires IS NOT NULL AND expires < FROM_UNIXTIME(?) AND paste_id IS NULL LIMIT ' . (int)$limit);
    $st->execute([$now]);
    $del = $pdo->prepare('DELETE FROM filehost_files WHERE id = ?');
    foreach ($st->fetchAll() as $row) {
        @unlink($dir . '/' . $row['slug'] . '.' . $row['ext']);
        $del->execute([$row['id']]);
    }
}
