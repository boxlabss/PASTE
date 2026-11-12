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
ob_start();
$__clean = function () {
    if (ob_get_level() > 0 && ob_get_length() !== false) { @ob_clean(); }
};
require_once 'includes/session.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once 'config.php';
require_once 'includes/functions.php';
require_once 'mail/mail.php';
require_once 'includes/recaptcha.php';
require_once 'includes/turnstile.php';

$error = null;
$success = null;

// Flash messages carried across the redirect after a successful POST (so a refresh never resends a spent captcha token)
if (!empty($_SESSION['flash_success'])) { $success = (string)$_SESSION['flash_success']; }
if (!empty($_SESSION['flash_error']))   { $error   = (string)$_SESSION['flash_error']; }
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$__redirect_flash = function (string $dest, ?string $succ = null, ?string $err = null) use ($__clean) {
    if ($succ !== null && $succ !== '') { $_SESSION['flash_success'] = $succ; }
    if ($err  !== null && $err  !== '') { $_SESSION['flash_error']   = $err; }
    $__clean();
    header('Location: ' . $dest, true, 303);
    exit;
};
// OAuth deps (soft)
$oauth_ready = true;
$oauth_autoloader = __DIR__ . '/oauth/vendor/autoload.php';
if (!file_exists($oauth_autoloader)) {
    $oauth_ready = false;
} else {
    require_once $oauth_autoloader;
    if (!class_exists('League\OAuth2\Client\Provider\Google')) $oauth_ready = false;
}

// ensure defined for header.php
$enablegoog = $enablegoog ?? 'no';

if (!$oauth_ready) { $enablegoog = 'no'; }

// DB (PDO)
try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        $pdo = new PDO("mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
} catch (Throwable $e) {
    error_log("login.php: DB connect failed: " . $e->getMessage());
    $error = "Unable to connect to database.";
}

// Captcha mode for the login forms (settings come from includes/session.php)
try {
    // Set captcha_mode for consistency with index.php
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if ($_SESSION['cap_e'] === 'on') {
            if ($_SESSION['mode'] === 'reCAPTCHA') {
                $_SESSION['captcha_mode'] = ($_SESSION['recaptcha_version'] === 'v3') ? 'recaptcha_v3' : 'recaptcha';
                $_SESSION['captcha'] = $_SESSION['recaptcha_sitekey'];
            } elseif ($_SESSION['mode'] === 'turnstile') {
                $_SESSION['captcha_mode'] = 'turnstile';
                $_SESSION['captcha'] = $_SESSION['turnstile_sitekey'];
            } else {
                $_SESSION['captcha_mode'] = 'internal';
                $_SESSION['captcha'] = captcha($color, $mode, $mul, $allowed);
            }
        } else {
            $_SESSION['captcha_mode'] = 'none';
        }
    }
} catch (Throwable $e) {
    // verification will fail gracefully
    error_log("login.php: Captcha session bootstrap failed: " . $e->getMessage());
}

// Site info
try {
    $stmt = $pdo->query("SELECT * FROM site_info WHERE id = 1");
    $site = $stmt->fetch() ?: [];
} catch (Throwable $e) { $site = []; }
$title = trim($site['title'] ?? 'Paste');
$des = trim($site['des'] ?? '');
$baseurl = paste_request_baseurl(trim($site['baseurl'] ?? ''));
$keyword = trim($site['keyword'] ?? '');
$site_name = trim($site['site_name'] ?? 'Paste');
$email = trim($site['email'] ?? '');
$admin_mail = $email;
$admin_name = $site_name;
$mod_rewrite = (string)($site['mod_rewrite'] ?? ($mod_rewrite ?? '1'));

