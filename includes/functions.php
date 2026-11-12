<?php
/*
 * Paste https://github.com/boxlabss/PASTE
 * demo: https://paste.boxlabs.uk/
 *
 * https://phpaste.sourceforge.io/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License in LICENCE for more details.
 */

declare(strict_types=1);

// Set default timezone
date_default_timezone_set('UTC');

// Start database connection
try {
    $dsn = "mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbuser, $dbpassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    $error = 'Database error. Please try again later.';
    $GLOBALS['error'] = $error;
    paste_error($error, 503); // themed render + exit
}

/**
 * Signs the visitor out when their account was deleted or banned, or its token changed
 * (a password change or reset); signs them back in from a "Keep me signed in" cookie.
 */
function paste_enforce_account_status(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    if (PHP_SAPI === 'cli') return;
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    if (empty($_SESSION['username']) && !empty($_COOKIE[PASTE_REMEMBER_COOKIE])) {
        paste_restore_remembered($pdo);
    }
    if (empty($_SESSION['username'])) return;

    // Accounts named Guest cannot stay signed in
    if (paste_is_guest_name((string)$_SESSION['username'])) {
        unset($_SESSION['token'], $_SESSION['oauth_uid'], $_SESSION['username'], $_SESSION['pic']);
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT verified, token FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([(string)$_SESSION['username']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return; // never lock people out over a transient DB hiccup
    }

    $sessTok = (string)($_SESSION['token'] ?? '');
    $dbTok   = (string)($row['token'] ?? '');

    $revoke =
        ($row === false)                                                  // account deleted
        || ((string)($row['verified'] ?? '') === '2')                     // banned
        || ($sessTok !== '' && $dbTok !== '' && !hash_equals($dbTok, $sessTok)); // token rotated elsewhere

    if ($revoke) {
        unset($_SESSION['token'], $_SESSION['oauth_uid'], $_SESSION['username'], $_SESSION['pic']);
    }
}

require_once __DIR__ . '/remember.php';
require_once __DIR__ . '/account.php';

// Apply account status (ban / forced logout) as early as possible on every request.
if (isset($pdo) && $pdo instanceof PDO) {
    paste_enforce_account_status($pdo);
}

require_once __DIR__ . '/paste_crypt.php';

// ============================================================================
// SLUG (Randomized URL) Functions
// ============================================================================

/** Random URL slug from letters and digits that cannot be confused (no 0/O, 1/l/I). */
function generatePasteSlug(int $length = 8): string
{
    // Characters that are URL-safe and unambiguous
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $slug = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $slug .= $chars[random_int(0, $max)];
    }
    return $slug;
}

/** A slug no paste uses yet; grows by one character after $maxAttempts collisions. */
function generateUniquePasteSlug(PDO $pdo, int $length = 8, int $maxAttempts = 10): string
{
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM pastes WHERE slug = ?");
    $attempts = 0;

    do {
        $slug = generatePasteSlug($length);
        $checkStmt->execute([$slug]);
        $exists = (int)$checkStmt->fetchColumn() > 0;
        $attempts++;

        // If we're having trouble finding unique slugs, increase length
        if ($attempts >= $maxAttempts) {
            $length++;
            $attempts = 0;
            error_log("generateUniquePasteSlug: Increased slug length to $length due to collisions");
        }
    } while ($exists && $attempts < $maxAttempts * 3);

    return $slug;
}

/** Paste URL mode from the admin setting: 'slug' (default) or 'numeric'. */
function getPasteUrlMode(?PDO $pdo = null): string
{
    // Get from database only (admin panel settings)
    if ($pdo !== null) {
        try {
            // Check if table exists
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'paste_options'");
            if ($tableCheck->rowCount() === 0) {
                // Create the table
                $pdo->exec("CREATE TABLE paste_options (
                    id INT NOT NULL AUTO_INCREMENT,
                    option_name VARCHAR(100) NOT NULL,
                    option_value TEXT,
                    PRIMARY KEY(id),
                    UNIQUE KEY idx_option_name (option_name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                // Insert default values
                $pdo->exec("INSERT INTO paste_options (option_name, option_value) VALUES ('url_mode', 'slug'), ('slug_length', '8')");
                return 'slug';
            }

            $stmt = $pdo->prepare("SELECT option_value FROM paste_options WHERE option_name = 'url_mode'");
            $stmt->execute();
            $result = $stmt->fetchColumn();

            // Handle null, false, empty string
            if ($result !== false && $result !== null && $result !== '') {
                $result = trim((string)$result);
                if (in_array($result, ['slug', 'numeric'], true)) {
                    return $result;
                }
            }

            // Row doesn't exist or has invalid value, insert/update default
            $pdo->exec("INSERT INTO paste_options (option_name, option_value) VALUES ('url_mode', 'slug')
                        ON DUPLICATE KEY UPDATE option_value = 'slug'");
            return 'slug';
        } catch (PDOException $e) {
            error_log("getPasteUrlMode error: " . $e->getMessage());
        }
    }

    // Default to slug for new installs
    return 'slug';
}

/** Configured slug length (default 8). */
function getPasteSlugLength(?PDO $pdo = null): int
{
    // Get from database only (admin panel settings)
    if ($pdo !== null) {
        try {
            $stmt = $pdo->prepare("SELECT option_value FROM paste_options WHERE option_name = 'slug_length'");
            $stmt->execute();
            $result = $stmt->fetchColumn();
            if ($result !== false && is_numeric($result)) {
                $len = (int)$result;
                if ($len >= 4 && $len <= 32) {
                    return $len;
                }
            }

            // Row doesn't exist, insert default
            $pdo->exec("INSERT IGNORE INTO paste_options (option_name, option_value) VALUES ('slug_length', '8')");
            return 8;
        } catch (PDOException $e) {
            error_log("getPasteSlugLength error: " . $e->getMessage());
        }
    }

    return 8; // Default
}

/** A paste row by numeric id or slug, or null. */
function getPasteByIdentifier(PDO $pdo, string $identifier): ?array
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }

    // Numeric id
    if (ctype_digit($identifier)) {
        $stmt = $pdo->prepare("SELECT * FROM pastes WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$identifier]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    // Slug
    $stmt = $pdo->prepare("SELECT * FROM pastes WHERE slug = ? LIMIT 1");
    $stmt->execute([$identifier]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : null;
}

/**
 * Look up a paste for a page other than its own (diff, forks), by the identifier its own page uses:
 * a paste with a slug is only found by that slug, and numeric ids follow "block numeric URLs".
 */
function paste_find_linked(PDO $pdo, string $identifier): ?array
{
    $row = getPasteByIdentifier($pdo, $identifier);
    if (!$row || !ctype_digit(trim($identifier))) {
        return $row;
    }
    if ((string)($row['slug'] ?? '') !== '' || shouldBlockNumericUrls($pdo)) {
        return null;
    }
    return $row;
}

/**
 * True if the visitor may see this paste's content outside its own page.
 * Private and password-protected pastes are left to their owner; expired, view-once
 * and browser-encrypted pastes are only shown on their own page.
 */
function paste_readable_elsewhere(array $row, string $viewer): bool
{
    $owner = $viewer !== '' && (string)($row['member'] ?? '') === $viewer;
    if (paste_is_expired((string)($row['expiry'] ?? ''))) return false;
    if (trim((string)($row['expiry'] ?? '')) === 'SELF') return false;
    if ((string)($row['encrypt'] ?? '0') === '2') return false;
    if ((string)($row['visible'] ?? '0') === '2' && !$owner) return false;
    if ((string)($row['password'] ?? 'NONE') !== 'NONE' && !$owner) return false;
    return true;
}

/** The Referer when it points at this site, otherwise $fallback. */
function paste_same_site_referer(string $baseurl, string $fallback): string
{
    $ref  = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $host = parse_url($baseurl, PHP_URL_HOST);
    if ($ref === '' || !$host || strcasecmp((string)parse_url($ref, PHP_URL_HOST), (string)$host) !== 0) {
        return $fallback;
    }
    return $ref;
}

/** Sign the visitor out and send them back to the page they came from on this site. */
function paste_logout_and_redirect(string $baseurl): never
{
    $dest = paste_same_site_referer($baseurl, $baseurl !== '' ? $baseurl : './');
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    paste_remember_cookie(null);
    header('Location: ' . $dest);
    exit;
}

/**
 * Token that opens a password-protected paste's raw, download and embed links in place of the password.
 * Bound to the paste and its password, so changing the password ends it; $expires 0 never expires.
 */
function paste_access_token(int $id, string $passwordHash, int $expires): string
{
    if (!defined('SECRET') || (string)SECRET === '') return '';
    return $expires . '.' . substr(hash_hmac('sha256', 'paste-access|' . $id . '|' . $passwordHash . '|' . $expires, (string)SECRET), 0, 32);
}

/** True if $token came from paste_access_token() for this paste and password and has not expired. */
function paste_access_token_ok(string $token, int $id, string $passwordHash): bool
{
    if (!preg_match('/^(\d{1,10})\.[0-9a-f]{32}$/', $token, $m)) return false;
    $expires = (int)$m[1];
    if ($expires !== 0 && $expires < time()) return false;
    $want = paste_access_token($id, $passwordHash, $expires);
    return $want !== '' && hash_equals($want, $token);
}

/**
 * Counts a failed attempt of $kind (paste password, login) from this IP when $count is true,
 * and reports whether the IP has used its 10 tries in the current 10 minutes. Uses the API's rate-limit table.
 */
function paste_throttled(PDO $pdo, string $kind, bool $count = false): bool
{
    require_once __DIR__ . '/api_keys.php';
    $fails = api_rate($pdo, $kind . ':' . ($_SERVER['REMOTE_ADDR'] ?? ''), 600, $count);
    return $fails !== null && $fails >= 10;
}

/** A paste's URL identifier: its slug when it has one, else its id. */
function getPasteUrlIdentifier(array $paste, ?PDO $pdo = null): string
{
    $mode = getPasteUrlMode($pdo);

    // If slug mode and paste has a slug, use it
    if ($mode === 'slug' && !empty($paste['slug'])) {
        return $paste['slug'];
    }

    // Fallback to numeric ID
    return (string)($paste['id'] ?? '');
}

/** URL of a theme file with its modification time appended, so browsers fetch it again after an update. */
function paste_theme_asset(string $baseurl, string $theme, string $file): string
{
    $theme = basename($theme);
    $path = dirname(__DIR__) . '/theme/' . $theme . '/' . ltrim($file, '/');
    $v = is_file($path) ? (string) filemtime($path) : '';
    return $baseurl . 'theme/' . $theme . '/' . ltrim($file, '/') . ($v !== '' ? '?v=' . $v : '');
}

/**
 * Site URL for this request: $stored, with its host switched to the requested
 * one when that host is listed in $alias_domains (config.php). The scheme is
 * https when the request is, otherwise the stored one.
 */
function paste_request_baseurl(string $stored): string
{
    $aliases = $GLOBALS['alias_domains'] ?? [];
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    if (!is_array($aliases) || !$aliases || !preg_match('/^[a-z0-9.-]+(?::\d{1,5})?$/', $host)) {
        return $stored;
    }
    $listed = array_map(static fn($d): string => strtolower(trim((string)$d)), $aliases);
    if (!in_array($host, $listed, true) || !preg_match('#^(https?)://[^/]+#i', $stored, $m)) {
        return $stored;
    }
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $scheme = $https ? 'https' : strtolower($m[1]);
    return $scheme . '://' . $host . substr($stored, strlen($m[0]));
}

/** True if $table has $column. */
function columnExists(PDO $pdo, string $table, string $column): bool
{
    try {
        // Sanitize table and column names (alphanumeric and underscore only)
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $stmt->fetch() !== false;
    } catch (PDOException $e) {
        return false;
    }
}

/** Add the pastes.slug column when missing; true when it exists. */
function ensureSlugColumnExists(PDO $pdo): bool
{
    // First check if column exists
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM pastes LIKE 'slug'");
        if ($stmt->fetch() !== false) {
            return true; // Column exists
        }
    } catch (PDOException $e) {
        // Continue to try creating it
    }

    try {
        // Add the slug column after the id column
        $pdo->exec("ALTER TABLE pastes ADD COLUMN slug VARCHAR(32) DEFAULT NULL AFTER id");
    } catch (PDOException $e) {
        // Error code 1060 = Duplicate column name (column already exists)
        if (strpos($e->getMessage(), '1060') !== false || strpos($e->getMessage(), 'Duplicate column') !== false) {
            // Column already exists, that's fine
            return true;
        }
        error_log("ensureSlugColumnExists error: " . $e->getMessage());
        return false;
    }

    // Add unique index (ignore if already exists)
    try {
        $pdo->exec("CREATE UNIQUE INDEX idx_pastes_slug ON pastes(slug)");
    } catch (PDOException $e) {
        // Index might already exist - that's OK
    }

    return true;
}

/** True when pastes may not be opened by numeric id. */
function shouldBlockNumericUrls(?PDO $pdo = null): bool
{
    if ($pdo === null) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("SELECT option_value FROM paste_options WHERE option_name = 'block_numeric_urls'");
        $stmt->execute();
        $result = $stmt->fetchColumn();
        return $result === '1';
    } catch (PDOException $e) {
        return false;
    }
}

// ============================================================================
// End of Slug Functions
// ============================================================================

/**
 * Convert an expiry code to the stored pastes.expiry value: a unix timestamp,
 * 'SELF' (view once) or 'NULL' (never).
 * Accepts the web form codes (10M, 1H, 1D, 1W, 2W, 1M, self, N) and the
 * API/filehost codes (T, H, D, W, M, B, Y, NULL).
 */
function paste_expiry_value(string $code, ?int $now = null): string
{
    $now = $now ?? time();
    $offset = match (strtoupper(trim($code))) {
        '10M', 'T' => '+10 minutes',
        '1H', 'H'  => '+1 hour',
        '1D', 'D'  => '+1 day',
        '1W', 'W'  => '+1 week',
        '2W'       => '+2 weeks',
        '1M', 'M'  => '+1 month',
        'B'        => '+6 months',
        'Y'        => '+1 year',
        'SELF'     => 'SELF',
        default    => 'NULL',
    };
    if ($offset === 'SELF' || $offset === 'NULL') {
        return $offset;
    }
    return (string)strtotime($offset, $now);
}

/** True if a stored expiry value is a timestamp in the past. */
function paste_is_expired(?string $expiry, ?int $now = null): bool
{
    $expiry = trim((string)$expiry);
    if ($expiry === '' || !ctype_digit($expiry)) {
        return false;
    }
    $ts = (int)$expiry;
    return $ts > 0 && $ts < ($now ?? time());
}

/** SQL condition for pastes that may appear in public lists: not view-once and not expired. $alias qualifies the column. */
function paste_listable_sql(string $alias = ''): string
{
    $c = ($alias !== '' ? $alias . '.' : '') . 'expiry';
    return "(COALESCE($c, '') <> 'SELF' AND ($c IS NULL OR $c NOT REGEXP '^[0-9]+$'"
         . " OR CAST($c AS UNSIGNED) = 0 OR CAST($c AS UNSIGNED) >= UNIX_TIMESTAMP()))";
}

/** A title as stored: one line, HTML-escaped, at most 255 characters after escaping. */
function paste_store_title(string $title): string
{
    $title = trim((string)preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $title));
    $out = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    while (mb_strlen($out) > 255) {
        $cut = max(1, intdiv(mb_strlen($out) - 255 + 5, 6));
        $title = rtrim(mb_substr($title, 0, max(0, mb_strlen($title) - $cut)));
        $out = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    return $out;
}

function deleteMyPaste(PDO $pdo, int $paste_id): bool
{
    try {
        $query = "DELETE FROM pastes WHERE id = :paste_id";
        $stmt = $pdo->prepare($query);
        return $stmt->execute(['paste_id' => $paste_id]);
    } catch (PDOException $e) {
        error_log("Failed to delete paste ID {$paste_id}: " . $e->getMessage());
        return false;
    }
}

function getRecent(PDO $pdo, int $count = 5, int $offset = 0, string $sortColumn = 'date', string $sortDirection = 'DESC'): array
{
    try {
        $sortColumn = in_array($sortColumn, ['date', 'title', 'code', 'views']) ? $sortColumn : 'date';
        $sortDirection = in_array($sortDirection, ['ASC', 'DESC']) ? $sortDirection : 'DESC';
        // Databases not yet upgraded may lack the slug column
        $hasSlug = columnExists($pdo, 'pastes', 'slug');
        $slugCol = $hasSlug ? ', slug' : '';
        $query = "SELECT id{$slugCol}, title, visible, code, expiry, password, member, date, UNIX_TIMESTAMP(date) AS now_time, encrypt
                  FROM pastes WHERE visible = '0' AND password = 'NONE' AND " . paste_listable_sql() . "
                  ORDER BY $sortColumn $sortDirection LIMIT :count OFFSET :offset";
        $stmt = $pdo->prepare($query);
        $stmt->bindValue(':count', $count, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            if ($row['encrypt'] == "1") {
                $row['title'] = decrypt($row['title'], hex2bin(SECRET)) ?? $row['title'];
            }
            // Ensure slug key exists even if column doesn't
            if (!isset($row['slug'])) {
                $row['slug'] = '';
            }
        }
        unset($row);
        return $rows;
    } catch (PDOException $e) {
        error_log("Failed to fetch recent pastes: " . $e->getMessage());
        return [];
    }
}

function getUserRecent(PDO $pdo, string $username, int $count = 5): array
{
    try {
        // Databases not yet upgraded may lack the slug column
        $hasSlug = columnExists($pdo, 'pastes', 'slug');
        $slugCol = $hasSlug ? ', slug' : '';
        $query = "SELECT id{$slugCol}, title, visible, code, expiry, password, member, date, UNIX_TIMESTAMP(date) AS now_time, encrypt
                  FROM pastes WHERE member = :username ORDER BY id DESC LIMIT :count";
        $stmt = $pdo->prepare($query);
        $stmt->bindValue(':username', $username, PDO::PARAM_STR);
        $stmt->bindValue(':count', $count, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            if ($row['encrypt'] == "1") {
                $row['title'] = decrypt($row['title'], hex2bin(SECRET)) ?? $row['title'];
            }
            // Ensure slug key exists even if column doesn't
            if (!isset($row['slug'])) {
                $row['slug'] = '';
            }
        }
        unset($row);
        return $rows;
    } catch (PDOException $e) {
        error_log("Failed to fetch user recent pastes for {$username}: " . $e->getMessage());
        return [];
    }
}

function getTotalPastes(PDO $pdo, string $username): int
{
    try {
        $query = "SELECT COUNT(*) FROM pastes WHERE member = :username";
        $stmt = $pdo->prepare($query);
        $stmt->execute(['username' => $username]);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Failed to count pastes for {$username}: " . $e->getMessage());
        return 0;
    }
}

function isValidUsername(string $str): bool
{
    return preg_match('/^[A-Za-z0-9.#\\-$]+$/', $str) === 1;
}

function existingUser(PDO $pdo, string $username): bool
{
    try {
        $query = "SELECT COUNT(*) FROM users WHERE username = :username";
        $stmt = $pdo->prepare($query);
        $stmt->execute(['username' => $username]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("Failed to check existing user {$username}: " . $e->getMessage());
        return false;
    }
}

// Function to get paste view count from paste_views
function getPasteViewCount(PDO $pdo, int $paste_id): int
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM paste_views WHERE paste_id = :paste_id");
        $stmt->execute(['paste_id' => $paste_id]);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Failed to get view count for paste ID {$paste_id}: " . $e->getMessage());
        return 0;
    }
}

/** Count a page view for today, and the visitor once per day. */
function pageViewTrack(PDO $pdo, string $ip): void
{
    $date = date('Y-m-d');
    try {
        $st = $pdo->prepare("INSERT IGNORE INTO visitor_ips (ip, visit_date) VALUES (?, ?)");
        $st->execute([$ip, $date]);
        $newVisitor = $st->rowCount() > 0 ? 1 : 0;
        $st = $pdo->prepare("UPDATE page_view SET tpage = tpage + 1, tvisit = tvisit + ? WHERE date = ?");
        $st->execute([$newVisitor, $date]);
        if ($st->rowCount() === 0) {
            $pdo->prepare("INSERT INTO page_view (date, tpage, tvisit) VALUES (?, 1, ?)")->execute([$date, $newVisitor]);
        }
    } catch (PDOException $e) {
        error_log('pageViewTrack: ' . $e->getMessage());
    }
}

function updateMyView(PDO $pdo, int $paste_id): bool
{
    try {
        $ip = $_SERVER['REMOTE_ADDR'];
        $view_date = date('Y-m-d');

        // Use INSERT IGNORE to handle race conditions - if the row already exists, it's silently ignored
        $stmt = $pdo->prepare("INSERT IGNORE INTO paste_views (paste_id, ip, view_date) VALUES (:paste_id, :ip, :view_date)");
        $stmt->execute(['paste_id' => $paste_id, 'ip' => $ip, 'view_date' => $view_date]);

        // rowCount() returns 1 if inserted, 0 if ignored (duplicate)
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Failed to update view count for paste ID {$paste_id}: " . $e->getMessage());
        return false;
    }
}

// Function to format file size in a human-readable format for view.php
function formatSize($bytes) {
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

function conTime(int $timestamp): string
{
    if ($timestamp <= 0) {
        return '0 seconds';
    }
    $now = time();
    $diff = $now - $timestamp;
    if ($diff < 0) {
        return 'In the future';
    }
    $periods = [
        'year' => 31536000,
        'month' => 2592000,
        'day' => 86400,
        'hour' => 3600,
        'minute' => 60,
        'second' => 1
    ];
    $result = '';
    foreach ($periods as $name => $duration) {
        $value = floor($diff / $duration);
        if ($value >= 1) {
            $result .= "$value $name" . ($value > 1 ? 's' : '') . ' ';
            $diff -= $value * $duration;
        }
    }
    return trim($result) ?: 'just now';
}

function formatRealTime(string $dateStr): string
{
    // Convert database date (Y-m-d H:i:s) to a formatted date with time
    if (empty($dateStr)) {
        return 'Invalid date';
    }
    try {
        $date = new DateTime($dateStr, new DateTimeZone('UTC'));
        return $date->format('jS F Y H:i'); // e.g., "11th August 2025 23:43"
    } catch (Exception $e) {
        return 'Invalid date';
    }
}

function truncate(string $input, int $maxWords, int $maxChars): string
{
    $words = preg_split('/\s+/', trim($input), $maxWords + 1, PREG_SPLIT_NO_EMPTY);
    $words = array_slice($words, 0, $maxWords);
    $result = '';
    $chars = 0;
    foreach ($words as $word) {
        $chars += strlen($word) + 1;
        if ($chars > $maxChars) {
            break;
        }
        $result .= $word . ' ';
    }
    $result = rtrim($result);
    return $result === $input ? $result : $result . '[...]';
}

function doDownload(int $paste_id, string $p_title, string $p_content, string $p_code): bool
{
    if ($p_code === '' || $p_content === '') {
        header('HTTP/1.1 404 Not Found');
        return false;
    }
    $ext = match ($p_code) {
        'bash' => 'sh',
        'actionscript', 'html4strict' => 'html',
        'javascript' => 'js',
        'perl' => 'pl',
        'csharp' => 'cs',
        'ruby' => 'rb',
        'python' => 'py',
        'sql' => 'sql',
        'php' => 'php',
        'c' => 'c',
        'cpp' => 'cpp',
        'css' => 'css',
        'xml' => 'xml',
        default => 'txt',
    };
    $base = html_entity_decode($p_title, ENT_QUOTES, 'UTF-8');
    $base = trim((string)preg_replace('/[^A-Za-z0-9._ -]+/', '_', $base), " ._");
    if ($base === '') {
        $base = 'paste';
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . substr($base, 0, 100) . '.' . $ext . '"');
    echo $p_content;
    return true;
}

function rawView(int $paste_id, string $p_title, string $p_content, string $p_code): bool
{
    if (!$paste_id || $p_code === '' || $p_content === '') {
        header('HTTP/1.1 404 Not Found');
        return false;
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo $p_content;
    return true;
}

/**
 * Output a paste as a document.write() embed script.
 * $paste_id is the URL identifier (slug or id); $p_content must already be safe HTML.
 */
function embedView( $paste_id, $p_title, $p_content, $p_code, $title, $baseurl, $ges_style, $lang ) {
    global $mod_rewrite;
    $stats = false;
    if ( (string)$p_content !== '' ) {
        $e        = static fn($s) => htmlspecialchars(html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
        $base     = rtrim((string)$baseurl, '/') . '/';
        $ident    = rawurlencode((string)$paste_id);
        $pretty   = ((string)($mod_rewrite ?? '0') === '1');
        $viewUrl  = $pretty ? $base . $ident : $base . 'paste.php?id=' . $ident;
        $rawUrl   = $pretty ? $base . 'raw/' . $ident : $base . 'paste.php?raw&id=' . $ident;
        $hostedBy = $e($lang['embed-hosted-by'] ?? 'hosted by');
        $viewRaw  = $e(strtolower((string)($lang['view-raw'] ?? 'view raw')));

        $output = "<div class='paste_embed_container'>";
        $output .= "<style>
            .paste_embed_container {
                font-family: monospace;
                font-size: 13px;
                color: #333;
                background: #fff;
                border-radius: 8px;
                overflow: hidden;
                border: 1px solid #ccc;
                margin-bottom: 1em;
                position: relative;
                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                direction: ltr;
            }
            /* Footer with black gradient */
            .paste_embed_footer {
                font-size: 12px;
                padding: 8px;
                background: linear-gradient(90deg, #000000, #333333);
                color: #ffffff;
                border-top: 1px solid #ccc;
            }
            .paste_embed_footer a {
                color: #ffffff;
                text-decoration: none;
            }
            .paste_embed_footer a:hover {
                text-decoration: underline;
            }
            .paste_embed_code {
                margin: 0;
                padding: 12px;
                white-space: pre;
                max-height: 300px;
                overflow-y: auto;
                overflow-x: auto;
                scroll-behavior: smooth;
                position: relative;
                background: #fafafa;
            }
            /* Fade effect */
            .fade-out {
                position: absolute;
                bottom: 38px;
                left: 0;
                right: 0;
                height: 40px;
                background: linear-gradient(to bottom, rgba(250,250,250,0) 0%, rgba(250,250,250,1) 100%);
                pointer-events: none;
            }
            $ges_style
        </style>";

        // Code content
        $output .= "<div class='paste_embed_code'>" . $p_content . "</div>";
        $output .= "<div class='fade-out'></div>";

        // Footer
        $output .= "<div class='paste_embed_footer'>"
            . "<a href='" . $e($viewUrl) . "'>" . $e($p_title) . "</a> {$hostedBy} "
            . "<a href='" . $e($base) . "'>" . $e($title) . "</a> | "
            . "<a href='" . $e($rawUrl) . "'>{$viewRaw}</a>"
            . "</div>";

        $output .= "</div>";

        header( 'Content-type: text/javascript; charset=utf-8;' );
        echo 'document.write(' . json_encode( $output ) . ')';
        $stats = true;
    } else {
        header( 'HTTP/1.1 404 Not Found' );
    }
    return $stats;
}


function getEmbedUrl($paste_id, $mod_rewrite, $baseurl) {
    if ($mod_rewrite) {
        return $baseurl . 'embed/' . $paste_id;
    } else {
        return $baseurl . 'paste.php?embed&id=' . $paste_id;
    }
}

function addToSitemap(PDO $pdo, int $paste_id, string $priority, string $changefreq, bool $mod_rewrite, ?string $slug = null): bool
{
    try {
        global $baseurl;
        $c_date = date('Y-m-d');

        // Use slug if provided, otherwise try to get it from database
        $identifier = $paste_id;
        if ($slug !== null && $slug !== '') {
            $identifier = $slug;
        } else {
            // Try to get slug from database if URL mode is 'slug'
            try {
                $urlMode = getPasteUrlMode($pdo);
                if ($urlMode === 'slug') {
                    $stmt = $pdo->prepare("SELECT slug FROM pastes WHERE id = ?");
                    $stmt->execute([$paste_id]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row && !empty($row['slug'])) {
                        $identifier = $row['slug'];
                    }
                }
            } catch (PDOException $e) {
                // Use numeric ID as fallback
            }
        }

        $server_name = $mod_rewrite
            ? rtrim($baseurl, '/') . '/' . $identifier
            : rtrim($baseurl, '/') . "/paste.php?id=" . $identifier;

        $sitemap_path = 'sitemap.xml';

        // Build the new URL entry
        $new_entry = "  <url>\n    <loc>" . htmlspecialchars($server_name, ENT_QUOTES, 'UTF-8') . "</loc>\n    <priority>$priority</priority>\n    <changefreq>$changefreq</changefreq>\n    <lastmod>$c_date</lastmod>\n  </url>\n";

        if (file_exists($sitemap_path)) {
            $site_data = file_get_contents($sitemap_path);
            if ($site_data === false) {
                return false;
            }
            // Remove closing </urlset> tag, add new entry, then close
            $site_data = preg_replace('/<\/urlset>\s*$/', '', $site_data);
            $full_map = $site_data . $new_entry . "</urlset>\n";
        } else {
            // Create new sitemap
            $full_map = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n" . $new_entry . "</urlset>\n";
        }

        return file_put_contents($sitemap_path, $full_map) !== false;
    } catch (Exception $e) {
        error_log("Failed to update sitemap for paste ID {$paste_id}: " . $e->getMessage());
        return false;
    }
}

/**
 * Remove a paste from the sitemap when it becomes non-public.
 */
function removeFromSitemap(PDO $pdo, int $paste_id): bool
{
    try {
        if (!file_exists('sitemap.xml')) {
            return true; // Nothing to remove
        }

        global $baseurl, $mod_rewrite;

        $site_data = file_get_contents('sitemap.xml');
        if ($site_data === false) {
            return false;
        }

        // Build possible URL patterns for this paste
        $patterns = [];

        // With mod_rewrite
        $patterns[] = preg_quote($baseurl . $paste_id, '/');
        $patterns[] = preg_quote(rtrim($baseurl, '/') . '/' . $paste_id, '/');

        // Without mod_rewrite
        $patterns[] = preg_quote($baseurl . "paste.php?id=" . $paste_id, '/');
        $patterns[] = preg_quote(rtrim($baseurl, '/') . "/paste.php?id=" . $paste_id, '/');

        // Also try to get the slug for this paste
        try {
            $stmt = $pdo->prepare("SELECT slug FROM pastes WHERE id = ?");
            $stmt->execute([$paste_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['slug'])) {
                $slug = $row['slug'];
                $patterns[] = preg_quote($baseurl . $slug, '/');
                $patterns[] = preg_quote(rtrim($baseurl, '/') . '/' . $slug, '/');
                $patterns[] = preg_quote($baseurl . "paste.php?id=" . $slug, '/');
            }
        } catch (PDOException $e) {
            // Ignore - slug column might not exist
        }

        // Remove any <url> block containing these patterns
        foreach ($patterns as $pattern) {
            $regex = '/<url>\s*<loc>' . $pattern . '<\/loc>.*?<\/url>\s*/s';
            $site_data = preg_replace($regex, '', $site_data);
        }

        return file_put_contents('sitemap.xml', $site_data) !== false;
    } catch (Exception $e) {
        error_log("Failed to remove paste ID {$paste_id} from sitemap: " . $e->getMessage());
        return false;
    }
}

/**
 * Regenerate the entire sitemap with current URL mode settings.
 * Call this after generating slugs or changing URL mode.
 */
function regenerateSitemap(PDO $pdo): array
{
    $result = ['success' => false, 'count' => 0, 'message' => ''];

    try {
        global $baseurl, $mod_rewrite;

        // Get sitemap options
        $stmt = $pdo->query("SELECT priority, changefreq FROM sitemap_options WHERE id = 1");
        $opt = $stmt->fetch() ?: ['priority' => '0.5', 'changefreq' => 'weekly'];
        $priority = number_format((float)($opt['priority'] ?? 0.5), 1, '.', '');
        $changefreq = $opt['changefreq'] ?? 'weekly';

        // Check URL mode
        $urlMode = getPasteUrlMode($pdo);

        // Check if slug column exists
        $hasSlug = false;
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM pastes LIKE 'slug'");
            $hasSlug = ($chk && $chk->rowCount() > 0);
        } catch (Exception $e) {}

        $useSlug = ($hasSlug && $urlMode === 'slug');

        $today = date('Y-m-d');
        $sitemap_path = rtrim(dirname(__DIR__), '/\\') . '/sitemap.xml';
        $tmp_path = $sitemap_path . '.tmp';

        $fh = fopen($tmp_path, 'wb');
        if (!$fh) {
            $result['message'] = 'Unable to open sitemap file for writing';
            return $result;
        }

        // XML header
        fwrite($fh, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
        fwrite($fh, "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n");

        // Homepage
        $home = htmlspecialchars(rtrim($baseurl, '/') . '/', ENT_QUOTES, 'UTF-8');
        fwrite($fh, "  <url>\n    <loc>{$home}</loc>\n    <priority>1.0</priority>\n    <changefreq>daily</changefreq>\n    <lastmod>{$today}</lastmod>\n  </url>\n");

        $written = 1;
        $selectCols = $useSlug ? "id, slug, date" : "id, date";

        // Stream public pastes in chunks
        $listable = paste_listable_sql();
        $total = (int)$pdo->query("SELECT COUNT(*) FROM pastes WHERE visible = '0' AND $listable")->fetchColumn();
        $limit = 500;

        for ($offset = 0; $offset < $total; $offset += $limit) {
            $stmt = $pdo->prepare("SELECT {$selectCols} FROM pastes WHERE visible = '0' AND $listable ORDER BY id DESC LIMIT ? OFFSET ?");
            $stmt->execute([$limit, $offset]);

            while ($row = $stmt->fetch()) {
                $identifier = ($useSlug && !empty($row['slug'])) ? $row['slug'] : $row['id'];
                $url = $mod_rewrite
                    ? rtrim($baseurl, '/') . '/' . rawurlencode($identifier)
                    : rtrim($baseurl, '/') . '/paste.php?id=' . urlencode($identifier);
                $loc = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                $lastmod = !empty($row['date']) ? substr($row['date'], 0, 10) : $today;

                fwrite($fh, "  <url>\n    <loc>{$loc}</loc>\n    <priority>{$priority}</priority>\n    <changefreq>{$changefreq}</changefreq>\n    <lastmod>{$lastmod}</lastmod>\n  </url>\n");
                $written++;
            }
        }

        fwrite($fh, "</urlset>\n");
        fclose($fh);

        // Atomic replace
        if (!rename($tmp_path, $sitemap_path)) {
            @unlink($tmp_path);
            $result['message'] = 'Failed to move sitemap into place';
            return $result;
        }

        $result['success'] = true;
        $result['count'] = $written;
        $result['message'] = "Sitemap regenerated with {$written} URLs";

    } catch (Exception $e) {
        error_log("regenerateSitemap error: " . $e->getMessage());
        $result['message'] = $e->getMessage();
    }

    return $result;
}

function is_banned(PDO $pdo, string $ip): bool
{
    try {
        // Exact addresses, and patterns such as 1.2.3.* where * stands for the rest of the address
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ban_user WHERE ip = ? OR (ip LIKE '%*' AND ? LIKE REPLACE(ip, '*', '%'))");
        $stmt->execute([$ip, $ip]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("Failed to check ban status for IP {$ip}: " . $e->getMessage());
        return false;
    }
}

/**
 * Build a page URL that respects mod_rewrite.
 * With mod_rewrite:  {$baseurl}page/{page_name}
 * Without:          {$baseurl}page.php?p={page_name}
 */
function getPageUrl(string $page_name): string
{
    global $baseurl, $mod_rewrite;

    $safe = rawurlencode($page_name);
    if (!empty($mod_rewrite) && $mod_rewrite === "1") {
        return rtrim($baseurl, '/') . '/page/' . $safe;
    }
    return rtrim($baseurl, '/') . '/pages.php?p=' . $safe;
}

/**
 * Fetch pages for a given location (header|footer).
 * Returns a hierarchical array: each item has keys: id, name, title, url, children[]
 */
function getNavLinks(PDO $pdo, string $location): array
{
    $location = in_array($location, ['header', 'footer'], true) ? $location : 'header';

    try {
        // Check if API is enabled (for filtering API page)
        $api_enabled = true;
        try {
            $apiStmt = $pdo->query("SELECT option_value FROM paste_options WHERE option_name = 'api_enabled'");
            if ($apiStmt) {
                $apiRow = $apiStmt->fetch();
                if ($apiRow) {
                    $api_enabled = ($apiRow['option_value'] !== '0');
                }
            }
        } catch (PDOException $e) {
            // Table might not exist, default to showing API
        }

        // Get all active pages that match this location or are marked for both
        $stmt = $pdo->prepare("
            SELECT id, page_name, page_title, nav_parent, sort_order, external_url
            FROM pages
            WHERE is_active = 1
              AND (location = :loc OR location = 'both')
            ORDER BY sort_order ASC, page_title ASC
        ");
        $stmt->execute(['loc' => $location]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $items = [];
        foreach ($rows as $r) {
            // Skip API page if API is disabled
            if ($r['page_name'] === 'api' && !$api_enabled) {
                continue;
            }

            // Determine URL: use external_url if set, otherwise use page URL
            $url = !empty($r['external_url'])
                ? $r['external_url']
                : getPageUrl((string)$r['page_name']);

            $items[(int)$r['id']] = [
                'id'       => (int)$r['id'],
                'name'     => (string)$r['page_name'],
                'title'    => (string)$r['page_title'],
                'parent'   => $r['nav_parent'] !== null ? (int)$r['nav_parent'] : null,
                'order'    => (int)$r['sort_order'],
                'url'      => $url,
                'external' => !empty($r['external_url']),
                'children' => [],
            ];
        }

        // Build tree
        $tree = [];
        foreach ($items as $id => &$node) {
            if ($node['parent'] !== null && isset($items[$node['parent']])) {
                $items[$node['parent']]['children'][] = &$node;
            } else {
                $tree[] = &$node;
            }
        }
        unset($node); // break reference

        // Ensure children are sorted (by sort_order then title)
        $sortFn = static function (&$list) use (&$sortFn) {
            usort($list, static function ($a, $b) {
                return ($a['order'] <=> $b['order']) ?: strcasecmp($a['title'], $b['title']);
            });
            foreach ($list as &$i) {
                if (!empty($i['children'])) {
                    $sortFn($i['children']);
                }
            }
            unset($i);
        };
        $sortFn($tree);

        return $tree;
    } catch (PDOException $e) {
        error_log("getNavLinks failed for {$location}: " . $e->getMessage());
        return [];
    }
}


// Simple HTML renderer for nav links.
function renderNavListSimple(array $links, string $separator = ''): string
{
    // Render a flat inline list if separator provided, else nested <ul>
    if ($separator !== '') {
        $flat = [];
        $stack = $links;
        while ($stack) {
            $node = array_shift($stack);
            $flat[] = '<a href="' . htmlspecialchars($node['url'], ENT_QUOTES, 'UTF-8') . '">' .
                      htmlspecialchars($node['title'], ENT_QUOTES, 'UTF-8') . '</a>';
            foreach ($node['children'] as $child) {
                $stack[] = $child;
            }
        }
        return implode($separator, $flat);
    }

    $render = static function (array $nodes) use (&$render): string {
        $html = "<ul>";
        foreach ($nodes as $n) {
            $html .= '<li><a href="' . htmlspecialchars($n['url'], ENT_QUOTES, 'UTF-8') . '">' .
                     htmlspecialchars($n['title'], ENT_QUOTES, 'UTF-8') . '</a>';
            if (!empty($n['children'])) {
                $html .= $render($n['children']);
            }
            $html .= '</li>';
        }
        $html .= "</ul>";
        return $html;
    };
    return $render($links);
}

/**
 * Bootstrap 5 nav renderer (supports one dropdown level).
 * Returns <li> items ready to live inside <ul class="navbar-nav">.
 */
function renderBootstrapNav(array $links): string
{
    $html = '';
    foreach ($links as $item) {
        $title = htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8');
        $url   = htmlspecialchars($item['url'],   ENT_QUOTES, 'UTF-8');

        if (!empty($item['children'])) {
            $id = 'dd_' . $item['id'];
            $html .= '<li class="nav-item dropdown">';
            $html .= '<a class="nav-link dropdown-toggle" href="#" id="'. $id .'" role="button" data-bs-toggle="dropdown" aria-expanded="false">'. $title .'</a>';
            $html .= '<ul class="dropdown-menu" aria-labelledby="'. $id .'">';
            foreach ($item['children'] as $child) {
                $ctitle = htmlspecialchars($child['title'], ENT_QUOTES, 'UTF-8');
                $curl   = htmlspecialchars($child['url'],   ENT_QUOTES, 'UTF-8');
                $html  .= '<li><a class="dropdown-item" href="'. $curl .'">'. $ctitle .'</a></li>';
            }
            $html .= '</ul></li>';
        } else {
            $html .= '<li class="nav-item"><a class="nav-link" href="'. $url .'">'. $title .'</a></li>';
        }
    }
    return $html;
}

/**
 * sanitizer for Markdown output.
 * Keeps only common Markdown tags + safe attributes, strips on* and style, validates href/src.
 */
if (!function_exists('sanitize_allowlist_html')) {
    function sanitize_allowlist_html(string $html): string {
        $allowedTags = [
            'p','br','hr','em','strong','i','b','u','s','del','ins','code','pre','kbd','samp',
            'blockquote','ul','ol','li','dl','dt','dd',
            'h1','h2','h3','h4','h5','h6',
            'table','thead','tbody','tfoot','tr','th','td',
            'a','img'
        ];
        $allowedAttrs = [
            'a'   => ['href','title'],
            'img' => ['src','alt','title'],
            '*'   => [] // no other attributes anywhere
        ];

        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $walker = function(DOMNode $node) use (&$walker, $allowedTags, $allowedAttrs) {
            for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
                $child = $node->childNodes->item($i);
                if (!($child instanceof DOMElement)) continue;

                $tag = strtolower($child->nodeName);
                if (!in_array($tag, $allowedTags, true)) {
                    // unwrap unknown element but keep its children/text
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }

                // clean attributes
                $allowed = $allowedAttrs[$tag] ?? $allowedAttrs['*'];
                $toRemove = [];
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $name = strtolower($attr->name);
                    $val  = $attr->value;

                    // nuke event handlers and inline styles entirely
                    if (str_starts_with($name, 'on') || $name === 'style') { $toRemove[] = $name; continue; }

                    // enforce allowlist
                    if (!in_array($name, $allowed, true)) { $toRemove[] = $name; continue; }

                    // validate href/src values
                    if ($tag === 'a' && $name === 'href') {
                        if (!preg_match('#^(https?://|mailto:)#i', $val)) { $toRemove[] = $name; continue; }
                        // add safe rel/target
                        $child->setAttribute('rel', 'nofollow noopener noreferrer');
                        $child->setAttribute('target', '_blank');
                    }
                    if ($tag === 'img' && $name === 'src') {
                        // allow only http/https images, disallow svg (can execute scripts)
                        if (!preg_match('#^https?://#i', $val)) { $toRemove[] = $name; continue; }
                        $path = parse_url($val, PHP_URL_PATH) ?? '';
                        if (preg_match('#\.svg(\?.*)?$#i', (string)$path)) { $toRemove[] = $name; continue; }
                    }
                }
                foreach ($toRemove as $r) { $child->removeAttribute($r); }

                $walker($child);
            }
        };
        $walker($doc);

        return $doc->saveHTML() ?: '';
    }
}
// Language autodetection (paste_autodetect_language and helpers)
require_once __DIR__ . '/langdetect.php';

// Configs written by older installers label autodetection "experimental"
if (($GLOBALS['geshiformats']['autodetect'] ?? '') === 'Autodetect (experimental)') {
    $GLOBALS['geshiformats']['autodetect'] = 'Autodetect';
}

function addPasteComment(
    PDO $pdo,
    int $paste_id,
    ?int $user_id = null,
    string $username = 'Guest',
    string $ip = '0.0.0.0',
    string $body = '',
    ?int $parent_id = null
): ?int {
    try {
        $body = trim($body);
        if ($body === '') return null;
        if (strlen($body) > 4000) $body = substr($body, 0, 4000);

        // if replying, make sure the parent exists and belongs to the same paste
        if ($parent_id !== null) {
            $chk = $pdo->prepare("SELECT paste_id FROM paste_comments WHERE id = ?");
            $chk->execute([$parent_id]);
            $pp = $chk->fetchColumn();
            if ((int)$pp !== $paste_id) {
                $parent_id = null; // ignore bogus parent
            }
        }

        $st = $pdo->prepare("
            INSERT INTO paste_comments (paste_id, parent_id, user_id, username, body, created_at, ip)
            VALUES (:pid, :parent, :uid, :un, :body, :ts, :ip)
        ");
        $st->execute([
            ':pid'    => $paste_id,
            ':parent' => $parent_id,
            ':uid'    => $user_id,
            ':un'     => $username,
            ':body'   => $body,
            ':ts'     => date('Y-m-d H:i:s'),
            ':ip'     => $ip
        ]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("addPasteComment($paste_id): " . $e->getMessage());
        return null;
    }
}

function getPasteCommentsTree(PDO $pdo, int $paste_id): array {
    try {
        $sql = "SELECT id, paste_id, parent_id, user_id, username, body, created_at, ip
                FROM paste_comments
                WHERE paste_id = :pid
                ORDER BY created_at ASC, id ASC";
        $st = $pdo->prepare($sql);
        $st->execute([':pid' => $paste_id]);
        $all = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // index and attach children
        $byId = [];
        foreach ($all as $r) {
            $r['children'] = [];
            $byId[$r['id']] = $r;
        }
        $roots = [];
        foreach ($byId as $id => &$node) {
            $p = $node['parent_id'] ?? null;
            if (!empty($p) && isset($byId[$p])) {
                $byId[$p]['children'][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }
        unset($node);
        return $roots;
    } catch (PDOException $e) {
        error_log("getPasteCommentsTree($paste_id): " . $e->getMessage());
        return [];
    }
}


/** Comments: authorisation check for delete (owner of comment) */
function userOwnsComment(PDO $pdo, int $comment_id, int $user_id, string $username): bool {
    try {
        $st = $pdo->prepare("SELECT user_id, username FROM paste_comments WHERE id = ?");
        $st->execute([$comment_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        // Prefer user_id match; fallback to username (for old/guest cases)
        if (!empty($row['user_id']) && (int)$row['user_id'] === (int)$user_id) return true;
        return strcasecmp((string)$row['username'], $username) === 0;
    } catch (PDOException $e) {
        error_log("userOwnsComment($comment_id): " . $e->getMessage());
        return false;
    }
}

/** Comments: delete */
function deleteComment(PDO $pdo, int $comment_id): bool {
    try {
        $st = $pdo->prepare("DELETE FROM paste_comments WHERE id = ?");
        return $st->execute([$comment_id]);
    } catch (PDOException $e) {
        error_log("deleteComment($comment_id): " . $e->getMessage());
        return false;
    }
}

/** Minimal safe render for comment body: escape + autolink + nl2br */
function render_comment_html(string $text): string {
    $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    // autolink http/https
    $safe = preg_replace('~(?i)\bhttps?://[^\s<]+~', '<a href="$0" target="_blank" rel="nofollow noopener noreferrer">$0</a>', $safe);
    return nl2br($safe);
}

// --- Fatal Errors ---
function paste_error(string $msg, int $httpCode = 200): void
{
    // Endpoints that answer machines get the error as JSON
    if (defined('PASTE_JSON_ERRORS')) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        if (!headers_sent()) {
            http_response_code($httpCode);
            header('Content-Type: application/json; charset=utf-8');
        }
        exit(json_encode(['success' => false, 'error' => $msg]));
    }

    // Variables expected by theme/default/errors.php
    $error            = $msg;
    $notfound         = null;
    $require_password = false;
    $paste_id         = 0;

    // fallbacks
    $default_theme = (string)($GLOBALS['default_theme'] ?? 'default');
    $baseurl       = (string)($GLOBALS['baseurl'] ?? '/');
    $mod_rewrite   = (string)($GLOBALS['mod_rewrite'] ?? '0');
    $lang          = (array)($GLOBALS['lang'] ?? []);
    $title         = (string)($GLOBALS['title'] ?? 'Error');
    $site_name     = (string)($GLOBALS['site_name'] ?? 'Paste');
    $des           = (string)($GLOBALS['des'] ?? '');
    $keyword       = (string)($GLOBALS['keyword'] ?? '');
    $ga            = (string)($GLOBALS['ga'] ?? '');
    $additional_scripts = (string)($GLOBALS['additional_scripts'] ?? '');
    $ads_1         = (string)($GLOBALS['ads_1'] ?? '');
    $ads_2         = (string)($GLOBALS['ads_2'] ?? '');
    $text_ads      = (string)($GLOBALS['text_ads'] ?? '');
    $privatesite   = (string)($GLOBALS['privatesite'] ?? 'off');
    $noguests      = (string)($GLOBALS['noguests'] ?? 'off');
    $enablegoog    = (string)($GLOBALS['enablegoog'] ?? 'no');
    $hl_style      = (string)($GLOBALS['hl_style'] ?? 'hybrid.css');
    $ges_style     = (string)($GLOBALS['ges_style'] ?? '');

    // Provide a safe $pdo so header.php can call getNavLinks($pdo,'header')
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $pdo = $GLOBALS['pdo']; // make it local for the include scope
    } else {
        if (!class_exists('PasteDummyPDO')) {
            class PasteDummyPDO extends PDO {
                public function __construct() { /* no parent ctor */ }
                public function prepare($query, $options = []) { throw new PDOException('DB unavailable'); }
                public function query($query, $mode = null, ...$args) { throw new PDOException('DB unavailable'); }
            }
        }
        $pdo = new PasteDummyPDO();
        $GLOBALS['pdo'] = $pdo; // optional
    }

    // If we're here from an OOM, avoid loading header/footer (keeps memory tiny)
    if (!empty($GLOBALS['__PASTE_FATAL_OOM__'])) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        http_response_code($httpCode);
        header('Content-Type: text/html; charset=utf-8');
        $safe = htmlspecialchars($error, ENT_QUOTES, 'UTF-8');
        exit("<!doctype html><meta charset='utf-8'><title>Error</title><p>{$safe}</p>");
    }

    // theme
    $rootDir  = dirname(__DIR__);
    $themeDir = $rootDir . '/theme/' . basename($default_theme);
    $header   = $themeDir . '/header.php';
    $errors   = $themeDir . '/errors.php';
    $footer   = $themeDir . '/footer.php';

    // Clean any partial output and set status
    while (ob_get_level() > 0) { @ob_end_clean(); }
    http_response_code($httpCode);

    if (is_file($header) && is_file($errors) && is_file($footer)) {
        require $header;   // sees all locals above ($pdo, $baseurl, $lang, …)
        require $errors;
        require $footer;
        exit;
    }

    // Minimal fallback
    header('Content-Type: text/html; charset=utf-8');
    $safe = htmlspecialchars($error, ENT_QUOTES, 'UTF-8');
    exit("<!doctype html><meta charset='utf-8'><title>Error</title><p>{$safe}</p>");
}

function paste_enable_themed_errors(): void
{
    if (!empty($GLOBALS['PASTE_NO_THEMED_ERRORS'])) {
        return; // per-script opt-out
    }

    if (!headers_sent()) {
        ob_start(); // allow clearing partial output on fatal
    }

    set_exception_handler(static function (Throwable $ex): void {
        error_log('[paste-uncaught] ' . get_class($ex) . ': ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
        $GLOBALS['error'] = 'An unexpected error occurred while processing your request.';
        paste_error($GLOBALS['error'], 200);
    });

    set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0) {
        // Respect @-silencing
        if (!(error_reporting() & $severity)) {
            return false;
        }
        // Escalate only serious, recoverable fatals
        if (in_array($severity, [E_RECOVERABLE_ERROR, E_USER_ERROR], true)) {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }
        return false; // let PHP handle warnings/notices
    });

    register_shutdown_function(static function (): void {
        $err = error_get_last();
        if (!$err) {
            return;
        }

        $type = (int)($err['type'] ?? 0);
        if (!in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        $msg = (string)($err['message'] ?? '');
        $isOOM     = stripos($msg, 'Allowed memory size') !== false;
        $isTimeout = stripos($msg, 'Maximum execution time') !== false;

        if (!$isOOM && !$isTimeout && $type !== E_PARSE) {
            return; // ignore non-fatal shutdowns
        }

        $ml = ini_get('memory_limit') ?: '';
        $mlText = $ml !== '' ? " Current memory_limit in php.ini settings: $ml." : '';

        $diffHint = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'diff.php' ? ' Try unified view or download the .diff.' : '';
        if ($isOOM) {
            $error = 'The page needs more memory than the server allows.' . $diffHint . $mlText;
        } elseif ($isTimeout) {
            $error = 'The page took too long and timed out.' . $diffHint;
        } else {
            $error = 'A fatal error occurred while rendering this page.';
        }

        $GLOBALS['error'] = $error;
        header('X-Paste-Fatal: ' . ($isOOM ? 'oom' : ($isTimeout ? 'timeout' : 'fatal')));
        paste_error($error, 200);
    });
}

/**
 * Headers for pages that hold a decryption key in the URL fragment: only
 * first-party and CDN scripts may run, and nothing may be sent elsewhere.
 * Templates also skip ads, analytics and captcha scripts when $no_third_party is set.
 */
function paste_privacy_headers(): void
{
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' cdn.jsdelivr.net cdnjs.cloudflare.com; "
        . "style-src 'self' 'unsafe-inline' cdn.jsdelivr.net fonts.googleapis.com; "
        . "font-src 'self' data: cdn.jsdelivr.net fonts.gstatic.com; "
        . "img-src 'self' blob: data: https:; media-src 'self' blob:; connect-src 'self'; "
        . "frame-src 'none'; object-src 'none'; base-uri 'self'; form-action 'self'");
    header('Referrer-Policy: no-referrer');
}

// Spam detection engine (rules, heuristics, DNSBL, StopForumSpam)
require_once __DIR__ . '/spam.php';

// Web uploads (/img) on the FILEHOST store
require_once __DIR__ . '/img_store.php';
