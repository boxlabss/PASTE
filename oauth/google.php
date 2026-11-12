<?php
/*
 * OAuth2 user integration for Google
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
ob_start(); // start output buffering to avoid header issues
session_start([
    'cookie_secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'use_strict_mode' => true,
]);

// include config (DB creds, OAuth constants)
require_once '../config.php';
require_once __DIR__ . '/../includes/remember.php';
require_once __DIR__ . '/../includes/account.php';

// fetch $baseurl from DB (site_info)
try {
    $pdo = new PDO("mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpassword);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->prepare("SELECT baseurl FROM site_info WHERE id = 1");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $baseurl = $result['baseurl'] ?? '';
} catch (PDOException $e) {
    error_log("google.php: Failed to fetch baseurl from site_info: " . $e->getMessage());
    // fallback: build baseurl manually
    $base_path = rtrim(dirname($_SERVER['PHP_SELF'], 2), '/') . '/';
    $baseurl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['SERVER_NAME'] . $base_path;
}

// force trailing slash on baseurl
$baseurl = rtrim($baseurl, '/') . '/';

// load composer autoload for OAuth lib
require_once '../oauth/vendor/autoload.php';

use League\OAuth2\Client\Provider\Google;

// check required OAuth constants exist
if (!defined('G_CLIENT_ID') || !defined('G_CLIENT_SECRET') || !defined('G_REDIRECT_URI')) {
    error_log("google.php: Google OAuth constants not defined in config.php");
    header('Location: ' . $baseurl . 'login.php?error=' . urlencode('OAuth configuration error'));
    exit;
}

// init Google OAuth provider
$provider = new Google([
    'clientId'     => G_CLIENT_ID,
    'clientSecret' => G_CLIENT_SECRET,
    'redirectUri'  => G_REDIRECT_URI,
    'accessType'   => 'offline',
    'scopes'       => G_SCOPES,
]);

// start OAuth login
if (isset($_GET['login']) && $_GET['login'] === '1') {
    $authUrl = $provider->getAuthorizationUrl(['prompt' => 'select_account']);
    $_SESSION['oauth2state'] = $provider->getState(); // CSRF protection
    if (ob_get_length()) { ob_end_clean(); }
    header('Location: ' . $authUrl);
    exit;
}

// handle OAuth callback
if (isset($_GET['code']) && isset($_GET['state']) && isset($_SESSION['oauth2state']) && $_GET['state'] === $_SESSION['oauth2state']) {
    try {
        // exchange code for token
        $accessToken = $provider->getAccessToken('authorization_code', ['code' => $_GET['code']]);
        $resourceOwner = $provider->getResourceOwner($accessToken);
        $user = $resourceOwner->toArray();

        // extract user data; only an address Google has verified may sign in to an account
        $email = (string)($user['email'] ?? '');
        if ($email === '' || !in_array($user['email_verified'] ?? false, [true, 'true'], true)) {
            throw new RuntimeException('Google did not confirm this email address');
        }
        $name = $user['name'] ?? strstr($email, '@', true);
        $oauth_uid = (string)$resourceOwner->getId(); // google user id

        // check if user already exists by email
        $stmt = $pdo->prepare("SELECT id, username, token, password, oauth_uid FROM users WHERE email_id = ?");
        $stmt->execute([$email]);
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
        $dest = $baseurl;

        if ($existingUser) {
            // The first Google sign-in to an account with a password takes the account over:
            // its password, its other sessions and its API keys end
            $takeover = (string)($existingUser['oauth_uid'] ?? '') !== $oauth_uid
                && (string)($existingUser['password'] ?? '') !== '';

            // update session; devices share the account's token
            $_SESSION['token'] = (string)($existingUser['token'] ?? '') !== '' && !$takeover
                ? (string)$existingUser['token'] : bin2hex(random_bytes(32));
            $_SESSION['username'] = $existingUser['username'];
            $_SESSION['id'] = $existingUser['id'];
            $_SESSION['platform'] = 'Google';
            $stmt = $pdo->prepare("UPDATE users SET token = ?, oauth_uid = ?, platform = ?, verified = IF(verified = '0', '1', verified)"
                . ($takeover ? ", password = ''" : '') . " WHERE id = ?");
            $stmt->execute([$_SESSION['token'], $oauth_uid, 'Google', $existingUser['id']]);
            if ($takeover) {
                try {
                    $pdo->prepare("DELETE FROM api_keys WHERE user_id = ?")->execute([$existingUser['id']]);
                } catch (PDOException $e) {
                    // api_keys table not created yet
                }
                $_SESSION['profile_notice'] = 'You signed in with Google, so this account\'s password was removed, and its other sessions '
                    . 'and API keys were ended. Set a new password below if you want one.';
                $dest = $baseurl . 'profile.php';
            }
        } else {
            // create new OAuth user with randomised username
            do {
                $username = strstr($email, '@', true) . '_' . bin2hex(random_bytes(2));
            } while (paste_username_taken($pdo, $username));
            $token = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare("INSERT INTO users (oauth_uid, username, email_id, full_name, platform, token, verified, username_locked, date, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $oauth_uid,
                $username,
                $email,
                $name,
                'Google',
                $token,
                '1',       // email verified
                '0',       // username_locked = false so user can change it once
                date('Y-m-d H:i:s'),
                $_SERVER['REMOTE_ADDR']
            ]);
            $_SESSION['token'] = $token;
            $_SESSION['username'] = $username;
            $_SESSION['id'] = $pdo->lastInsertId();
            $_SESSION['platform'] = 'Google';
        }

        // new session id for the signed-in session, kept signed in, clear oauth state + redirect
        session_regenerate_id(true);
        paste_remember_cookie(paste_remember_value($existingUser ? (int)$existingUser['id'] : (int)$_SESSION['id'], (string)$_SESSION['token']));
        unset($_SESSION['oauth2state']);
        header('Location: ' . $dest);
        exit;

    } catch (Exception $e) {
        error_log("google.php: OAuth error: " . $e->getMessage());
        header('Location: ' . $baseurl . 'login.php?error=' . urlencode('OAuth error'));
        exit;
    }
}
// if Google returned error
elseif (isset($_GET['error'])) {
    error_log("google.php: OAuth error from Google: " . $_GET['error']);
    header('Location: ' . $baseurl . 'login.php?error=' . urlencode('OAuth error'));
    exit;
}

// default redirect back to login
header('Location: ' . $baseurl . 'login.php');
exit;

ob_end_flush(); // flush output buffer
