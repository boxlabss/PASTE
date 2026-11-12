<?php
/*
 * Paste Installer https://github.com/boxlabss/PASTE
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License in LICENCE for more details.
 */

// Start output buffering
ob_start();

// Ensure JSON content type
header('Content-Type: application/json; charset=utf-8');

// Disable display errors
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// --- PHP Version Check (require 8.1+) ---
if (version_compare(PHP_VERSION, '8.1', '<')) {
    ob_end_clean();
    echo json_encode([
        'status'  => 'error',
        'message' => 'PHP 8.1 or higher is required. Current version: ' . PHP_VERSION
    ]);
    exit;
}

// Check required PHP extensions
$required_extensions = ['pdo_mysql', 'openssl', 'curl'];
$missing_required = array_filter($required_extensions, static fn($ext) => !extension_loaded($ext));
if (!empty($missing_required)) {
    ob_end_clean();
    error_log("configure.php: Missing required PHP extensions: " . implode(', ', $missing_required));
    echo json_encode([
        'status'  => 'error',
        'message' => 'Missing required PHP extensions: ' . implode(', ', $missing_required) . '. Enable them in php.ini.'
    ]);
    exit;
}

// Database details are used exactly as entered; config.php is written with var_export
$dbhost     = trim((string)($_POST['data_host'] ?? ''));
$dbname     = trim((string)($_POST['data_name'] ?? ''));
$dbuser     = trim((string)($_POST['data_user'] ?? ''));
$dbpassword = (string)($_POST['data_pass'] ?? '');

$enablegoog = (isset($_POST['enablegoog']) && $_POST['enablegoog'] === 'yes') ? 'yes' : 'no';
$enablesmtp = (isset($_POST['enablesmtp']) && $_POST['enablesmtp'] === 'yes') ? 'yes' : 'no';

$img_choice = in_array($_POST['img_enabled'] ?? '', ['yes', 'no', 'keep'], true) ? $_POST['img_enabled'] : 'keep';
$img_dir_in = trim((string)($_POST['img_dir'] ?? ''));

require_once __DIR__ . '/lib.php';

// An existing site, or a config.php whose database cannot be checked, needs an admin sign-in first
installer_session_start();
$existing = installer_existing('../config.php');
if ($existing['locked'] && !installer_signed_in()) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => installer_locked_message($existing)]);
    exit;
}

if (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $dbname)) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'The database name can only contain letters, numbers, underscores, hyphens and $.']);
    exit;
}
foreach (['host' => $dbhost, 'user' => $dbuser] as $field => $value) {
    if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
        ob_end_clean();
        echo json_encode(['status' => 'error', 'message' => "The database $field contains a control character such as a line break. Check it and try again."]);
        exit;
    }
}

if (empty($dbhost) || empty($dbname) || empty($dbuser)) {
    ob_end_clean();
    error_log("configure.php: Missing required database parameters");
    echo json_encode(['status' => 'error', 'message' => 'Please provide all required database information (host, database name, user).']);
    exit;
}

