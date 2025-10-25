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

// Search API
if (isset($_GET['api']) && $_GET['api'] === 'search') {
  header('Content-Type: application/json');
  $query = trim($_GET['q'] ?? '');
  if (strlen($query) < 3) {
    echo json_encode(['error' => 'Query too short (min 3 chars)']);
    exit;
  }
  $limit = min(50, (int)($_GET['limit'] ?? 10));
  $offset = max(0, (int)($_GET['offset'] ?? 0));
  $search_term = '%' . $query . '%';
  $sql = "SELECT p.id, p.title, p.code, p.date, COUNT(pv.id) AS views
          FROM pastes p LEFT JOIN paste_views pv ON p.id = pv.paste_id
          WHERE p.visible = '0' AND p.password = 'NONE' AND (p.title LIKE ? OR p.content LIKE ?)
          GROUP BY p.id ORDER BY p.date DESC LIMIT ? OFFSET ?";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$search_term, $search_term, $limit, $offset]);
  $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $count_sql = "SELECT COUNT(*) FROM pastes WHERE visible = '0' AND password = 'NONE' AND (title LIKE ? OR content LIKE ?)";
  $count_stmt = $pdo->prepare($count_sql);
  $count_stmt->execute([$search_term, $search_term]);
  $total = (int)$count_stmt->fetchColumn();
  echo json_encode(['results' => $results, 'total' => $total]);
  exit;
}

// Disable non-GET requests
if ($_SERVER['REQUEST_METHOD'] != 'GET') {
    http_response_code(405);
    exit('405 Method Not Allowed.');
}

$date = date('Y-m-d H:i:s'); // Use DATETIME format for database
$ip = $_SERVER['REMOTE_ADDR'];

// Database Connection
global $pdo;

