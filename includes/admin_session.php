<?php
/*
 * Paste https://github.com/boxlabss/PASTE
 *
 * Admin session: the public site's cookie settings, and the form token helpers.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/** The session's form token. */
function admin_csrf_token(): string
{
    return (string)$_SESSION['csrf_token'];
}

/** True if the request carries the form token, as a POST field or in the query string of an action link. */
function admin_csrf_ok(): bool
{
    $sent = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    return is_string($sent) && hash_equals(admin_csrf_token(), $sent);
}

/** Hidden form field carrying the token. */
function admin_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}
