<?php
/*
 * Paste https://github.com/boxlabss/PASTE
 *
 * Web uploads (/img): settings, validation, storage, albums, lookups and reports.
 * Files share the FILEHOST store (filehost_files table and FILEHOST_DIR),
 * marked with network = 'web'. Uploads are stored encrypted with their link
 * name as the key (filecrypt.php), or as ciphertext made in the browser when
 * "Encrypt in my browser" is ticked; the server never sees that key.
 * Albums (img_albums) are sealed the same way with their own link name, and
 * hold the link names of their uploads. Signed-in uploaders can keep a copy
 * of each link in their account (owner_key), sealed with the site secret.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */
declare(strict_types=1);

require_once __DIR__ . '/filehost_store.php';
require_once __DIR__ . '/filehost_exif.php';

const IMG_NETWORK = 'web';

/** Accepted sniffed types for public uploads: mime => [kind, extension]. */
const IMG_TYPES = [
    'image/jpeg'       => ['image', 'jpg'],
    'image/png'        => ['image', 'png'],
    'image/gif'        => ['image', 'gif'],
    'image/webp'       => ['image', 'webp'],
    'video/mp4'        => ['video', 'mp4'],
    'video/x-m4v'      => ['video', 'm4v'],
    'video/webm'       => ['video', 'webm'],
    'video/quicktime'  => ['video', 'mov'],
    'video/x-msvideo'  => ['video', 'avi'],
    'video/x-matroska' => ['video', 'mkv'],
];

/** Video types browsers can play inline. */
const IMG_PLAYABLE = ['video/mp4', 'video/x-m4v', 'video/webm', 'video/quicktime'];

/** Expiry choices offered on the upload form: code => seconds (0 = never). */
const IMG_EXPIRY = ['N' => 0, '1H' => 3600, '1D' => 86400, '1W' => 604800, '1M' => 2592000];

/** Encrypted blob overhead: 16 byte salt, 12 byte IV, 16 byte GCM tag. */
const IMG_CRYPT_OVERHEAD = 44;

/** Largest encrypted thumbnail accepted. */
const IMG_THUMB_MAX = 524288;

/** Longest upload or album title, in characters. */
const IMG_TITLE_MAX = 120;

/** Longest album description, in characters. */
const IMG_DESC_MAX = 1000;

/** Thrown with a message that is safe to show to the uploader. */
final class ImgUploadError extends RuntimeException {}

/* ============================ Settings ============================ */

function img_default_settings(): array
{
    return [
        'img_enabled'         => 1,
        'img_guests'          => 1,
        'img_max_image_mb'    => 10,
        'img_max_video_mb'    => 25,
        'img_per_hour'        => 20,
        'img_quota_mb'        => 250,
        'img_strip_default'   => 1,
        'img_encrypt_default' => 0,
        'img_album_guest'     => 5,
        'img_album_member'    => 50,
    ];
}

/** Upload settings from paste_options, falling back to defaults. */
function img_settings(PDO $pdo, bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;

    $out = img_default_settings();
    try {
        $rows = $pdo->query("SELECT option_name, option_value FROM paste_options WHERE option_name LIKE 'img\\_%'")
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($out as $k => $v) {
            if (isset($rows[$k]) && is_numeric($rows[$k])) {
                $out[$k] = (int)$rows[$k];
            }
        }
    } catch (Throwable $e) {
        // paste_options missing: defaults
    }
    return $cache = $out;
}

/** Save upload settings (known keys only). */
function img_save_settings(PDO $pdo, array $values): void
{
    $stmt = $pdo->prepare("INSERT INTO paste_options (option_name, option_value) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)");
    foreach (img_default_settings() as $k => $default) {
        if (array_key_exists($k, $values)) {
            $stmt->execute([$k, (string)max(0, (int)$values[$k])]);
        }
    }
    img_settings($pdo, true);
}

/** Bytes for a php.ini size value such as "25M". */
function img_ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v === '-1') return 0;
    $n = (float)$v;
    return (int)match (strtolower(substr($v, -1))) {
        'g' => $n * 1073741824,
        'm' => $n * 1048576,
        'k' => $n * 1024,
        default => $n,
    };
}

/** Largest body PHP will accept, 0 when unlimited. */
function img_php_limit(): int
{
    $limits = array_filter([
        img_ini_bytes((string)ini_get('upload_max_filesize')),
        img_ini_bytes((string)ini_get('post_max_size')),
    ]);
    return $limits ? min($limits) : 0;
}

/** Effective byte limits: ['image' => int, 'video' => int, 'php' => int]. */
function img_limits(array $settings): array
{
    $php = img_php_limit();
    $room = $php > 0 ? max(0, $php - IMG_THUMB_MAX - 65536) : 0;
    $cap = static fn(int $mb): int => $room > 0 ? min($mb * 1048576, $room) : $mb * 1048576;
    return [
        'image' => $cap((int)$settings['img_max_image_mb']),
        'video' => $cap((int)$settings['img_max_video_mb']),
        'php'   => $php,
    ];
}

