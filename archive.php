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

// Search API
if (isset($_GET['api']) && $_GET['api'] === 'search') {
  header('Content-Type: application/json');
  header('Access-Control-Allow-Origin: *');

  $query = trim($_GET['q'] ?? '');
  if (strlen($query) < 2) {
    echo json_encode(['success' => false, 'error' => 'Query too short (min 2 chars)']);
    exit;
  }

  $limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));
  $page = max(1, (int)($_GET['page'] ?? 1));
  $offset = ($page - 1) * $limit;
  $search_term = '%' . $query . '%';

  // Check if slug column exists
  $hasSlug = false;
  try {
      $check = $pdo->query("SHOW COLUMNS FROM pastes LIKE 'slug'");
      $hasSlug = $check->fetch() !== false;
  } catch (PDOException $e) {}

  $slugCol = $hasSlug ? ', p.slug' : '';

  // Get site info for URLs
  $stmt = $pdo->query("SELECT baseurl FROM site_info WHERE id = 1");
  $si = $stmt->fetch() ?: [];
  $baseurl = paste_request_baseurl(rtrim($si['baseurl'] ?? '', '/') . '/');
  // mod_rewrite comes from config.php (global)
  global $mod_rewrite;
  $use_mod_rewrite = ((string)($mod_rewrite ?? '0') === '1');

  $sql = "SELECT p.id{$slugCol}, p.title, p.code, p.date, p.member, COUNT(pv.id) AS views
          FROM pastes p LEFT JOIN paste_views pv ON p.id = pv.paste_id
          WHERE p.visible = '0' AND p.password = 'NONE' AND p.encrypt = '0' AND " . paste_listable_sql('p') . "
          AND (p.title LIKE ? OR p.content LIKE ?)
          GROUP BY p.id ORDER BY p.date DESC LIMIT ? OFFSET ?";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$search_term, $search_term, $limit, $offset]);
  $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // Add URLs to results
  foreach ($results as &$row) {
      $identifier = ($hasSlug && !empty($row['slug'])) ? $row['slug'] : $row['id'];
      $row['url'] = $use_mod_rewrite ? $baseurl . $identifier : $baseurl . 'paste.php?id=' . $identifier;
  }
  unset($row);

  $count_sql = "SELECT COUNT(*) FROM pastes WHERE visible = '0' AND password = 'NONE' AND encrypt = '0' AND " . paste_listable_sql() . " AND (title LIKE ? OR content LIKE ?)";
  $count_stmt = $pdo->prepare($count_sql);
  $count_stmt->execute([$search_term, $search_term]);
  $total = (int)$count_stmt->fetchColumn();

  echo json_encode([
      'success' => true,
      'query' => $query,
      'results' => $results,
      'pagination' => [
          'page' => $page,
          'limit' => $limit,
          'total' => $total,
          'pages' => (int)ceil($total / $limit)
      ]
  ]);
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
    $baseurl = paste_request_baseurl(trim($row['baseurl']));
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
    $siteprivate = trim((string)($row['siteprivate'] ?? 'off'));
    $privatesite = ($siteprivate === 'on' && empty($_SESSION['username'])) ? 'on' : 'off';

    // Logout
    if (isset($_GET['logout'])) {
        paste_logout_and_redirect($baseurl);
    }

    // Page views
    pageViewTrack($pdo, $ip);

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

    // Databases not yet upgraded may lack the slug column
    $hasSlug = columnExists($pdo, 'pastes', 'slug');
    $slugCol = $hasSlug ? ', p.slug' : '';
    $slugGroup = $hasSlug ? ', p.slug' : '';

    // Base query with LEFT JOIN to paste_views
    $baseQuery = "SELECT p.id{$slugCol}, p.title, p.code, p.date, UNIX_TIMESTAMP(p.date) AS now_time, p.encrypt, p.member, COUNT(pv.id) AS view_count
                  FROM pastes p
                  LEFT JOIN paste_views pv ON p.id = pv.paste_id
                  WHERE p.visible = '0' AND p.password = 'NONE' AND " . paste_listable_sql('p');
    $countQuery = "SELECT COUNT(*)
                   FROM pastes p
                   WHERE p.visible = '0' AND p.password = 'NONE' AND " . paste_listable_sql('p');
    $params = [];

    if ($search_query && strlen($search_query) >= 3) { // Search query provided
        $search_term = '%' . $search_query . '%';
        $baseQuery .= " AND (p.title LIKE ? OR p.content LIKE ?)";
        $countQuery .= " AND (p.title LIKE ? OR p.content LIKE ?)";
        $params = [$search_term, $search_term];
    }

    // Add GROUP BY and ORDER BY
    $baseQuery .= " GROUP BY p.id, p.title, p.code, p.date, p.encrypt, p.member{$slugGroup} ORDER BY $sortColumn $sortDirection LIMIT ? OFFSET ?";
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
        // Ensure slug key exists even if column doesn't
        if (!isset($row['slug'])) {
            $row['slug'] = '';
        }
        // Use slug if available, otherwise fall back to numeric ID
        $url_identifier = !empty($row['slug']) ? $row['slug'] : $row['id'];
        $row['time_display'] = formatRealTime($row['date']);
        $row['url'] = $mod_rewrite == '1' ? $baseurl . $url_identifier : $baseurl . 'paste.php?id=' . $url_identifier;
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
