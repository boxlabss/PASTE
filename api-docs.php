<?php
/*
 * API Documentation for Paste
 *
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
paste_enable_themed_errors();

header('Content-Type: text/html; charset=utf-8');

$si = $pdo->query("SELECT * FROM site_info WHERE id = 1")->fetch() ?: [];
$title              = trim((string)($si['title'] ?? 'Paste'));
$des                = trim((string)($si['des'] ?? ''));
$keyword            = trim((string)($si['keyword'] ?? ''));
$site_name          = trim((string)($si['site_name'] ?? 'Paste'));
$baseurl            = paste_request_baseurl(rtrim(trim((string)($si['baseurl'] ?? '')), '/') . '/');
$ga                 = trim((string)($si['ga'] ?? ''));
$additional_scripts = trim((string)($si['additional_scripts'] ?? ''));

$iface         = $pdo->query("SELECT * FROM interface WHERE id = 1")->fetch() ?: [];
$default_lang  = basename(trim((string)($iface['lang'] ?? 'en.php')));
$default_theme = basename(trim((string)($iface['theme'] ?? 'default')));
require_once "langs/$default_lang";

$perm        = $pdo->query("SELECT * FROM site_permissions WHERE id = 1")->fetch() ?: [];
$privatesite = trim((string)($perm['siteprivate'] ?? 'off')) === 'on' ? 'on' : 'off';

$ads      = $pdo->query("SELECT * FROM ads WHERE id = 1")->fetch() ?: [];
$text_ads = trim((string)($ads['text_ads'] ?? ''));
$ads_1    = trim((string)($ads['ads_1'] ?? ''));
$ads_2    = trim((string)($ads['ads_2'] ?? ''));

$api_url = $baseurl . 'api.php';
$apiOptions = ['api_rate_limit' => '60', 'api_guest_pastes' => '0', 'api_guest_uploads' => '0', 'api_guest_rate_limit' => '10'];
try {
    $rows = $pdo->query("SELECT option_name, option_value FROM paste_options
                         WHERE option_name IN ('api_rate_limit', 'api_guest_pastes', 'api_guest_uploads', 'api_guest_rate_limit')")->fetchAll();
    foreach ($rows as $r) {
        $apiOptions[$r['option_name']] = (string)$r['option_value'];
    }
} catch (PDOException $e) {
    // paste_options missing: keep the defaults
}
$api_rate_limit       = max(0, (int)$apiOptions['api_rate_limit']);
$api_guest_pastes     = $apiOptions['api_guest_pastes'] === '1';
$api_guest_uploads    = $apiOptions['api_guest_uploads'] === '1';
$api_guest_rate_limit = max(0, (int)$apiOptions['api_guest_rate_limit']);

$p_title = 'API Documentation';

$template = is_file(__DIR__ . "/theme/$default_theme/api_docs.php") ? "theme/$default_theme/api_docs.php" : 'theme/default/api_docs.php';
require_once "theme/$default_theme/header.php";
require_once $template;
require_once "theme/$default_theme/footer.php";