try {
    // Get site info
    $stmt = $pdo->query("SELECT * FROM site_info WHERE id = '1'");
    $row = $stmt->fetch();
    $title = trim($row['title']);
    $des = trim($row['des']);
    $baseurl = trim($row['baseurl']);
    $keyword = trim($row['keyword']);
    $site_name = trim($row['site_name']);
    $email = trim($row['email']);
    $twit = trim($row['twit']);
    $face = trim($row['face']);
    $gplus = trim($row['gplus']);
    $ga = trim($row['ga']);
    $additional_scripts = trim($row['additional_scripts']);

    // Set theme and language
    $stmt = $pdo->query("SELECT * FROM interface WHERE id = '1'");
    $row = $stmt->fetch();
    $default_lang = trim($row['lang']);
    $default_theme = trim($row['theme']);
    require_once("langs/$default_lang");

    $p_title = $lang['archive'];

    // Check if IP is banned
    if (is_banned($pdo, $ip)) die($lang['banned']);

    // Site permissions
    $stmt = $pdo->query("SELECT * FROM site_permissions WHERE id = 1 LIMIT 1");
    $row = $stmt->fetch();
    $siteprivate = trim($row['siteprivate']);
    $privatesite = ($siteprivate === '0' || $siteprivate === 0) ? '0' : '1';

    // Logout
    if (isset($_GET['logout'])) {
        header('Location: ' . $_SERVER['HTTP_REFERER']);
        unset($_SESSION['token']);
        unset($_SESSION['oauth_uid']);
        unset($_SESSION['username']);
        session_destroy();
    }

    // Page views
    $date = date('Y-m-d');
    $ip = $_SERVER['REMOTE_ADDR'];

    try {
        // Fetch or create the page_view record for today
        $stmt = $pdo->prepare("SELECT id, tpage, tvisit FROM page_view WHERE date = ?");
        $stmt->execute([$date]);
        $row = $stmt->fetch();

        if ($row) {
            // Record exists for today
            $page_view_id = $row['id'];
            $tpage = (int)$row['tpage'] + 1; // Increment total page views
            $tvisit = (int)$row['tvisit'];

            // Check if this IP has visited today
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM visitor_ips WHERE ip = ? AND visit_date = ?");
            $stmt->execute([$ip, $date]);
            if ($stmt->fetchColumn() == 0) {
                // New unique visitor
                $tvisit += 1;
                $stmt = $pdo->prepare("INSERT INTO visitor_ips (ip, visit_date) VALUES (?, ?)");
                $stmt->execute([$ip, $date]);
            }

            // Update page_view with new counts
            $stmt = $pdo->prepare("UPDATE page_view SET tpage = ?, tvisit = ? WHERE id = ?");
            $stmt->execute([$tpage, $tvisit, $page_view_id]);
        } else {
            // No record for today: create one
            $tpage = 1;
            $tvisit = 1;
            $stmt = $pdo->prepare("INSERT INTO page_view (date, tpage, tvisit) VALUES (?, ?, ?)");
            $stmt->execute([$date, $tpage, $tvisit]);

            // Log the visitor's IP
            $stmt = $pdo->prepare("INSERT INTO visitor_ips (ip, visit_date) VALUES (?, ?)");
            $stmt->execute([$ip, $date]);
        }
    } catch (PDOException $e) {
        error_log("Page view tracking error: " . $e->getMessage());
    }

    // Ads
    $stmt = $pdo->query("SELECT * FROM ads WHERE id = '1'");
    $row = $stmt->fetch();
    $text_ads = trim($row['text_ads']);
    $ads_1 = trim($row['ads_1']);
    $ads_2 = trim($row['ads_2']);

    // Search, pagination, and sorting
    $search_query = isset($_GET['q']) && !empty($_GET['q']) ? trim($_GET['q']) : '';
    $sort = isset($_GET['sort']) && in_array($_GET['sort'], ['date_desc', 'date_asc', 'title_asc', 'title_desc', 'code_asc', 'code_desc', 'views_desc', 'views_asc']) ? $_GET['sort'] : 'date_desc';
    $perPage = 50; // Increased to 50 pastes per page
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($page - 1) * $perPage;

    // Determine sort column and direction
    $sortColumn = 'p.date';
    $sortDirection = 'DESC';
    switch ($sort) {
        case 'date_asc':
            $sortDirection = 'ASC';
            break;
        case 'title_asc':
            $sortColumn = 'p.title';
            $sortDirection = 'ASC';
            break;
        case 'title_desc':
            $sortColumn = 'p.title';
            $sortDirection = 'DESC';
            break;
        case 'code_asc':
            $sortColumn = 'p.code';
            $sortDirection = 'ASC';
            break;
        case 'code_desc':
            $sortColumn = 'p.code';
            $sortDirection = 'DESC';
            break;
        case 'views_desc':
            $sortColumn = 'view_count';
            $sortDirection = 'DESC';
            break;
        case 'views_asc':
            $sortColumn = 'view_count';
            $sortDirection = 'ASC';
            break;
    }

    // Initialize variables
    $pastes = [];
    $totalItems = 0;
    $totalPages = 1;
    $error = '';

    // Base query with LEFT JOIN to paste_views
    $baseQuery = "SELECT p.id, p.title, p.code, p.date, UNIX_TIMESTAMP(p.date) AS now_time, p.encrypt, p.member, COUNT(pv.id) AS view_count 
                  FROM pastes p 
                  LEFT JOIN paste_views pv ON p.id = pv.paste_id 
                  WHERE p.visible = '0' AND p.password = 'NONE'";
    $countQuery = "SELECT COUNT(*) 
                   FROM pastes p 
                   WHERE p.visible = '0' AND p.password = 'NONE'";
    $params = [];

    if ($search_query && strlen($search_query) >= 3) { // Search query provided
        $search_term = '%' . $search_query . '%';
        $baseQuery .= " AND (p.title LIKE ? OR p.content LIKE ?)";
        $countQuery .= " AND (p.title LIKE ? OR p.content LIKE ?)";
        $params = [$search_term, $search_term];
    }

    // Add GROUP BY and ORDER BY
    $baseQuery .= " GROUP BY p.id, p.title, p.code, p.date, p.encrypt, p.member ORDER BY $sortColumn $sortDirection LIMIT ? OFFSET ?";
    $params[] = $perPage;
    $params[] = $offset;

    // Execute main query
    try {
        $stmt = $pdo->prepare($baseQuery);
        $stmt->execute($params);
        $pastes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Count total matching pastes for pagination
        $stmt = $pdo->prepare($countQuery);
        $stmt->execute($search_query ? [$search_term, $search_term] : []);
        $totalItems = $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Paste query error: " . $e->getMessage());
        $pastes = [];
        $totalItems = 0;
    }

    $totalPages = $totalItems > 0 ? ceil($totalItems / $perPage) : 1;

    // Decrypt titles and format time
    foreach ($pastes as &$row) {
        if ($row['encrypt'] == '1') {
            $row['title'] = decrypt($row['title'], hex2bin(SECRET)) ?? $row['title'];
        }
        $row['time_display'] = formatRealTime($row['date']);
        $row['url'] = $mod_rewrite == '1' ? $baseurl . $row['id'] : $baseurl . 'paste.php?id=' . $row['id'];
        $row['title'] = truncate($row['title'], 20, 50);
        $row['views'] = $row['view_count'];
    }
    unset($row);

    if (isset($_GET['q']) && (empty($search_query) || strlen($search_query) < 3)) {
        $error = "Please use a keyword to search. Here are the latest 50 pastes.";
    }

    // Pagination
    $prev_page_query = http_build_query(array_merge($_GET, ['page' => $page > 1 ? $page - 1 : 1]));
    $next_page_query = http_build_query(array_merge($_GET, ['page' => $page < $totalPages ? $page + 1 : $totalPages]));
    $page_queries = [];
    for ($i = 1; $i <= $totalPages; $i++) {
        $page_queries[$i] = http_build_query(array_merge($_GET, ['page' => $i]));
    }

    // Set archives title
    $archives_title = htmlspecialchars($lang['archives'] ?? 'Archives', ENT_QUOTES, 'UTF-8');
    if ($search_query && !empty($search_query)) {
        $archives_title .= ' - ' . htmlspecialchars($lang['search_results_for'] ?? 'Search Results for', ENT_QUOTES, 'UTF-8') . ' "' . htmlspecialchars($search_query, ENT_QUOTES, 'UTF-8') . '"';
    }

    // Theme
    require_once('theme/' . $default_theme . '/header.php');
    require_once('theme/' . $default_theme . '/archive.php');
    require_once('theme/' . $default_theme . '/footer.php');
} catch (PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
?>