<?php
/*
 * session variables
 *
 * Paste $v3.1 2025/08/16 https://github.com/boxlabss/PASTE
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
    'use_strict_mode' => true,
]);
// Initialize CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// Initialize OAuth2 state for Google and other platforms
if (isset($_GET['login']) && in_array($_GET['login'], ['google', 'facebook']) && !isset($_SESSION['oauth2_state'])) {
    $_SESSION['oauth2_state'] = bin2hex(random_bytes(16));
}
// Initialize CAPTCHA settings
require_once 'config.php';
try {
    $pdo = new PDO("mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $stmt = $pdo->query("SELECT cap_e, mode, recaptcha_version, recaptcha_sitekey, recaptcha_secretkey, turnstile_sitekey, turnstile_secretkey FROM captcha WHERE id = 1");
    $captcha_settings = $stmt->fetch() ?: [];
    $_SESSION['cap_e'] = $captcha_settings['cap_e'] ?? 'off';
    $_SESSION['mode'] = $captcha_settings['mode'] ?? 'normal';
    $_SESSION['recaptcha_version'] = $captcha_settings['recaptcha_version'] ?? 'v2';
    $_SESSION['recaptcha_sitekey'] = $captcha_settings['recaptcha_sitekey'] ?? '';
    $_SESSION['recaptcha_secretkey'] = $captcha_settings['recaptcha_secretkey'] ?? '';
    $_SESSION['turnstile_sitekey'] = $captcha_settings['turnstile_sitekey'] ?? '';
    $_SESSION['turnstile_secretkey'] = $captcha_settings['turnstile_secretkey'] ?? '';
    $_SESSION['captcha_settings_timestamp'] = time();
    /*
     * Determine the unified captcha mode and value.
     */
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
} catch (PDOException $e) {
    error_log("session.php: Failed to fetch captcha settings: " . $e->getMessage());
    $_SESSION['cap_e'] = 'off';
    $_SESSION['mode'] = 'normal';
    $_SESSION['recaptcha_version'] = 'v2';
    $_SESSION['recaptcha_sitekey'] = '';
    $_SESSION['recaptcha_secretkey'] = '';
    $_SESSION['turnstile_sitekey'] = '';
    $_SESSION['turnstile_secretkey'] = '';
    $_SESSION['captcha_mode'] = 'none';
    $_SESSION['captcha'] = null;
    $_SESSION['captcha_settings_timestamp'] = time();
} finally {
    $pdo = null;
}