/** Human readable size. */
function img_format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024) . ' KB';
    return $bytes . ' bytes';
}

/* ============================ Schema ============================ */

/** Create or upgrade filehost_files and img_reports. */
function img_ensure_table(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `filehost_files` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `slug` varchar(32) COLLATE utf8mb4_bin NOT NULL,
            `account` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
            `network` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
            `mime` varchar(127) COLLATE utf8mb4_unicode_ci NOT NULL,
            `size` int unsigned NOT NULL,
            `ext` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL,
            `filename` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `paste_id` int DEFAULT NULL,
            `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
            `created` datetime NOT NULL,
            `expires` datetime DEFAULT NULL,
            `encrypted` tinyint(1) NOT NULL DEFAULT 0,
            `meta` text COLLATE utf8mb4_bin DEFAULT NULL,
            `thumb_size` int unsigned DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `slug` (`slug`),
            KEY `account_created` (`account`,`created`),
            KEY `expires` (`expires`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $have = array_column($pdo->query("SHOW COLUMNS FROM filehost_files")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $add = [
            'encrypted'  => "ADD COLUMN `encrypted` tinyint(1) NOT NULL DEFAULT 0",
            'meta'       => "ADD COLUMN `meta` text COLLATE utf8mb4_bin DEFAULT NULL",
            'thumb_size' => "ADD COLUMN `thumb_size` int unsigned DEFAULT NULL",
            'owner_key'  => "ADD COLUMN `owner_key` text COLLATE utf8mb4_bin DEFAULT NULL",
        ];
        foreach ($add as $col => $sql) {
            if (!in_array($col, $have, true)) {
                $pdo->exec("ALTER TABLE filehost_files $sql");
            }
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS `img_reports` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `slug` varchar(32) COLLATE utf8mb4_bin NOT NULL,
            `reason` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
            `pass` varchar(255) COLLATE utf8mb4_bin DEFAULT NULL,
            `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
            `created` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `slug` (`slug`),
            KEY `ip_created` (`ip`,`created`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `img_albums` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `slug` varchar(32) COLLATE utf8mb4_bin NOT NULL,
            `account` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
            `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
            `items` int unsigned NOT NULL DEFAULT 0,
            `meta` mediumtext COLLATE utf8mb4_bin DEFAULT NULL,
            `owner_key` text COLLATE utf8mb4_bin DEFAULT NULL,
            `created` datetime NOT NULL,
            `updated` datetime NOT NULL,
            `expires` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `slug` (`slug`),
            KEY `account_created` (`account`,`created`),
            KEY `expires` (`expires`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return $ok = true;
    } catch (Throwable $e) {
        error_log('img_ensure_table: ' . $e->getMessage());
        return $ok = false;
    }
}

/* ============================ URLs ============================ */

/** Page URL for an upload (/img/<slug>). */
function img_page_url(string $slug, string $baseurl, bool $rewrite): string
{
    $base = rtrim($baseurl, '/') . '/';
    return $rewrite ? $base . 'img/' . rawurlencode($slug) : $base . 'img/index.php?v=' . rawurlencode($slug);
}

/** Direct file URL for an upload (/img/<slug>.<ext>). */
function img_file_url(string $slug, string $ext, string $baseurl, bool $rewrite): string
{
    $base = rtrim($baseurl, '/') . '/';
    $f = rawurlencode($slug) . '.' . rawurlencode($ext);
    return $rewrite ? $base . 'img/' . $f : $base . 'img/index.php?f=' . $f;
}

/** Upload form URL (/img/). */
function img_home_url(string $baseurl): string
{
    return rtrim($baseurl, '/') . '/img/';
}

/* ============================ Lookups ============================ */

/** A live web upload by its link name, or null. */
function img_find(PDO $pdo, string $name): ?array
{
    if (!preg_match('/^[A-Za-z0-9]{6,32}$/', $name)) return null;
    try {
        $st = $pdo->prepare("SELECT * FROM filehost_files WHERE network = ? AND paste_id IS NULL
                             AND ((slug = ? AND encrypted = ?) OR (slug = ? AND encrypted = 1)) LIMIT 1");
        $st->execute([IMG_NETWORK, filecrypt_id($name), FILECRYPT_MODE, $name]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    return $row ? img_prepare_row($row, $name) : null;
}

/** An upload by its stored slug (for deletes from lists, which do not know the link name). */
function img_row(PDO $pdo, string $slug): ?array
{
    if (!preg_match('/^[A-Za-z0-9]{6,32}$/', $slug)) return null;
    try {
        $st = $pdo->prepare("SELECT * FROM filehost_files WHERE slug = ? AND network = ? AND paste_id IS NULL LIMIT 1");
        $st->execute([$slug, IMG_NETWORK]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    return $row ? img_prepare_row($row, null) : null;
}

/**
 * Normalise a row; null when it has expired. '_name' is the name its links
 * use: the link name for files encrypted with it (only known when the
 * caller had the link), otherwise the slug. '_meta' holds its opened details.
 */
function img_prepare_row(array $row, ?string $name): ?array
{
    if ($row['expires'] !== null && strtotime((string)$row['expires']) < time()) return null;
    $row['encrypted'] = (int)($row['encrypted'] ?? 0);
    $row['_name'] = $row['encrypted'] === FILECRYPT_MODE ? $name : $row['slug'];
    $row['_meta'] = $row['encrypted'] === FILECRYPT_MODE && $name !== null
        ? filecrypt_open_meta($row['meta'] ?? null, $name)
        : [];
    return $row;
}

/** Sealed details of an upload stored with its link name; [] for browser-encrypted ones. */
function img_meta(array $row): array
{
    if (isset($row['_meta'])) return $row['_meta'];
    if ((int)$row['encrypted'] !== FILECRYPT_MODE || ($row['_name'] ?? null) === null) return [];
    return filecrypt_open_meta($row['meta'] ?? null, $row['_name']);
}

/** Original file name of an upload stored with its link name; null for browser-encrypted ones. */
function img_display_name(array $row): ?string
{
    $meta = img_meta($row);
    return isset($meta['n']) ? (string)$meta['n'] : null;
}

/** Title typed by the uploader, or null. */
function img_display_title(array $row): ?string
{
    $meta = img_meta($row);
    return isset($meta['title']) && $meta['title'] !== '' ? (string)$meta['title'] : null;
}

/** File name for downloads: the title with the file's extension, else the original file name. */
function img_download_name(array $row): ?string
{
    $title = img_display_title($row);
    if ($title === null) return img_display_name($row);
    $base = (string)preg_replace('~[\x00-\x1f\x7f"\\\\/:*?<>|]+~u', ' ', $title);
    $base = trim((string)preg_replace('/\s+/u', ' ', $base), " .");
    if ($base === '') return img_display_name($row);
    $ext = (string)$row['ext'];
    return str_ends_with(strtolower($base), '.' . strtolower($ext)) ? $base : $base . '.' . $ext;
}

/** Web uploads by an account, newest first, with the links kept for the account opened. */
function img_user_uploads(PDO $pdo, string $account, int $limit = 60, int $offset = 0): array
{
    try {
        $st = $pdo->prepare("SELECT * FROM filehost_files
                             WHERE account = ? AND network = ? AND paste_id IS NULL AND encrypted <> 0
                               AND (expires IS NULL OR expires > NOW())
                             ORDER BY id DESC LIMIT " . max(1, $limit) . " OFFSET " . max(0, $offset));
        $st->execute([$account, IMG_NETWORK]);
        return array_map('img_with_owner_name', $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable $e) {
        return [];
    }
}

/** Number of live web uploads by an account. */
function img_user_upload_count(PDO $pdo, string $account): int
{
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM filehost_files
                             WHERE account = ? AND network = ? AND paste_id IS NULL AND encrypted <> 0
                               AND (expires IS NULL OR expires > NOW())");
        $st->execute([$account, IMG_NETWORK]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Web uploads by slug list (for guests, from their session). */
function img_uploads_by_slugs(PDO $pdo, array $slugs): array
{
    $slugs = array_values(array_filter($slugs, static fn($s) => is_string($s) && preg_match('/^[A-Za-z0-9]{6,32}$/', $s)));
    if (!$slugs) return [];
    try {
        $in = implode(',', array_fill(0, count($slugs), '?'));
        $st = $pdo->prepare("SELECT * FROM filehost_files WHERE slug IN ($in) AND network = ? AND paste_id IS NULL AND encrypted <> 0
                               AND (expires IS NULL OR expires > NOW()) ORDER BY id DESC");
        $st->execute([...$slugs, IMG_NETWORK]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/* ============================ Links kept for accounts ============================ */

/** Key that seals the copies of links kept for accounts; null without the site secret. */
function img_owner_key(): ?string
{
    if (!defined('SECRET') || (string)SECRET === '') return null;
    $secret = (string)SECRET;
    if (ctype_xdigit($secret) && strlen($secret) % 2 === 0) {
        $secret = (string)hex2bin($secret);
    }
    return hash_hkdf('sha256', $secret, 32, 'paste upload owner v1');
}

/** Copy of a link name for its uploader's account, bound to the storage id the name opens; null without the site secret. */
function img_owner_seal(string $name): ?string
{
    $key = img_owner_key();
    if ($key === null) return null;
    $nonce = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($name, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, filecrypt_id($name), FILECRYPT_TAG);
    return $ct === false ? null : base64_encode($nonce . $ct . $tag);
}

/** The link name in a copy kept for an account, or null when it does not open or belongs to another storage id. */
function img_owner_open(?string $sealed, string $id): ?string
{
    $key = img_owner_key();
    $raw = $key !== null && $sealed !== null && $sealed !== '' ? base64_decode($sealed, true) : false;
    if ($raw === false || strlen($raw) <= 12 + FILECRYPT_TAG) return null;
    $name = openssl_decrypt(substr($raw, 12, -FILECRYPT_TAG), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
        substr($raw, 0, 12), substr($raw, -FILECRYPT_TAG), $id);
    return is_string($name) && filecrypt_id($name) === $id ? $name : null;
}

/** An upload row from its owner's list, with '_name' and '_meta' filled in when its link was kept for the account. */
function img_with_owner_name(array $row): array
{
    $row['encrypted'] = (int)($row['encrypted'] ?? 0);
    $name = $row['encrypted'] === FILECRYPT_MODE ? img_owner_open($row['owner_key'] ?? null, (string)$row['slug']) : null;
    $row['_name'] = $name;
    $row['_meta'] = $name !== null ? filecrypt_open_meta($row['meta'] ?? null, $name) : [];
    return $row;
}

/** Keep a copy of an upload's link for its account (when it has no answer yet), or record that it should not be kept. */
function img_keep_link(PDO $pdo, string $name, bool $keep): bool
{
    $sealed = $keep ? img_owner_seal($name) : '';
    if ($sealed === null || !img_ensure_table($pdo)) return false;
    try {
        $st = $pdo->prepare("UPDATE filehost_files SET owner_key = ? WHERE slug = ? AND encrypted = ? AND owner_key IS NULL");
        $st->execute([$sealed, filecrypt_id($name), FILECRYPT_MODE]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('img_keep_link: ' . $e->getMessage());
        return false;
    }
}

/** Delete a stored upload (row, file, thumbnail and reports). Works for any network. */
function img_delete(PDO $pdo, string $slug): bool
{
    $st = $pdo->prepare("SELECT slug, ext FROM filehost_files WHERE slug = ? AND paste_id IS NULL LIMIT 1");
    $st->execute([$slug]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    $dir = filehost_storage_dir();
    @unlink($dir . '/' . $row['slug'] . '.' . $row['ext']);
    @unlink($dir . '/' . $row['slug'] . '.thumb');
    @unlink($dir . '/' . $row['slug'] . '.enc');
    $pdo->prepare("DELETE FROM filehost_files WHERE slug = ?")->execute([$slug]);
    try {
        $pdo->prepare("DELETE FROM img_reports WHERE slug = ?")->execute([$slug]);
    } catch (Throwable $e) {
        // reports table not created yet
    }
    return true;
}

/** Remove a few expired web uploads and albums. */
function img_sweep(PDO $pdo, int $limit = 20): void
{
    try {
        $st = $pdo->prepare("SELECT slug FROM filehost_files
                             WHERE network = ? AND paste_id IS NULL AND expires IS NOT NULL AND expires < NOW()
                             LIMIT " . max(1, $limit));
        $st->execute([IMG_NETWORK]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $slug) {
            img_delete($pdo, (string)$slug);
        }
        $pdo->exec("DELETE FROM img_albums WHERE expires IS NOT NULL AND expires < NOW() LIMIT " . max(1, $limit));
    } catch (Throwable $e) {
        error_log('img_sweep: ' . $e->getMessage());
    }
}

/** Uploads in the last hour by this account, or by guests from this IP. */
function img_recent_count(PDO $pdo, string $account, string $ip): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM filehost_files
                         WHERE network = ? AND created > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                           AND account = ? AND (account <> 'Guest' OR ip = ?)");
    $st->execute([IMG_NETWORK, $account, $ip]);
    return (int)$st->fetchColumn();
}

/** Bytes stored in the last 24 hours by this account, or by guests from this IP. */
function img_recent_bytes(PDO $pdo, string $account, string $ip): int
{
    $st = $pdo->prepare("SELECT COALESCE(SUM(size + COALESCE(thumb_size, 0)), 0) FROM filehost_files
                         WHERE network = ? AND created > DATE_SUB(NOW(), INTERVAL 1 DAY)
                           AND account = ? AND (account <> 'Guest' OR ip = ?)");
    $st->execute([IMG_NETWORK, $account, $ip]);
    return (int)$st->fetchColumn();
}

/* ============================ Metadata removal ============================ */

/** EXIF orientation (1-8) of a JPEG; 1 when absent or unreadable. */
function img_jpeg_orientation(string $jpeg): int
{
    $len = strlen($jpeg);
    if ($len < 4 || substr($jpeg, 0, 2) !== "\xFF\xD8") return 1;
    $pos = 2;
    while ($pos + 4 <= $len && $jpeg[$pos] === "\xFF") {
        $marker = ord($jpeg[$pos + 1]);
        if ($marker === 0xDA || $marker === 0xD9) break;
        $seglen = (ord($jpeg[$pos + 2]) << 8) | ord($jpeg[$pos + 3]);
        if ($seglen < 2) break;
        if ($marker === 0xE1 && substr($jpeg, $pos + 4, 6) === "Exif\0\0") {
            $tiff = substr($jpeg, $pos + 10, $seglen - 8);
            $le = substr($tiff, 0, 2) === 'II';
            if (!$le && substr($tiff, 0, 2) !== 'MM') return 1;
            $u16 = static fn(int $o): int => strlen($tiff) >= $o + 2 ? unpack($le ? 'v' : 'n', substr($tiff, $o, 2))[1] : 0;
            $u32 = static fn(int $o): int => strlen($tiff) >= $o + 4 ? unpack($le ? 'V' : 'N', substr($tiff, $o, 4))[1] : 0;
            $ifd = $u32(4);
            $count = $u16($ifd);
            for ($i = 0; $i < $count; $i++) {
                $entry = $ifd + 2 + $i * 12;
                if ($u16($entry) === 0x0112) {
                    $o = $u16($entry + 8);
                    return ($o >= 1 && $o <= 8) ? $o : 1;
                }
            }
            return 1;
        }
        $pos += 2 + $seglen;
    }
    return 1;
}

/** Re-encode a JPEG upright using GD (drops all metadata). Null when GD cannot. */
function img_jpeg_upright(string $jpeg, int $orientation): ?string
{
    if ($orientation <= 1 || !function_exists('imagecreatefromstring')) return null;
    $im = @imagecreatefromstring($jpeg);
    if ($im === false) return null;

    switch ($orientation) {
        case 2: imageflip($im, IMG_FLIP_HORIZONTAL); break;
        case 3: $im = imagerotate($im, 180, 0); break;
        case 4: imageflip($im, IMG_FLIP_VERTICAL); break;
        case 5: imageflip($im, IMG_FLIP_VERTICAL); $im = imagerotate($im, -90, 0); break;
        case 6: $im = imagerotate($im, -90, 0); break;
        case 7: imageflip($im, IMG_FLIP_HORIZONTAL); $im = imagerotate($im, -90, 0); break;
        case 8: $im = imagerotate($im, 90, 0); break;
    }
    if ($im === false) return null;

    ob_start();
    $ok = imagejpeg($im, null, 92);
    $out = (string)ob_get_clean();
    imagedestroy($im);
    return $ok && $out !== '' ? $out : null;
}

/**
 * Blank the metadata boxes (udta, meta, XMP uuid) of an MP4/MOV/M4V file.
 * Boxes become 'free' boxes of the same length, so media offsets stay valid.
 */
function img_mp4_strip_metadata(string $body): string
{
    static $xmp = "\xBE\x7A\xCF\xCB\x97\xA9\x42\xE8\x9C\x71\x99\x94\x91\xE3\xAF\xAC";
    $blank = static function (string &$b, int $pos, int $header, int $size): void {
        $b = substr_replace($b, 'free', $pos + 4, 4);
        $b = substr_replace($b, str_repeat("\0", $size - $header), $pos + $header, $size - $header);
    };
    $walk = static function (string &$b, int $start, int $end, int $depth) use (&$walk, $blank, $xmp): void {
        $pos = $start;
        while ($pos + 8 <= $end) {
            $size = unpack('N', substr($b, $pos, 4))[1];
            $type = substr($b, $pos + 4, 4);
            $header = 8;
            if ($size === 1) {
                if ($pos + 16 > $end) return;
                $hi = unpack('N', substr($b, $pos + 8, 4))[1];
                $lo = unpack('N', substr($b, $pos + 12, 4))[1];
                $size = $hi * 4294967296 + $lo;
                $header = 16;
            } elseif ($size === 0) {
                $size = $end - $pos;
            }
            if ($size < $header || $pos + $size > $end) return;
            if ($type === 'udta' || $type === 'meta' || ($type === 'uuid' && substr($b, $pos + $header, 16) === $xmp)) {
                $blank($b, $pos, $header, $size);
            } elseif (($type === 'moov' || $type === 'trak') && $depth < 3) {
                $walk($b, $pos + $header, $pos + $size, $depth + 1);
            }
            $pos += $size;
        }
    };
    if (strlen($body) < 16 || substr($body, 4, 4) !== 'ftyp') {
        return $body;
    }
    $walk($body, 0, strlen($body), 0);
    return $body;
}

/* ============================ Storing ============================ */

/** Clean a client filename for display and Content-Disposition. */
function img_clean_filename(string $name): ?string
{
    $name = basename(str_replace('\\', '/', trim($name)));
    $name = preg_replace('/[\x00-\x1f\x7f"]/u', '', $name) ?? '';
    $name = mb_substr($name, 0, 120);
    return ($name === '' || $name === '.' || $name === '..') ? null : $name;
}

/** Clean an uploader's title: one line, at most IMG_TITLE_MAX characters; null when empty. */
function img_clean_title(mixed $title): ?string
{
    if (!is_string($title) || !mb_check_encoding($title, 'UTF-8')) return null;
    $title = (string)preg_replace('/[\x00-\x1f\x7f\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', $title);
    $title = trim((string)preg_replace('/\s+/u', ' ', $title));
    $title = mb_substr($title, 0, IMG_TITLE_MAX);
    return $title === '' ? null : $title;
}

/** Message for a PHP upload error code. */
function img_upload_error_message(int $code, array $limits): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is larger than the server accepts (' . img_format_bytes(max($limits['image'], $limits['video'])) . ').',
        UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_FILE => 'Choose a file to upload.',
        default => 'The server could not receive the file.',
    };
}

/** Body of a PHP upload after the standard checks. */
function img_read_upload(array $file, array $limits): string
{
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        throw new ImgUploadError(img_upload_error_message($err, $limits));
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new ImgUploadError('The server could not receive the file.');
    }
    return (string)file_get_contents($tmp);
}

/** True when a blob looks like ciphertext: no media signature a browser would render, and incompressible. */
function img_looks_encrypted(string $blob): bool
{
    static $magic = ["\xFF\xD8\xFF", "\x89PNG", 'GIF8', "\x1A\x45\xDF\xA3", 'OggS', 'ID3', '%PDF', '<svg', '<?xml',
                     "\xEF\xBB\xBF", "\x00\x00\x01\x00", "\x00\x00\x02\x00", "\x00\x00\x00\x0CJXL "];
    foreach ($magic as $m) {
        if (str_starts_with($blob, $m)) return false;
    }
    if (substr($blob, 4, 4) === 'ftyp') return false;
    if (str_starts_with($blob, 'RIFF') && in_array(substr($blob, 8, 4), ['WEBP', 'AVI ', 'WAVE'], true)) return false;

    $sample = substr($blob, IMG_CRYPT_OVERHEAD - 16, 65536);
    if (strlen($sample) < 256) return true;
    return strlen((string)gzdeflate($sample, 1)) >= (int)(strlen($sample) * 0.97);
}

/** Insert the filehost_files row for a stored upload and return it. */
function img_insert_row(PDO $pdo, array $cols): array
{
    $cols += ['network' => IMG_NETWORK, 'paste_id' => null, 'encrypted' => 0, 'meta' => null, 'thumb_size' => null, 'filename' => null];
    $names = array_keys($cols);
    $pdo->prepare("INSERT INTO filehost_files (" . implode(',', $names) . ", created) VALUES ("
                  . implode(',', array_fill(0, count($names), '?')) . ", NOW())")
        ->execute(array_values($cols));
    $row = img_find($pdo, (string)$cols['slug']);
    if ($row === null) {
        throw new RuntimeException('stored upload not found');
    }
    return $row;
}

/** Expiry datetime for an expiry code, or null. */
function img_expiry_datetime(string $expiry): ?string
{
    $seconds = IMG_EXPIRY[$expiry] ?? 0;
    return $seconds > 0 ? date('Y-m-d H:i:s', time() + $seconds) : null;
}

/**
 * Validate and store an upload from $_FILES, encrypted with a new link name
 * as its key, with an optional title. Returns the new row ('_name' is the
 * link name); throws ImgUploadError on a user error.
 */
function img_store_upload(PDO $pdo, array $file, string $account, string $ip, bool $strip, string $expiry, array $settings,
                          ?string $title = null): array
{
    $limits = img_limits($settings);
    $body = img_read_upload($file, $limits);

    $mime = class_exists('finfo') ? (string)(new finfo(FILEINFO_MIME_TYPE))->buffer($body) : '';
    if (!isset(IMG_TYPES[$mime])) {
        throw new ImgUploadError('That file type is not supported. Upload JPEG, PNG, GIF or WebP images, or MP4, WebM, MOV, AVI or MKV videos.');
    }
    [$kind, $ext] = IMG_TYPES[$mime];

    if ($body === '') {
        throw new ImgUploadError('The file is empty.');
    }
    if (strlen($body) > $limits[$kind]) {
        throw new ImgUploadError(ucfirst($kind) . 's can be up to ' . img_format_bytes($limits[$kind]) . '.');
    }
    if ($kind === 'image' && @getimagesizefromstring($body) === false) {
        throw new ImgUploadError('That image could not be read.');
    }

    if ($strip && $kind === 'image') {
        $upright = $mime === 'image/jpeg' ? img_jpeg_upright($body, img_jpeg_orientation($body)) : null;
        $body = $upright ?? filehost_strip_metadata($body, $mime)[0];
    } elseif ($strip && in_array($mime, ['video/mp4', 'video/x-m4v', 'video/quicktime'], true)) {
        $body = img_mp4_strip_metadata($body);
    }

    if (!img_ensure_table($pdo)) {
        throw new RuntimeException('filehost_files table unavailable');
    }
    $name = filecrypt_store($pdo, filehost_storage_dir(), $body, [
        'account' => $account,
        'network' => IMG_NETWORK,
        'mime'    => $mime,
        'ext'     => $ext,
        'ip'      => $ip,
        'expires' => img_expiry_datetime($expiry),
    ], img_clean_filename((string)($file['name'] ?? '')), img_clean_title($title));
    $row = img_find($pdo, $name);
    if ($row === null) {
        throw new RuntimeException('stored upload not found');
    }
    return $row;
}

/**
 * Store an encrypted upload: the ciphertext file, an optional encrypted
 * thumbnail and the encrypted metadata (base64). $kind is what the browser
 * says it encrypted and only selects the size limit.
 */
function img_store_encrypted(PDO $pdo, array $file, ?array $thumb, string $meta, string $kind,
                             string $account, string $ip, string $expiry, array $settings): array
{
    $limits = img_limits($settings);
    if (!in_array($kind, ['image', 'video'], true)) {
        throw new ImgUploadError('Unknown upload type.');
    }

    $body = img_read_upload($file, $limits);
    $size = strlen($body);
    if ($size <= IMG_CRYPT_OVERHEAD) {
        throw new ImgUploadError('The encrypted file is empty.');
    }
    if ($size > $limits[$kind] + IMG_CRYPT_OVERHEAD) {
        throw new ImgUploadError(ucfirst($kind) . 's can be up to ' . img_format_bytes($limits[$kind]) . '.');
    }
    if (!img_looks_encrypted($body)) {
        throw new ImgUploadError('That file is not encrypted. Turn off encryption to upload it publicly.');
    }

    if (strlen($meta) > 4096 || !preg_match('~^[A-Za-z0-9+/]+={0,2}$~', $meta)
        || strlen((string)base64_decode($meta, true)) <= IMG_CRYPT_OVERHEAD) {
        throw new ImgUploadError('The encrypted file details are invalid.');
    }

    $thumbBody = null;
    if ($thumb !== null && (int)($thumb['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $thumbBody = img_read_upload($thumb, $limits);
        if (strlen($thumbBody) <= IMG_CRYPT_OVERHEAD || strlen($thumbBody) > IMG_THUMB_MAX || !img_looks_encrypted($thumbBody)) {
            throw new ImgUploadError('The encrypted preview is invalid.');
        }
    }

    if (!img_ensure_table($pdo)) {
        throw new RuntimeException('filehost_files table unavailable');
    }
    $slug = filehost_new_slug($pdo);
    $dir = filehost_storage_dir();
    filehost_store_file($dir, $body, $slug, 'bin');
    if ($thumbBody !== null) {
        filehost_store_file($dir, $thumbBody, $slug, 'thumb');
    }

    return img_insert_row($pdo, [
        'slug'       => $slug,
        'account'    => $account,
        'mime'       => 'application/octet-stream',
        'size'       => $size,
        'ext'        => 'bin',
        'ip'         => $ip,
        'expires'    => img_expiry_datetime($expiry),
        'encrypted'  => 1,
        'meta'       => $meta,
        'thumb_size' => $thumbBody !== null ? strlen($thumbBody) : null,
    ]);
}

/* ============================ Albums ============================ */

/** Most uploads an album may hold for this visitor; 0 when albums are off for them. */
function img_album_limit(array $settings, bool $member): int
{
    return max(0, (int)($member ? $settings['img_album_member'] : $settings['img_album_guest']));
}

/** Clean an album description: at most IMG_DESC_MAX characters, line breaks kept; null when empty. */
function img_clean_desc(mixed $desc): ?string
{
    if (!is_string($desc) || !mb_check_encoding($desc, 'UTF-8')) return null;
    $desc = str_replace(["\r\n", "\r"], "\n", $desc);
    $desc = (string)preg_replace('/[\x00-\x09\x0b-\x1f\x7f\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', $desc);
    $desc = trim((string)preg_replace("/\n{3,}/", "\n\n", $desc));
    $desc = mb_substr($desc, 0, IMG_DESC_MAX);
    return $desc === '' ? null : $desc;
}

/**
 * A live album by its link name, or null. '_name' is the link name and '_meta'
 * its opened contents: ['title' => ?string, 'desc' => ?string, 'items' => [link names]].
 */
function img_album_find(PDO $pdo, string $name): ?array
{
    if (!preg_match('/^[A-Za-z0-9]{6,32}$/', $name) || !img_ensure_table($pdo)) return null;
    try {
        $st = $pdo->prepare("SELECT * FROM img_albums WHERE slug = ? LIMIT 1");
        $st->execute([filecrypt_id($name)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    if (!$row || ($row['expires'] !== null && strtotime((string)$row['expires']) < time())) return null;
    $meta = filecrypt_open_meta($row['meta'] ?? null, $name);
    $row['_name'] = $name;
    $row['_meta'] = [
        'title' => isset($meta['title']) ? (string)$meta['title'] : null,
        'desc'  => isset($meta['desc']) ? (string)$meta['desc'] : null,
        'items' => array_values(array_filter((array)($meta['items'] ?? []), static fn($n) => is_string($n) && preg_match('/^[A-Za-z0-9]{6,32}$/', $n))),
    ];
    return $row;
}

/** Album display title: its title, or "Album" with the number of uploads. */
function img_album_title(array $album): string
{
    $title = $album['_meta']['title'] ?? null;
    if ($title !== null && $title !== '') return $title;
    $n = (int)($album['items'] ?? count($album['_meta']['items'] ?? []));
    return 'Album (' . $n . ($n === 1 ? ' upload)' : ' uploads)');
}

/** The uploads of an album that still exist, in album order. */
function img_album_uploads(PDO $pdo, array $album): array
{
    $out = [];
    foreach ($album['_meta']['items'] as $name) {
        $row = img_find($pdo, $name);
        if ($row !== null && $row['encrypted'] === FILECRYPT_MODE) $out[] = $row;
    }
    return $out;
}

/**
 * Create an album holding the uploads with these link names. Returns its link name.
 * $keep stores a copy of the link for a signed-in creator's account.
 */
function img_album_create(PDO $pdo, array $names, ?string $title, ?string $desc, string $account, string $ip, string $expiry, bool $keep): string
{
    if (!img_ensure_table($pdo)) {
        throw new RuntimeException('img_albums table unavailable');
    }
    $check = $pdo->prepare('SELECT 1 FROM img_albums WHERE slug = ?');
    do {
        $name = filecrypt_new_name();
        $check->execute([filecrypt_id($name)]);
    } while ($check->fetchColumn());

    $names = array_values(array_unique($names));
    $pdo->prepare("INSERT INTO img_albums (slug, account, ip, items, meta, owner_key, created, updated, expires)
                   VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)")
        ->execute([filecrypt_id($name), $account, $ip, count($names),
            filecrypt_seal_meta(img_album_meta($title, $desc, $names), $name),
            $keep ? img_owner_seal($name) : null, img_expiry_datetime($expiry)]);
    return $name;
}

/** Contents sealed into an album. */
function img_album_meta(?string $title, ?string $desc, array $names): array
{
    return array_filter(['title' => $title, 'desc' => $desc, 'items' => array_values($names)],
        static fn($v) => $v !== null && $v !== '');
}

/** Save new contents for an album (title, description, uploads). */
function img_album_save(PDO $pdo, array $album, ?string $title, ?string $desc, array $names): void
{
    $names = array_values(array_unique($names));
    $pdo->prepare("UPDATE img_albums SET meta = ?, items = ?, updated = NOW() WHERE slug = ?")
        ->execute([filecrypt_seal_meta(img_album_meta($title, $desc, $names), $album['_name']), count($names), $album['slug']]);
}

/** Delete an album; with $uploads, also the uploads in it that $canDelete allows. */
function img_album_delete(PDO $pdo, array $album, bool $uploads, ?callable $canDelete = null): void
{
    if ($uploads) {
        foreach (img_album_uploads($pdo, $album) as $row) {
            if ($canDelete === null || $canDelete($row)) img_delete($pdo, $row['slug']);
        }
    }
    $pdo->prepare("DELETE FROM img_albums WHERE slug = ?")->execute([$album['slug']]);
}

/** Albums made by an account, newest first, with the links kept for the account opened. */
function img_user_albums(PDO $pdo, string $account, int $limit = 60): array
{
    if (!img_ensure_table($pdo)) return [];
    try {
        $st = $pdo->prepare("SELECT * FROM img_albums WHERE account = ? AND (expires IS NULL OR expires > NOW())
                             ORDER BY id DESC LIMIT " . max(1, $limit));
        $st->execute([$account]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $name = img_owner_open($row['owner_key'] ?? null, (string)$row['slug']);
            $album = $name !== null ? img_album_find($pdo, $name) : null;
            $out[] = $album ?? $row + ['_name' => null, '_meta' => null];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/** Albums by link name (for guests, from their session), newest first. */
function img_albums_by_names(PDO $pdo, array $names): array
{
    $out = [];
    foreach (array_reverse($names) as $name) {
        $album = is_string($name) ? img_album_find($pdo, $name) : null;
        if ($album !== null) $out[] = $album;
    }
    return $out;
}

/** Cover upload of an album: its first image, else its first upload; null when empty. */
function img_album_cover(PDO $pdo, array $album): ?array
{
    $first = null;
    foreach ($album['_meta']['items'] ?? [] as $name) {
        $row = img_find($pdo, $name);
        if ($row === null) continue;
        if ((IMG_TYPES[$row['mime']][0] ?? '') === 'image') return $row;
        $first ??= $row;
    }
    return $first;
}

/* ============================ Reports ============================ */

/** File a report about an upload. Returns false when this IP has reported too often. */
function img_add_report(PDO $pdo, string $slug, string $reason, ?string $pass, string $ip): bool
{
    if (!img_ensure_table($pdo)) return false;
    $st = $pdo->prepare("SELECT COUNT(*) FROM img_reports WHERE ip = ? AND created > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $st->execute([$ip]);
    if ((int)$st->fetchColumn() >= 10) return false;
    $pdo->prepare("INSERT INTO img_reports (slug, reason, pass, ip, created) VALUES (?, ?, ?, ?, NOW())")
        ->execute([$slug, mb_substr(trim($reason), 0, 500), $pass !== null && $pass !== '' ? mb_substr($pass, 0, 255) : null, $ip]);
    return true;
}

/** Open reports with their upload, newest first. */
function img_reports(PDO $pdo, int $limit = 100): array
{
    try {
        return $pdo->query("SELECT r.*, f.account, f.size, f.encrypted, f.mime, f.ext, f.created AS uploaded
                            FROM img_reports r JOIN filehost_files f ON f.slug = r.slug
                            ORDER BY r.id DESC LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** Dismiss one report. */
function img_dismiss_report(PDO $pdo, int $id): void
{
    $pdo->prepare("DELETE FROM img_reports WHERE id = ?")->execute([$id]);
}
