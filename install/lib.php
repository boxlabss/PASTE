<?php
/*
 * Paste Installer https://github.com/boxlabss/PASTE
 *
 * Shared by the installer pages and the upgrader: the version being
 * installed, reading and editing an existing config.php, detecting an
 * existing installation, and the admin sign-in that guards it.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */
declare(strict_types=1);

/** Version written to config.php by the installer and the upgrader. */
const PASTE_VERSION = '3.5';

/** How long an installer admin sign-in lasts, in seconds. */
const INSTALLER_SIGNIN_TTL = 1800;

/* ============================ config.php ============================ */

/**
 * define() statements whose constant name starts with one of $prefixes,
 * as name => the statement's source text. Commented-out defines are skipped.
 */
function installer_config_defines(string $src, array $prefixes): array
{
    $wanted = static function (string $name) use ($prefixes): bool {
        foreach ($prefixes as $p) {
            if (str_starts_with($name, $p)) return true;
        }
        return false;
    };

    if (!function_exists('token_get_all')) {
        $out = [];
        if (preg_match_all('/^[ \t]*(define\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]\s*,.*\);)[ \t]*$/m', $src, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                if ($wanted($row[2])) $out[$row[2]] = $row[1];
            }
        }
        return $out;
    }

    $tokens = @token_get_all($src);
    $n = count($tokens);
    $out = [];
    $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING || strcasecmp($t[1], 'define') !== 0) continue;

        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && in_array($tokens[$j][0], $skip, true)) $j++;
        if (($tokens[$j] ?? null) !== '(') continue;
        $j++;
        while ($j < $n && is_array($tokens[$j]) && in_array($tokens[$j][0], $skip, true)) $j++;
        $nameTok = $tokens[$j] ?? null;
        if (!is_array($nameTok) || $nameTok[0] !== T_CONSTANT_ENCAPSED_STRING) continue;
        $name = substr($nameTok[1], 1, -1);
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $name) || !$wanted($name)) continue;

        $depth = 0;
        $text = '';
        for ($k = $i; $k < $n; $k++) {
            $s = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
            $text .= $s;
            if ($s === '(' || $s === '[') {
                $depth++;
            } elseif ($s === ')' || $s === ']') {
                $depth--;
            } elseif ($s === ';' && $depth === 0) {
                break;
            }
        }
        if ($k >= $n) continue;
        $out[$name] = $text;
        $i = $k;
    }
    return $out;
}

/** Default upload folder: next to the site root, outside the web root. */
function installer_default_upload_dir(): string
{
    $root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    return dirname($root) . DIRECTORY_SEPARATOR . 'filehost_files';
}

/**
 * Check and create an upload folder for FILEHOST_DIR. Returns the define()
 * statement for config.php, or null when the path is not usable; problems
 * the admin should fix are added to $notes.
 */
function installer_upload_dir_define(string $path, string $webUser, array &$notes): ?string
{
    if ($path === '' || strlen($path) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $path)) {
        $notes[] = 'The upload storage folder is not a valid path.';
        return null;
    }
    $root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    $abs = ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path)) ? $path : $root . '/' . preg_replace('#^\./#', '', $path);
    if (!is_dir($abs)) {
        @mkdir($abs, 0750, true);
    }
    $html = htmlspecialchars($abs, ENT_QUOTES, 'UTF-8');
    if (!is_dir($abs) || !is_writable($abs)) {
        $notes[] = "PHP cannot write to the upload folder yet. Run: <code>mkdir -p $html && chown "
            . htmlspecialchars($webUser, ENT_QUOTES, 'UTF-8') . " $html && chmod 750 $html</code>";
    }
    $real = realpath($abs);
    if ($real !== false && str_starts_with($real . '/', $root . '/')) {
        $notes[] = 'The upload folder is inside the web root, so its files could be fetched directly. A folder outside the web root is safer.';
    }
    return "define('FILEHOST_DIR', " . var_export($path, true) . ");";
}

/** PHP source for a version number in config.php. */
function installer_version_literal(string $version): string
{
    return preg_match('/^\d+\.\d+$/', $version) ? $version : var_export($version, true);
}

/** True when PHP source parses (always true without the tokenizer). */
function installer_php_parses(string $src, ?string &$error = null): bool
{
    if (!function_exists('token_get_all')) return true;
    try {
        token_get_all($src, TOKEN_PARSE);
        return true;
    } catch (ParseError $e) {
        $error = 'line ' . $e->getLine() . ': ' . $e->getMessage();
        return false;
    }
}

/**
 * Update an existing config.php in place: the version line and, when given,
 * the FILEHOST_DIR define. Everything else in the file is left as it is. A
 * copy of the old file is saved first. Returns true when the file changed.
 */
