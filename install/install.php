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

date_default_timezone_set('UTC');

// Increase timeout for large installations
set_time_limit(300); // 5 minutes
ini_set('max_execution_time', '300');

// JSON response + clean buffers
ob_start();
header('Content-Type: application/json; charset=utf-8');

// Keep errors out of the output; log them instead
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('html_errors', '0');

// Log start
error_log("install.php: Starting installation process");

// warnings/notices and fatals during runtime will be wrapped below.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        error_log("install.php: Fatal error - " . $e['message'] . " in " . $e['file'] . ":" . $e['line']);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Fatal error: ' . htmlspecialchars($e['message'], ENT_QUOTES, 'UTF-8') .
                         ' in ' . htmlspecialchars(basename($e['file']), ENT_QUOTES, 'UTF-8') .
                         ':' . (int)$e['line']
        ]);
    }
});

// ---- Load config.php (and swallow accidental output/BOMs) ----
$config_file = '../config.php';
if (!file_exists($config_file)) {
    while (ob_get_level()) { ob_end_clean(); }
    echo json_encode(['status' => 'error', 'message' => 'config.php not found. Run configure.php first.']);
    exit;
}
ob_start();
try {
    require_once $config_file; // expects $dbhost,$dbname,$dbuser,$dbpassword,$enablegoog,$enablesmtp,$sec_key
} catch (Throwable $e) {
    while (ob_get_level()) { ob_end_clean(); }
    echo json_encode(['status' => 'error', 'message' => 'Failed to include config.php: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]);
    exit;
}
$noise = ob_get_clean();
if ($noise !== '') {
    error_log('install.php: config.php produced output (discarded).');
}

// ---- quick env checks for files we rely on (non-fatal for optional deps) ----
$critical_errors = [];
$warnings = [];

if (!file_exists('../theme/default/login.php')) {
    $critical_errors[] = 'Missing required file: ../theme/default/login.php';
}

$needsOAuth = !empty($enablegoog) && $enablegoog === 'yes';
if ($needsOAuth) {
    if (!file_exists('../oauth/google.php')) {
        $warnings[] = 'OAuth enabled but missing ../oauth/google.php';
    }
    if (!file_exists('../oauth/vendor/autoload.php')) {
        $warnings[] = 'OAuth enabled: Composer autoload missing in /oauth. Run: <code>cd oauth && composer require google/apiclient:^2.12 league/oauth2-client</code>';
    }
}
if (!empty($enablesmtp) && $enablesmtp === 'yes') {
    if (!file_exists('../mail/mail.php')) {
        $warnings[] = 'SMTP enabled but missing ../mail/mail.php';
    }
    if (!file_exists('../mail/vendor/autoload.php')) {
        $warnings[] = 'SMTP enabled: Composer autoload missing in /mail. Run: <code>cd mail && composer require phpmailer/phpmailer</code>';
    }
}
if ($critical_errors) {
    while (ob_get_level()) { ob_end_clean(); }
    echo json_encode(['status' => 'error', 'message' => implode('<br>', $critical_errors)]);
    exit;
}

// ---- read installer POST ----
$admin_user     = isset($_POST['admin_user']) ? trim((string)$_POST['admin_user']) : '';
$admin_pass_raw = isset($_POST['admin_pass']) ? (string)$_POST['admin_pass'] : '';
$admin_pass     = $admin_pass_raw !== '' ? password_hash($admin_pass_raw, PASSWORD_DEFAULT) : '';
$old_sec_key_in = isset($_POST['old_sec_key']) ? (string)$_POST['old_sec_key'] : '';
$img_choice     = in_array($_POST['img_enabled'] ?? '', ['yes', 'no', 'keep'], true) ? $_POST['img_enabled'] : 'keep';
$img_guests     = ($_POST['img_guests'] ?? '') === '1';
$img_encrypt    = ($_POST['img_encrypt'] ?? '') === '1';

$date = date('Y-m-d H:i:s');

// validate admin creds
if ($admin_user !== '' && !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $admin_user)) {
    while (ob_get_level()) { ob_end_clean(); }
    echo json_encode(['status' => 'error', 'message' => 'Username must be 3–50 chars: letters, digits, dot, underscore, dash.']);
    exit;
}
if ($admin_user === '' || $admin_pass_raw === '') {
    while (ob_get_level()) { ob_end_clean(); }
    echo json_encode(['status' => 'error', 'message' => 'Please provide both admin username and password.']);
    exit;
}

// derive baseurl (root, not /install/)
$base_path = rtrim(dirname($_SERVER['PHP_SELF'], 2), '/') . '/';
$scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
$host      = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
$baseurl   = $scheme . $host . $base_path;

// ---- DB connect ----
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/schema.php';
try {
    $pdo = installer_connect($dbhost, $dbname, $dbuser, $dbpassword);
} catch (PDOException $e) {
    while (ob_get_level()) { ob_end_clean(); }
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]);
    exit;
}

