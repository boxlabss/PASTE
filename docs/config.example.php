<?php
/*
 * Paste $v3.2 2025/08/16 https://github.com/boxlabss/PASTE
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

$currentversion = 3.2;
$pastelimit = "10"; // 10 MB

// OAuth settings (for signups)
$enablefb = "no";
$enablegoog = "no";
$enablesmtp = "no";
define('G_CLIENT_ID', '');
define('G_CLIENT_SECRET', '');
define('G_REDIRECT_URI', 'https://paste.boxlabs.uk/oauth/google.php');
define('G_APPLICATION_NAME', 'Paste');
define('G_SCOPES', [
    'https://www.googleapis.com/auth/userinfo.profile',
    'https://www.googleapis.com/auth/userinfo.email'
]);
// Database information
$dbhost = "localhost";
$dbuser = "paste";
$dbpassword = "";
$dbname = "paste";

// Secret key for encryption
$sec_key = ""; //bin2hex(random_bytes(32));
define('SECRET', $sec_key);

// set to 1 to enable tidy urls
// see docs for an example nginx conf, or .htaccess
$mod_rewrite = "1";

// Enable SMTP debug logging (uncomment)
// define('SMTP_DEBUG', true);

// Code highlighting engine for non-Markdown pastes: 'highlight' (highlight.php) or 'geshi' (default)
$highlighter = $highlighter ?? 'geshi';

// Style theme for highlighter.php (see includes/Highlight/styles)
$hl_style = 'hybrid.css';

// Comments
$comments_enabled          = true;   // on/off
$comments_require_login    = true;   // if false, guests can comment
$comments_on_protected     = false;  // allow/show comments on password-protected pastes

/**
 * Build the list of selectable formats
 * - When using highlight.php, we get the json language files from includes/Highlight/languages
 * - When using GeSHi, we fall back to the classic list.
 */
require_once __DIR__ . '/includes/list_languages.php';

$popular_formats = []; // set below

if ($highlighter === 'highlight') {
    $langs        = highlight_supported_languages();
    $geshiformats = highlight_language_map($langs);   // id => label
    $HL_ALIAS_MAP = highlight_alias_map($langs);      // alias => id
    $popular_formats = paste_popular_formats_highlight();
} else {
    $geshiformats = ['autodetect' => 'Autodetect (experimental)', 'markdown' => 'Markdown', 'text' => 'Plain Text']
                  + geshi_language_map();
    $HL_ALIAS_MAP = geshi_alias_map($geshiformats);   // alias => id
    $popular_formats = paste_popular_formats_geshi();
}
?>