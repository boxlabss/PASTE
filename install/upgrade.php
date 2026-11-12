<?php
/*
 * Paste Installer https://github.com/boxlabss/PASTE
 *
 * Upgrades an existing installation in place: brings the database up to
 * date and sets the version in config.php. Pastes, users, admin accounts,
 * settings, pages and the rest of config.php are kept.
 *
 *   Web: POSTed by the installer page (action=signin, then action=upgrade).
 *   CLI: php install/upgrade.php [--no-slugs] [--old-sec-key=KEY]
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */
declare(strict_types=1);

$cli = PHP_SAPI === 'cli';
date_default_timezone_set('UTC');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (!$cli) {
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    set_time_limit(300);
}

require_once __DIR__ . '/lib.php';

/** Send the result (JSON on the web, text on the command line) and exit. */
function upgrade_reply(bool $ok, string $message, int $status = 200): never
{
    global $cli;
    if ($cli) {
        $text = str_replace(['<br>', '<ul>', '<li>', '</li>', '</ul>'], ["\n", "\n", '  - ', "\n", ''], $message);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
        fwrite($ok ? STDOUT : STDERR, trim((string)preg_replace("/\n{3,}/", "\n\n", $text)) . "\n");
        exit($ok ? 0 : 1);
    }
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code($status);
    echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $message]);
    exit;
}

register_shutdown_function(static function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        upgrade_reply(false, 'Fatal error: ' . htmlspecialchars($e['message'], ENT_QUOTES, 'UTF-8')
            . ' in ' . htmlspecialchars(basename($e['file']), ENT_QUOTES, 'UTF-8') . ':' . (int)$e['line'], 500);
    }
});

$configFile = dirname(__DIR__) . '/config.php';
$existing = installer_existing($configFile);
if (!$existing['config']) {
    upgrade_reply(false, 'config.php was not found, so there is nothing to upgrade. Run the installer instead.', 404);
}
if ($existing['error'] !== null) {
    upgrade_reply(false, htmlspecialchars($existing['error'], ENT_QUOTES, 'UTF-8'), 503);
}
if (!$existing['installed']) {
    upgrade_reply(false, 'The database has no admin account yet, so there is nothing to upgrade. Run the installer instead.', 409);
}
$pdo  = $existing['pdo'];
$vars = $existing['vars'];

/* ---- Options and access ---- */
if ($cli) {
    $args = array_slice($argv ?? [], 1);
    $unknown = array_filter($args, static fn($a) => $a !== '--no-slugs' && !str_starts_with($a, '--old-sec-key='));
    if ($unknown) {
        upgrade_reply(false, "Unknown option: " . implode(' ', $unknown) . "\nUsage: php install/upgrade.php [--no-slugs] [--old-sec-key=KEY]");
    }
    if (!$existing['upgradable']) {
        upgrade_reply(false, (string)$existing['reason'], 409);
    }
    $opts = [
        'generate_slugs' => !in_array('--no-slugs', $args, true),
        'old_sec_key'    => '',
        'img_choice'     => 'keep',
        'img_dir'        => '',
    ];
    foreach ($args as $a) {
        if (str_starts_with($a, '--old-sec-key=')) $opts['old_sec_key'] = substr($a, 14);
    }
} else {
    installer_session_start();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        upgrade_reply(false, 'Use the installer page to upgrade.', 405);
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'signin') {
        // Five wrong sign-ins per IP address every ten minutes; per browser session when they cannot be counted
        $fails = array_filter((array)($_SESSION['installer_fails'] ?? []), static fn($t) => $t > time() - 600);
        $ipFails = installer_signin_failures($pdo);
        if (($ipFails ?? count($fails)) >= 5) {
            upgrade_reply(false, 'Too many failed sign-ins. Wait ten minutes and try again.', 429);
        }
        $user = trim((string)($_POST['admin_user'] ?? ''));
        $pass = (string)($_POST['admin_pass'] ?? '');
        $check = $user === '' || $pass === '' ? 'wrong' : installer_check_admin($pdo, $user, $pass);
        if ($check === 'wrong') {
            $fails[] = time();
            $_SESSION['installer_fails'] = array_values($fails);
            installer_signin_failures($pdo, true);
            sleep(1);
            upgrade_reply(false, 'That admin username or password is not right.', 403);
        }
        if ($check === 'limited') {
            upgrade_reply(false, 'The installer needs an admin with full access. This account can only open some sections of the admin panel.', 403);
        }
        session_regenerate_id(true);
        $_SESSION['installer_fails'] = [];
        $_SESSION['installer_admin_until'] = time() + INSTALLER_SIGNIN_TTL;
        upgrade_reply(true, 'Signed in.');
    }

    if ($action !== 'upgrade') {
        upgrade_reply(false, 'Unknown request.', 400);
    }
    if (!$existing['upgradable']) {
        upgrade_reply(false, (string)$existing['reason'], 409);
    }
    if (!installer_signed_in()) {
        upgrade_reply(false, 'Sign in again: your installer sign-in has expired.', 403);
    }
    $img = (string)($_POST['img_enabled'] ?? 'keep');
    $opts = [
        'generate_slugs' => ($_POST['generate_slugs'] ?? '') === 'on',
        'old_sec_key'    => (string)($_POST['old_sec_key'] ?? ''),
        'img_choice'     => in_array($img, ['yes', 'no', 'keep'], true) ? $img : 'keep',
        'img_guests'     => ($_POST['img_guests'] ?? '') === '1',
        'img_encrypt'    => ($_POST['img_encrypt'] ?? '') === '1',
        'img_dir'        => trim((string)($_POST['img_dir'] ?? '')),
    ];
}

