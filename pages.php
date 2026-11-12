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

$date = date('Y-m-d');
$ip = $_SERVER['REMOTE_ADDR'];
$data_ip = @file_get_contents('tmp/temp.tdata') ?: '';

// Database Connection
global $pdo;

try {
    // Get site info
    $stmt = $pdo->query("SELECT * FROM site_info WHERE id = '1'");
    $row = $stmt->fetch();
    if (!$row) {
        throw new Exception("Site configuration not found.");
    }
    $title = trim($row['title']);
    $des = trim($row['des']);
    $baseurl = paste_request_baseurl(rtrim(trim($row['baseurl'])));
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
    if (!$row) {
        throw new Exception("Interface configuration not found.");
    }
    $default_lang = trim($row['lang']);
    $default_theme = trim($row['theme']);
    require_once("langs/$default_lang");

    // Check if IP is banned
    if (is_banned($pdo, $ip)) die($lang['banned']);

    // Logout
    if (isset($_GET['logout'])) {
        paste_logout_and_redirect($baseurl);
    }

    // Page views
    pageViewTrack($pdo, $ip);

    // Ads
    $stmt = $pdo->query("SELECT * FROM ads WHERE id = '1'");
    $row = $stmt->fetch();
    if (!$row) {
        $text_ads = $ads_1 = $ads_2 = '';
    } else {
        $text_ads = trim($row['text_ads']);
        $ads_1 = trim($row['ads_1']);
        $ads_2 = trim($row['ads_2']);
    }

    // Accept both ?p=slug and ?page=slug (mod_rewrite typically maps to ?p=)
    $page_name = isset($_GET['p']) ? trim($_GET['p']) : (isset($_GET['page']) ? trim($_GET['page']) : '');
    if ($page_name !== '') {
        $stmt = $pdo->prepare("
            SELECT page_title, page_content, last_date, external_url
            FROM pages
            WHERE page_name = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$page_name]);
        $row = $stmt->fetch();
        if ($row) {
            // Check for external URL redirect
            if (!empty($row['external_url'])) {
                $external = $row['external_url'];
                // If relative URL, prepend baseurl
                if (!preg_match('/^https?:\/\//', $external)) {
                    $external = rtrim($baseurl, '/') . '/' . ltrim($external, '/');
                }
                header('Location: ' . $external);
                exit;
            }
            $page_title   = $row['page_title'];
            $page_content = $row['page_content'];
            $last_date    = $row['last_date'];
            $stats        = "OK";
            $p_title      = $page_title;
        } else {
            $page_title   = "Error";
            $page_content = "<div class='alert alert-danger text-center'>Page not found or inactive.</div>";
            $last_date    = $date;
            $stats        = null;
            $p_title      = "Error";
        }
    }

    // Theme
    require_once('theme/' . $default_theme . '/header.php');
    require_once('theme/' . $default_theme . '/pages.php');
    require_once('theme/' . $default_theme . '/footer.php');
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