// next resolver
function safe_next_url(string $baseurl): string {
    $rawNext = (string)($_GET['next'] ?? $_POST['next'] ?? $_SESSION['login_next'] ?? '');
    $next = trim(html_entity_decode($rawNext, ENT_QUOTES, 'UTF-8'));
    if ($next === '' || preg_match('/[\x00-\x1f\x7f\\\\]/', $next)) return '';
    $base = rtrim($baseurl ?: './', '/');
    $pBase = parse_url($base);
    if (!$pBase || empty($pBase['host']) || empty($pBase['scheme'])) return '';
    if (strpos($next, '//') === 0) {
        $next = $pBase['scheme'] . ':' . $next;
    }
    if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $next)) {
        $pNext = parse_url($next);
        if (!$pNext) return '';
        if (strcasecmp($pNext['host'] ?? '', $pBase['host']) !== 0) return '';
        $scheme = $pBase['scheme'];
        $host = $pBase['host'];
        $path = $pNext['path'] ?? '/';
        $query = isset($pNext['query']) ? ('?' . $pNext['query']) : '';
        $frag = isset($pNext['fragment']) ? ('#' . $pNext['fragment']) : '';
        return "{$scheme}://{$host}{$path}{$query}{$frag}";
    }
    if ($next[0] === '/') return $next;
    if ($next[0] === '#') return $base . '/' . ltrim($next, '#');
    return $base . '/' . ltrim($next, '/');
}

// capture ?next= on arrival (sanitized) so it survives POST
if (isset($_GET['next']) && $_GET['next'] !== '') {
    $tmp = safe_next_url($baseurl);
    if ($tmp !== '') $_SESSION['login_next'] = $tmp;
}
$next_url = safe_next_url($baseurl);

// UI: language & theme
try {
    $iface = $pdo->query("SELECT * FROM interface WHERE id = 1")->fetch() ?: [];
} catch (Throwable $e) { $iface = []; }
$default_lang = trim($iface['lang'] ?? 'en.php');
$default_theme = trim($iface['theme'] ?? 'default');
require_once("langs/$default_lang");

// Page title

$p_title = $lang['login/register'] ?? 'Login / Register';

// Ads
try {
    $ads = $pdo->query("SELECT * FROM ads WHERE id = 1")->fetch() ?: [];
} catch (Throwable $e) { $ads = []; }
$text_ads = trim($ads['text_ads'] ?? '');
$ads_1 = trim($ads['ads_1'] ?? '');
$ads_2 = trim($ads['ads_2'] ?? '');

// CSRF / basics
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf_token = $_SESSION['csrf_token'];

// Ban check
if (isset($pdo) && is_banned($pdo, $ip)) { $error = $lang['banned'] ?? 'You are banned.'; }

// Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    paste_remember_cookie(null);
    $_SESSION = [];
    unset($_SESSION['token'], $_SESSION['oauth_uid'], $_SESSION['username'], $_SESSION['platform'], $_SESSION['id'], $_SESSION['oauth2state'], $_SESSION['login_next']);
    @session_regenerate_id(true);
    @session_destroy();
    $__clean();
    header('Location: ' . ($baseurl ?: './'));
    exit;
}

// Already logged in?
if (isset($_SESSION['token']) && !(isset($_GET['action']) && $_GET['action'] === 'logout')) {
    $__clean();
    $dest = $next_url ?: ($baseurl ?: './');
    unset($_SESSION['login_next']);
    header('Location: ' . $dest);
    exit;
}

// Mail settings (for verify/forgot)
try {
    $mail = $pdo->query("SELECT * FROM mail WHERE id = 1")->fetch() ?: [];
} catch (Throwable $e) { $mail = []; }
$verification = trim($mail['verification'] ?? 'disabled');

// Page views
pageViewTrack($pdo, $ip);

// Actions (verify/resend/forgot/reset)
$valid_csrf = static fn($token) => hash_equals($_SESSION['csrf_token'] ?? '', (string)$token);

// verify (GET)
if (isset($_GET['action'], $_GET['code'], $_GET['username']) && $_GET['action'] === 'verify') {
    $u = filter_var((string)$_GET['username'], FILTER_SANITIZE_SPECIAL_CHARS);
    $c = filter_var((string)$_GET['code'], FILTER_SANITIZE_SPECIAL_CHARS);
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND verification_code = ?");
        $stmt->execute([$u, $c]);
        if ($stmt->fetch()) {
            $pdo->prepare("UPDATE users SET verified = '1', verification_code = NULL WHERE username = ?")->execute([$u]);
            $success = $lang['email_verified'] ?? 'Email verified successfully. You can now log in.';
        } else { $error = $lang['invalid_code'] ?? 'Invalid verification code or username.'; }
    } catch (Throwable $e) { $error = "Verification error."; }
}