function installer_update_config(string $file, string $version, ?string $uploadDefine, array &$notes): bool
{
    $src = @file_get_contents($file);
    if ($src === false) {
        $notes[] = 'config.php could not be read.';
        return false;
    }
    $new = $src;
    $literal = installer_version_literal($version);

    $re = '/(\$currentversion\s*=\s*)(["\']?)[0-9][0-9.]*\2(\s*;)/';
    if (preg_match($re, $new)) {
        $new = (string)preg_replace($re, '${1}' . $literal . '${3}', $new, 1);
    } else {
        $new = (string)preg_replace('/^<\?php\b[^\n]*\n/', "\$0\$currentversion = $literal;\n", $new, 1);
    }

    if ($uploadDefine !== null) {
        $old = installer_config_defines($new, ['FILEHOST_DIR'])['FILEHOST_DIR'] ?? null;
        if ($old !== null) {
            $at = strpos($new, $old);
            $new = substr_replace($new, $uploadDefine, (int)$at, strlen($old));
        } else {
            $block = "\n// Upload storage (/img and FILEHOST); keep it outside the web root\n$uploadDefine\n";
            $end = strrpos($new, '?>');
            if ($end !== false && trim(substr($new, $end + 2)) === '') {
                $new = rtrim(substr($new, 0, $end)) . "\n" . $block . "?>\n";
            } else {
                $new = rtrim($new) . "\n" . $block;
            }
        }
    }

    if ($new === $src) return false;
    $error = null;
    if (!installer_php_parses($new, $error)) {
        $notes[] = 'config.php was not changed: the update would not be valid PHP (' . htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') . ').';
        return false;
    }
    $backup = dirname($file) . '/config.backup-' . date('Ymd-His') . '.php';
    if (!@copy($file, $backup)) {
        $notes[] = 'config.php was not changed: a backup copy could not be written next to it.';
        return false;
    }
    @chmod($backup, 0600);
    if (file_put_contents($file, $new, LOCK_EX) === false) {
        $notes[] = 'config.php could not be written.';
        return false;
    }
    installer_forget_cached($file);
    $notes[] = 'Updated config.php; the previous version was saved as <code>' . htmlspecialchars(basename($backup), ENT_QUOTES, 'UTF-8') . '</code>.';
    return true;
}

/* ============================ Existing installation ============================ */

/** Drop a PHP file from the opcode cache so the next include reads it from disk. */
function installer_forget_cached(string $file): void
{
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($file, true);
    }
}

/** Variables set by a config.php, read in a separate scope; ['__error' => ...] when it fails. */
function installer_read_config(string $file): array
{
    if (!is_file($file) || !is_readable($file)) return ['__error' => 'config.php is not readable.'];
    installer_forget_cached($file);
    ob_start();
    try {
        $vars = (static function (string $__file): array {
            include $__file;
            unset($__file);
            return get_defined_vars();
        })($file);
    } catch (Throwable $e) {
        $vars = ['__error' => $e->getMessage()];
    }
    ob_end_clean();
    return $vars;
}

/**
 * What is already installed: config.php, its database, and whether it can be
 * upgraded in place. 'installed' means the database already has an admin.
 * 'locked' means the installer needs an admin sign-in: Paste is installed, or
 * config.php names a database that cannot be checked.
 */
