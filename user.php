<?php
/*
 * Paste $v3.3 2025/10/24 https://github.com/boxlabss/PASTE
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

// JSON response for ajax delete
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
    $baseurl = rtrim(trim($si['baseurl'] ?? ''), '/') . '/';
    $keyword = trim($si['keyword'] ?? '');
    $site_name = trim($si['site_name'] ?? '');
    $email     = trim($si['email'] ?? '');
    $twit      = trim($si['twit'] ?? '');
    $face      = trim($si['face'] ?? '');
    $gplus     = trim($si['gplus'] ?? '');
    $ga        = trim($si['ga'] ?? '');
    $additional_scripts = trim($si['additional_scripts'] ?? '');
    $mod_rewrite = (string)($si['mod_rewrite'] ?? '1'); // used later

    // interface
    $stmt = $pdo->query("SELECT * FROM interface WHERE id = '1'");
    $iface = $stmt->fetch() ?: [];
    $default_lang  = trim($iface['lang'] ?? 'en.php');
    $default_theme = trim($iface['theme'] ?? 'default');
    require_once("langs/$default_lang");

    // ban check
    if (is_banned($pdo, $ip)) {
        // ajax delete path?
        if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
            send_json(false, $lang['banned'] ?? 'You are banned from this site.');
        }
        die(htmlspecialchars($lang['banned'] ?? 'You are banned from this site.', ENT_QUOTES, 'UTF-8'));
    }

    // permissions
    $stmt = $pdo->query("SELECT * FROM site_permissions WHERE id = '1'");
    $perm = $stmt->fetch() ?: [];
    $siteprivate = trim($perm['siteprivate'] ?? 'off');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $siteprivate === "1") {
        $privatesite = "1";
    }

    // profile username
    if (!isset($_GET['user'])) {
        header("Location: ../");
        exit;
    }
    $profile_username = trim($_GET['user']);
    if (!existingUser($pdo, $profile_username)) {
        header("Location: ../");
        exit;
    }

    $p_title = $profile_username . ($lang['user_public_pastes'] ?? 'Public Pastes');
    $is_me = (isset($_SESSION['username']) && $_SESSION['username'] === $profile_username);

    // stats for profile page
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pastes WHERE member = ?");
    $stmt->execute([$profile_username]);
    $profile_total_pastes = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pastes WHERE member = ? AND visible = 0");
    $stmt->execute([$profile_username]);
    $profile_total_public = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pastes WHERE member = ? AND visible = 1");
    $stmt->execute([$profile_username]);
    $profile_total_unlisted = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pastes WHERE member = ? AND visible = 2");
    $stmt->execute([$profile_username]);
    $profile_total_private = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(COUNT(pv.id), 0) AS total_views
        FROM pastes p
        LEFT JOIN paste_views pv ON p.id = pv.paste_id
        WHERE p.member = ?
    ");
    $stmt->execute([$profile_username]);
    $profile_total_paste_views = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$profile_username]);
    $profile_user_id = (int)($stmt->fetchColumn() ?: 0);

    // Get joined date
    $stmt = $pdo->prepare("SELECT date FROM users WHERE username = ?");
    $stmt->execute([$profile_username]);
    $profile_join_date = $stmt->fetchColumn();
    $profile_join_date = is_string($profile_join_date) ? $profile_join_date : '';

    // logout
    if (isset($_GET['logout'])) {
        $ref = $_SERVER['HTTP_REFERER'] ?? $baseurl;
        unset($_SESSION['token'], $_SESSION['oauth_uid'], $_SESSION['username']);
        session_destroy();
        header('Location: ' . $ref);
        exit;
    }

    // page views
    $view_date = date('Y-m-d');
    try {
        $stmt = $pdo->prepare("SELECT id, tpage, tvisit FROM page_view WHERE date = ?");
        $stmt->execute([$view_date]);
        $pv = $stmt->fetch();
        if ($pv) {
            $page_view_id = $pv['id'];
            $tpage = (int)$pv['tpage'] + 1;
            $tvisit = (int)$pv['tvisit'];
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM visitor_ips WHERE ip = ? AND visit_date = ?");
            $stmt->execute([$ip, $view_date]);
            if ((int)$stmt->fetchColumn() === 0) {
                $tvisit++;
                $stmt = $pdo->prepare("INSERT INTO visitor_ips (ip, visit_date) VALUES (?, ?)");
                $stmt->execute([$ip, $view_date]);
            }
            $stmt = $pdo->prepare("UPDATE page_view SET tpage = ?, tvisit = ? WHERE id = ?");
            $stmt->execute([$tpage, $tvisit, $page_view_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO page_view (date, tpage, tvisit) VALUES (?, ?, ?)");
            $stmt->execute([$view_date, 1, 1]);
            $stmt = $pdo->prepare("INSERT INTO visitor_ips (ip, visit_date) VALUES (?, ?)");
            $stmt->execute([$ip, $view_date]);
        }
    } catch (PDOException $e) {
        error_log("Page view tracking error: " . $e->getMessage());
    }

    // AJAX: delete own comment
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
        if ($comment_id <= 0) send_json(false, 'Invalid comment.');

        // current user
        $owner = (string)$_SESSION['username'];
        $uid = 0;
        try {
            $q = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $q->execute([$owner]);
            $uid = (int)($q->fetchColumn() ?: 0);
        } catch (Throwable $e) { $uid = 0; }

        if (!userOwnsComment($pdo, $comment_id, $uid, $owner)) {
            send_json(false, $lang['delete_error_invalid'] ?? 'Not allowed.');
        }
        if (!deleteComment($pdo, $comment_id)) {
            send_json(false, $lang['wentwrong'] ?? 'Failed to delete comment.');
        }
        send_json(true, $lang['deleted'] ?? 'Deleted.', ['id' => $comment_id]);
    }

    // Non-AJAX: delete own comment from profile
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['action']) && $_POST['action'] === 'delete_comment') {

        $redir = $baseurl . ($mod_rewrite ? 'user/' . rawurlencode($profile_username)
                                          : 'user.php?user=' . rawurlencode($profile_username));
        $goto  = $redir . (strpos($redir, '?') === false ? '?':'&');

        do {
            if (empty($_SESSION['username']) || $_SESSION['username'] !== $profile_username) {
                $msg = $lang['not_logged_in'] ?? 'You must be logged in.';
                header('Location: ' . $goto . 'c_err=' . rawurlencode($msg) . '#my-comments'); exit;
            }
            if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
                $msg = $lang['invalidtoken'] ?? 'Invalid CSRF token.';
                header('Location: ' . $goto . 'c_err=' . rawurlencode($msg) . '#my-comments'); exit;
            }
            $cid = (int)($_POST['comment_id'] ?? 0);
            if ($cid <= 0) {
                header('Location: ' . $goto . 'c_err=' . rawurlencode('Invalid comment.') . '#my-comments'); exit;
            }

            // resolve current user id for ownership check
            $uid = (int)($profile_user_id ?? 0);
            if (!userOwnsComment($pdo, $cid, $uid, $profile_username)) {
                $msg = $lang['delete_error_invalid'] ?? 'Not allowed.';
                header('Location: ' . $goto . 'c_err=' . rawurlencode($msg) . '#my-comments'); exit;
            }

            if (!deleteComment($pdo, $cid)) {
                $msg = $lang['wentwrong'] ?? 'Failed to delete comment.';
                header('Location: ' . $goto . 'c_err=' . rawurlencode($msg) . '#my-comments'); exit;
            }

            $msg = $lang['deleted'] ?? 'Comment deleted.';
            header('Location: ' . $goto . 'c_ok=' . rawurlencode($msg) . '#my-comments'); exit;
        } while (false);
    }

    // DELETE paste (supports AJAX via POST ajax=1 and anchor GET fallback)
    if (isset($_GET['del'])) {
        $is_ajax = (isset($_POST['ajax']) && $_POST['ajax'] === '1');

        if (empty($_SESSION['token']) || empty($_SESSION['username'])) {
            if ($is_ajax) {
                send_json(false, $lang['not_logged_in'] ?? 'You must be logged in to delete pastes.');
            }
            $error = $lang['not_logged_in'] ?? 'You must be logged in to delete pastes.';
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
                    // also clean up views (optional)
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
                    // redirect for non-ajax
                    $redirect = $baseurl . ($mod_rewrite ? 'user/' . urlencode($owner) : 'user.php?user=' . urlencode($owner));
                    header('Location: ' . $redirect);
                    exit;
                }
            }
        }
        // if we reach here and not ajax, fall through to render page with $error
    }

    // ---------- PASTES PAGINATION ----------
    // Visitors see only visible=0 (public)
    $per_default = 25;
    $per_max     = 100;
    $per = isset($_GET['per']) ? (int)$_GET['per'] : $per_default;
    if ($per < 5)   $per = 5;
    if ($per > $per_max) $per = $per_max;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

    // Count (filtered by permission)
    try {
        $sqlCount = "SELECT COUNT(*) FROM pastes WHERE member = ?" . ($is_me ? "" : " AND visible = 0");
        $stmt = $pdo->prepare($sqlCount);
        $stmt->execute([$profile_username]);
        $total_pastes = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $total_pastes = 0;
    }

    $total_pages = max(1, (int)ceil($total_pastes / ($per ?: 1)));
    $page        = min($page, $total_pages);
    $offset      = ($page - 1) * $per;

    // Fetch one page
    try {
        $sqlRows = "
            SELECT id, title, code, date, views, visible
            FROM pastes
            WHERE member = ?" . ($is_me ? "" : " AND visible = 0") . "
            ORDER BY date DESC
            LIMIT :offset, :limit
        ";
        $stmt = $pdo->prepare($sqlRows);
        $stmt->bindValue(1, $profile_username, PDO::PARAM_STR);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit',  $per,    PDO::PARAM_INT);
        $stmt->execute();
        $pastes_page = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $pastes_page = [];
    }

    // Range text for "showing X–Y of Z"
    $range_from = $total_pastes ? ($offset + 1) : 0;
    $range_to   = min($offset + $per, $total_pastes);

    // small URL builder for the profile (works with/without mod_rewrite)
    $profile_url = function(array $add = [], array $drop = []) use ($baseurl, $mod_rewrite, $profile_username) {
        $base = $baseurl . ($mod_rewrite ? ('user/' . rawurlencode($profile_username)) : ('user.php?user=' . rawurlencode($profile_username)));
        // merge current query with $add, drop keys in $drop
        $q = $_GET;
        foreach ($drop as $k) unset($q[$k]);
        foreach ($add as $k => $v) {
            if ($v === null) unset($q[$k]); else $q[$k] = $v;
        }
        $qs = http_build_query($q);
        return $base . ($mod_rewrite ? ($qs ? ('?' . $qs) : '') : ($qs ? ('&' . $qs) : ''));
    };

    // ---------- USER COMMENTS ----------
    $stmt = $pdo->query("SELECT * FROM ads WHERE id = '1'");
    $ads  = $stmt->fetch() ?: [];
    $text_ads = trim($ads['text_ads'] ?? '');
    $ads_1    = trim($ads['ads_1'] ?? '');
    $ads_2    = trim($ads['ads_2'] ?? '');

    // Build comments list for this profile (latest first)
    $owner_viewing = $is_me;
    $vis_sql = $owner_viewing ? '' : ' AND p.visible IN (0,1) ';

    $st = $pdo->prepare("
        SELECT c.id, c.paste_id, c.user_id, c.username, c.body, c.created_at,
               p.title, p.visible
        FROM paste_comments c
        JOIN pastes p ON p.id = c.paste_id
        WHERE (c.user_id = :uid OR c.username = :uname)
              $vis_sql
        ORDER BY c.created_at DESC
        LIMIT 200
    ");
    $st->execute([
        ':uid'   => (int)($profile_user_id ?? 0),
        ':uname' => $profile_username
    ]);
    $user_comments = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $total_user_comments = (int)count($user_comments);
	
	// ----- pagination + data for the table ---------------------------------
	$is_owner = (isset($_SESSION['username']) && $_SESSION['username'] === $profile_username);

	// page size & offset
	$per  = max(1, min(100, (int)($_GET['per'] ?? 25)));
	$page = max(1, (int)($_GET['page'] ?? 1));
	$off  = ($page - 1) * $per;

	// visibility filter: owner sees all; others see public + unlisted
	$vis_list = $is_owner ? '0,1,2' : '0,1';

	// total rows (for pager)
	$stmt = $pdo->prepare("SELECT COUNT(*) FROM pastes WHERE member = :member AND visible IN ($vis_list)");
	$stmt->execute([':member' => $profile_username]);
	$total_pastes = (int)$stmt->fetchColumn();

	// current page rows
	$sql = "
	  SELECT
		p.id,
		p.title,
		p.code,
		p.`date`,
		p.visible,
		COALESCE(v.view_count, 0) AS views
	  FROM pastes p
	  LEFT JOIN (
		SELECT paste_id, COUNT(*) AS view_count
		FROM paste_views
		GROUP BY paste_id
	  ) v ON v.paste_id = p.id
	  WHERE p.member = :member
		AND p.visible IN ($vis_list)
	  ORDER BY p.`date` DESC
	  LIMIT " . (int)$per . " OFFSET " . (int)$off;

	$stmt = $pdo->prepare($sql);
	$stmt->execute([':member' => $profile_username]);
	$pastesPage = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

	// range info for "Showing X–Y of Z"
	$range_from = $total_pastes ? ($off + 1) : 0;
	$range_to   = min($total_pastes, $off + count($pastesPage));

    // theme
    require_once('theme/' . htmlspecialchars($default_theme, ENT_QUOTES, 'UTF-8') . '/header.php');
    require_once('theme/' . htmlspecialchars($default_theme, ENT_QUOTES, 'UTF-8') . '/user_profile.php');
    require_once('theme/' . htmlspecialchars($default_theme, ENT_QUOTES, 'UTF-8') . '/footer.php');
} catch (PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