// resend (POST)
if (isset($_GET['action']) && $_GET['action'] === 'resend'
    && isset($_POST['email'], $_POST['csrf_token']) && $valid_csrf($_POST['csrf_token'])) {
    if ($_SESSION['captcha_mode'] === 'turnstile') {
        if (!require_human_turnstile('resend')) {
            $error = $error ?: ($lang['turnstile_failed'] ?? 'Turnstile verification failed. Please try again.');
        }
    } else {
        require_human('resend');
    }
    if (!$error) {
        $email_in = filter_var((string)$_POST['email'], FILTER_SANITIZE_EMAIL);
        try {
            $stmt = $pdo->prepare("SELECT username, full_name, verified FROM users WHERE email_id = ?");
            $stmt->execute([$email_in]);
            $user = $stmt->fetch();
            if ($user && (string)$user['verified'] === '0') {
                $code = bin2hex(random_bytes(16));
                $pdo->prepare("UPDATE users SET verification_code = ? WHERE email_id = ?")->execute([$code, $email_in]);
                $verify_url = rtrim($baseurl, '/') . "/login.php?action=verify&username=" . urlencode($user['username']) . "&code=" . urlencode($code);
                $subject = $lang['mail_acc_con'] ?? 'Account Confirmation';
                $body = "Hello " . htmlspecialchars((string)$user['full_name']) . ", please verify your account:<br><br><a href='$verify_url' target='_self'>$verify_url</a>";
                send_mail($email_in, $subject, $body, $site_name, $_SESSION['csrf_token']);
                $success = $lang['mail_suc'] ?? 'Verification email sent.';
            } else {
                $success = $lang['mail_suc'] ?? 'Verification email sent.'; // avoid enumeration
            }
        } catch (Throwable $e) { $error = "Could not resend verification."; }
    }
    if (!$error && $success) { $__redirect_flash($baseurl . 'login.php', $success); }
}

// forgot (POST)
if (isset($_GET['action']) && $_GET['action'] === 'forgot'
    && isset($_POST['email'], $_POST['csrf_token']) && $valid_csrf($_POST['csrf_token'])) {
    if ($_SESSION['captcha_mode'] === 'turnstile') {
        if (!require_human_turnstile('forgot')) {
            $error = $error ?: ($lang['turnstile_failed'] ?? 'Turnstile verification failed. Please try again.');
        }
    } else {
        require_human('forgot');
    }
    if (!$error) {
        $email_in = filter_var((string)$_POST['email'], FILTER_SANITIZE_EMAIL);
        try {
            $stmt = $pdo->prepare("SELECT username FROM users WHERE email_id = ?");
            $stmt->execute([$email_in]);
            $user = $stmt->fetch();
            if ($user) {
                $code = bin2hex(random_bytes(16));
                $exp = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $pdo->prepare("UPDATE users SET reset_code = ?, reset_expiry = ? WHERE email_id = ?")->execute([$code, $exp, $email_in]);
                $reset_url = rtrim($baseurl, '/') . "/login.php?action=reset&username=" . urlencode($user['username']) . "&code=" . urlencode($code);
                $subject = "$site_name Password Reset";
                $body = "To reset your password, click:<br><br><a href='$reset_url' target='_self'>$reset_url</a><br><br>This link expires in 1 hour.";
                send_mail($email_in, $subject, $body, $site_name, $_SESSION['csrf_token']);
            }
            $success = $lang['pass_change'] ?? 'Password reset link sent. Check your email.';
        } catch (Throwable $e) { $error = "Could not start reset process."; }
    }
    if (!$error && $success) { $__redirect_flash($baseurl . 'login.php', $success); }
}