// Test database connection
try {
    $dsn = "mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbuser, $dbpassword, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    ob_end_clean();
    error_log("configure.php: Database connection failed: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]);
    exit;
}

// Calculate redirect URI for OAuth
$protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host      = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
$base_path = rtrim(dirname($_SERVER['SCRIPT_NAME'], 2), '/\\'); // Adjust for /install directory
$baseurl   = $protocol . $host . ($base_path ? $base_path . '/' : '/');
$redirect_uri  = $baseurl . 'oauth/google.php';
$https_warning = $enablegoog === 'yes' && $protocol === 'http://' ? 'Warning: OAuth is enabled without HTTPS. This is insecure and may cause issues with OAuth providers.' : '';

// --- File Permission Check for config.php and its directory ---
$config_file = '../config.php';
$parent_dir  = dirname($config_file);

// Try to guess web server user for help text only
$web_user = $_SERVER['USER']
    ?? getenv('APACHE_RUN_USER')
    ?? getenv('USER')
    ?? 'www-data';

// Helpers for POSIX info if available
$fmt_perms = static function (string $path): string {
    $st = @stat($path);
    return $st ? sprintf("%o", $st['mode'] & 0777) : '???';
};
$owner_name = static function (string $path): string {
    $st = @stat($path);
    if (!$st) return 'unknown';
    if (function_exists('posix_getpwuid')) {
        $pw = @posix_getpwuid($st['uid']);
        return $pw['name'] ?? (string)$st['uid'];
    }
    return (string)$st['uid'];
};
$group_name = static function (string $path): string {
    $st = @stat($path);
    if (!$st) return 'unknown';
    if (function_exists('posix_getgrgid')) {
        $gr = @posix_getgrgid($st['gid']);
        return $gr['name'] ?? (string)$st['gid'];
    }
    return (string)$st['gid'];
};

// Check for existing config.php and extract preserved settings
$existing_sec_key = null;
$existing_mod_rewrite = '0';
$existing_highlighter = 'highlight';
$existing_hl_style = 'hybrid.css';
$existing_comments_enabled = true;
$existing_comments_require_login = true;
$existing_comments_on_protected = false;
$existing_version = null;
$is_upgrade = false;

// FILEHOST_* and PASTE_* defines to carry over (IRC FILEHOST, upload folder, debug switches)
$preserved_defines = [];

// OAuth settings to preserve
$existing_g_client_id = '';
$existing_g_client_secret = '';
$existing_g_redirect_uri = '';
$existing_g_application_name = 'Paste';

if (file_exists($config_file)) {
    // Read existing config to preserve settings
    $existing_content = @file_get_contents($config_file);
    if ($existing_content !== false) {
        // Extract version
        if (preg_match('/\$currentversion\s*=\s*["\']?([0-9.]+)["\']?/', $existing_content, $m)) {
            $existing_version = $m[1];
            if (version_compare($existing_version, PASTE_VERSION, '<')) {
                $is_upgrade = true;
            }
        }
        // Extract sec_key (preserve existing encryption key!)
        // Supports: $sec_key = "abc123"; or $sec_key = 'abc123'; or $sec_key = abc123;
        if (preg_match('/\$sec_key\s*=\s*["\']?([a-f0-9]{64})["\']?\s*;/', $existing_content, $m)) {
            $existing_sec_key = $m[1];
        } elseif (preg_match("/define\s*\(\s*['\"]SECRET['\"]\s*,\s*['\"]([a-f0-9]{64})['\"]\s*\)/", $existing_content, $m)) {
            // Fallback: try to get from define('SECRET', 'xxx')
            $existing_sec_key = $m[1];
        }
        // Extract mod_rewrite
        // Supports: $mod_rewrite = "1"; or $mod_rewrite = '1'; or $mod_rewrite = 1;
        if (preg_match('/\$mod_rewrite\s*=\s*["\']?([01])["\']?\s*;/', $existing_content, $m)) {
            $existing_mod_rewrite = $m[1];
        }
        // Extract highlighter
        if (preg_match('/\$highlighter\s*=.*["\']?(highlight|geshi)["\']?/', $existing_content, $m)) {
            $existing_highlighter = $m[1];
        }
        // Extract hl_style
        if (preg_match('/\$hl_style\s*=\s*["\']([^"\']+)["\']/', $existing_content, $m)) {
            $existing_hl_style = $m[1];
        }
        // Extract comment settings
        if (preg_match('/\$comments_enabled\s*=\s*(true|false)/', $existing_content, $m)) {
            $existing_comments_enabled = $m[1] === 'true';
        }
        if (preg_match('/\$comments_require_login\s*=\s*(true|false)/', $existing_content, $m)) {
            $existing_comments_require_login = $m[1] === 'true';
        }
        if (preg_match('/\$comments_on_protected\s*=\s*(true|false)/', $existing_content, $m)) {
            $existing_comments_on_protected = $m[1] === 'true';
        }
        // Extract OAuth settings - simple patterns that match the actual config format
        // Format: define('G_CLIENT_ID', 'value');
        if (preg_match("/define\('G_CLIENT_ID',\s*'([^']*)'\)/", $existing_content, $m)) {
            $existing_g_client_id = $m[1];
        }
        if (preg_match("/define\('G_CLIENT_SECRET',\s*'([^']*)'\)/", $existing_content, $m)) {
            $existing_g_client_secret = $m[1];
        }
        if (preg_match("/define\('G_REDIRECT_URI',\s*'([^']*)'\)/", $existing_content, $m)) {
            $existing_g_redirect_uri = $m[1];
        }
        if (preg_match("/define\('G_APPLICATION_NAME',\s*'([^']*)'\)/", $existing_content, $m)) {
            $existing_g_application_name = $m[1];
        }

        $preserved_defines = installer_config_defines($existing_content, ['FILEHOST_', 'PASTE_']);
    }

    if (!is_writable($config_file)) {
        ob_end_clean();
        error_log("configure.php: config.php exists but is not writable (owner: {$owner_name($config_file)}, group: {$group_name($config_file)}, perms: {$fmt_perms($config_file)})");
        echo json_encode([
            'status'  => 'error',
            'message' => "config.php exists but is not writable.<br>Run: <code>chmod 664 " .
                htmlspecialchars($config_file, ENT_QUOTES, 'UTF-8') .
                "</code> or adjust ownership: <code>chown $web_user " .
                htmlspecialchars($config_file, ENT_QUOTES, 'UTF-8') . "</code>"
        ]);
        exit;
    }
} else {
    if (!is_dir($parent_dir) || !is_writable($parent_dir)) {
        ob_end_clean();
        error_log("configure.php: Parent directory not writable: $parent_dir (owner: {$owner_name($parent_dir)}, group: {$group_name($parent_dir)}, perms: {$fmt_perms($parent_dir)})");
        echo json_encode([
            'status'  => 'error',
            'message' => "Cannot create <code>config.php</code> in <code>" .
                htmlspecialchars($parent_dir, ENT_QUOTES, 'UTF-8') .
                "</code>. Grant the web server write access to the directory.<br>" .
                "Example:<br><code>chmod 775 " . htmlspecialchars($parent_dir, ENT_QUOTES, 'UTF-8') .
                "</code><br><code>chown $web_user " . htmlspecialchars($parent_dir, ENT_QUOTES, 'UTF-8') . "</code>"
        ]);
        exit;
    }
}

// Use existing sec_key if available, otherwise generate new one
if ($existing_sec_key !== null) {
    $sec_key = $existing_sec_key;
} else {
    $sec_key = bin2hex(random_bytes(32));
}

// Use preserved settings
$mod_rewrite = $existing_mod_rewrite;
$highlighter = $existing_highlighter;
$hl_style = $existing_hl_style;
$comments_enabled_str = $existing_comments_enabled ? 'true' : 'false';
$comments_require_login_str = $existing_comments_require_login ? 'true' : 'false';
$comments_on_protected_str = $existing_comments_on_protected ? 'true' : 'false';

// Use preserved OAuth settings or defaults
$g_client_id = $existing_g_client_id;
$g_client_secret = $existing_g_client_secret;
$g_redirect_uri = $existing_g_redirect_uri ?: $redirect_uri;
$g_application_name = $existing_g_application_name;

// Upload storage folder (FILEHOST_DIR)
$upload_notes = [];
if ($img_choice === 'yes' && $img_dir_in !== '') {
    $upload_define = installer_upload_dir_define($img_dir_in, $web_user, $upload_notes);
    if ($upload_define === null) {
        ob_end_clean();
        echo json_encode(['status' => 'error', 'message' => implode('<br>', $upload_notes)]);
        exit;
    }
    $preserved_defines['FILEHOST_DIR'] = $upload_define;
}
$version_php = installer_version_literal(PASTE_VERSION);
$defines_block = $preserved_defines
    ? implode("\n", $preserved_defines)
    : "// define('FILEHOST_DIR', '/path/outside/the/web/root/filehost_files');";

// Database settings as PHP literals, so quotes and $ in passwords stay intact
$dbhost_php     = var_export($dbhost, true);
$dbuser_php     = var_export($dbuser, true);
$dbpassword_php = var_export($dbpassword, true);
$dbname_php     = var_export($dbname, true);

// Build config.php content
$config_content = <<<EOD
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License in LICENCE for more details.
 */
\$devmode = 0; // Only enabled for demo site
\$currentversion = $version_php;
\$pastelimit = "10"; // 10 MB

// OAuth settings (for signups)
\$enablegoog = "$enablegoog";
\$enablesmtp = "$enablesmtp";

define('G_CLIENT_ID', '$g_client_id');
define('G_CLIENT_SECRET', '$g_client_secret');
define('G_REDIRECT_URI', '$g_redirect_uri');
define('G_APPLICATION_NAME', '$g_application_name');
define('G_SCOPES', [
    'https://www.googleapis.com/auth/userinfo.profile',
    'https://www.googleapis.com/auth/userinfo.email'
]);

// Database information
\$dbhost = $dbhost_php;
\$dbuser = $dbuser_php;
\$dbpassword = $dbpassword_php;
\$dbname = $dbname_php;

// Secret key for encryption
\$sec_key = "$sec_key";
define('SECRET', \$sec_key);

// set to 1 to enable tidy urls
// see docs for an example nginx conf, or .htaccess
\$mod_rewrite = "$mod_rewrite";

// Enable SMTP debug logging (uncomment)
// define('SMTP_DEBUG', true);

// Code highlighting engine for non-Markdown pastes: 'highlight' (highlight.php - default) or 'geshi'
\$highlighter = \$highlighter ?? '$highlighter';

// Style theme for highlighter.php (see includes/Highlight/styles)
\$hl_style = '$hl_style';

// Comments
\$comments_enabled          = $comments_enabled_str;   // on/off
\$comments_require_login    = $comments_require_login_str;   // if false, guests can comment
\$comments_on_protected     = $comments_on_protected_str;  // allow/show comments on password-protected pastes

// Upload storage (/img and FILEHOST); keep it outside the web root. See docs/IMG.md and docs/FILEHOST.md
$defines_block

/**
 * Build the list of selectable formats
 * - When using highlight.php, we get the json language files from includes/Highlight/languages
 * - When using GeSHi, we get from includes/geshi/*.php
 */
require_once __DIR__ . '/includes/list_languages.php';

\$popular_formats = []; // set below

if (\$highlighter === 'highlight') {
    \$langs        = highlight_supported_languages();
    \$geshiformats = highlight_language_map(\$langs);   // id => label
    \$HL_ALIAS_MAP = highlight_alias_map(\$langs);      // alias => id
    \$popular_formats = paste_popular_formats_highlight();
} else {
    \$geshiformats = ['autodetect' => 'Autodetect', 'markdown' => 'Markdown', 'text' => 'Plain Text']
                  + geshi_language_map();
    \$HL_ALIAS_MAP = geshi_alias_map(\$geshiformats);   // alias => id
    \$popular_formats = paste_popular_formats_geshi();
}
?>
EOD;

// Refuse to write a config.php that would not parse
$parse_error = null;
if (!installer_php_parses($config_content, $parse_error)) {
    ob_end_clean();
    error_log('configure.php: generated config.php does not parse: ' . $parse_error);
    echo json_encode([
        'status'  => 'error',
        'message' => 'The new config.php would not be valid PHP (' . htmlspecialchars((string)$parse_error, ENT_QUOTES, 'UTF-8') . '). config.php was not changed.'
    ]);
    exit;
}

// Write config.php
if (file_put_contents($config_file, $config_content, LOCK_EX) === false) {
    ob_end_clean();
    error_log("configure.php: Failed to write config.php");
    echo json_encode([
        'status'  => 'error',
        'message' => "Failed to write config.php.<br>Ensure the directory is writable.<br>Example:<br><code>chmod 775 " .
            htmlspecialchars($parent_dir, ENT_QUOTES, 'UTF-8') . "</code><br><code>chown $web_user " .
            htmlspecialchars($parent_dir, ENT_QUOTES, 'UTF-8') . "</code>"
    ]);
    exit;
}

// Set config.php permissions (owner read/write only)
@chmod($config_file, 0600);
installer_forget_cached($config_file);
$_SESSION['installer_configured_until'] = time() + 3600;

// Prepare success message
$success_message = '';
if ($is_upgrade) {
    $success_message .= '<strong>Upgrade detected!</strong> Upgrading from v' . htmlspecialchars((string)$existing_version, ENT_QUOTES, 'UTF-8') . ' to v' . PASTE_VERSION . '.<br>';
    if ($existing_sec_key !== null) {
        $success_message .= 'Your encryption key has been preserved (existing pastes will remain readable).<br>';
    }
    $success_message .= 'Your settings (mod_rewrite, highlighter, comments) have been preserved.<br><br>';
}
$success_message .= 'Configuration saved successfully.<br>';
$kept = array_diff(array_keys($preserved_defines), $img_choice === 'yes' && $img_dir_in !== '' ? ['FILEHOST_DIR'] : []);
if ($kept) {
    $success_message .= 'Kept from your previous config.php: <code>' . htmlspecialchars(implode(', ', $kept), ENT_QUOTES, 'UTF-8') . '</code><br>';
}
if ($img_choice === 'yes') {
    $success_message .= 'Image and video uploads will be turned on in the next step.<br>';
    foreach ($upload_notes as $note) {
        $success_message .= $note . '<br>';
    }
}
if ($enablegoog === 'yes') {
    $success_message .= 'Install OAuth dependencies: <code>cd oauth && composer require google/apiclient:^2.12 league/oauth2-client</code><br>Ensure HTTPS is enabled for secure OAuth redirects.';
}
if ($enablesmtp === 'yes') {
    $success_message .= 'SMTP enabled. Install SMTP dependencies: <code>cd mail && composer require phpmailer/phpmailer</code><br>Configure SMTP settings in admin panel after installation.<br>';
}
if ($https_warning) {
    $success_message .= $https_warning . '<br>';
}
$success_message .= 'Ensure HTTPS is enabled for secure OAuth redirects.';

// Clean output buffer and send success response
ob_end_clean();
echo json_encode([
    'status'  => 'success',
    'message' => $success_message
]);