/* ---- Upgrade ---- */
require_once __DIR__ . '/schema.php';
try {
    $pdo = installer_connect((string)$vars['dbhost'], (string)$vars['dbname'], (string)$vars['dbuser'], (string)($vars['dbpassword'] ?? ''));
} catch (PDOException $e) {
    upgrade_reply(false, 'Database connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'), 503);
}

// The sitemap and functions.php read these as globals
foreach (['dbhost', 'dbname', 'dbuser', 'dbpassword', 'mod_rewrite'] as $k) {
    $GLOBALS[$k] = $vars[$k] ?? '';
}
try {
    $GLOBALS['baseurl'] = (string)($pdo->query('SELECT baseurl FROM site_info WHERE id = 1')->fetchColumn() ?: '');
} catch (Throwable $e) {
    $GLOBALS['baseurl'] = '';
}

$from = $existing['version'] ?? 'unknown';
$notes = [];
$uploadDefine = null;
if ($opts['img_choice'] === 'yes' && $opts['img_dir'] !== '') {
    $webUser = (string)($_SERVER['USER'] ?? getenv('APACHE_RUN_USER') ?: getenv('USER') ?: 'www-data');
    $uploadDefine = installer_upload_dir_define($opts['img_dir'], $webUser, $notes);
}

try {
    $result = paste_install_schema($pdo, [
        'upgrade'        => true,
        'sec_key'        => (string)($vars['sec_key'] ?? ''),
        'old_sec_key'    => $opts['old_sec_key'],
        'generate_slugs' => $opts['generate_slugs'],
        'img_choice'     => $opts['img_choice'],
        'img_guests'     => $opts['img_guests'] ?? false,
        'img_encrypt'    => $opts['img_encrypt'] ?? false,
    ]);
} catch (Throwable $e) {
    error_log('upgrade.php: ' . $e->getMessage());
    upgrade_reply(false, 'The database upgrade stopped with an error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
        . '<br>config.php was not changed. Fix the problem and run the upgrade again; it carries on from where it stopped.', 500);
}

installer_update_config($configFile, PASTE_VERSION, $uploadDefine, $notes);
installer_reserved_name_notes($pdo, $notes);

/* ---- Report ---- */
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$msg = version_compare($from, PASTE_VERSION, '<')
    ? '<strong>Upgraded Paste from ' . $h($from) . ' to ' . $h(PASTE_VERSION) . '.</strong><br>'
    : '<strong>Paste ' . $h(PASTE_VERSION) . ': database checked.</strong><br>';
if ($result['output']) {
    $msg .= 'Changes:<ul><li>' . implode('</li><li>', $result['output']) . '</li></ul>';
} else {
    $msg .= 'The database was already up to date.<br>';
}
if ($result['errors']) {
    $msg .= 'Warnings:<ul><li>' . implode('</li><li>', $result['errors']) . '</li></ul>';
}
foreach ($notes as $n) {
    $msg .= $n . '<br>';
}
if (!$cli) {
    unset($_SESSION['installer_admin_until']);
    $msg .= '<br>Remove the <code>/install</code> folder now. '
        . '<a href="../" class="btn btn-primary btn-sm">Main site</a> <a href="../admin/" class="btn btn-primary btn-sm">Dashboard</a>';
}
upgrade_reply(true, $msg);