// reset (POST)
if (isset($_GET['action'], $_GET['username'], $_GET['code']) && $_GET['action'] === 'reset'
    && isset($_POST['password'], $_POST['csrf_token']) && $valid_csrf($_POST['csrf_token'])) {
    if ($_SESSION['captcha_mode'] === 'turnstile') {
        if (!require_human_turnstile('reset')) {
            $error = $error ?: ($lang['turnstile_failed'] ?? 'Turnstile verification failed. Please try again.');
        }
    } else {
        require_human('reset');
    }
    if (!$error) {
        $u = filter_var((string)$_GET['username'], FILTER_SANITIZE_SPECIAL_CHARS);
        $c = filter_var((string)$_GET['code'], FILTER_SANITIZE_SPECIAL_CHARS);
        $pw = (string)$_POST['password'];
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND reset_code = ? AND reset_expiry > ?");
            $stmt->execute([$u, $c, date('Y-m-d H:i:s')]);
            if ($stmt->fetch()) {
                $hash = password_hash($pw, PASSWORD_DEFAULT);
                // A new token signs out every session of the account
                $pdo->prepare("UPDATE users SET password = ?, reset_code = NULL, reset_expiry = NULL, token = ? WHERE username = ?")
                    ->execute([$hash, bin2hex(random_bytes(32)), $u]);
                $success = $lang['pass_reset'] ?? 'Password reset successful. You can now log in.';
            } else { $error = $lang['invalid_code'] ?? 'Invalid or expired reset code.'; }
        } catch (Throwable $e) { $error = "Could not reset password."; }
    }
    if (!$error && $success) { $__redirect_flash($baseurl . 'login.php', $success); }
}

