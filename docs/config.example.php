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

$currentversion = 3.4;
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

// FILEHOST: uploads from IRC clients (IRCv3 draft/FILEHOST + draft/authtoken).
// See docs/filehost.md.  The IRC server signs a token per upload; we verify it
// with the network's public key, so no IRC credentials ever reach this site.
define('FILEHOST_ENABLED', false);
define('FILEHOST_URL', 'https://paste.example.com/filehost');   // must equal the ircd's Authtoken url (token audience)
define('FILEHOST_ISSUER', 'ExampleNet');                        // the IRC network name (token issuer)
define('FILEHOST_PUBKEY', "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----\n"); // from the ircd: STATS authtoken
define('FILEHOST_DIR', __DIR__ . '/../filehost_files');          // binaries; keep it outside the web root
define('FILEHOST_MAX_BYTES', 25 * 1024 * 1024);
define('FILEHOST_ACCEPT', 'image/*, video/*, text/*');          // Accept-Post (TIFF, SVG and HTML are always refused)
define('FILEHOST_MEMBER', 'irc');                               // site user that owns text uploads (pastes)
define('FILEHOST_EXPIRY', 'M');                                 // paste expiry letter for text uploads
define('FILEHOST_RETAIN_DAYS', 30);                             // binaries are removed after this (0 = keep)
define('FILEHOST_STRIP_METADATA', true);                        // drop EXIF/XMP/text chunks from JPEG, PNG, WebP (lossless)
define('FILEHOST_PER_HOUR', 30);                                // uploads per IRC account per hour

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