function installer_existing(string $configFile): array
{
    $out = ['config' => is_file($configFile), 'installed' => false, 'locked' => false, 'upgradable' => false,
            'version' => null, 'database' => null, 'error' => null, 'reason' => null, 'vars' => [], 'pdo' => null];
    if (!$out['config']) return $out;

    $vars = installer_read_config($configFile);
    if (isset($vars['__error'])) {
        $out['error'] = 'config.php could not be loaded: ' . $vars['__error'];
        $out['locked'] = true;
        return $out;
    }
    $out['vars'] = $vars;
    $out['version'] = isset($vars['currentversion']) ? (string)$vars['currentversion'] : null;
    $out['database'] = (string)($vars['dbname'] ?? '');
    if (!isset($vars['dbhost'], $vars['dbname'], $vars['dbuser'])) {
        $out['error'] = 'config.php has no database settings.';
        return $out;
    }

    try {
        $pdo = new PDO('mysql:host=' . $vars['dbhost'] . ';dbname=' . $vars['dbname'] . ';charset=utf8mb4',
            (string)$vars['dbuser'], (string)($vars['dbpassword'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]);
    } catch (PDOException $e) {
        $out['error'] = 'The database in config.php could not be reached: ' . $e->getMessage();
        $out['locked'] = true;
        return $out;
    }
    $out['pdo'] = $pdo;
    $out['installed'] = installer_has_admin($pdo);
    $out['locked'] = $out['installed'];
    if (!$out['installed']) return $out;

    if (!defined('SECRET') || !isset($vars['sec_key'])
        || ($out['version'] !== null && version_compare($out['version'], '3.0', '<'))) {
        $out['reason'] = 'This looks like a Paste 2.x installation. Use the full installer instead: it keeps your encryption key and can re-encrypt old pastes.';
        return $out;
    }
    $out['upgradable'] = true;
    return $out;
}

/** True when the database has an admin account, or when that cannot be checked; false only when there is none. */
function installer_has_admin(PDO $pdo): bool
{
    try {
        return (int)$pdo->query('SELECT COUNT(*) FROM admin')->fetchColumn() > 0;
    } catch (PDOException $e) {
        return $e->getCode() !== '42S02';
    } catch (Throwable $e) {
        return true;
    }
}

/* ============================ Installer sign-in ============================ */

/** Start the installer's own session (cookie limited to /install). */
function installer_session_start(): void
{
    if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) return;
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('PASTEINSTALL');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/install/x.php'), '/') . '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

/** Check an installer sign-in: 'ok', 'wrong' (name or password) or 'limited' (no full access). */
function installer_check_admin(PDO $pdo, string $user, string $pass): string
{
    try {
        $hasPermissions = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
                                            AND table_name = 'admin' AND column_name = 'permissions'")->fetchColumn() > 0;
        $st = $pdo->prepare('SELECT id, pass, ' . ($hasPermissions ? 'permissions' : 'NULL AS permissions') . ' FROM admin WHERE user = ? LIMIT 1');
        $st->execute([$user]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return 'wrong';
    }
    if (!$row || !is_string($row['pass']) || $row['pass'] === '' || !password_verify($pass, $row['pass'])) {
        return 'wrong';
    }
    return (int)$row['id'] === 1 || $row['permissions'] === null ? 'ok' : 'limited';
}

/** True while this browser holds a valid installer sign-in. */
function installer_signed_in(): bool
{
    return (int)($_SESSION['installer_admin_until'] ?? 0) > time();
}

/** True when this browser has just written config.php in step one. */
function installer_configured_here(): bool
{
    return (int)($_SESSION['installer_configured_until'] ?? 0) > time();
}

/** Message shown when the installer is used on an existing site without signing in. */
function installer_locked_message(array $existing = []): string
{
    if (empty($existing['installed']) && !empty($existing['error'])) {
        return 'config.php exists, but its database could not be reached or the file could not be loaded, so the installer cannot check '
            . 'whether Paste is installed. Fix the database connection or config.php, or remove config.php to start over.';
    }
    return 'Paste is already installed. Open the installer page and sign in with an admin account to upgrade or reconfigure it, '
        . 'or remove config.php to start over.';
}

/**
 * Failed installer sign-ins from this IP address in the current ten minutes,
 * counting one more first when $count. Null when they cannot be counted.
 */
function installer_signin_failures(PDO $pdo, bool $count = false): ?int
{
    $bucket = 'installer:' . (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $start = intdiv(time(), 600) * 600;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS api_rate_limits (
            bucket VARCHAR(64) NOT NULL,
            window_start INT NOT NULL,
            hits INT NOT NULL DEFAULT 0,
            PRIMARY KEY (bucket, window_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if ($count) {
            $pdo->prepare('INSERT INTO api_rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)
                           ON DUPLICATE KEY UPDATE hits = hits + 1')->execute([$bucket, $start]);
        }
        $st = $pdo->prepare('SELECT hits FROM api_rate_limits WHERE bucket = ? AND window_start = ?');
        $st->execute([$bucket, $start]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        error_log('installer_signin_failures: ' . $e->getMessage());
        return null;
    }
}

/** Add a note for each account whose name is reserved (Guest, or the FILEHOST owner of text uploads). */
function installer_reserved_name_notes(PDO $pdo, array &$notes): void
{
    $filehost = defined('FILEHOST_MEMBER') ? (string)FILEHOST_MEMBER : 'irc';
    try {
        $st = $pdo->prepare('SELECT username FROM users WHERE LOWER(username) IN (?, ?)');
        $st->execute(['guest', strtolower($filehost)]);
        $names = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }
    $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    foreach ($names as $name) {
        $name = (string)$name;
        if (strcasecmp($name, 'Guest') === 0) {
            $notes[] = '<strong>Check the account ' . $h($name) . '.</strong> Its name matches guest pastes and uploads, so it can no longer sign in. '
                . 'Delete it in Admin &gt; Users; guest pastes and uploads are kept.';
        } else {
            $notes[] = '<strong>Check the account ' . $h($name) . '.</strong> Its name owns IRC text uploads (FILEHOST_MEMBER). '
                . 'If you did not create it yourself, delete it in Admin &gt; Users; the IRC uploads are kept.';
        }
    }
}