// Login / Signup (POST)
$valid_post = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && isset($_POST['csrf_token']) && $valid_csrf($_POST['csrf_token']));
if ($valid_post) {
    // LOGIN
    if (isset($_POST['signin'])) {
        if ($_SESSION['captcha_mode'] === 'turnstile') {
            if (!require_human_turnstile('login')) {
                $error = $error ?: ($lang['turnstile_failed'] ?? 'Turnstile verification failed. Please try again.');
            }
        } else {
            require_human('login');
        }
        if (!$error) {
            $u = filter_var((string)($_POST['username'] ?? ''), FILTER_SANITIZE_SPECIAL_CHARS);
            $p = (string)($_POST['password'] ?? '');
            if ($u !== '' && $p !== '' && paste_throttled($pdo, 'login')) {
                $error = $lang['logintoomany'] ?? 'Too many failed sign-ins. Try again in a few minutes.';
            } elseif ($u !== '' && $p !== '') {
                try {
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
                    $stmt->execute([$u]);
                    $user = $stmt->fetch();
                    if ($user && password_verify($p, (string)$user['password'])) {
                        if (paste_is_guest_name((string)$user['username'])) {
                            $error = $lang['reservedname'] ?? 'This account name is reserved and cannot sign in.';
                        } elseif ((string)$user['verified'] === '1') {
                            // Devices share the account's token; a password change or reset replaces it to sign them all out
                            $new_token = (string)($user['token'] ?? '');
                            if ($new_token === '') {
                                $new_token = bin2hex(random_bytes(32));
                                $pdo->prepare("UPDATE users SET token = ? WHERE id = ?")->execute([$new_token, $user['id']]);
                            }
                            $redir = safe_next_url($baseurl);
                            session_regenerate_id(true);
                            paste_remember_cookie(!empty($_POST['rememberme']) ? paste_remember_value((int)$user['id'], $new_token) : null);
                            $_SESSION['token'] = $new_token;
                            $_SESSION['oauth_uid'] = $user['oauth_uid'];
                            $_SESSION['username'] = (string)$user['username'];
                            if ($redir === '') {
                                $ref = paste_same_site_referer($baseurl, '');
                                $redir = (stripos($ref, 'login.php') === false && $ref !== '') ? $ref : ($baseurl ?: './');
                            }
                            unset($_SESSION['login_next']);
                            $__clean();
                            header('Location: ' . $redir);
                            exit;
                        } else {
                            $error = ((string)$user['verified'] === '2')
                                ? ($lang['banned'] ?? 'Your account is banned.')
                                : ($lang['notverified'] ?? 'Account not verified.');
                        }
                    } else {
                        paste_throttled($pdo, 'login', true);
                        $error = $lang['incorrect'] ?? 'Incorrect username or password.';
                    }
                } catch (Throwable $e) { $error = "Login failed due to a server error."; }
            } else { $error = $lang['missingfields'] ?? 'Please fill in all fields.'; }
        }
    }
    // SIGNUP
    if (isset($_POST['signup'])) {
        if ($_SESSION['captcha_mode'] === 'turnstile') {
            if (!require_human_turnstile('signup')) {
                $error = $error ?: ($lang['turnstile_failed'] ?? 'Turnstile verification failed. Please try again.');
            }
        } else {
            require_human('signup');
        }
        if (!$error) {
            $u = filter_var((string)($_POST['username'] ?? ''), FILTER_SANITIZE_SPECIAL_CHARS);
            $p = (string)($_POST['password'] ?? '');
            $em = filter_var((string)($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
            $fn = filter_var((string)($_POST['full'] ?? ''), FILTER_SANITIZE_SPECIAL_CHARS);
            if ($u && $p && $em && $fn) {
                if (isValidUsername($u)) {
                    try {
                        // Not reserved, unused, and not the owner of pastes or uploads left by a deleted account
                        if (paste_username_taken($pdo, $u)) {
                            $error = $lang['userexists'] ?? 'Username already exists.';
                        } else {
                            // email unique
                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email_id = ?");
                            $stmt->execute([$em]);
                            if ((int)$stmt->fetchColumn() > 0) {
                                $error = $lang['emailexists'] ?? 'Email already exists.';
                            } else {
                                $hash = password_hash($p, PASSWORD_DEFAULT);
                                $verified = ($verification === 'disabled') ? '1' : '0';
                                $vcode = ($verification === 'disabled') ? null : bin2hex(random_bytes(16));
                                $pdo->prepare("
                                    INSERT INTO users (oauth_uid, username, email_id, full_name, platform, password, verified, picture, date, ip, verification_code)
                                    VALUES ('0', ?, ?, ?, 'Direct', ?, ?, 'NONE', ?, ?, ?)
                                ")->execute([$u, $em, $fn, $hash, $verified, date('Y-m-d H:i:s'), $ip, $vcode]);
                                if ($verification !== 'disabled') {
                                    $verify_url = rtrim($baseurl, '/') . "/login.php?action=verify&username=" . urlencode($u) . "&code=" . urlencode($vcode);
                                    $subject = $lang['mail_acc_con'] ?? 'Account Confirmation';
                                    $body = "Hello $fn, verify your $site_name account:<br><br><a href='$verify_url' target='_self'>$verify_url</a>";
                                    $res = send_mail($em, $subject, $body, $site_name, $_SESSION['csrf_token']);
                                    if (($res['status'] ?? 'error') !== 'success') {
                                        $error = ($lang['mail_error'] ?? 'Failed to send verification email.');
                                    }
                                }
                                if (!$error) {
                                    $success = ($lang['registered'] ?? 'Registration successful.')
                                        . ($verification !== 'disabled' ? ' Please check your email to verify your account.' : '');
                                }
                            }
                        }
                    } catch (Throwable $e) { $error = "Registration failed due to a server error."; }
                } else { $error = $lang['usrinvalid'] ?? 'Invalid username. Use only letters, numbers, .#$'; }
            } else { $error = $lang['missingfields'] ?? 'Please fill in all fields.'; }
        }
        if (!$error && $success) { $__redirect_flash($baseurl . 'login.php', $success); }
    }
}

// Mirror messages for theme (which reads $_GET)
if (!empty($error)) { $_GET['error'] = $error; }
if (!empty($success)) { $_GET['success'] = $success; }
if ($next_url !== '') { $_GET['next'] = $next_url; } // expose next to theme/forms

// OAuth launch (only if enabled & deps ok)
if ($oauth_ready && isset($_GET['login']) && $enablegoog === 'yes') {
    $n = safe_next_url($baseurl);
    $qnext = $n ? '&next=' . rawurlencode($n) : '';
    if ($_GET['login'] === 'google') {
        $__clean(); header("Location: oauth/google.php?login=1{$qnext}"); exit;
    }
    $_GET['error'] = "Invalid OAuth provider or disabled in config.";
}

// Render
$__clean();
header('Content-Type: text/html; charset=utf-8');
require_once("theme/$default_theme/header.php");
require_once("theme/$default_theme/login.php");
require_once("theme/$default_theme/footer.php");
