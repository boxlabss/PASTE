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
session_start([
    'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'use_strict_mode' => true,
]);
// Initialize CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// Initialize OAuth2 state for Google and other platforms
if (isset($_GET['login']) && $_GET['login'] === 'google' && !isset($_SESSION['oauth2_state'])) {
    $_SESSION['oauth2_state'] = bin2hex(random_bytes(16));
}
// Captcha settings: the public ones are kept in the session for a minute; the secret keys never are
require_once 'config.php';

/** The captcha settings row, secret keys included, read at most once per request. */
function paste_captcha_settings(): array
{
    static $row = null;
    if ($row !== null) return $row;
    global $dbhost, $dbname, $dbuser, $dbpassword;
    try {
        $db = ($GLOBALS['pdo'] ?? null) instanceof PDO ? $GLOBALS['pdo']
            : new PDO("mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        $row = $db->query("SELECT cap_e, mode, recaptcha_version, recaptcha_sitekey, recaptcha_secretkey, turnstile_sitekey, turnstile_secretkey
                           FROM captcha WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log("session.php: Failed to fetch captcha settings: " . $e->getMessage());
        $row = [];
    }
    return $row;
}

/** A captcha secret key ('recaptcha' or 'turnstile') for verifying a token. */
function paste_captcha_secret(string $which): string
{
    return trim((string)(paste_captcha_settings()[$which . '_secretkey'] ?? ''));
}

unset($_SESSION['recaptcha_secretkey'], $_SESSION['turnstile_secretkey']);
if ((int)($_SESSION['captcha_settings_timestamp'] ?? 0) < time() - 60 || !isset($_SESSION['cap_e'])) {
    $captcha_settings = paste_captcha_settings();
    $_SESSION['cap_e'] = $captcha_settings['cap_e'] ?? 'off';
    $_SESSION['mode'] = $captcha_settings['mode'] ?? 'normal';
    $_SESSION['recaptcha_version'] = $captcha_settings['recaptcha_version'] ?? 'v2';
    $_SESSION['recaptcha_sitekey'] = $captcha_settings['recaptcha_sitekey'] ?? '';
    $_SESSION['turnstile_sitekey'] = $captcha_settings['turnstile_sitekey'] ?? '';
    $_SESSION['captcha_settings_timestamp'] = time();
    if ($_SESSION['cap_e'] === 'on') {
        if ($_SESSION['mode'] === 'reCAPTCHA') {
            $_SESSION['captcha_mode'] = ($_SESSION['recaptcha_version'] === 'v3') ? 'recaptcha_v3' : 'recaptcha';
            $_SESSION['captcha'] = $_SESSION['recaptcha_sitekey'];
        } elseif ($_SESSION['mode'] === 'turnstile') {
            $_SESSION['captcha_mode'] = 'turnstile';
            $_SESSION['captcha'] = $_SESSION['turnstile_sitekey'];
        } else {
            $_SESSION['captcha_mode'] = 'internal';
            $_SESSION['captcha'] = null;
        }
    } else {
        $_SESSION['captcha_mode'] = 'none';
        $_SESSION['captcha'] = null;
    }
    unset($captcha_settings);
}