// An existing site can only be reinstalled after signing in, or straight after step one in this browser
installer_session_start();
if (installer_has_admin($pdo) && !installer_signed_in() && !installer_configured_here()) {
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => installer_locked_message()]);
    exit;
}

// ---------- Installation ----------
error_log("install.php: Beginning database schema installation");

try {
    $result = paste_install_schema($pdo, [
        'admin_user'     => $admin_user,
        'admin_pass'     => $admin_pass,
        'baseurl'        => $baseurl,
        'sec_key'        => $sec_key,
        'old_sec_key'    => $old_sec_key_in,
        'generate_slugs' => isset($_POST['generate_slugs']) && $_POST['generate_slugs'] === 'on',
        'img_choice'     => $img_choice,
        'img_guests'     => $img_guests,
        'img_encrypt'    => $img_encrypt,
    ]);
    $output = $result['output'];
    $errors = $result['errors'];

    // ---- finish ----
    $post = 'Installation and schema update completed successfully.<br>';
    if ($needsOAuth) {
        if (!empty($enablegoog) && $enablegoog === 'yes') {
            $post .= "Configure Google OAuth – Redirect URI: {$baseurl}oauth/google.php<br>Install deps: <code>cd oauth && composer require google/apiclient:^2.12 league/oauth2-client:^2.7</code><br>";
        }
    }
    if (!empty($enablesmtp) && $enablesmtp === 'yes') {
        $post .= "Configure Gmail SMTP OAuth – Redirect URI: {$baseurl}oauth/google_smtp.php<br>Install deps: <code>cd mail && composer require phpmailer/phpmailer:^6.9</code><br>";
    }
    if ((int)img_settings($pdo, true)['img_enabled'] === 1) {
        $uploadDir = filehost_storage_dir();
        $post .= 'Image and video uploads: <a href="' . htmlspecialchars($baseurl, ENT_QUOTES, 'UTF-8') . 'img/">/img</a>, stored in <code>'
            . htmlspecialchars($uploadDir, ENT_QUOTES, 'UTF-8') . '</code>.<br>';
        if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
            $warnings[] = 'The upload folder is missing or not writable by PHP. Create it and give the web server user write access.';
        }
        if ($scheme === 'http://') {
            $warnings[] = 'Browsers only encrypt uploads on HTTPS pages; over HTTP only public uploads work.';
        }
        $post .= 'On nginx, add the <code>/img</code> block from <code>docs/nginx.example.conf</code>.<br>';
    }
    installer_reserved_name_notes($pdo, $warnings);
    if ($warnings) { $post .= '<br>Notes: ' . implode('<br>', $warnings); }
    if ($errors)   { $post .= '<br>Warnings: ' . implode('<br>', $errors); }
    $post .= ' Remove <code>/install</code> and <code>chmod 600 config.php</code>. ';
    $post .= 'Go to <a href="../" class="btn btn-primary">main site</a> or <a href="../admin" class="btn btn-primary">dashboard</a>.';

    while (ob_get_level()) { ob_end_clean(); }
    echo json_encode(['status' => 'success', 'message' => implode('<br>', $output) . '<br>' . $post]);

} catch (Throwable $e) {
    error_log("install.php: Error - " . $e->getMessage());
    while (ob_get_level()) { ob_end_clean(); }
    echo json_encode(['status' => 'error', 'message' => 'Unexpected error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]);
} finally {
    try { $pdo = null; } catch (Throwable $e) {}
}
