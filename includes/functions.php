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
 
declare(strict_types=1);

// Set default timezone
date_default_timezone_set('UTC');

// Start database connection
try {
    $dsn = "mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbuser, $dbpassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    $error = 'Database error. Please try again later.';
    $GLOBALS['error'] = $error;
    paste_error($error, 503); // themed render + exit
}

function str_contains_polyfill(string $haystack, string $needle, bool $ignoreCase = false): bool
{
    if (function_exists('str_contains')) {
        return str_contains($haystack, $needle);
    }
    if ($ignoreCase) {
        $haystack = strtolower($haystack);
        $needle = strtolower($needle);
    }
    return strpos($haystack, $needle) !== false;
}

// Encrypt pastes with AES-256-CBC from our randomly generated $sec_key
function encrypt(string $value, string $sec_key): string
{
    $cipher = "AES-256-CBC";
    $ivlen = openssl_cipher_iv_length($cipher);
    $iv = openssl_random_pseudo_bytes($ivlen);
    $encrypted = openssl_encrypt($value, $cipher, $sec_key, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) {
        throw new RuntimeException('Encryption failed.');
    }
    $hmac = hash_hmac('sha256', $encrypted, $sec_key, true);
    return base64_encode($iv . $hmac . $encrypted);
}

function decrypt(string $value, string $sec_key): ?string
{
    $decoded = base64_decode($value, true);
    if ($decoded === false) {
        return null;
    }
    $cipher = "AES-256-CBC";
    $ivlen = openssl_cipher_iv_length($cipher);
    $sha256len = 32;
    if (strlen($decoded) < $ivlen + $sha256len) {
        return null;
    }
    $iv = substr($decoded, 0, $ivlen);
    $hmac = substr($decoded, $ivlen, $sha256len);
    $encrypted = substr($decoded, $ivlen + $sha256len);
    $calculated_hmac = hash_hmac('sha256', $encrypted, $sec_key, true);
    if (!hash_equals($hmac, $calculated_hmac)) {
        return null;
    }
    $decrypted = openssl_decrypt($encrypted, $cipher, $sec_key, OPENSSL_RAW_DATA, $iv);
    return $decrypted !== false ? $decrypted : null;
}

function deleteMyPaste(PDO $pdo, int $paste_id): bool
{
    try {
        $query = "DELETE FROM pastes WHERE id = :paste_id";
        $stmt = $pdo->prepare($query);
        return $stmt->execute(['paste_id' => $paste_id]);
    } catch (PDOException $e) {
        error_log("Failed to delete paste ID {$paste_id}: " . $e->getMessage());
        return false;
    }
}

if (isset($_POST['delete']) && isset($_SESSION['username']) && isset($paste_id)) {
    try {
        // Verify ownership
        $stmt = $pdo->prepare("SELECT member FROM pastes WHERE id = ?");
        $stmt->execute([$paste_id]);
        $paste = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($paste && $paste['member'] === $_SESSION['username']) {
            if (deleteMyPaste($pdo, $paste_id)) {
                header("Location: " . ($mod_rewrite ? $baseurl . "/profile" : $baseurl . "/profile.php"));
                exit;
            } else {
                $error = "Failed to delete paste.";
            }
        } else {
            $error = "You do not have permission to delete this paste.";
        }
    } catch (Exception $e) {
        $error = "Error deleting paste: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
}

function getRecent(PDO $pdo, int $count = 5, int $offset = 0, string $sortColumn = 'date', string $sortDirection = 'DESC'): array
{
    try {
        $sortColumn = in_array($sortColumn, ['date', 'title', 'code', 'views']) ? $sortColumn : 'date';
        $sortDirection = in_array($sortDirection, ['ASC', 'DESC']) ? $sortDirection : 'DESC';
        $query = "SELECT id, title, content, visible, code, expiry, password, member, date, UNIX_TIMESTAMP(date) AS now_time, encrypt 
                  FROM pastes WHERE visible = '0' AND password = 'NONE' ORDER BY $sortColumn $sortDirection LIMIT :count OFFSET :offset";
        $stmt = $pdo->prepare($query);
        $stmt->bindValue(':count', $count, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            if ($row['encrypt'] == "1") {
                $row['content'] = decrypt($row['content'], hex2bin(SECRET)) ?? '';
                $row['title'] = decrypt($row['title'], hex2bin(SECRET)) ?? $row['title'];
            }
        }
        unset($row);
        return $rows;
    } catch (PDOException $e) {
        error_log("Failed to fetch recent pastes: " . $e->getMessage());
        return [];
    }
}

function getUserRecent(PDO $pdo, string $username, int $count = 5): array
{
    try {
        $query = "SELECT id, title, content, visible, code, expiry, password, member, date, UNIX_TIMESTAMP(date) AS now_time, encrypt 
                  FROM pastes WHERE member = :username ORDER BY id DESC LIMIT :count";
        $stmt = $pdo->prepare($query);
        $stmt->bindValue(':username', $username, PDO::PARAM_STR);
        $stmt->bindValue(':count', $count, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            if ($row['encrypt'] == "1") {
                $row['content'] = decrypt($row['content'], hex2bin(SECRET)) ?? '';
                $row['title'] = decrypt($row['title'], hex2bin(SECRET)) ?? $row['title'];
            }
        }
        unset($row);
        return $rows;
    } catch (PDOException $e) {
        error_log("Failed to fetch user recent pastes for {$username}: " . $e->getMessage());
        return [];
    }
}

function getUserPastes(PDO $pdo, string $username): array
{
    try {
        $query = "
            SELECT p.id, p.title, p.content, p.visible, p.code, p.password, p.member, p.date, 
                   UNIX_TIMESTAMP(p.date) AS now_time, p.encrypt, p.expiry, 
                   COALESCE(COUNT(pv.id), 0) AS views
            FROM pastes p
            LEFT JOIN paste_views pv ON p.id = pv.paste_id
            WHERE p.member = :username
            GROUP BY p.id, p.title, p.content, p.visible, p.code, p.password, p.member, p.date, p.encrypt, p.expiry
            ORDER BY p.id DESC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->bindValue(':username', $username, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            if ($row['encrypt'] == "1") {
                $row['content'] = decrypt($row['content'], hex2bin(SECRET)) ?? '';
                $row['title'] = decrypt($row['title'], hex2bin(SECRET)) ?? $row['title'];
            }
        }
        unset($row);
        return $rows;
    } catch (PDOException $e) {
        error_log("Failed to fetch user pastes for $username: " . $e->getMessage());
        return [];
    }
}

function getTotalPastes(PDO $pdo, string $username): int
{
    try {
        $query = "SELECT COUNT(*) FROM pastes WHERE member = :username";
        $stmt = $pdo->prepare($query);
        $stmt->execute(['username' => $username]);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Failed to count pastes for {$username}: " . $e->getMessage());
        return 0;
    }
}

function isValidUsername(string $str): bool
{
    return preg_match('/^[A-Za-z0-9.#\\-$]+$/', $str) === 1;
}

function existingUser(PDO $pdo, string $username): bool
{
    try {
        $query = "SELECT COUNT(*) FROM users WHERE username = :username";
        $stmt = $pdo->prepare($query);
        $stmt->execute(['username' => $username]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("Failed to check existing user {$username}: " . $e->getMessage());
        return false;
    }
}

// Function to get paste view count from paste_views
function getPasteViewCount(PDO $pdo, int $paste_id): int
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM paste_views WHERE paste_id = :paste_id");
        $stmt->execute(['paste_id' => $paste_id]);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Failed to get view count for paste ID {$paste_id}: " . $e->getMessage());
        return 0;
    }
}

