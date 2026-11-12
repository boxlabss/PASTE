<?php
/*
 * Paste https://github.com/boxlabss/PASTE
 *
 * "Keep me signed in": a cookie that signs an account back in on this device
 * while the account's token is unchanged.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */
declare(strict_types=1);

/** Name of the "Keep me signed in" cookie. */
const PASTE_REMEMBER_COOKIE = 'paste_remember';

/** "Keep me signed in" cookie value: valid while the account's token is unchanged, so a password change or reset ends it. */
function paste_remember_value(int $id, string $token): ?string
{
    if (!defined('SECRET') || (string)SECRET === '' || $token === '') return null;
    return $id . '.' . hash_hmac('sha256', 'remember|' . $id . '|' . $token, (string)SECRET);
}

/** Set the "Keep me signed in" cookie for 30 days, or clear it with null. */
function paste_remember_cookie(?string $value): void
{
    if (headers_sent()) return;
    setcookie(PASTE_REMEMBER_COOKIE, $value ?? '', [
        'expires'  => $value === null ? time() - 3600 : time() + 30 * 86400,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Sign a visitor back in from a valid "Keep me signed in" cookie; clears a cookie that no longer matches. */
function paste_restore_remembered(PDO $pdo): void
{
    $cookie = (string)($_COOKIE[PASTE_REMEMBER_COOKIE] ?? '');
    $id = (int)strstr($cookie, '.', true);
    try {
        $stmt = $pdo->prepare('SELECT id, username, verified, token, oauth_uid FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return;
    }
    $expected = $row ? paste_remember_value((int)$row['id'], (string)$row['token']) : null;
    if ($expected === null || !hash_equals($expected, $cookie) || (string)$row['verified'] !== '1') {
        paste_remember_cookie(null);
        return;
    }
    session_regenerate_id(true);
    $_SESSION['token'] = (string)$row['token'];
    $_SESSION['oauth_uid'] = $row['oauth_uid'];
    $_SESSION['username'] = (string)$row['username'];
}
