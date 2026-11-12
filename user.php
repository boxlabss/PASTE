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

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

// UTF-8
header('Content-Type: text/html; charset=utf-8');

$date = date('Y-m-d H:i:s');
$ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

global $pdo;

// JSON response for ajax
function send_json($ok, $msg = '', $extra = []) {
    header_remove('Content-Type');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => (bool)$ok, 'message' => $msg], $extra));
    exit;
}

try {
    // site_info
    $stmt = $pdo->query("SELECT * FROM site_info WHERE id = '1'");
    $si   = $stmt->fetch() ?: [];
    $title   = trim($si['title'] ?? '');
    $des     = trim($si['des'] ?? '');
    $baseurl = paste_request_baseurl(rtrim(trim($si['baseurl'] ?? ''), '/') . '/');
    $keyword = trim($si['keyword'] ?? '');
    $site_name = trim($si['site_name'] ?? '');
    $email     = trim($si['email'] ?? '');
    $twit      = trim($si['twit'] ?? '');
    $face      = trim($si['face'] ?? '');
    $gplus     = trim($si['gplus'] ?? '');
    $ga        = trim($si['ga'] ?? '');
    $additional_scripts = trim($si['additional_scripts'] ?? '');
    $mod_rewrite = (string)($si['mod_rewrite'] ?? '1');

    // interface
    $stmt = $pdo->query("SELECT * FROM interface WHERE id = '1'");
    $iface = $stmt->fetch() ?: [];
    $default_lang  = trim($iface['lang'] ?? 'en.php');
    $default_theme = trim($iface['theme'] ?? 'default');
    require_once("langs/$default_lang");

    // ban check
    if (is_banned($pdo, $ip)) {
        if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
            send_json(false, $lang['banned'] ?? 'You are banned from this site.');
        }
        die(htmlspecialchars($lang['banned'] ?? 'You are banned from this site.', ENT_QUOTES, 'UTF-8'));
    }

    // permissions
    $stmt = $pdo->query("SELECT * FROM site_permissions WHERE id = '1'");
    $perm = $stmt->fetch() ?: [];
    $siteprivate = trim($perm['siteprivate'] ?? 'off');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $siteprivate === "on") {
        $privatesite = "on";
    }

    // profile username required - accept from GET or POST
    $profile_username = trim($_GET['user'] ?? $_POST['user'] ?? '');
    if ($profile_username === '' || !existingUser($pdo, $profile_username)) {
        header("Location: ../");
        exit;
    }

    $p_title = $profile_username . ($lang['user_public_pastes'] ?? 'Public Pastes');
    $is_me = (isset($_SESSION['username']) && $_SESSION['username'] === $profile_username);

    // Get user info and all stats in ONE query
    $stmt = $pdo->prepare("
        SELECT
            u.id AS user_id,
            u.date AS join_date,
            COUNT(p.id) AS total_pastes,
            SUM(CASE WHEN p.visible = 0 THEN 1 ELSE 0 END) AS total_public,
            SUM(CASE WHEN p.visible = 1 THEN 1 ELSE 0 END) AS total_unlisted,
            SUM(CASE WHEN p.visible = 2 THEN 1 ELSE 0 END) AS total_private
        FROM users u
        LEFT JOIN pastes p ON p.member = u.username
        WHERE u.username = ?
        GROUP BY u.id
    ");
    $stmt->execute([$profile_username]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $profile_user_id        = (int)($stats['user_id'] ?? 0);
    $profile_join_date      = $stats['join_date'] ?? '';
    $profile_total_pastes   = (int)($stats['total_pastes'] ?? 0);
    $profile_total_public   = (int)($stats['total_public'] ?? 0);
    $profile_total_unlisted = (int)($stats['total_unlisted'] ?? 0);
    $profile_total_private  = (int)($stats['total_private'] ?? 0);

    // Get total views separately
    $stmt = $pdo->prepare("
        SELECT COALESCE(COUNT(pv.id), 0)
        FROM pastes p
        LEFT JOIN paste_views pv ON p.id = pv.paste_id
        WHERE p.member = ?
    ");
    $stmt->execute([$profile_username]);
    $profile_total_paste_views = (int)$stmt->fetchColumn();

    // logout
    if (isset($_GET['logout'])) {
        paste_logout_and_redirect($baseurl);
    }

    // Page views
    pageViewTrack($pdo, $ip);

    // get current user ID for ownership checks
    $getCurrentUserId = function() use ($pdo) {
        if (empty($_SESSION['username'])) return 0;
        try {
            $q = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $q->execute([$_SESSION['username']]);
            return (int)($q->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0;
        }
    };

    // Delete comment (ajax)
    if (isset($_GET['action']) && $_GET['action'] === 'delete_comment') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || ($_POST['ajax'] ?? '') !== '1') {
            send_json(false, 'Bad request.');
        }
        if (empty($_SESSION['token']) || empty($_SESSION['username'])) {
            send_json(false, $lang['not_logged_in'] ?? 'You must be logged in.');
        }
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
            send_json(false, $lang['invalidtoken'] ?? 'Invalid CSRF token.');
        }

        $comment_id = (int)($_POST['comment_id'] ?? 0);
        if ($comment_id <= 0) {
            send_json(false, 'Invalid comment.');
        }

        $uid = $getCurrentUserId();
        if (!userOwnsComment($pdo, $comment_id, $uid, $_SESSION['username'])) {
            send_json(false, $lang['delete_error_invalid'] ?? 'Not allowed.');
        }
        if (!deleteComment($pdo, $comment_id)) {
            send_json(false, $lang['wentwrong'] ?? 'Failed to delete comment.');
        }
        send_json(true, $lang['deleted'] ?? 'Deleted.', ['id' => $comment_id]);
    }

    // Delete comment
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['action']) && $_POST['action'] === 'delete_comment') {

        $is_ajax = (
            (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (isset($_POST['ajax']) && $_POST['ajax'] === '1')
        );

        $redir = $baseurl . ($mod_rewrite === '1' ? 'user/' . rawurlencode($profile_username)
                                          : 'user.php?user=' . rawurlencode($profile_username));
        $goto  = $redir . (strpos($redir, '?') === false ? '?' : '&');

        do {
            if (empty($_SESSION['username']) || $_SESSION['username'] !== $profile_username) {
                $msg = $lang['not_logged_in'] ?? 'You must be logged in.';
                if ($is_ajax) { send_json(false, $msg); }
                header('Location: ' . $goto . 'c_err=' . rawurlencode($msg) . '#my-comments'); exit;
            }
            if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
                $msg = $lang['invalidtoken'] ?? 'Invalid CSRF token.';
                if ($is_ajax) { send_json(false, $msg); }
                header('Location: ' . $goto . 'c_err=' . rawurlencode($msg) . '#my-comments'); exit;
            }
            $cid = (int)($_POST['comment_id'] ?? 0);
            if ($cid <= 0) {
                $msg = 'Invalid comment.';
                if ($is_ajax) { send_json(false, $msg); }
                header('Location: ' . $goto . 'c_err=' . rawurlencode($msg) . '#my-comments'); exit;
            }

            $uid = $getCurrentUserId();
            if (!userOwnsComment($pdo, $cid, $uid, $profile_username)) {
                $msg = $lang['delete_error_invalid'] ?? 'Not allowed.';
                if ($is_ajax) { send_json(false, $msg); }
                header('Location: ' . $goto . 'c_err=' . rawurlencode($msg) . '#my-comments'); exit;
            }

            if (!deleteComment($pdo, $cid)) {
                $msg = $lang['wentwrong'] ?? 'Failed to delete comment.';
                if ($is_ajax) { send_json(false, $msg); }
                header('Location: ' . $goto . 'c_err=' . rawurlencode($msg) . '#my-comments'); exit;
            }

            $msg = $lang['deleted'] ?? 'Comment deleted.';
            if ($is_ajax) { send_json(true, $msg, ['id' => $cid]); }
            header('Location: ' . $goto . 'c_ok=' . rawurlencode($msg) . '#my-comments'); exit;
        } while (false);
    }

    // Delete a paste
    if (isset($_GET['del'])) {
        $is_ajax = (isset($_POST['ajax']) && $_POST['ajax'] === '1');

        if (empty($_SESSION['token']) || empty($_SESSION['username'])) {
            if ($is_ajax) {
                send_json(false, $lang['not_logged_in'] ?? 'You must be logged in to delete pastes.');
            }
            $error = $lang['not_logged_in'] ?? 'You must be logged in to delete pastes.';
        } elseif (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? ''))) {
            if ($is_ajax) send_json(false, $lang['invalidtoken'] ?? 'Invalid CSRF token.');
            $error = $lang['invalidtoken'] ?? 'Invalid CSRF token.';
        } else {
            $paste_id = (int)($_GET['id'] ?? 0);
            $owner    = (string)($_SESSION['username'] ?? '');

            if ($paste_id <= 0) {
                if ($is_ajax) send_json(false, $lang['delete_error_invalid'] ?? 'Invalid paste or not authorized to delete.');
                $error = $lang['delete_error_invalid'] ?? 'Invalid paste or not authorized to delete.';
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM pastes WHERE id = ? AND member = ?");
                $stmt->execute([$paste_id, $owner]);
                if ((int)$stmt->fetchColumn() === 0) {
                    if ($is_ajax) send_json(false, $lang['delete_error_invalid'] ?? 'Invalid paste or not authorized to delete.');
                    $error = $lang['delete_error_invalid'] ?? 'Invalid paste or not authorized to delete.';
                } else {
                    // perform delete
                    $stmt = $pdo->prepare("DELETE FROM pastes WHERE id = ? AND member = ?");
                    $stmt->execute([$paste_id, $owner]);
                    // also clean up views
                    try {
                        $stmt = $pdo->prepare("DELETE FROM paste_views WHERE paste_id = ?");
                        $stmt->execute([$paste_id]);
                    } catch (PDOException $e) {
                        // ignore
                    }
                    if ($is_ajax) {
                        send_json(true, $lang['pastedeleted'] ?? 'Paste deleted successfully.', ['id' => $paste_id]);
                    }
                    $success = $lang['pastedeleted'] ?? 'Paste deleted successfully.';
                    $redirect = $baseurl . ($mod_rewrite === '1' ? 'user/' . urlencode($owner) : 'user.php?user=' . urlencode($owner));
                    header('Location: ' . $redirect);
                    exit;
                }
            }
        }
    }

    // Pagination for pastes
    $per  = max(5, min(100, (int)($_GET['per'] ?? 25)));
    $page = max(1, (int)($_GET['page'] ?? 1));

    // Visibility filter: owner sees all; others see public ones that are not view-once or expired
    $vis_sql = $is_me ? '' : " AND p.visible = '0' AND " . paste_listable_sql('p');

    // Count total
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pastes p WHERE p.member = ?" . $vis_sql);
    $stmt->execute([$profile_username]);
    $total_pastes = (int)$stmt->fetchColumn();

    $total_pages = max(1, (int)ceil($total_pastes / $per));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per;

    // Fetch pastes with views count in one query
    $stmt = $pdo->prepare("
        SELECT
            p.id, p.slug, p.title, p.code, p.date, p.visible,
            COALESCE(v.view_count, 0) AS views
        FROM pastes p
        LEFT JOIN (
            SELECT paste_id, COUNT(*) AS view_count
            FROM paste_views
            GROUP BY paste_id
        ) v ON v.paste_id = p.id
        WHERE p.member = ?
        $vis_sql
        ORDER BY p.date DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$profile_username, $per, $offset]);
    $pastesPage = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Range text
    $range_from = $total_pastes ? ($offset + 1) : 0;
    $range_to   = min($offset + count($pastesPage), $total_pastes);

    // URL builder for pagination
    $profile_url = function(array $add = [], array $drop = []) use ($baseurl, $mod_rewrite, $profile_username) {
        $base = $baseurl . ($mod_rewrite === '1' ? ('user/' . rawurlencode($profile_username)) : ('user.php?user=' . rawurlencode($profile_username)));
        $q = $_GET;
        foreach ($drop as $k) unset($q[$k]);
        foreach ($add as $k => $v) {
            if ($v === null) unset($q[$k]); else $q[$k] = $v;
        }
        $qs = http_build_query($q);
        return $base . ($mod_rewrite === '1' ? ($qs ? ('?' . $qs) : '') : ($qs ? ('&' . $qs) : ''));
    };

    // User comments
    $stmt = $pdo->query("SELECT * FROM ads WHERE id = '1'");
    $ads  = $stmt->fetch() ?: [];
    $text_ads = trim($ads['text_ads'] ?? '');
    $ads_1    = trim($ads['ads_1'] ?? '');
    $ads_2    = trim($ads['ads_2'] ?? '');

    // Comments: owner sees all, others see those on public pastes that are not view-once or expired
    $vis_comment_sql = $is_me ? '' : " AND p.visible = '0' AND " . paste_listable_sql('p');

    $stmt = $pdo->prepare("
        SELECT c.id, c.paste_id, c.user_id, c.username, c.body, c.created_at,
               p.title, p.visible, p.slug AS paste_slug
        FROM paste_comments c
        JOIN pastes p ON p.id = c.paste_id
        WHERE (c.user_id = :uid OR c.username = :uname)
              $vis_comment_sql
        ORDER BY c.created_at DESC
        LIMIT 200
    ");
    $stmt->execute([
        ':uid'   => $profile_user_id,
        ':uname' => $profile_username
    ]);
    $user_comments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $total_user_comments = count($user_comments);

    // render
    require_once('theme/' . htmlspecialchars($default_theme, ENT_QUOTES, 'UTF-8') . '/header.php');
    require_once('theme/' . htmlspecialchars($default_theme, ENT_QUOTES, 'UTF-8') . '/user_profile.php');
    require_once('theme/' . htmlspecialchars($default_theme, ENT_QUOTES, 'UTF-8') . '/footer.php');

} catch (PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