function pageViewTrack(PDO $pdo, string $ip): void {
    $date = date('Y-m-d');
    try {
        $stmt = $pdo->prepare("SELECT id, tpage, tvisit FROM page_view WHERE date = ?");
        $stmt->execute([$date]);
        $row = $stmt->fetch();

        if ($row) {
            $page_view_id = $row['id'];
            $tpage = (int)$row['tpage'] + 1;
            $tvisit = (int)$row['tvisit'];

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM visitor_ips WHERE ip = ? AND visit_date = ?");
            $stmt->execute([$ip, $date]);
            if ($stmt->fetchColumn() == 0) {
                $tvisit += 1;
                $stmt = $pdo->prepare("INSERT INTO visitor_ips (ip, visit_date) VALUES (?, ?)");
                $stmt->execute([$ip, $date]);
            }

            $stmt = $pdo->prepare("UPDATE page_view SET tpage = ?, tvisit = ? WHERE id = ?");
            $stmt->execute([$tpage, $tvisit, $page_view_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO page_view (date, tpage, tvisit) VALUES (?, ?, ?)");
            $stmt->execute([$date, 1, 1]);

            $stmt = $pdo->prepare("INSERT INTO visitor_ips (ip, visit_date) VALUES (?, ?)");
            $stmt->execute([$ip, $date]);
        }
    } catch (PDOException $e) {
        error_log("Page view tracking error: " . $e->getMessage());
    }
}

function updateMyView(PDO $pdo, int $paste_id): bool
{
    try {
        $ip = $_SERVER['REMOTE_ADDR'];
        $view_date = date('Y-m-d');

        // Check if this IP has viewed the paste today
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM paste_views WHERE paste_id = :paste_id AND ip = :ip AND view_date = :view_date");
        $stmt->execute(['paste_id' => $paste_id, 'ip' => $ip, 'view_date' => $view_date]);
        $has_viewed = $stmt->fetchColumn() > 0;

        if (!$has_viewed) {
            // Log the unique view in paste_views table
            $stmt = $pdo->prepare("INSERT INTO paste_views (paste_id, ip, view_date) VALUES (:paste_id, :ip, :view_date)");
            $stmt->execute(['paste_id' => $paste_id, 'ip' => $ip, 'view_date' => $view_date]);
            return true;
        }

        return false; // Not a unique view
    } catch (PDOException $e) {
        error_log("Failed to update view count for paste ID {$paste_id}: " . $e->getMessage());
        return false;
    }
}

// Function to format file size in a human-readable format for view.php
function formatSize($bytes) {
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

function conTime(int $timestamp): string
{
    if ($timestamp <= 0) {
        return '0 seconds';
    }
    $now = time();
    $diff = $now - $timestamp;
    if ($diff < 0) {
        return 'In the future';
    }
    $periods = [
        'year' => 31536000,
        'month' => 2592000,
        'day' => 86400,
        'hour' => 3600,
        'minute' => 60,
        'second' => 1
    ];
    $result = '';
    foreach ($periods as $name => $duration) {
        $value = floor($diff / $duration);
        if ($value >= 1) {
            $result .= "$value $name" . ($value > 1 ? 's' : '') . ' ';
            $diff -= $value * $duration;
        }
    }
    return trim($result) ?: 'just now';
}

function getRelativeTime(int $seconds): string
{
    if ($seconds <= 0) {
        return '0 seconds';
    }
    $now = new DateTime('@0');
    $then = new DateTime("@$seconds");
    $diff = $now->diff($then);
    $ret = '';
    foreach ([
        'y' => 'year',
        'm' => 'month',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second'
    ] as $time => $timename) {
        if ($diff->$time !== 0) {
            $ret .= $diff->$time . ' ' . $timename;
            if (abs($diff->$time) !== 1) {
                $ret .= 's';
            }
            $ret .= ' ';
        }
    }
    return trim($ret);
}

function formatRealTime(string $dateStr): string
{
    // Convert database date (Y-m-d H:i:s) to a formatted date with time
    if (empty($dateStr)) {
        return 'Invalid date';
    }
    try {
        $date = new DateTime($dateStr, new DateTimeZone('UTC'));
        return $date->format('jS F Y H:i'); // e.g., "11th August 2025 23:43"
    } catch (Exception $e) {
        return 'Invalid date';
    }
}

function truncate(string $input, int $maxWords, int $maxChars): string
{
    $words = preg_split('/\s+/', trim($input), $maxWords + 1, PREG_SPLIT_NO_EMPTY);
    $words = array_slice($words, 0, $maxWords);
    $result = '';
    $chars = 0;
    foreach ($words as $word) {
        $chars += strlen($word) + 1;
        if ($chars > $maxChars) {
            break;
        }
        $result .= $word . ' ';
    }
    $result = rtrim($result);
    return $result === $input ? $result : $result . '[...]';
}

function doDownload(int $paste_id, string $p_title, string $p_content, string $p_code): bool
{
    if (!$p_code || !$p_content) {
        header('HTTP/1.1 404 Not Found');
        return false;
    }
    $ext = match ($p_code) {
        'bash' => 'sh',
        'actionscript', 'html4strict' => 'html',
        'javascript' => 'js',
        'perl' => 'pl',
        'csharp' => 'cs',
        'ruby' => 'rb',
        'python' => 'py',
        'sql' => 'sql',
        'php' => 'php',
        'c' => 'c',
        'cpp' => 'cpp',
        'css' => 'css',
        'xml' => 'xml',
        default => 'txt',
    };
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . htmlspecialchars($p_title, ENT_QUOTES, 'UTF-8') . '.' . $ext . '"');
    echo $p_content;
    return true;
}

function rawView(int $paste_id, string $p_title, string $p_content, string $p_code): bool
{
    if (!$paste_id || !$p_code || !$p_content) {
        header('HTTP/1.1 404 Not Found');
        error_log("Debug: rawView - Invalid input: paste_id=$paste_id, p_code=$p_code, p_content length=" . strlen($p_content));
        return false;
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo $p_content;
    return true;
}

function embedView( $paste_id, $p_title, $p_content, $p_code, $title, $baseurl, $ges_style, $lang ) {
    $stats = false;
    if ( $p_content ) {
        $output = "<div class='paste_embed_container'>";
        $output .= "<style>
            .paste_embed_container {
                font-family: monospace;
                font-size: 13px;
                color: #333;
                background: #fff;
                border-radius: 8px;
                overflow: hidden;
                border: 1px solid #ccc;
                margin-bottom: 1em;
                position: relative;
                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                direction: ltr;
            }
            /* Footer with black gradient */
            .paste_embed_footer {
                font-size: 12px;
                padding: 8px;
                background: linear-gradient(90deg, #000000, #333333);
                color: #ffffff;
                border-top: 1px solid #ccc;
            }
            .paste_embed_footer a {
                color: #ffffff;
                text-decoration: none;
            }
            .paste_embed_footer a:hover {
                text-decoration: underline;
            }
            .paste_embed_code {
                margin: 0;
                padding: 12px;
                max-height: 300px;
                overflow-y: auto;
                overflow-x: auto;
                scroll-behavior: smooth;
                position: relative;
                background: #fafafa;
            }
            /* Fade effect */
            .fade-out {
                position: absolute;
                bottom: 38px;
                left: 0;
                right: 0;
                height: 40px;
                background: linear-gradient(to bottom, rgba(250,250,250,0) 0%, rgba(250,250,250,1) 100%);
                pointer-events: none;
            }
            $ges_style
        </style>";

        // Code content
        $output .= "<div class='paste_embed_code'>" . $p_content . "</div>";
        $output .= "<div class='fade-out'></div>";

        // Footer
        $output .= "<div class='paste_embed_footer'>
            <a href='$baseurl/$paste_id'>$p_title</a> {$lang['embed-hosted-by']}
            <a href='$baseurl'>$title</a> | 
            <a href='$baseurl/raw/$paste_id'>" . strtolower($lang['view-raw']) . "</a>
        </div>";

        $output .= "</div>";

        header( 'Content-type: text/javascript; charset=utf-8;' );
        echo 'document.write(' . json_encode( $output ) . ')';
        $stats = true;
    } else {
        header( 'HTTP/1.1 404 Not Found' );
    }
    return $stats;
}


function getEmbedUrl($paste_id, $mod_rewrite, $baseurl) {
    if ($mod_rewrite) {
        return $baseurl . 'embed/' . $paste_id;
    } else {
        return $baseurl . 'paste.php?embed&id=' . $paste_id;
    }
}

function addToSitemap(PDO $pdo, int $paste_id, string $priority, string $changefreq, bool $mod_rewrite): bool
{
    try {
	    global $baseurl, $mod_rewrite;
        $c_date = date('Y-m-d H:i:s');
        $server_name = $mod_rewrite
            ? $baseurl . $paste_id
            : $baseurl . "paste.php?id=" . $paste_id;
        $site_data = file_exists('sitemap.xml') ? file_get_contents('sitemap.xml') : '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        $site_data = rtrim($site_data, "</urlset>");
        $c_sitemap = "\t<url>\n\t\t<loc>" . htmlspecialchars($server_name, ENT_QUOTES, 'UTF-8') . "</loc>\n\t\t<priority>$priority</priority>\n\t\t<changefreq>$changefreq</changefreq>\n\t\t<lastmod>$c_date</lastmod>\n\t</url>\n</urlset>";
        $full_map = $site_data . $c_sitemap;
        return file_put_contents('sitemap.xml', $full_map) !== false;
    } catch (Exception $e) {
        error_log("Failed to update sitemap for paste ID {$paste_id}: " . $e->getMessage());
        return false;
    }
}

function is_banned(PDO $pdo, string $ip): bool
{
    try {
        $query = "SELECT COUNT(*) FROM ban_user WHERE ip = :ip";
        $stmt = $pdo->prepare($query);
        $stmt->execute(['ip' => $ip]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("Failed to check ban status for IP {$ip}: " . $e->getMessage());
        return false;
    }
}

// Get a single page by its slug-like name (pages.page_name), only if active.
function getPageByName(PDO $pdo, string $page_name): ?array
{
    try {
        $stmt = $pdo->prepare("
            SELECT id, last_date, page_name, page_title, page_content, location, nav_parent, sort_order, is_active
            FROM pages
            WHERE page_name = :name AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute(['name' => $page_name]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (PDOException $e) {
        error_log("getPageByName failed for {$page_name}: " . $e->getMessage());
        return null;
    }
}

/**
 * Build a page URL that respects mod_rewrite.
 * With mod_rewrite:  {$baseurl}page/{page_name}
 * Without:          {$baseurl}page.php?p={page_name}
 */
function getPageUrl(string $page_name): string
{
    global $baseurl, $mod_rewrite;

    $safe = rawurlencode($page_name);
    if (!empty($mod_rewrite) && $mod_rewrite === "1") {
        return rtrim($baseurl, '/') . '/page/' . $safe;
    }
    return rtrim($baseurl, '/') . '/pages.php?p=' . $safe;
}

/**
 * Fetch pages for a given location (header|footer).
 * Returns a hierarchical array: each item has keys: id, name, title, url, children[]
 */
function getNavLinks(PDO $pdo, string $location): array
{
    $location = in_array($location, ['header', 'footer'], true) ? $location : 'header';

    try {
        // Get all active pages that match this location or are marked for both
        $stmt = $pdo->prepare("
            SELECT id, page_name, page_title, nav_parent, sort_order
            FROM pages
            WHERE is_active = 1
              AND (location = :loc OR location = 'both')
            ORDER BY sort_order ASC, page_title ASC
        ");
        $stmt->execute(['loc' => $location]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $items = [];
        foreach ($rows as $r) {
            $items[(int)$r['id']] = [
                'id'       => (int)$r['id'],
                'name'     => (string)$r['page_name'],
                'title'    => (string)$r['page_title'],
                'parent'   => $r['nav_parent'] !== null ? (int)$r['nav_parent'] : null,
                'order'    => (int)$r['sort_order'],
                'url'      => getPageUrl((string)$r['page_name']),
                'children' => [],
            ];
        }

        // Build tree
        $tree = [];
        foreach ($items as $id => &$node) {
            if ($node['parent'] !== null && isset($items[$node['parent']])) {
                $items[$node['parent']]['children'][] = &$node;
            } else {
                $tree[] = &$node;
            }
        }
        unset($node); // break reference

        // Ensure children are sorted (by sort_order then title)
        $sortFn = static function (&$list) use (&$sortFn) {
            usort($list, static function ($a, $b) {
                return ($a['order'] <=> $b['order']) ?: strcasecmp($a['title'], $b['title']);
            });
            foreach ($list as &$i) {
                if (!empty($i['children'])) {
                    $sortFn($i['children']);
                }
            }
            unset($i);
        };
        $sortFn($tree);

        return $tree;
    } catch (PDOException $e) {
        error_log("getNavLinks failed for {$location}: " . $e->getMessage());
        return [];
    }
}


// Simple HTML renderer for nav links.
function renderNavListSimple(array $links, string $separator = ''): string
{
    // Render a flat inline list if separator provided, else nested <ul>
    if ($separator !== '') {
        $flat = [];
        $stack = $links;
        while ($stack) {
            $node = array_shift($stack);
            $flat[] = '<a href="' . htmlspecialchars($node['url'], ENT_QUOTES, 'UTF-8') . '">' .
                      htmlspecialchars($node['title'], ENT_QUOTES, 'UTF-8') . '</a>';
            foreach ($node['children'] as $child) {
                $stack[] = $child;
            }
        }
        return implode($separator, $flat);
    }

    $render = static function (array $nodes) use (&$render): string {
        $html = "<ul>";
        foreach ($nodes as $n) {
            $html .= '<li><a href="' . htmlspecialchars($n['url'], ENT_QUOTES, 'UTF-8') . '">' .
                     htmlspecialchars($n['title'], ENT_QUOTES, 'UTF-8') . '</a>';
            if (!empty($n['children'])) {
                $html .= $render($n['children']);
            }
            $html .= '</li>';
        }
        $html .= "</ul>";
        return $html;
    };
    return $render($links);
}

// Fetch only the content of a page by name if active (helper for page.php).
function getPageContentByName(PDO $pdo, string $page_name): ?array
{
    try {
        $stmt = $pdo->prepare("
            SELECT page_title, page_content, last_date
            FROM pages
            WHERE page_name = :name AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute(['name' => $page_name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) {
        error_log("getPageContentByName failed for {$page_name}: " . $e->getMessage());
        return null;
    }
}

/**
 * Bootstrap 5 nav renderer (supports one dropdown level).
 * Returns <li> items ready to live inside <ul class="navbar-nav">.
 */
function renderBootstrapNav(array $links): string
{
    $html = '';
    foreach ($links as $item) {
        $title = htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8');
        $url   = htmlspecialchars($item['url'],   ENT_QUOTES, 'UTF-8');

        if (!empty($item['children'])) {
            $id = 'dd_' . $item['id'];
            $html .= '<li class="nav-item dropdown">';
            $html .= '<a class="nav-link dropdown-toggle" href="#" id="'. $id .'" role="button" data-bs-toggle="dropdown" aria-expanded="false">'. $title .'</a>';
            $html .= '<ul class="dropdown-menu" aria-labelledby="'. $id .'">';
            foreach ($item['children'] as $child) {
                $ctitle = htmlspecialchars($child['title'], ENT_QUOTES, 'UTF-8');
                $curl   = htmlspecialchars($child['url'],   ENT_QUOTES, 'UTF-8');
                $html  .= '<li><a class="dropdown-item" href="'. $curl .'">'. $ctitle .'</a></li>';
            }
            $html .= '</ul></li>';
        } else {
            $html .= '<li class="nav-item"><a class="nav-link" href="'. $url .'">'. $title .'</a></li>';
        }
    }
    return $html;
}

/**
 * sanitizer for Markdown output.
 * Keeps only common Markdown tags + safe attributes, strips on* and style, validates href/src.
 */
if (!function_exists('sanitize_allowlist_html')) {
    function sanitize_allowlist_html(string $html): string {
        $allowedTags = [
            'p','br','hr','em','strong','i','b','u','s','del','ins','code','pre','kbd','samp',
            'blockquote','ul','ol','li','dl','dt','dd',
            'h1','h2','h3','h4','h5','h6',
            'table','thead','tbody','tfoot','tr','th','td',
            'a','img'
        ];
        $allowedAttrs = [
            'a'   => ['href','title'],
            'img' => ['src','alt','title'],
            '*'   => [] // no other attributes anywhere
        ];

        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $walker = function(DOMNode $node) use (&$walker, $allowedTags, $allowedAttrs) {
            for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
                $child = $node->childNodes->item($i);
                if (!($child instanceof DOMElement)) continue;

                $tag = strtolower($child->nodeName);
                if (!in_array($tag, $allowedTags, true)) {
                    // unwrap unknown element but keep its children/text
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }

                // clean attributes
                $allowed = $allowedAttrs[$tag] ?? $allowedAttrs['*'];
                $toRemove = [];
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $name = strtolower($attr->name);
                    $val  = $attr->value;

                    // nuke event handlers and inline styles entirely
                    if (str_starts_with($name, 'on') || $name === 'style') { $toRemove[] = $name; continue; }

                    // enforce allowlist
                    if (!in_array($name, $allowed, true)) { $toRemove[] = $name; continue; }

                    // validate href/src values
                    if ($tag === 'a' && $name === 'href') {
                        if (!preg_match('#^(https?://|mailto:)#i', $val)) { $toRemove[] = $name; continue; }
                        // add safe rel/target
                        $child->setAttribute('rel', 'nofollow noopener noreferrer');
                        $child->setAttribute('target', '_blank');
                    }
                    if ($tag === 'img' && $name === 'src') {
                        // allow only http/https images, disallow svg (can execute scripts)
                        if (!preg_match('#^https?://#i', $val)) { $toRemove[] = $name; continue; }
                        $path = parse_url($val, PHP_URL_PATH) ?? '';
                        if (preg_match('#\.svg(\?.*)?$#i', (string)$path)) { $toRemove[] = $name; continue; }
                    }
                }
                foreach ($toRemove as $r) { $child->removeAttribute($r); }

                $walker($child);
            }
        };
        $walker($doc);

        return $doc->saveHTML() ?: '';
    }
}
// ------------------------------------------------------------
// Autodetect helpers (shared by highlight.php & GeSHi paths)
// ------------------------------------------------------------

/** Stricter Markdown sniff: avoid false matches from lists-only or C/C++ code. */
function paste_probable_markdown(string $s): bool {
    // Strong MD signals
    $fenced  = (bool) preg_match('/^\s*(```|~~~)\s*[a-z0-9._+-]*\s*$/mi', $s);
    $links   = (bool) preg_match('/\[[^\]]+\]\([^)]+\)/', $s) || preg_match('/!\[[^\]]*\]\([^)]+\)/', $s);
    $tables  = (bool) (preg_match('/^\s*\|.+\|\s*$/m', $s) &&
                       preg_match('/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/m', $s));
    if ($fenced || $links || $tables) return true;

    // ATX/Setext headings — but guard against YAML (comments with "# ..." on first lines)
    $atx     = (bool) preg_match('/^\s*#{1,6}\s+\S/m', $s);
    $setext  = (bool) preg_match('/^[^\r\n]+\r?\n=+\s*$/m', $s) || preg_match('/^[^\r\n]+\r?\n-+\s*$/m', $s);

    // Weaker cues need at least one "markdown-only" feature
    $bullets2   = (preg_match_all('/^\s*[-*+]\s+\S/m', $s) >= 2);
    $blockquote = (bool) preg_match('/^\s*>\s+\S/m', $s);
    $inlinecode = (bool) preg_match('/`[^`\r\n]+`/', $s);

    // If likely YAML (lots of key: value lines), do NOT treat single "# ..." as markdown
    $yaml_like = (preg_match_all('/^\s*[A-Za-z0-9_.-]+\s*:\s+[^#\r\n]+$/m', $s) ?: 0) >= 3;

    if ($yaml_like && $atx && !($fenced || $links || $tables)) {
        // Prefer YAML in this ambiguous case
        return false;
    }

    return ($atx || $setext) || ($bullets2 && ($blockquote || $inlinecode));
}

/** Friendly label if none is provided. */
function paste_friendly_label(string $id): string {
    $t = str_replace(['-', '_'], ' ', strtolower($id));
    $t = ucwords($t);
    $t = preg_replace('/\bSql\b/i','SQL',$t);
    $t = preg_replace('/\bJson\b/i','JSON',$t);
    $t = preg_replace('/\bYaml\b/i','YAML',$t);
    $t = preg_replace('/\bXml\b/i','XML',$t);
    return $t;
}

/** Map a detection "source" to a short UI label. */
function paste_detect_source_label(string $src): string {
    $map = [
        'filename'  => 'Filename',
        'modeline'  => 'Editor modeline',
        'shebang'   => 'Shebang',
        'php-tag'   => 'PHP tag',
        'markdown'  => 'Markdown markers',
        'hljs'      => 'Highlighter auto',
        'auto'      => 'Highlighter auto',
        'ensemble'  => 'Heuristics',
        'heuristic' => 'Heuristics',
        'explicit'  => 'Selected',
        'fallback'  => 'Fallback',
    ];
    return $map[$src] ?? paste_friendly_label($src);
}

/** Synonym map used by shebangs/heuristics -> highlight ids. */
function paste_synonyms_map(): array {
    return [
        // shells
        'sh'=>'bash','bash'=>'bash','zsh'=>'bash','ksh'=>'bash','dash'=>'bash','csh'=>'bash','tcsh'=>'bash','busybox'=>'bash','fish'=>'bash',
        // node/js/ts
        'node'=>'javascript','nodejs'=>'javascript','deno'=>'javascript','bun'=>'javascript',
        'ts'=>'typescript','tsx'=>'typescript','ts-node'=>'typescript','ts-node-script'=>'typescript',
        // python
        'python'=>'python','python2'=>'python','python3'=>'python','pypy'=>'python','pypy3'=>'python',
        // perl/raku
        'perl'=>'perl','perl5'=>'perl','perl6'=>'perl','raku'=>'perl',
        // ruby
        'ruby'=>'ruby','jruby'=>'ruby','truffleruby'=>'ruby',
        // jvm & friends
        'groovysh'=>'groovy','kscript'=>'kotlin',
        // R
        'rscript'=>'r',
        // lua
        'luajit'=>'lua',
        // haskell
        'runghc'=>'haskell','runhaskell'=>'haskell',
        // lisp/scheme
        'guile'=>'scheme','racket'=>'scheme','clisp'=>'lisp',
        // erlang/elixir
        'escript'=>'erlang','iex'=>'elixir',
        // math / sci
        'octave'=>'matlab',
        // nim
        'nim'=>'nimrod','nimscript'=>'nimrod',
        // tcl
        'tcl'=>'tcl','tclsh'=>'tcl','wish'=>'tcl',
        // awk/sed
        'awk'=>'awk','gawk'=>'awk','mawk'=>'awk','nawk'=>'awk','sed'=>'bash',
        // powershell
        'pwsh'=>'powershell','powershell.exe'=>'powershell',
        // php variants
        'php'=>'php','php-cgi'=>'php',
        // qml tools
        'qmlscene'=>'qml','qmlcachegen'=>'qml'
    ];
}

/**
 * Normalize a language id to highlight.php or GeSHi id.
 * $engine: 'highlight' or 'geshi'
 */
function paste_normalize_lang(string $id, string $engine = 'highlight', $hl = null): string {
    global $HL_ALIAS_MAP; // set by includes/list_languages.php
    $id0 = strtolower(trim($id));
    if ($id0 === '') return '';

    $syn = paste_synonyms_map();
    if (isset($syn[$id0])) $id0 = $syn[$id0];

    if ($engine === 'highlight') {
        if (isset($HL_ALIAS_MAP[$id0])) $id0 = $HL_ALIAS_MAP[$id0];
        if ($hl && method_exists($hl, 'listLanguages')) {
            $set = array_map('strtolower', (array)$hl->listLanguages());
            if (!in_array($id0, $set, true)) {
                if ($id0 === 'pgsql' && in_array('sql', $set, true)) return 'sql';
            }
        }
        return $id0;
    }

    // GeSHi mapping
    static $HL_TO_GESHI = [
        'plaintext'=>'text',
        'text'     =>'text',
        'xml'      =>'xml',
        'html'     =>'html5',
        'bash'     =>'bash',
        'dos'      =>'dos',
        'javascript'=>'javascript',
        'typescript'=>'javascript',
        'json'     =>'javascript',
        'yaml'     =>'yaml',
        'ini'      =>'ini',
        'toml'     =>'ini',
        'properties'=>'properties',
        'php'      =>'php',
        'python'   =>'python',
        'ruby'     =>'ruby',
        'perl'     =>'perl',
        'java'     =>'java',
        'c'        =>'c',
        'cpp'      =>'cpp',
        'csharp'   =>'csharp',
        'go'       =>'go',
        'rust'     =>'rust',
        'kotlin'   =>'java',
        'pgsql'    =>'postgresql',
        'sql'      =>'sql',
        'scss'     =>'css',
        'less'     =>'css',
        'markdown' =>'markdown',
        'powershell'=>'powershell',
        'vbnet'    =>'vbnet',
        'objectivec'=>'objc',
        'ocaml'    =>'ocaml',
        'haskell'  =>'haskell',
        'lua'      =>'lua',
        'matlab'   =>'matlab',
        'makefile' =>'make',
        'nginx'    =>'nginx',
        'apache'   =>'apache',
        'dockerfile'=>'bash',
        'vbscript' =>'vbscript',
        'vbscript-html'=>'vbscript-html',
        'vhdl'     =>'vhdl',
        'verilog'  =>'verilog',
        'x86asm'   =>'asm',
        'mirc'     =>'mirc',
        'qml'      =>'qml',
    ];
    return $HL_TO_GESHI[$id0] ?? 'text';
}

/** Robust shebang detection; returns *highlight* id (normalized) or null. */
function paste_detect_shebang(string $s): ?string {
    if (!preg_match('/^\s*#!\s*(.+)$/m', $s, $m)) return null;
    $line = strtolower(trim($m[1]));
    if (preg_match('/env(?:\s+-s)?\s+([^\s]+)/', $line, $em)) {
        $prog = $em[1];
    } else {
        $parts = preg_split('/\s+/', $line);
        $exe   = $parts[0] ?? '';
        $prog  = basename($exe);
    }
    $prog = preg_replace('/(\.exe)?$/', '', $prog);
    $prog = preg_replace('/\d+$/', '', $prog);
    return paste_normalize_lang($prog, 'highlight', null);
}

/** Modeline detection (vim/emacs). Returns highlight id or null. */
function paste_detect_modeline(string $s): ?string {
    // Vim: "vim: set ft=python", "vi: set filetype=javascript", "vim:ft=sh"
    if (preg_match('/\b(?:vi|vim):.*?\b(?:ft|filetype)\s*=\s*([a-z0-9._+-]+)/i', $s, $m)) {
        return paste_normalize_lang(strtolower($m[1]), 'highlight', null);
    }
    // Emacs: "-*- mode: ruby -*-" or "-*- ruby -*-"
    if (preg_match('/-\*-\s*(?:mode:\s*)?([a-z0-9._+-]+)\s*-\*-/i', $s, $m)) {
        return paste_normalize_lang(strtolower($m[1]), 'highlight', null);
    }
    return null;
}

/** From file title extension. Returns highlight id or null. */
function paste_detect_from_title_ext(?string $title, string $code): ?string {
    if (!$title) return null;
    $t = strtolower(trim($title));
    // Pull last ".ext" (handles "name.tar.gz" -> gz; acceptable for our use)
    if (!preg_match('/\.([a-z0-9_+-]+)\s*$/', $t, $m)) return null;
    $ext = $m[1];

    // Disambiguate certain extensions using content when needed
    if ($ext === 'm') {
        // Objective-C vs MATLAB
        if (preg_match('/@interface|@implementation|#\s*import\s*<Foundation/i', $code)) return 'objectivec';
        return 'matlab';
    }

    static $MAP = [
        'php'=>'php', 'phtml'=>'php', 'php3'=>'php', 'php4'=>'php', 'php5'=>'php', 'phps'=>'php',
        'html'=>'html','htm'=>'html','xhtml'=>'xml','xml'=>'xml',
        'js'=>'javascript','mjs'=>'javascript','cjs'=>'javascript',
        'ts'=>'typescript','tsx'=>'typescript','jsx'=>'javascript',
        'json'=>'json','ndjson'=>'json',
        'yml'=>'yaml','yaml'=>'yaml',
        'ini'=>'ini','cfg'=>'ini','conf'=>'ini','properties'=>'properties','toml'=>'toml',
        'md'=>'markdown','markdown'=>'markdown','mkd'=>'markdown',
        'sh'=>'bash','bash'=>'bash','zsh'=>'bash','ksh'=>'bash','bat'=>'dos','cmd'=>'dos','ps1'=>'powershell',
        'py'=>'python','rb'=>'ruby','pl'=>'perl','raku'=>'perl','pm'=>'perl','t'=>'perl',
        'java'=>'java','c'=>'c','h'=>'c','cpp'=>'cpp','cc'=>'cpp','cxx'=>'cpp','hpp'=>'cpp','hh'=>'cpp','hxx'=>'cpp',
        'cs'=>'csharp','go'=>'go','rs'=>'rust','swift'=>'swift','kt'=>'kotlin','kts'=>'kotlin',
        'sql'=>'sql','pgsql'=>'pgsql','psql'=>'pgsql',
        'makefile'=>'makefile','mk'=>'makefile',
        'psh'=>'powershell',
        'lua'=>'lua','tcl'=>'tcl','mrc'=>'mirc','qml'=>'qml',
        'tex'=>'tex','bib'=>'bibtex',
    ];
    return $MAP[$ext] ?? null;
}

/** Fast PHP tag check to hard-lock PHP when present. */
function paste_detect_php_tag(string $s): bool {
    return (bool) preg_match('/<\?(php|=)/i', $s);
}

function paste_natural_language_score(string $code): float {
    // Top 50 common English words (source: Oxford English Corpus; case-insensitive)
    $commonWords = [
        'the', 'be', 'to', 'of', 'and', 'a', 'in', 'that', 'have', 'have',
        'it', 'for', 'not', 'on', 'with', 'he', 'as', 'you', 'do', 'at',
        'this', 'but', 'his', 'by', 'from', 'they', 'we', 'say', 'her', 'she',
        'or', 'an', 'will', 'my', 'one', 'all', 'would', 'there', 'their', 'what',
        'so', 'up', 'out', 'if', 'about', 'who', 'get', 'which', 'go', 'me'
    ];
    
    // Normalize text: lowercase, split into words (alphanumeric only for simplicity)
    $lower = strtolower($code);
    preg_match_all('/\b[a-z]+\b/', $lower, $matches);
    $words = $matches[0] ?? [];
    $totalWords = count($words);
    if ($totalWords < 10) return 0.0; // Too short to judge
    
    $commonCount = 0;
    foreach ($words as $w) {
        if (in_array($w, $commonWords, true)) $commonCount++;
    }
    
    return $commonCount / $totalWords;
}

/**
 * Feature extractor used by the ensemble. Fast & language-agnostic.
 * Returns an array of simple counts/ratios.
 */
function paste_feature_extract(string $code): array {
    $len  = max(1, strlen($code));
    $lines = preg_split('/\R/', $code);
    $lc   = max(1, count($lines));

    // For very large code (>500KB), sample to cap regex time
    if ($len > 500000) {
        $code = substr($code, 0, 250000) . substr($code, -250000);
        $len = strlen($code);
    }

    $semicolon = substr_count($code, ';');
    $braces    = preg_match_all('/[{}]/', $code) ?: 0;

    // HTML/XML-like tags (rough)
    $tags = preg_match_all('/<\s*\/?\s*[A-Za-z!?][^>\n]*>/', $code) ?: 0;

    // C/C++ preprocessor-ish
    $has_preproc = (bool) preg_match('/^\s*#\s*(include|define|if|ifdef|ifndef|endif|pragma)\b/m', $code);

    // YAML-like "key: value" lines (avoid JSON with colons)
    $yaml_key_lines = preg_match_all('/^\s*[A-Za-z0-9_.-]+\s*:\s+[^#\r\n]+$/m', $code) ?: 0;

    // INI headers
    $ini_headers = preg_match_all('/^\s*\[[^\]]+\]\s*$/m', $code) ?: 0;

    // Very rough JSON sniff
    $trim = ltrim($code);
    $json_like = false;
    if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[') && $len < 2000000) {
        $tmp = json_decode($code, true);
        $json_like = (json_last_error() === JSON_ERROR_NONE) && (is_array($tmp) || is_object($tmp));
    }

    // SQL keywords
    $sql_kw = preg_match_all('/\b(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)\b/i', $code) ?: 0;

    // Makefile targets & tab-indented commands
    $make_targets = preg_match_all('/^[A-Za-z0-9_.-]+(?:\s+|)[:](?![=]).*$/m', $code) ?: 0;
    $has_make_tab = (bool) preg_match('/^\t\S+/m', $code);
    if (!$has_make_tab) $make_targets = 0;

    // mIRC signals
    $mirc_patterns = preg_match_all('/(^|\n)\s*on\s+[^:]+:[^:]+:/i', $code) ?: 0;

    // PowerShell cues
    $powershell_sig = preg_match_all('/(^|\n)\s*param\s*\(|\$\w+:\w+|Write-Host|Get-Process|^\s*function\s+\w+\s*\{|Begin\s*\{|Process\s*\{|End\s*\{/i', $code) ?: 0;

    // TCL cues
    $tcl_signals = preg_match_all('/\bproc\s+\w+|\bset\s+\w+|\bputs\s+|\bforeach\s+|\bswitch\s+|\bnamespace\s+|\bexpr\s*\{|(\[string\s+\w+)/i', $code) ?: 0;

    // QML hints
    $qml_hint = 0;
    $qml_hint += preg_match_all('/(^|\n)\s*import\s+Qt[^\r\n]*/', $code) ?: 0;
    $qml_hint += preg_match_all('/(^|\n)\s*[A-Z][A-Za-z0-9_]*\s*\{\s*$/m', $code) ?: 0;

    // Assembly opcodes (for GeSHi/Highlight asm variants like 6502, armasm, z80)
    $assembly_opcodes = preg_match_all('/\b(mov|jmp|add|sub|push|pop|lea|call|ret|int|cmp|test|ldr|str|bl|bx|movz|movk)\b/i', $code) ?: 0;

    // Lisp/Scheme parens density (for lisp, scheme, clojure, racket)
    $lisp_parens = substr_count($code, '(') + substr_count($code, ')');
    $lisp_parens_ratio = $lisp_parens / $len;

    // Fortran fixed-format (columns 1-5 label, 6 continuation, 7-72 code; for fortran)
    $fortran_fixed = preg_match_all('/^ {0,5}\d| {6}[^ !*]/m', $code) ?: 0;

    // BNF/ABNF/EBNF rules (::= or | or <rule>)
    $bnf_rules = preg_match_all('/::=|\||<[^>]+>/', $code) ?: 0;

    // GLSL shaders (for glsl)
    $glsl_keywords = preg_match_all('/\b(gl_Position|vec[234]|mat[234]|uniform|varying|attribute|shader|fragment|vertex)\b/i', $code) ?: 0;

    return [
        'len'              => $len,
        'lines'            => $lc,
        'semicolon'        => $semicolon,
        'braces'           => $braces,
        'brace_ratio'      => $braces / $len,
        'semicolon_ratio'  => $semicolon / $lc,
        'tag_count'        => $tags,
        'tag_density'      => $tags / $lc,
        'has_preproc'      => $has_preproc,
        'yaml_key_lines'   => $yaml_key_lines,
        'ini_headers'      => $ini_headers,
        'json_like'        => $json_like,
        'sql_keywords'     => $sql_kw,
        'make_targets'     => $make_targets,
        'mirc_patterns'    => $mirc_patterns,
        'powershell_sig'   => $powershell_sig,
        'tcl_signals'      => $tcl_signals,
        'qml_hint'         => $qml_hint,
        'assembly_opcodes' => $assembly_opcodes,
        'lisp_parens_ratio' => $lisp_parens_ratio,
        'fortran_fixed'    => $fortran_fixed,
        'bnf_rules'        => $bnf_rules,
        'glsl_keywords'    => $glsl_keywords,
    ];
}

/** Build a reduced autodetect allowlist for highlight.php */
function paste_hl_autodetect_allowlist($hl, string $code, bool $allowPhp): ?array {
    if (!$hl || !method_exists($hl, 'listLanguages')) return null;
    $langs = (array)$hl->listLanguages();
    $out = [];
    foreach ($langs as $L) {
        $id = strtolower((string)$L);
        if ($id === 'markdown') continue;          // we render Markdown via Parsedown
        if (!$allowPhp && $id === 'php') continue; // avoid PHP false positives when tags absent
        $out[] = $id;
    }
    return $out;
}

/**
 * Build engine-specific candidate ids from generic features.
 * Keeps YAML out unless it actually looks like YAML, and avoids HTML when C/C++ preproc is present.
 */
function paste_candidate_languages(string $engine, array $f): array {
    $c_like    = ['cpp','c','java','csharp','go','rust','kotlin','objectivec','javascript','typescript'];
    $scripting = ['python','ruby','perl','php','lua','tcl','javascript','typescript','bash','powershell'];
    $data      = ['json','ini','properties','toml','sql','pgsql']; // YAML added below only on signal
    $markup    = ['xml','html','xhtml'];
    $build     = ['makefile','cmake','nginx','apache','dos'];
    $misc      = ['mirc','qml','markdown', ($engine === 'highlight' ? 'plaintext' : 'text')];

    $cand = [];

    // Strong signals
    if ($f['json_like'])                $cand[] = 'json';
    if ($f['ini_headers'] > 0)          array_push($cand, 'ini', 'properties');
    if ($f['sql_keywords'] > 0)         array_push($cand, 'sql', 'pgsql');
    if ($f['make_targets'] > 0)         $cand[] = 'makefile';
    if ($f['mirc_patterns'] > 0)        $cand[] = 'mirc';
    if ($f['powershell_sig'] > 0)       $cand[] = 'powershell';
    if ($f['tcl_signals'] > 0)          array_push($cand, 'tcl','perl');
    if ($f['qml_hint'] > 0)             array_push($cand, 'qml','javascript');

    // Markup only when it dominates and no preprocessor
    if ($f['tag_density'] > 0.015 && !$f['has_preproc']) {
        $cand = array_merge($cand, $markup);
    }

    // YAML only with clear YAML shape and low C-like signals
    if ($f['yaml_key_lines'] >= 3 && $f['brace_ratio'] < 0.02 && $f['semicolon_ratio'] < 0.15) {
        $cand[] = 'yaml';
    }

    // C-like?
    if ($f['has_preproc'] || $f['semicolon_ratio'] >= 0.15 || $f['brace_ratio'] >= 0.02) {
        $cand = array_merge($cand, $c_like);
    }

    // Always consider scripting & some data/build & misc
    $cand = array_merge($cand, $scripting, $data, $build, $misc);

    // Dedup & cap
    $seen = []; $out = [];
    foreach ($cand as $id) {
        $id = strtolower($id);
        if (!isset($seen[$id])) { $seen[$id] = true; $out[] = $id; }
        if (count($out) >= 24) break;
    }

    if ($engine !== 'highlight') {
        $mapped = [];
        foreach ($out as $id) $mapped[] = paste_normalize_lang($id, 'geshi', null);
        return array_values(array_unique($mapped));
    }
    return $out;
}

/** Simple heuristic pick for GeSHi (returns a *highlight* id). */
function paste_pick_from_features(array $f): string {
    if ($f['json_like']) return 'json';
    if ($f['yaml_key_lines'] >= 3 && $f['semicolon_ratio'] < 0.15) return 'yaml';
    if ($f['sql_keywords'] > 0) return 'sql';
    if ($f['mirc_patterns'] > 0) return 'mirc';
    if ($f['powershell_sig'] > 0) return 'powershell';
    if ($f['tcl_signals'] > 0) return 'tcl';
    if ($f['ini_headers'] > 0) return 'ini';
    if ($f['make_targets'] > 0) return 'makefile';
    if ($f['tag_density'] > 0.02 && !$f['has_preproc']) return 'xml';
    if ($f['qml_hint'] > 0) return 'qml';
    if ($f['has_preproc'] || $f['brace_ratio'] >= 0.02) return 'cpp';
    if ($f['semicolon_ratio'] >= 0.15) return 'javascript';
    // Assembly if opcodes present and low semicolons (for 6502, arm, z80, etc.)
    if ($f['assembly_opcodes'] >= 5 && $f['semicolon_ratio'] < 0.1) return 'x86asm'; // or 'armasm', etc.; group as 'asm'
    // Lisp if high parens ratio
    if ($f['lisp_parens_ratio'] > 0.05) return 'lisp'; // or 'scheme', 'clojure'
    // Fortran fixed-format
    if ($f['fortran_fixed'] >= 3) return 'fortran';
    // BNF/ABNF if rules present
    if ($f['bnf_rules'] >= 5) return 'bnf'; // or 'abnf', 'ebnf'
    // GLSL if keywords present
    if ($f['glsl_keywords'] >= 3) return 'glsl';
    return 'plaintext';
}

/**
 * One entry-point: detect language.
 * Returns: ['id' => engine id, 'label' => display, 'source' => 'filename|modeline|shebang|php-tag|markdown|hljs|heuristic|explicit|fallback']
 * $engine: 'highlight' or 'geshi'
 *
 * NOTE: Uses global $p_title (paste title) for filename extension hints.
 */
function paste_autodetect_language(string $code, string $engine = 'highlight', $hl = null): array {
    global $p_title;

    // 0) Hard locks
    if (paste_detect_php_tag($code)) {
        $id = ($engine === 'geshi') ? paste_normalize_lang('php', 'geshi', null) : 'php';
        return ['id'=>$id, 'label'=>'PHP', 'source'=>'php-tag'];
    }

    // 1) Filename extension from title
    if ($p_title && ($extId = paste_detect_from_title_ext($p_title, $code))) {
        $id = ($engine === 'geshi') ? paste_normalize_lang($extId, 'geshi', null) : paste_normalize_lang($extId, 'highlight', $hl);
        return ['id'=>$id, 'label'=>paste_friendly_label($id), 'source'=>'filename'];
    }

    // 2) Shebang
    if ($lang = paste_detect_shebang($code)) {
        $id = ($engine === 'geshi') ? paste_normalize_lang($lang, 'geshi', null) : $lang;
        return ['id'=>$id, 'label'=>paste_friendly_label($id), 'source'=>'shebang'];
    }

    // 3) Editor modelines
    if ($lang = paste_detect_modeline($code)) {
        $id = ($engine === 'geshi') ? paste_normalize_lang($lang, 'geshi', null) : $lang;
        return ['id'=>$id, 'label'=>paste_friendly_label($id), 'source'=>'modeline'];
    }

    // 4) Markdown quick sniff (guard against YAML-dominant text)
    if (paste_probable_markdown($code)) {
        // If strong YAML shape, prefer YAML over markdown
        $yaml_keys = preg_match_all('/^\s*[A-Za-z0-9_.-]+\s*:\s+[^#\r\n]+$/m', $code) ?: 0;
        if ($yaml_keys < 3) {
            $id = ($engine === 'geshi') ? 'markdown' : 'markdown';
            return ['id'=>$id, 'label'=>'Markdown', 'source'=>'markdown'];
        }
    }

    // 5) Quick natural language check for plaintext
    $nlScore = paste_natural_language_score($code);
    if ($nlScore > 0.20 && strlen($code) > 100) {  // Threshold: 20% common words, min length to avoid noise
        // Confirm no strong code signals (adjust thresholds as needed)
        $F = paste_feature_extract($code);  // Extract features if not already
        if ($F['semicolon_ratio'] < 0.15 && $F['brace_ratio'] < 0.02 && !$F['has_preproc'] && $F['tag_density'] < 0.01) {
            $id = ($engine === 'highlight') ? 'plaintext' : 'text';
            return ['id' => $id, 'label' => 'Plain Text', 'source' => 'heuristic'];
        }
    }

    // 6) Feature-driven ensemble (no auto for 'highlight')
    $F = paste_feature_extract($code);
    $lang = paste_pick_from_features($F);
    if ($lang === 'markdown') {
        return ['id'=>'markdown', 'label'=>'Markdown', 'source'=>'heuristic'];
    }
    $id = paste_normalize_lang($lang, $engine, $hl);
    if ($id === '') $id = ($engine === 'highlight' ? 'plaintext' : 'text');
    return ['id'=>$id, 'label'=>paste_friendly_label($id), 'source'=>'heuristic'];
}

/** Comments: fetch latest (simple, no threading) */
function getPasteComments(PDO $pdo, int $paste_id, int $limit = 50, int $offset = 0): array {
    try {
        $sql = "SELECT c.id, c.paste_id, c.user_id, c.username, c.body, c.created_at, c.ip
                FROM paste_comments c
                WHERE c.paste_id = :pid
                ORDER BY c.created_at DESC
                LIMIT :lim OFFSET :off";
        $st = $pdo->prepare($sql);
        $st->bindValue(':pid', $paste_id, PDO::PARAM_INT);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log("getPasteComments($paste_id): " . $e->getMessage());
        return [];
    }
}

function addPasteComment(
    PDO $pdo,
    int $paste_id,
    ?int $user_id = null,
    string $username = 'Guest',
    string $ip = '0.0.0.0',
    string $body = '',
    ?int $parent_id = null
): ?int {
    try {
        $body = trim($body);
        if ($body === '') return null;
        if (strlen($body) > 4000) $body = substr($body, 0, 4000);

        // if replying, make sure the parent exists and belongs to the same paste
        if ($parent_id !== null) {
            $chk = $pdo->prepare("SELECT paste_id FROM paste_comments WHERE id = ?");
            $chk->execute([$parent_id]);
            $pp = $chk->fetchColumn();
            if ((int)$pp !== $paste_id) {
                $parent_id = null; // ignore bogus parent
            }
        }

        $st = $pdo->prepare("
            INSERT INTO paste_comments (paste_id, parent_id, user_id, username, body, created_at, ip)
            VALUES (:pid, :parent, :uid, :un, :body, :ts, :ip)
        ");
        $st->execute([
            ':pid'    => $paste_id,
            ':parent' => $parent_id,
            ':uid'    => $user_id,
            ':un'     => $username,
            ':body'   => $body,
            ':ts'     => date('Y-m-d H:i:s'),
            ':ip'     => $ip
        ]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("addPasteComment($paste_id): " . $e->getMessage());
        return null;
    }
}

function getPasteCommentsTree(PDO $pdo, int $paste_id): array {
    try {
        $sql = "SELECT id, paste_id, parent_id, user_id, username, body, created_at, ip
                FROM paste_comments
                WHERE paste_id = :pid
                ORDER BY created_at ASC, id ASC";
        $st = $pdo->prepare($sql);
        $st->execute([':pid' => $paste_id]);
        $all = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // index and attach children
        $byId = [];
        foreach ($all as $r) {
            $r['children'] = [];
            $byId[$r['id']] = $r;
        }
        $roots = [];
        foreach ($byId as $id => &$node) {
            $p = $node['parent_id'] ?? null;
            if (!empty($p) && isset($byId[$p])) {
                $byId[$p]['children'][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }
        unset($node);
        return $roots;
    } catch (PDOException $e) {
        error_log("getPasteCommentsTree($paste_id): " . $e->getMessage());
        return [];
    }
}


/** Comments: authorisation check for delete (owner of comment) */
function userOwnsComment(PDO $pdo, int $comment_id, int $user_id, string $username): bool {
    try {
        $st = $pdo->prepare("SELECT user_id, username FROM paste_comments WHERE id = ?");
        $st->execute([$comment_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        // Prefer user_id match; fallback to username (for old/guest cases)
        if (!empty($row['user_id']) && (int)$row['user_id'] === (int)$user_id) return true;
        return strcasecmp((string)$row['username'], $username) === 0;
    } catch (PDOException $e) {
        error_log("userOwnsComment($comment_id): " . $e->getMessage());
        return false;
    }
}

/** Comments: delete */
function deleteComment(PDO $pdo, int $comment_id): bool {
    try {
        $st = $pdo->prepare("DELETE FROM paste_comments WHERE id = ?");
        return $st->execute([$comment_id]);
    } catch (PDOException $e) {
        error_log("deleteComment($comment_id): " . $e->getMessage());
        return false;
    }
}

/** Minimal safe render for comment body: escape + autolink + nl2br */
function render_comment_html(string $text): string {
    $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    // autolink http/https
    $safe = preg_replace('~(?i)\bhttps?://[^\s<]+~', '<a href="$0" target="_blank" rel="nofollow noopener noreferrer">$0</a>', $safe);
    return nl2br($safe);
}

// --- Fatal Errors ---
if (!function_exists('paste_error')) {
    function paste_error(string $msg, int $httpCode = 200): void
    {
        // Variables expected by theme/default/errors.php
        $error            = $msg;
        $notfound         = null;
        $require_password = false;
        $paste_id         = 0;

        // fallbacks
        $default_theme = (string)($GLOBALS['default_theme'] ?? 'default');
        $baseurl       = (string)($GLOBALS['baseurl'] ?? '/');
        $mod_rewrite   = (string)($GLOBALS['mod_rewrite'] ?? '0');
        $lang          = (array)($GLOBALS['lang'] ?? []);
        $title         = (string)($GLOBALS['title'] ?? 'Error');
        $site_name     = (string)($GLOBALS['site_name'] ?? 'Paste');
        $des           = (string)($GLOBALS['des'] ?? '');
        $keyword       = (string)($GLOBALS['keyword'] ?? '');
        $ga            = (string)($GLOBALS['ga'] ?? '');
        $additional_scripts = (string)($GLOBALS['additional_scripts'] ?? '');
        $ads_1         = (string)($GLOBALS['ads_1'] ?? '');
        $ads_2         = (string)($GLOBALS['ads_2'] ?? '');
        $text_ads      = (string)($GLOBALS['text_ads'] ?? '');
        $privatesite   = (string)($GLOBALS['privatesite'] ?? 'off');
        $noguests      = (string)($GLOBALS['noguests'] ?? 'off');
        $enablegoog    = (string)($GLOBALS['enablegoog'] ?? 'no');
        $enablefb      = (string)($GLOBALS['enablefb'] ?? 'no');
        $hl_style      = (string)($GLOBALS['hl_style'] ?? 'hybrid.css');
        $ges_style     = (string)($GLOBALS['ges_style'] ?? '');

        // Provide a safe $pdo so header.php can call getNavLinks($pdo,'header')
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            $pdo = $GLOBALS['pdo']; // make it local for the include scope
        } else {
            if (!class_exists('PasteDummyPDO')) {
                class PasteDummyPDO extends PDO {
                    public function __construct() { /* no parent ctor */ }
                    public function prepare($query, $options = []) { throw new PDOException('DB unavailable'); }
                    public function query($query, $mode = null, ...$args) { throw new PDOException('DB unavailable'); }
                }
            }
            $pdo = new PasteDummyPDO();
            $GLOBALS['pdo'] = $pdo; // optional
        }

        // If we're here from an OOM, avoid loading header/footer (keeps memory tiny)
        if (!empty($GLOBALS['__PASTE_FATAL_OOM__'])) {
            while (ob_get_level() > 0) { @ob_end_clean(); }
            http_response_code($httpCode);
            header('Content-Type: text/html; charset=utf-8');
            $safe = htmlspecialchars($error, ENT_QUOTES, 'UTF-8');
            exit("<!doctype html><meta charset='utf-8'><title>Error</title><p>{$safe}</p>");
        }

        // theme
        $rootDir  = dirname(__DIR__);
        $themeDir = $rootDir . '/theme/' . basename($default_theme);
        $header   = $themeDir . '/header.php';
        $errors   = $themeDir . '/errors.php';
        $footer   = $themeDir . '/footer.php';

        // Clean any partial output and set status
        while (ob_get_level() > 0) { @ob_end_clean(); }
        http_response_code($httpCode);

        if (is_file($header) && is_file($errors) && is_file($footer)) {
            require $header;   // sees all locals above ($pdo, $baseurl, $lang, …)
            require $errors;
            require $footer;
            exit;
        }

        // Minimal fallback
        header('Content-Type: text/html; charset=utf-8');
        $safe = htmlspecialchars($error, ENT_QUOTES, 'UTF-8');
        exit("<!doctype html><meta charset='utf-8'><title>Error</title><p>{$safe}</p>");
    }
}

if (!function_exists('paste_enable_themed_errors')) {
    function paste_enable_themed_errors(): void
    {
        if (!empty($GLOBALS['PASTE_NO_THEMED_ERRORS'])) {
            return; // per-script opt-out
        }

        if (!headers_sent()) {
            ob_start(); // allow clearing partial output on fatal
        }

        set_exception_handler(static function (Throwable $ex): void {
            error_log('[paste-uncaught] ' . get_class($ex) . ': ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
            $GLOBALS['error'] = 'An unexpected error occurred while processing your request.';
            paste_error($GLOBALS['error'], 200);
        });

        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0) {
            // Respect @-silencing
            if (!(error_reporting() & $severity)) {
                return false;
            }
            // Escalate only serious, recoverable fatals
            if (in_array($severity, [E_RECOVERABLE_ERROR, E_USER_ERROR], true)) {
                throw new ErrorException($message, 0, $severity, $file, $line);
            }
            return false; // let PHP handle warnings/notices
        });

        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if (!$err) {
                return;
            }

            $type = (int)($err['type'] ?? 0);
            if (!in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            $msg = (string)($err['message'] ?? '');
            $isOOM     = stripos($msg, 'Allowed memory size') !== false;
            $isTimeout = stripos($msg, 'Maximum execution time') !== false;

            if (!$isOOM && !$isTimeout && $type !== E_PARSE) {
                return; // ignore non-fatal shutdowns
            }

            $ml = ini_get('memory_limit') ?: '';
            $mlText = $ml !== '' ? " Current memory_limit in php.ini settings: $ml." : '';

            if ($isOOM) {
                $error = 'The operation needs more memory than the server allows. Try unified view or download the .diff.' . $mlText;
            } elseif ($isTimeout) {
                $error = 'The operation took too long and timed out. Try unified view or downloading the .diff.';
            } else {
                $error = 'A fatal error occurred while rendering this page.';
            }

            $GLOBALS['error'] = $error;
            header('X-Paste-Fatal: ' . ($isOOM ? 'oom' : ($isTimeout ? 'timeout' : 'fatal')));
            paste_error($error, 200);
        });
    }
}

?>