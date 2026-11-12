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

// If the installer exists, redirect to prevent errors from empty config variables
$directory = 'install';
if (file_exists($directory)) {
    header("Location: install");
    exit();
}
require_once 'includes/session.php';
// production-style error handling
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// timezone
date_default_timezone_set('UTC');
// core includes
require_once('config.php');
require_once('includes/captcha.php');
require_once('includes/functions.php');
// Error handling
paste_enable_themed_errors();
// ip
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
// Initialize permissions to avoid undefined variable warnings
$disableguest = 'off';
$siteprivate = 'off';
// pull config from DB
try {
    // site_info
    $stmt = $pdo->query("SELECT * FROM site_info WHERE id='1'");
    $siteinfo = $stmt->fetch() ?: [];
    $title = trim($siteinfo['title'] ?? 'Paste');
    $des = trim($siteinfo['des'] ?? '');
    $baseurl = paste_request_baseurl(trim($siteinfo['baseurl'] ?? ''));
    $keyword = trim($siteinfo['keyword'] ?? '');
    $site_name = trim($siteinfo['site_name'] ?? 'Paste');
    $ga = trim($siteinfo['ga'] ?? '');
    $additional_scripts = trim($siteinfo['additional_scripts'] ?? '');
    // interface
    $stmt = $pdo->query("SELECT * FROM interface WHERE id='1'");
    $iface = $stmt->fetch() ?: [];
    $default_lang = trim($iface['lang'] ?? 'en.php');
    $default_theme = trim($iface['theme'] ?? 'default');
    require_once("langs/$default_lang");
    // ads
    $stmt = $pdo->query("SELECT * FROM ads WHERE id='1'");
    $ads = $stmt->fetch() ?: [];
    $text_ads = trim($ads['text_ads'] ?? '');
    $ads_1 = trim($ads['ads_1'] ?? '');
    $ads_2 = trim($ads['ads_2'] ?? '');
    // sitemap options
    $stmt = $pdo->query("SELECT * FROM sitemap_options WHERE id='1'");
    $sm = $stmt->fetch() ?: [];
    $priority = $sm['priority'] ?? '0.8';
    $changefreq = $sm['changefreq'] ?? 'daily';
    // captcha settings (from admin/configuration.php)
    $stmt = $pdo->query("SELECT * FROM captcha WHERE id='1'");
    $cap = $stmt->fetch() ?: [];
    $color = trim($cap['color'] ?? '');
    $mode = trim($cap['mode'] ?? 'normal'); // "normal" | "reCAPTCHA" | "turnstile"
    $mul = trim($cap['mul'] ?? '');
    $allowed = trim($cap['allowed'] ?? '');
    $cap_e = trim($cap['cap_e'] ?? 'off'); // "on" | "off"
    $recaptcha_sitekey = trim($cap['recaptcha_sitekey'] ?? '');
    $recaptcha_version = trim($cap['recaptcha_version'] ?? 'v2'); // "v2" | "v3"
    $turnstile_sitekey = trim($cap['turnstile_sitekey'] ?? '');
    // permissions
    $stmt = $pdo->query("SELECT * FROM site_permissions WHERE id='1'");
    $perm = $stmt->fetch() ?: [];
    $disableguest = trim($perm['disableguest'] ?? 'off');
    $siteprivate = trim($perm['siteprivate'] ?? 'off');
    $hiderecentpastes = trim($perm['hiderecentpastes'] ?? 'off');
    // Mirror captcha config into session for recaptcha.php (expects these names)
    $_SESSION['cap_e'] = $cap_e; // 'on'|'off'
    $_SESSION['mode'] = $mode; // 'reCAPTCHA'|'normal'
    $_SESSION['recaptcha_version'] = $recaptcha_version; // 'v2'|'v3'
    $_SESSION['recaptcha_sitekey'] = $recaptcha_sitekey; // site key used by client
    $_SESSION['turnstile_sitekey'] = $turnstile_sitekey;
} catch (PDOException $e) {
    error_log("index.php: DB error ".$e->getMessage());
    $error = $lang['db_error'] ?? 'Database error.';
    goto OutPut;
}
// set session flags for captcha on initial GET
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($cap_e === "on") {
        if ($mode === "reCAPTCHA") {
            $_SESSION['captcha_mode'] = ($recaptcha_version === 'v3') ? "recaptcha_v3" : "recaptcha";
            $_SESSION['captcha'] = $recaptcha_sitekey;
        } elseif ($mode === "turnstile") {
            $_SESSION['captcha_mode'] = "turnstile";
            $_SESSION['captcha'] = $turnstile_sitekey;
        } else {
            $_SESSION['captcha_mode'] = "internal";
            $_SESSION['captcha'] = captcha($color, $mode, $mul, $allowed);
        }
    } else {
        $_SESSION['captcha_mode'] = "none";
    }
}
// ban check
if (is_banned($pdo, $ip)) {
    $error = $lang['banned'] ?? 'You are banned from this site.';
    goto OutPut;
}
// guest/private flags for theme
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $noguests = $disableguest === "on" ? "on" : "off";
    $privatesite = $siteprivate === "on" ? "on" : "off";
    if (isset($_SESSION['username'])) {
        $noguests = "off";
    }
}
// logout passthrough
if (isset($_GET['logout'])) {
    paste_logout_and_redirect($baseurl);
}
// Page views
pageViewTrack($pdo, $ip);
// POST: create paste
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Check if this is an edit of an existing paste (only if "edit" button was clicked)
    $isEdit = isset($_POST['edit']) && isset($_POST['paste_id']) && !empty($_POST['paste_id']);
    // Check if this is an explicit fork (fork button clicked)
    $isFork = isset($_POST['fork']) && isset($_POST['fork_from']) && !empty($_POST['fork_from']);

    // Check guest permissions - guests cannot paste or fork if disabled, and cannot make private pastes
    if (!isset($_SESSION['username'])) {
        if ($disableguest === 'on') {
            $error = $lang['guestsdisabled'] ?? 'Guest posting is disabled. Please login or register.';
            goto OutPut;
        }
        if ((string)($_POST['visibility'] ?? '0') === '2') {
            $error = $lang['guestprivate'] ?? 'Private pastes need an account. Sign in, or choose Public or Unlisted.';
            goto OutPut;
        }
    }

    // Signed-in posts must carry the session CSRF token
    if (isset($_SESSION['username'])
        && !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
        $error = $lang['invalidtoken'] ?? 'Your session token was missing or expired. Please try again.';
        goto OutPut;
    }

    $editPasteId = null;
    $editPasteSlug = null;
    $editPastePassword = null;
    $forkParentId = null; // Track the original paste when forking

    // Handle explicit fork - always create new paste with parent_id
    $currentUser = (string)($_SESSION['username'] ?? '');
    // A parent is linked when it is found by its own page's identifier and is not someone else's private paste
    $linkableParent = static function (?array $row) use ($currentUser): ?int {
        if (!$row) return null;
        if ((string)($row['visible'] ?? '0') === '2' && ($currentUser === '' || (string)$row['member'] !== $currentUser)) return null;
        return (int)$row['id'];
    };
    if ($isFork) {
        try {
            $forkParentId = $linkableParent(paste_find_linked($pdo, (string)$_POST['fork_from']));
        } catch (PDOException $e) {
            error_log("index.php: fork lookup error: " . $e->getMessage());
        }
        $isEdit = false; // Forks always create new pastes
    }
    // Handle edit button - only update if user owns the paste
    elseif ($isEdit) {
        $editPasteId = (string)$_POST['paste_id'];
        try {
            $existingPaste = paste_find_linked($pdo, $editPasteId);
            if (!$existingPaste) {
                $error = $lang['notfound'] ?? 'Paste not found.';
                goto OutPut;
            }

            if ($currentUser === '' || $currentUser !== (string)($existingPaste['member'] ?? 'Guest')) {
                // Not the owner - fall back to creating a fork (new paste)
                $isEdit = false;
                $editPasteId = null;
                $forkParentId = $linkableParent($existingPaste);
            } else {
                // User owns the paste - get the numeric ID and slug for update
                $editPasteId = (int)$existingPaste['id'];
                $editPasteSlug = $existingPaste['slug'] ?? null;
                $editPastePassword = (string)($existingPaste['password'] ?? 'NONE');
            }
        } catch (PDOException $e) {
            error_log("index.php: edit verify err " . $e->getMessage());
            $isEdit = false; // Fall back to creating new paste
            $editPasteId = null;
        }
    }

    // empty content
    if (empty($_POST["paste_data"]) || trim($_POST["paste_data"]) === '') {
        $error = $lang['empty_paste'] ?? 'Paste content cannot be empty.';
        goto OutPut;
    }
    // size check
    if (mb_strlen($_POST["paste_data"], '8bit') > 1024 * 1024 * ($pastelimit ?? 10)) {
        $error = $lang['large_paste'] ?? 'Paste is too large.';
        goto OutPut;
    }
    // require fields
    if (!isset($_POST['title']) || !isset($_POST['paste_data'])) {
        $error = $lang['error'] ?? 'Invalid form submission.';
        goto OutPut;
    }
    // Captcha for guests when guest posting is allowed
    if (!isset($_SESSION['username']) && $disableguest !== "on" && $cap_e === "on") {
        if ($mode === "reCAPTCHA") {
            require_once __DIR__ . '/includes/recaptcha.php';
            require_human('create_paste');
            if (!empty($error)) {
                $error = $lang['recaptcha_failed'] ?? 'reCAPTCHA failed to verify you\'re not a bot. Refresh and try again.';
                goto OutPut;
            }
        } elseif ($mode === "turnstile") {
            require_once __DIR__ . '/includes/turnstile.php';
            if (!require_human_turnstile('create_paste')) {
                $error = $error ?: ($lang['turnstile_failed'] ?? 'Turnstile verification failed. Refresh and try again.');
                goto OutPut;
            }
        } else {
            // internal captcha (image)
            $posted = strtolower(preg_replace('/\s+/', '', (string)($_POST['scode'] ?? '')));
            $cfg = isset($_SESSION['_CAPTCHA']['config'])
                ? @unserialize($_SESSION['_CAPTCHA']['config'], ['allowed_classes' => false])
                : null;
            $cap_code = strtolower((string)($cfg['code'] ?? ($_SESSION['captcha']['code'] ?? '')));
            // timing-safe compare; require non-empty
            if ($cap_code === '' || !hash_equals($cap_code, $posted)) {
                $error = $lang['image_wrong'] ?? 'Incorrect CAPTCHA code.';
                goto OutPut;
            }
            unset($_SESSION['captcha'], $_SESSION['_CAPTCHA']);
        }
    }
    // sanitize inputs
    $p_title = paste_store_title((string)($_POST['title'] ?? '')) ?: 'Untitled';
    $p_content = htmlspecialchars($_POST['paste_data'], ENT_QUOTES, 'UTF-8');
    $p_visible = (string)($_POST['visibility'] ?? '0');
    if (!in_array($p_visible, ['0', '1', '2'], true)) {
        $p_visible = '0';
    }
    $p_code = trim((string)($_POST['format'] ?? 'text'));
    if (!preg_match('/^[A-Za-z0-9._+#-]{1,50}$/', $p_code)) {
        $p_code = 'text';
    }
    $p_expiry = trim((string)($_POST['paste_expire_date'] ?? 'N'));
    $p_password = trim($_POST['pass'] ?? '') === '' ? 'NONE' : trim($_POST['pass']);
    $p_encrypt = '1';
    $p_member = (string)($_SESSION['username'] ?? 'Guest');
    $p_date = date('Y-m-d H:i:s');
    $now_time = mktime(date("H"), date("i"), date("s"), date("n"), date("j"), date("Y"));
    $s_date = date('Y-m-d');

    // Client-side encrypted? Skip server encryption
    $is_client_encrypted = isset($_POST['is_client_encrypted']) && $_POST['is_client_encrypted'] === '1';

    // Client-encrypted bodies must be the base64 salt|iv|ciphertext blob built by the browser
    if ($is_client_encrypted) {
        $blob = trim((string)$_POST['paste_data']);
        $raw  = preg_match('~^[A-Za-z0-9+/]+={0,2}$~', $blob) ? base64_decode($blob, true) : false;
        if ($raw === false || strlen($raw) < 28) {
            $error = $lang['error'] ?? 'Invalid encrypted paste data.';
            goto OutPut;
        }
    }

    // ---- Spam filter (new pastes, forks and edits; runs on plaintext before encryption) ----
    $spam_verdict = ['action' => 'allow', 'score' => 0, 'reasons' => []];
    $scanTitle = html_entity_decode($p_title, ENT_QUOTES, 'UTF-8');
    // Body is unreadable for client-encrypted pastes -> judge on title + IP only.
    $scanBody  = $is_client_encrypted ? '' : html_entity_decode($p_content, ENT_QUOTES, 'UTF-8');
    $isGuest      = !isset($_SESSION['username']) || $p_member === 'Guest';
    $isPrivate    = $p_visible === '2' || $p_password !== 'NONE'
                    || ($isEdit && $editPasteId && ($editPastePassword ?? 'NONE') !== 'NONE');
    $spam_verdict = spam_evaluate($pdo, $ip, $p_member, $scanTitle, $scanBody, $isGuest, ($isEdit && $editPasteId) ? 'edit' : 'paste', $_POST, $isPrivate);
    // Edits cannot be queued, so a held edit is refused like a blocked one
    if ($spam_verdict['action'] === 'block' || ($spam_verdict['action'] === 'hold' && $isEdit && $editPasteId)) {
        $error = $lang['spam_blocked'] ?? 'This paste was flagged as spam and was not posted.';
        goto OutPut;
    }

    if ($is_client_encrypted) {
        $p_encrypt = '2'; // Client-encrypted flag
        $p_content = $blob;
    } else {
        // encrypt content
        try {
            if (!defined('SECRET')) {
                error_log("index.php: SECRET undefined");
                $error = $lang['error'] ?? 'Server configuration error.';
                goto OutPut;
            }
            $p_content = encrypt($p_content, hex2bin(SECRET));
            if ($p_content === null) {
                $error = $lang['error'] ?? 'Encryption failed.';
                goto OutPut;
            }
        } catch (RuntimeException $e) {
            $error = ($lang['error'] ?? 'Error') . ': ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            goto OutPut;
        }
    }
    // hash password if provided; a blank password on edit keeps the existing one
    if ($p_password !== "NONE") {
        $p_password = password_hash($p_password, PASSWORD_DEFAULT);
    } elseif ($isEdit && $editPasteId && $editPastePassword !== null) {
        $p_password = $editPastePassword;
    }
    // expiry
    $expires = paste_expiry_value($p_expiry);
    // insert or update
    try {
        if ($isEdit && $editPasteId) {
            // UPDATE existing paste (user owns it)
            $stmt = $pdo->prepare("UPDATE pastes SET title = ?, content = ?, visible = ?, code = ?, expiry = ?, password = ?, encrypt = ?, date = ?, ip = ?, now_time = ?, s_date = ? WHERE id = ?");
            $stmt->execute([$p_title, $p_content, $p_visible, $p_code, $expires, $p_password, $p_encrypt, $p_date, $ip, $now_time, $s_date, $editPasteId]);

            $paste_id = $editPasteId;
            $p_slug = $editPasteSlug; // Preserve the original slug

            // Update sitemap based on visibility
            if ($p_visible === '0' && $expires !== 'SELF') {
                addToSitemap($pdo, (int)$paste_id, $priority, $changefreq, $mod_rewrite == '1', $p_slug);
            } else {
                // Remove from sitemap if no longer public
                removeFromSitemap($pdo, (int)$paste_id);
            }

            // Determine URL identifier (slug or numeric ID)
            $url_identifier = ($p_slug && $p_slug !== '') ? $p_slug : $paste_id;
        } else {
            // Slug for the new paste (also given to a held paste, so its author gets the link)
            $useSlug = false;
            $p_slug = null;
            if (getPasteUrlMode($pdo) === 'slug') {
                if (ensureSlugColumnExists($pdo)) {
                    $useSlug = true;
                    $p_slug = generateUniquePasteSlug($pdo, getPasteSlugLength($pdo));
                } else {
                    error_log("index.php: Failed to ensure slug column exists");
                }
            }

            // ---- Held for moderation? Stash the exact row and stop here. ----
            if (($spam_verdict['action'] ?? 'allow') === 'hold') {
                $row = [
                    'parent_id' => $forkParentId,
                    'title'     => $p_title,
                    'content'   => $p_content,
                    'visible'   => $p_visible,
                    'code'      => $p_code,
                    'expiry'    => $expires,
                    'password'  => $p_password,
                    'encrypt'   => $p_encrypt,
                    'member'    => $p_member,
                    'date'      => $p_date,
                    'ip'        => $ip,
                    'now_time'  => $now_time,
                    's_date'    => $s_date,
                ];
                if ($useSlug) {
                    $row['slug'] = $p_slug;
                }
                // If the queue is unavailable the paste is published rather than lost
                if (spam_enqueue($pdo, $row, $ip, $p_member, (int)($spam_verdict['score'] ?? 0), $spam_verdict['reasons'] ?? [], $scanTitle, mb_substr($scanBody, 0, 500))) {
                    $notice = $lang['spam_held'] ?? 'Your paste was submitted and is awaiting moderator approval.';
                    if ($useSlug) {
                        $notice .= ' ' . ($lang['spam_held_link'] ?? 'Once it is approved it will be at this link:');
                        $notice_link = ($mod_rewrite == '1') ? ($baseurl . $p_slug) : ($baseurl . 'paste.php?id=' . $p_slug);
                    }
                    goto OutPut;
                }
            }

            // INSERT new paste
            if ($useSlug) {
                $stmt = $pdo->prepare("INSERT INTO pastes (slug, parent_id, title, content, visible, code, expiry, password, encrypt, member, date, ip, now_time, s_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$p_slug, $forkParentId, $p_title, $p_content, $p_visible, $p_code, $expires, $p_password, $p_encrypt, $p_member, $p_date, $ip, $now_time, $s_date]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO pastes (parent_id, title, content, visible, code, expiry, password, encrypt, member, date, ip, now_time, s_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$forkParentId, $p_title, $p_content, $p_visible, $p_code, $expires, $p_password, $p_encrypt, $p_member, $p_date, $ip, $now_time, $s_date]);
            }

            $paste_id = $pdo->lastInsertId();

            // sitemap for public pastes that are not view-once
            if ($p_visible === '0' && $expires !== 'SELF') {
                addToSitemap($pdo, (int)$paste_id, $priority, $changefreq, $mod_rewrite == '1', $p_slug);
            }

            // Determine URL identifier (slug or numeric ID)
            $url_identifier = ($useSlug && $p_slug) ? $p_slug : $paste_id;
        }

        // redirect to paste
        $paste_url = ($mod_rewrite == '1') ? ($baseurl . $url_identifier) : ($baseurl . 'paste.php?id=' . $url_identifier);
        header("Location: " . $paste_url);
        exit;
    } catch (PDOException $e) {
        error_log("index.php: " . ($isEdit ? "update" : "insert") . " err " . $e->getMessage());
        $error = $lang['paste_db_error'] ?? 'Database error.';
        goto OutPut;
    }
}
// output: render theme
OutPut:
$themeDir = 'theme/' . htmlspecialchars($default_theme, ENT_QUOTES, 'UTF-8');
require_once $themeDir . '/header.php';
/**
 * Decide which view to render.
 * Hard errors -> errors.php
 * Soft form errors -> $error
 */
$error_text = $error ?? '';
$notfound = $notfound ?? '';
$needs_pw = !empty($require_password);
// classify hard vs soft
$error_hard = false;
if ($notfound !== '' || $needs_pw) {
    $error_hard = true; // 404 / password
} elseif ($error_text !== '') {
    $hard_markers = [
        'banned', 'Database error', 'Encryption failed',
        'Password hashing failed', 'Server configuration error',
    ];
    foreach ($hard_markers as $m) {
        if (stripos($error_text, $m) !== false) { $error_hard = true; break; }
    }
}
if ($error_hard) {
    // HARD: render errors.php between header & footer
    $err = $themeDir . '/errors.php';
    if (is_file($err)) {
        $error_msg = $error_text; // expose to partial
        require $err;
    } else {
        echo '<main class="container py-4"><div class="alert alert-danger" role="alert">'
            . htmlspecialchars($error_text ?: ($notfound ?: ($lang['error'] ?? 'An error occurred.')), ENT_QUOTES, 'UTF-8')
            . '</div></main>';
    }
} else {
    // SOFT: show form with inline alert
    if ($error_text !== '') { $flash_error = $error_text; }
    if (!empty($notice)) { $flash_notice = $notice; $flash_notice_link = $notice_link ?? ''; }
    // Keep what the user typed when a submission is rejected
    if ($error_text !== '' && $_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['is_client_encrypted'] ?? '0') !== '1') {
        $paste_data       = (string)($_POST['paste_data'] ?? '');
        $paste_title      = (string)($_POST['title'] ?? '');
        $paste_expire_date = (string)($_POST['paste_expire_date'] ?? 'N');
    }
    try {
        require_once $themeDir . '/main.php';
    } catch (Throwable $e) {
        error_log("index.php: main.php error: " . $e->getMessage());
        echo '<main class="container py-4"><div class="alert alert-danger" role="alert">'
            . htmlspecialchars($lang['error'] ?? 'An error occurred while rendering the form.', ENT_QUOTES, 'UTF-8')
            . '</div></main>';
    }
}
require_once $themeDir . '/footer.php';
