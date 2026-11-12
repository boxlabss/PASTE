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

declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

// Load highlighter engine libs conditionally
if (($highlighter ?? 'geshi') === 'geshi') {
    require_once 'includes/geshi.php';
} else {
    require_once __DIR__ . '/includes/hlbootstrap.php';
}

// ensure these are visible to all included templates (header/footer/sidebar)
global $pdo, $mod_rewrite;

// default to avoid notices if config hasn't set it (DB can override later)
if (!isset($mod_rewrite)) {
    $mod_rewrite = '0';
}

$path             = 'includes/geshi/';                        // GeSHi language files
$ges_style        = '';                                       // no inline CSS injection
$require_password = false; // errors.php shows password box when true

// ---------------- Helpers ----------------

// --- highlight theme override (?theme= or ?highlight=) ---
if (($highlighter ?? 'geshi') === 'highlight') {
    $param = $_GET['theme'] ?? $_GET['highlight'] ?? null;
    if ($param !== null) {
        // normalize: accept "dracula", "dracula.css", or "atelier estuary dark"
        $t = strtolower((string)$param);
        $t = str_replace(['+', ' ', '_'], '-', $t);
        $t = preg_replace('~\.css$~', '', $t);
        $t = preg_replace('~[^a-z0-9.-]~', '', $t);

        $stylesRel = 'includes/Highlight/styles';
        $fs = __DIR__ . '/' . $stylesRel . '/' . $t . '.css';
        if (is_file($fs)) {
            // header.php will read this to seed the initial <link>
            $hl_style = $t . '.css';
        }
    }
}

// Map some GeSHi-style names to highlight.js ids
function map_to_hl_lang(string $code): string {
    static $map = [
        'text'        => 'plaintext',
        'html5'       => 'xml',
        'html4strict' => 'xml',
        'php-brief'   => 'php',
        'pycon'       => 'python',
        'postgresql'  => 'pgsql',
        'dos'         => 'dos',
        'vb'          => 'vbnet',
    ];
    $code = strtolower($code);
    return $map[$code] ?? $code;
}

// Wrap hljs tokens in a line-numbered <ol> so togglev() works
function hl_wrap_with_lines(string $value, string $hlLang, array $highlight_lines): string {
    $lines  = explode("\n", $value);
    $digits = max(2, strlen((string) count($lines))); // how many digits do we need?
    $hlset  = $highlight_lines ? array_flip($highlight_lines) : [];

    $out   = [];
    $out[] = '<pre class="hljs"><code class="hljs language-' . htmlspecialchars($hlLang, ENT_QUOTES, 'UTF-8') . '">';
    $out[] = '<ol class="hljs-ln" style="--ln-digits:' . (int)$digits . '">';
    foreach ($lines as $i => $lineHtml) {
        $ln  = $i + 1;
        $cls = isset($hlset[$ln]) ? ' class="hljs-ln-line hljs-hl"' : ' class="hljs-ln-line"';
        $out[] = '<li' . $cls . '><span class="hljs-ln-n">' . $ln . '</span><span class="hljs-ln-c">' . $lineHtml . '</span></li>';
    }
    $out[] = '</ol></code></pre>';
    return implode('', $out);
}

// Add a class to specific <li> lines in GeSHi output (no inline styles)
function geshi_add_line_highlight_class(string $html, array $highlight_lines, string $class = 'hljs-hl'): string {
    if (!$highlight_lines) return $html;
    $targets = array_flip($highlight_lines);
    $i = 0;
    return preg_replace_callback('/<li\b([^>]*)>/', static function($m) use (&$i, $targets, $class) {
        $i++;
        $attrs = $m[1];
        if (!isset($targets[$i])) return '<li' . $attrs . '>';
        if (preg_match('/\bclass="([^"]*)"/i', $attrs, $cm)) {
            $new = trim($cm[1] . ' ' . $class);
            $attrs = preg_replace('/\bclass="[^"]*"/i', 'class="' . htmlspecialchars($new, ENT_QUOTES, 'UTF-8') . '"', $attrs, 1);
        } else {
            $attrs .= ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"';
        }
        return '<li' . $attrs . '>';
    }, $html) ?? $html;
}

// ---------- Explain how autodetect made a decision ----------
function paste_build_explain(string $source, string $title, string $sample, string $lang): string {
    $sample = (string) $sample;
    if ($sample !== '') $sample = substr($sample, 0, 2048);
    $lang   = strtolower($lang);
    $ext    = strtolower((string) pathinfo((string)$title, PATHINFO_EXTENSION));

    switch ($source) {
        case 'php-tag':
            $phpCount  = preg_match_all('/<\?(php|=)/i', $sample, $m1);
            return "Found PHP opening tag(s) (" . (int)$phpCount . ") such as '<?php' or '<?='. Locked to PHP.";
        case 'filename':
            return $ext ? "Paste title ends with .{$ext}. Mapped extension → {$lang}." : "Filename hint used. Mapped to {$lang}.";
        case 'shebang':
            if (preg_match('/^#![^\r\n]+/m', $sample, $m)) return "Shebang line detected: {$m[0]} → {$lang}.";
            return "Shebang detected → {$lang}.";
        case 'modeline':
            if (preg_match('/-\*-\s*mode:\s*([a-z0-9#+-]+)\s*;?/i', $sample, $m)) return "Emacs modeline ‘mode: {$m[1]}’ → {$lang}.";
            if (preg_match('/(?:^|\n)[ \t]*(?:vi|vim):[^\n]*\bfiletype=([a-z0-9#+-]+)/i', $sample, $m)) return "Vim modeline ‘filetype={$m[1]}’ → {$lang}.";
            return "Editor modeline matched → {$lang}.";
        case 'fence':
            if (preg_match('/```([a-z0-9#+._-]{1,32})/i', $sample, $m)) return "Markdown code fence language tag ‘{$m[1]}’ → {$lang}.";
            return "Markdown code fence language tag → {$lang}.";
        case 'markdown': {
            $h = preg_match_all('/^(?:#{1,6}\s|>|-{3,}\s*$|\*\s|\d+\.\s|\[.+?\]\(.+?\))/m', $sample);
            return "Markdown structure detected (headings/lists/links: ~{$h} signals). Rendered as Markdown.";
        }
        case 'heuristic': {
            if (in_array($lang, ['yaml','yml'], true)) {
                $kv   = preg_match_all('/^[ \t\-]*[A-Za-z0-9_.-]+:\s/m', $sample);
                $list = preg_match_all('/^[ \t]*-\s/m', $sample);
                return "YAML heuristics: key:value pairs (~{$kv}) and list items (~{$list}).";
            }
            if (in_array($lang, ['sql','pgsql','mysql','tsql'], true)) {
                $kw = preg_match_all('/\b(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|FROM|WHERE|JOIN)\b/i', $sample);
                return "SQL keywords matched (~{$kw}). Guessed {$lang}.";
            }
            if ($lang === 'makefile' || $lang === 'make') {
                $t = preg_match_all('/^[A-Za-z0-9_.-]+:.*$/m', $sample);
                return "Makefile targets detected (~{$t}).";
            }
            if ($lang === 'dos' || $lang === 'batch') {
                $b = preg_match_all('/^\s*(?:@?echo|rem|set|call|goto)\b/i', $sample);
                return "Batch/DOS commands matched (~{$b}).";
            }
            if ($lang === 'hex') {
                $h = preg_match_all('/^[ \t]*(?:on|alias)[ \t]+\S/mi', $sample);
                return "HexDroid script: on/alias blocks (~{$h}) with HexDroid-only headers or functions.";
            }
            return "Heuristic rules matched characteristic tokens for {$lang}.";
        }
        case 'hljs':
        case 'hljs-auto':
            return "highlight.php auto-guess selected ‘{$lang}’ after other hints were inconclusive.";
        case 'fallback':
            return "Generic fallback selected a safe default (‘{$lang}’).";
        case 'explicit':
            return "Language was explicitly chosen by the author.";
        default:
            return ucfirst($source) . " → {$lang}.";
    }
}

// ---------- autodetect: filename/extension hint ----------
function paste_pick_from_filename(?string $title, string $engine = 'highlight', $hl = null): ?string {
    $title = (string) ($title ?? '');
    $base  = trim(basename($title));
    if ($base === '') return null;

    // No extension cases
    if (strcasecmp($base, 'Makefile') === 0) return ($engine === 'geshi') ? 'make' : 'makefile';
    if (strcasecmp($base, 'Dockerfile') === 0) return 'dockerfile';

    $ext = strtolower((string) pathinfo($base, PATHINFO_EXTENSION));
    if ($ext === '') return null;

    static $ext2hl = [
        'php'=>'php','phtml'=>'php','inc'=>'php',
        'js'=>'javascript','mjs'=>'javascript','cjs'=>'javascript',
        'ts'=>'typescript','tsx'=>'typescript','jsx'=>'javascript',
        'py'=>'python','rb'=>'ruby','pl'=>'perl','pm'=>'perl','raku'=>'perl',
        'sh'=>'bash','bash'=>'bash','zsh'=>'bash','ksh'=>'bash',
        'ps1'=>'powershell',
        'c'=>'c','h'=>'c','i'=>'c',
        'cpp'=>'cpp','cxx'=>'cpp','cc'=>'cpp','hpp'=>'cpp','hxx'=>'cpp','hh'=>'cpp',
        'cs'=>'csharp','java'=>'java','go'=>'go','rs'=>'rust','kt'=>'kotlin','kts'=>'kotlin',
        'm'=>'objectivec','mm'=>'objectivec',
        'json'=>'json','yml'=>'yaml','yaml'=>'yaml','ini'=>'ini','toml'=>'toml','properties'=>'properties',
        'xml'=>'xml','xhtml'=>'xml','xq'=>'xquery','xqy'=>'xquery',
        'html'=>'xml','htm'=>'xml','svg'=>'xml',
        'md'=>'markdown','markdown'=>'markdown',
        'sql'=>'sql','psql'=>'pgsql',
        'bat'=>'dos','cmd'=>'dos','nginx'=>'nginx','conf'=>'ini','cfg'=>'ini','txt'=>'plaintext',
        'make'=>'makefile','mk'=>'makefile','mak'=>'makefile','gradle'=>'gradle','dockerfile'=>'dockerfile',
        'hex'=>'hex','mrc'=>'mirc'
    ];

    $hlId = $ext2hl[$ext] ?? null;
    if ($hlId === null) return null;

    if ($engine === 'highlight') {
        if ($hl && method_exists($hl, 'listLanguages')) {
            $set = array_map('strtolower', (array)$hl->listLanguages());
            if (in_array($hlId, $set, true)) return $hlId;
            if ($hlId === 'hex' && in_array('mirc', $set, true)) return 'mirc';
            if ($hlId === 'pgsql' && in_array('sql', $set, true)) return 'sql';
            if ($hlId === 'plaintext' && in_array('text', $set, true)) return 'text';
            return null;
        }
        return $hlId;
    }

    return paste_normalize_lang($hlId, 'geshi', null) ?: null;
}

/**
 * Language for an "autodetect" paste: from the title's file name, a PHP tag, or the detector.
 * Returns [id, source, explanation, label from the detector or null].
 */
function paste_auto_language(string $code, string $title, string $engine, $hl): array {
    $byName = paste_pick_from_filename($title, $engine, $hl);
    if ($byName) {
        return [$byName, 'filename', paste_build_explain('filename', $title, $code, $byName), null];
    }
    if (paste_detect_php_tag($code)) {
        $php = $engine === 'geshi' ? paste_normalize_lang('php', 'geshi', null) : 'php';
        return [$php, 'php-tag', paste_build_explain('php-tag', $title, $code, $php), 'PHP'];
    }
    $det = paste_autodetect_language($code, $engine, $hl);
    $explain = ($det['explain'] ?? '') ?: paste_build_explain($det['source'], $title, $code, $det['id']);
    return [$det['id'], $det['source'], $explain, $det['label']];
}

/** Markdown as sanitised HTML in the page's Markdown wrapper (raw HTML in it is escaped). */
function paste_render_markdown(string $md): string {
    require_once __DIR__ . '/includes/Parsedown/Parsedown.php';
    $parsedown = new Parsedown();
    $parsedown->setSafeMode(true);
    $parsedown->setMarkupEscaped(true);
    return '<div class="md-body">' . sanitize_allowlist_html($parsedown->text($md)) . '</div>';
}

// --- Safe themed error renderers (header -> errors -> footer) ---
function themed_error_render(string $msg, int $http_code = 404, bool $show_password_form = false): void {
    global $default_theme, $lang, $baseurl, $site_name, $pdo, $mod_rewrite, $require_password, $paste_id;

    $site_name   = $site_name   ?? '';
    $p_title     = $lang['error'] ?? 'Error';
    $enablegoog  = 'no';

    if (!headers_sent()) {
        http_response_code($http_code);
        header('Content-Type: text/html; charset=utf-8');
    }

    $require_password = $show_password_form;
    $error = $msg;

    $theme = 'theme/' . htmlspecialchars($default_theme ?? 'default', ENT_QUOTES, 'UTF-8');
    require_once $theme . '/header.php';
    require_once $theme . '/errors.php';
    require_once $theme . '/footer.php';
    exit;
}

function render_error_and_exit(string $msg, string $http = '404'): void {
    $code = ($http === '403') ? 403 : 404;
    themed_error_render($msg, $code, false);
}

function render_password_required_and_exit(string $msg): void {
    themed_error_render($msg, 403, true);
}

// --- Inputs ---
$p_password = '';
$paste_id   = null;
$paste_identifier = ''; // Can be numeric ID or slug
$accessed_by_numeric_id = false;

if (isset($_GET['id']) && $_GET['id'] !== '') {
    $paste_identifier = trim((string) $_GET['id']);
    if (ctype_digit($paste_identifier)) {
        $paste_id = (int) $paste_identifier;
        $accessed_by_numeric_id = true;
    }
} elseif (isset($_POST['id']) && $_POST['id'] !== '') {
    $paste_identifier = trim((string) $_POST['id']);
    if (ctype_digit($paste_identifier)) {
        $paste_id = (int) $paste_identifier;
        $accessed_by_numeric_id = true;
    }
}

try {
    // site_info
    $stmt = $pdo->query("SELECT * FROM site_info WHERE id='1'");
    $si   = $stmt->fetch() ?: [];
    $title       = trim($si['title'] ?? '');
    $des         = trim($si['des'] ?? '');
    $baseurl     = paste_request_baseurl(rtrim(trim($si['baseurl'] ?? ''), '/') . '/');
    $keyword     = trim($si['keyword'] ?? '');
    $site_name   = trim($si['site_name'] ?? '');
    $email       = trim($si['email'] ?? '');
    $twit        = trim($si['twit'] ?? '');
    $face        = trim($si['face'] ?? '');
    $gplus       = trim($si['gplus'] ?? '');
    $ga          = trim($si['ga'] ?? '');
    $additional_scripts = trim($si['additional_scripts'] ?? '');

    // allow DB to define mod_rewrite
    if (isset($si['mod_rewrite']) && $si['mod_rewrite'] !== '') {
        $mod_rewrite = (string) $si['mod_rewrite'];
    }

    // interface
    $stmt = $pdo->query("SELECT * FROM interface WHERE id='1'");
    $iface = $stmt->fetch() ?: [];
    $default_lang  = trim($iface['lang'] ?? 'en.php');
    $default_theme = trim($iface['theme'] ?? 'default');
    require_once("langs/$default_lang");

    // ban check
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (is_banned($pdo, $ip)) {
        render_error_and_exit($lang['banned'] ?? 'You are banned from this site.', '403');
    }

    // site permissions
    $stmt = $pdo->query("SELECT * FROM site_permissions WHERE id='1'");
    $perm = $stmt->fetch() ?: [];
    $disableguest = trim($perm['disableguest'] ?? 'off');
    $siteprivate  = trim($perm['siteprivate'] ?? 'off');
    $hiderecentpastes = trim($perm['hiderecentpastes'] ?? 'off');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $siteprivate === "on") {
        $privatesite = "on";
    }

    // Page views
    pageViewTrack($pdo, $ip);

    // ads
    $stmt = $pdo->query("SELECT * FROM ads WHERE id='1'");
    $ads  = $stmt->fetch() ?: [];
    $text_ads = trim($ads['text_ads'] ?? '');
    $ads_1    = trim($ads['ads_1'] ?? '');
    $ads_2    = trim($ads['ads_2'] ?? '');

    // Guard ID/Slug
    if (!$paste_identifier && !$paste_id) {
        render_error_and_exit($lang['notfound'] ?? 'Paste not found.');
    }

    // Check if numeric URL access should be blocked
    if ($accessed_by_numeric_id && shouldBlockNumericUrls($pdo)) {
        // Numeric URL access is blocked - show 404 regardless of whether paste exists
        render_error_and_exit($lang['notfound'] ?? 'Paste not found.', '404');
    }

    // load paste - supports both numeric ID and slug
    $row = null;
    if ($paste_identifier !== '') {
        $row = getPasteByIdentifier($pdo, $paste_identifier);
    } elseif ($paste_id) {
        $stmt = $pdo->prepare("SELECT * FROM pastes WHERE id = ?");
        $stmt->execute([$paste_id]);
        $row = $stmt->fetch() ?: null;
    }

    if (!$row) {
        render_error_and_exit($lang['notfound'] ?? 'Paste not found.');
    }

    // Numeric id of the paste
    $paste_id = (int) $row['id'];
    $p_slug = (string) ($row['slug'] ?? '');

    // Determine the identifier to use in URLs (prefer slug if available)
    $url_identifier = ($p_slug !== '') ? $p_slug : $paste_id;

    // paste fields
    $p_title    = (string) ($row['title'] ?? '');
    $p_content  = (string) ($row['content'] ?? '');
    $p_visible  = (string) ($row['visible'] ?? '0');
    $p_code     = (string) ($row['code'] ?? 'text');
    $p_expiry   = trim((string) ($row['expiry'] ?? 'NULL'));
    $p_password = (string) ($row['password'] ?? 'NONE');
    $p_member   = (string) ($row['member'] ?? '');
    $p_date     = (string) ($row['date'] ?? '');
    $p_encrypt  = (string) ($row['encrypt'] ?? '0');
    $p_views    = getPasteViewCount($pdo, (int) $paste_id);
    $p_parent_id = isset($row['parent_id']) && $row['parent_id'] ? (int) $row['parent_id'] : null;

    // Parent of a fork: linked when public, or for the owner of either paste
    $viewer = (string)($_SESSION['username'] ?? '');
    $parent_paste = null;
    $parent_url = null;
    if ($p_parent_id && $p_parent_id > 0) {
        try {
            $stmt = $pdo->prepare("SELECT id, slug, title, visible, member FROM pastes WHERE id = ?");
            $stmt->execute([$p_parent_id]);
            $parent_paste = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($parent_paste && (string)$parent_paste['visible'] !== '0'
                && ($viewer === '' || ($viewer !== $p_member && $viewer !== (string)$parent_paste['member']))) {
                $parent_paste = null;
            }
            if ($parent_paste) {
                $parent_identifier = !empty($parent_paste['slug']) ? $parent_paste['slug'] : $parent_paste['id'];
                $parent_url = ($mod_rewrite == '1') ? $baseurl . $parent_identifier : $baseurl . 'paste.php?id=' . $parent_identifier;
            }
        } catch (PDOException $e) {
            error_log("paste.php: Error loading parent paste: " . $e->getMessage());
        }
    }

    // Forks of this paste: public ones that are not view-once or expired, plus the visitor's own
    $child_pastes = [];
    try {
        if (columnExists($pdo, 'pastes', 'parent_id')) {
            $stmt = $pdo->prepare("SELECT id, slug, title, member, date FROM pastes
                                   WHERE parent_id = ? AND ((visible = '0' AND " . paste_listable_sql() . ") OR (member = ? AND member <> 'Guest'))
                                   ORDER BY date DESC LIMIT 10");
            $stmt->execute([$paste_id, $viewer]);
            $child_pastes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("paste.php: Error loading child pastes: " . $e->getMessage());
    }

    // ---- comments config for view (AFTER $p_password is known) ----
    // Read site-wide flags from config.php (with safe defaults)
    $comments_enabled       = isset($comments_enabled) ? (bool)$comments_enabled : true;
    $comments_require_login = isset($comments_require_login) ? (bool)$comments_require_login : true;
    $comments_on_protected  = isset($comments_on_protected) ? (bool)$comments_on_protected : false;

    // Should we render the comments section for this paste?
    $show_comments = $comments_enabled && ($comments_on_protected || $p_password === "NONE");
    // Is the current user allowed to post a comment?
    $can_comment   = $show_comments && ( !$comments_require_login || isset($_SESSION['username']) );

    $comment_error   = '';
    $comment_success = '';

    // private?
    if ($p_visible === "2") {
        if (!isset($_SESSION['username']) || $p_member !== (string) ($_SESSION['username'] ?? '')) {
            render_error_and_exit($lang['privatepaste'] ?? 'This is a private paste.', '403');
        }
    }

    // expiry
    if (paste_is_expired($p_expiry)) {
        render_error_and_exit($lang['expired'] ?? 'This paste has expired.');
    }

    // decrypt if needed
    $original_p_code = $row['code'] ?? 'text'; // Store original language
    $raw_base64 = null; // Variable for raw base64
    if ($p_encrypt === "2") { // Clientside encryption (AES-256-GCM)
        $is_client_encrypted = true;
        $raw_base64 = $row['content']; // Store raw base64 from database
        $p_content = htmlspecialchars($raw_base64, ENT_QUOTES, 'UTF-8'); // Placeholder until the browser decrypts it
        $p_code = 'Clientside Encryption'; // Skip server-side highlighting. Using Highlight.js
    } else {
        if ($p_encrypt === "1") {
            if (!defined('SECRET')) {
                render_error_and_exit(($lang['error'] ?? 'Error') . ': Missing SECRET.', '403');
            }
            $dec = decrypt($p_content, hex2bin(SECRET));
            if ($dec === null || $dec === '') {
                render_error_and_exit(($lang['error'] ?? 'Error') . ': Decryption failed.', '403');
            }
            $p_content = $dec;
            // Older API pastes stored an encrypted title
            $decTitle = decrypt($p_title, hex2bin(SECRET));
            if ($decTitle !== null && $decTitle !== '') {
                $p_title = htmlspecialchars($decTitle, ENT_QUOTES, 'UTF-8');
            }
        }
    }
    $op_content = htmlspecialchars_decode($p_content); // For non-client-encrypted display

    // View-once pastes are deleted after a second unique viewer, whichever route they use
    $burn_after_read = static function () use ($pdo, $paste_id, $p_expiry): void {
        if ($p_expiry !== 'SELF') return;
        updateMyView($pdo, (int) $paste_id);
        if (getPasteViewCount($pdo, (int) $paste_id) >= 2) {
            deleteMyPaste($pdo, (int) $paste_id);
        }
    };

    // Checks a paste password, allowing 10 wrong tries per IP every 10 minutes. Returns '' when it is right, otherwise the message to show
    $check_password = static function (string $given, string $wrongMsg, bool $count = true) use ($pdo, $p_password, $lang): string {
        if ($given === '') return $lang['pwdprotected'] ?? 'This paste is password-protected.';
        if (paste_throttled($pdo, 'pastepw')) {
            return $lang['pwdtoomany'] ?? 'Too many wrong passwords. Try again in a few minutes.';
        }
        if (password_verify($given, $p_password)) return '';
        if ($count) paste_throttled($pdo, 'pastepw', true);
        return $wrongMsg;
    };
    // Links shown after the password is entered carry a token (pt) instead of the password
    $url_password_error = '';
    $url_token_ok = $p_password !== 'NONE' && paste_access_token_ok((string) ($_GET['pt'] ?? ''), (int) $paste_id, $p_password);
    if ($p_password !== 'NONE' && !$url_token_ok && (isset($_GET['download']) || isset($_GET['raw']) || isset($_GET['embed']))) {
        $url_password_error = $check_password((string) ($_GET['password'] ?? ''), $lang['wrongpassword'] ?? 'Incorrect password.');
    }

    // download/raw/embed
    if (isset($_GET['download'])) {
        if ($url_password_error === '') {
            doDownload((int) $paste_id, $p_title, $op_content, $p_code);
            $burn_after_read();
            exit;
        }
        render_password_required_and_exit($url_password_error);
    }

    if (isset($_GET['raw'])) {
        if ($url_password_error === '') {
            rawView((int) $paste_id, $p_title, $op_content, $p_code);
            $burn_after_read();
            exit;
        }
        render_password_required_and_exit($url_password_error);
    }

    if (isset($_GET['embed'])) {
        // Embeds would burn view-once pastes and cannot decrypt client-side ones
        if ($p_expiry === 'SELF' || $p_encrypt === '2') {
            render_error_and_exit($lang['notfound'] ?? 'Paste not found.');
        }
        if ($url_password_error === '') {
            // Embed view is standalone; we pass empty $ges_style as we don't inject CSS here.
            embedView($url_identifier, $p_title, htmlspecialchars($op_content, ENT_QUOTES, 'UTF-8'), $p_code, $title, $baseurl, $ges_style, $lang);
            exit;
        }
        render_password_required_and_exit($url_password_error);
    }

    // highlight extraction
    $highlight = [];
    $prefix = '!highlight!';
    if ($prefix !== '') {
        $lines = explode("\n", $p_content);
        $p_content = '';
        foreach ($lines as $idx => $line) {
            if (strncmp($line, $prefix, strlen($prefix)) === 0) {
                $highlight[] = $idx + 1;
                $line = substr($line, strlen($prefix));
            }
            $p_content .= $line . "\n";
        }
        $p_content = rtrim($p_content);
    }

    // -------------- transform content --------------
    $p_code_explain = '';

    if ($p_encrypt !== "2") { // Client-encrypted pastes are rendered in the browser
        $code_input = htmlspecialchars_decode($p_content);

        if ($p_code === "markdown") {
            $p_content = paste_render_markdown($code_input);
            $p_code_effective = 'markdown';
            $p_code_label = $geshiformats['markdown'] ?? 'Markdown';
            $p_code_source = 'explicit';
            $p_code_explain = 'Language was explicitly chosen by the author.';
        } elseif (($highlighter ?? 'geshi') === 'highlight') {
            // ---- highlight.php ----
            $hlId = map_to_hl_lang($p_code);
            try {
                $hl = make_highlighter();
                if (!$hl) { throw new \RuntimeException('Highlighter not available'); }
                $res = null;

                if (in_array($hlId, ['auto', 'autodetect', ''], true)) {
                    [$langTry, $p_code_source, $p_code_explain, $hint] = paste_auto_language($code_input, (string) $p_title, 'highlight', $hl);
                    $p_code_label = $geshiformats[$langTry] ?? ($hint ?? paste_friendly_label($langTry));
                    $markdown = $langTry === 'markdown' && !in_array($p_code_source, ['filename', 'php-tag'], true);
                    if (!$markdown) $res = $hl->highlight($langTry, $code_input);
                } else {
                    $langTry = paste_normalize_lang($hlId, 'highlight', $hl);
                    $p_code_source = 'explicit';
                    $markdown = false;
                    try {
                        $res = $hl->highlight($langTry, $code_input);
                    } catch (\Throwable $e) {
                        if ($langTry === 'pgsql') {
                            $res = $hl->highlight('sql', $code_input);
                        } else {
                            // A language highlight.php does not have: detect one instead
                            $det = paste_autodetect_language($code_input, 'highlight', $hl);
                            $langTry = $det['id'];
                            $p_code_label = $geshiformats[$langTry] ?? $det['label'];
                            $p_code_source = $det['source'];
                            $p_code_explain = ($det['explain'] ?? '') ?: paste_build_explain($p_code_source, (string) $p_title, $code_input, $langTry);
                            $markdown = $langTry === 'markdown';
                            if (!$markdown) $res = $hl->highlight($langTry, $code_input);
                        }
                    }
                    if (!$markdown) $p_code_label = $geshiformats[$langTry] ?? paste_friendly_label($langTry);
                }

                $p_content = $markdown
                    ? paste_render_markdown($code_input)
                    : hl_wrap_with_lines($res->value ?: htmlspecialchars($code_input, ENT_QUOTES, 'UTF-8'), $langTry, $highlight);
                $p_code_effective = $langTry;
            } catch (\Throwable $t) {
                $p_content = hl_wrap_with_lines(htmlspecialchars($code_input, ENT_QUOTES, 'UTF-8'), 'plaintext', $highlight);
                $p_code_effective = 'plaintext';
                $p_code_label = $geshiformats['plaintext'] ?? 'Plain Text';
                $p_code_source = $p_code_source ?? 'fallback';
                $p_code_explain = $p_code_explain ?: paste_build_explain('fallback', (string) $p_title, $code_input, 'plaintext');
            }
        } else {
            // ---- GeSHi ----
            $lang_for_geshi = $p_code;
            $markdown = false;
            if (in_array($p_code, ['auto', 'autodetect', '', 'text'], true)) {
                [$lang_for_geshi, $p_code_source, $p_code_explain, $hint] = paste_auto_language($code_input, (string) $p_title, 'geshi', null);
                $fromDetector = !in_array($p_code_source, ['filename', 'php-tag'], true);
                $p_code_label = $fromDetector ? $hint : ($geshiformats[$lang_for_geshi] ?? ($hint ?? paste_friendly_label($lang_for_geshi)));
                $markdown = $fromDetector && $lang_for_geshi === 'markdown';
            }
            $p_code_effective = $lang_for_geshi;

            if ($markdown) {
                $p_content = paste_render_markdown($code_input);
            } else {
                $geshi = new GeSHi($code_input, $lang_for_geshi, $path);
                $geshi->enable_classes();
                $geshi->set_header_type(GESHI_HEADER_DIV);
                $geshi->enable_line_numbers(GESHI_NORMAL_LINE_NUMBERS);
                if ($highlight) {
                    $geshi->highlight_lines_extra($highlight);
                }
                $p_content = $geshi->parse_code();
                if ($highlight) {
                    $p_content = geshi_add_line_highlight_class($p_content, $highlight, 'hljs-hl');
                }
                $p_code_label ??= $geshiformats[$p_code_effective] ?? paste_friendly_label($p_code_effective);
            }
        }
    }

    // ======= Comment submit (PRG): supports parent_id for replies =======
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_comment') {
        if (!$paste_id) {
            $comment_error = 'Invalid paste.';
        } elseif (!$comments_enabled) {
            $comment_error = $lang['comments_off'] ?? 'Comments are disabled.';
        } elseif (!$show_comments) {
            $comment_error = $lang['comments_blocked_here'] ?? 'Comments are not available for this paste.';
        } elseif ($comments_require_login && !isset($_SESSION['username'])) {
            $comment_error = $lang['commentlogin'] ?? 'You must be logged in to comment.';
        } elseif (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
            $comment_error = $lang['invalidtoken'] ?? 'Invalid CSRF token.';
        } else {
            $uid = 0;
            $uname = 'Guest';
            if (isset($_SESSION['username'])) {
                $uname = (string)$_SESSION['username'];
                // fetch id to store as FK
                try {
                    $q = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                    $q->execute([$uname]);
                    $uid = (int)($q->fetchColumn() ?: 0);
                } catch (Throwable $e) { $uid = 0; }
            }

            $parent_id = null;
            if (isset($_POST['parent_id']) && $_POST['parent_id'] !== '') {
                $parent_id = (int)$_POST['parent_id'];
            }

            // Comments have no moderation queue, so held comments are refused too
            $commentBody = (string)($_POST['comment_body'] ?? '');
            $cv = spam_evaluate($pdo, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', $uname, '', $commentBody, $uname === 'Guest', 'comment');
            if ($cv['action'] !== 'allow') {
                $comment_error = $lang['spam_blocked_comment'] ?? 'Your comment was flagged as spam and was not posted.';
                goto COMMENT_DONE;
            }

            try {
                $okId = addPasteComment($pdo, (int)$paste_id, $uid ?: null, $uname, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                    $commentBody, $parent_id);
            } catch (Throwable $e) {
                error_log('add_comment error: ' . $e->getMessage());
                $okId = null;
            }

            if ($okId) {
                // Avoid resubmit on refresh - use slug if available
                $redirect_ident = ($p_slug !== '') ? $p_slug : $paste_id;
                $to = ($mod_rewrite == '1')
                    ? $baseurl . rawurlencode((string)$redirect_ident) . '#comments'
                    : $baseurl . 'paste.php?id=' . rawurlencode((string)$redirect_ident) . '#comments';
                header('Location: ' . $to);
                exit;
            }
            $comment_error = 'Could not add comment.';
        }
        COMMENT_DONE: ;
    }

    // ======= Delete comment (PRG): hard delete; replies removed via FK CASCADE) =======
    // (Allowed even if comments are currently disabled, so users can still remove their content.)
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['action']) && $_POST['action'] === 'delete_comment') {

        $isAjax = (
            (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (isset($_POST['ajax']) && $_POST['ajax'] === '1')
        );

        $err = '';
        do {
            if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
                $err = $lang['invalidtoken'] ?? 'Invalid CSRF token.'; break;
            }
            if (!isset($_SESSION['username'])) {
                $err = $lang['commentlogin'] ?? 'Log in required.'; break;
            }

            // Ensure current paste context (accept id from GET OR POST for robustness)
            if (!$paste_id) {
                $paste_id = (isset($_POST['id']) && $_POST['id'] !== '') ? (int)$_POST['id'] : $paste_id;
            }
            $cid = isset($_POST['comment_id']) ? (int)$_POST['comment_id'] : 0;
            if ($cid <= 0 || !$paste_id) { $err = 'Invalid comment.'; break; }

            // Resolve current user id
            $uid = 0;
            try {
                $q = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $q->execute([$_SESSION['username']]);
                $uid = (int)($q->fetchColumn() ?: 0);
            } catch (Throwable $e) {}

            if (!userOwnsComment($pdo, $cid, $uid, (string)$_SESSION['username'])) {
                $err = $lang['forbidden'] ?? 'Not allowed.'; break;
            }

            if (!deleteComment($pdo, $cid)) { $err = $lang['error'] ?? 'Delete failed.'; }
        } while (false);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => ($err === ''),
                'message' => $err,
            ]);
            exit;
        }

        // Redirect back to #comments (PRG). If error, carry as c_err=
        $redirect_ident = ($p_slug !== '') ? $p_slug : $paste_id;
        $to = ($mod_rewrite == '1')
            ? $baseurl . rawurlencode((string)$redirect_ident) . '#comments'
            : $baseurl . 'paste.php?id=' . rawurlencode((string)$redirect_ident) . '#comments';
        if ($err !== '') {
            $to .= (strpos($to, '?') === false ? '?' : '&') . 'c_err=' . rawurlencode($err);
        }
        header('Location: ' . $to);
        exit;
    }

    // Client-encrypted pastes carry their key in the URL: no third-party scripts on this page
    if ($p_encrypt === '2') {
        $no_third_party = true;
        $ga = $additional_scripts = $ads_1 = $ads_2 = $text_ads = '';
        paste_privacy_headers();
    }

    // header
    $theme = 'theme/' . htmlspecialchars($default_theme, ENT_QUOTES, 'UTF-8');
    require_once $theme . '/header.php';

    // Fetch comments (tree if available), then decorate
    $viewerName = isset($_SESSION['username'], $_SESSION['csrf_token']) ? (string)$_SESSION['username'] : '';
    $viewerUid  = 0;
    if ($viewerName !== '') {
        try {
            $q = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $q->execute([$viewerName]);
            $viewerUid = (int)($q->fetchColumn() ?: 0);
        } catch (Throwable $e) { $viewerUid = 0; }
    }
    // Same ownership rule as userOwnsComment(), without a query per comment
    $ownsComment = static function (array $c) use ($viewerName, $viewerUid): bool {
        if ($viewerName === '') return false;
        if (!empty($c['user_id']) && (int)$c['user_id'] === $viewerUid) return true;
        return strcasecmp((string)($c['username'] ?? ''), $viewerName) === 0;
    };

    // Comment tree, with rendered bodies and delete rights
    $comments = getPasteCommentsTree($pdo, (int)$paste_id);
    $decorate = function (&$node) use (&$decorate, $ownsComment) {
        $node['body_html'] = render_comment_html((string)($node['body'] ?? ''));
        $node['can_delete'] = $ownsComment($node);
        if (!empty($node['children'])) {
            foreach ($node['children'] as &$ch) $decorate($ch);
            unset($ch);
        }
    };
    foreach ($comments as &$c) $decorate($c);
    unset($c);

    // Carry any error from PRG redirect
    if ($comment_error === '' && isset($_GET['c_err']) && $_GET['c_err'] !== '') {
        $comment_error = (string)$_GET['c_err'];
    }

    // view OR password prompt
    if ($p_password === "NONE") {
        updateMyView($pdo, (int) $paste_id);

        $p_download = $mod_rewrite == '1' ? $baseurl . "download/$url_identifier" : $baseurl . "paste.php?download&id=$url_identifier";
        $p_raw      = $mod_rewrite == '1' ? $baseurl . "raw/$url_identifier"      : $baseurl . "paste.php?raw&id=$url_identifier";
        $p_embed    = $mod_rewrite == '1' ? $baseurl . "embed/$url_identifier"    : $baseurl . "paste.php?embed&id=$url_identifier";

        require_once $theme . '/view.php';

        // View-once (SELF) cleanup after increment
        $current_views = getPasteViewCount($pdo, (int) $paste_id);
        if ($p_expiry === "SELF" && $current_views >= 2) {
            deleteMyPaste($pdo, (int) $paste_id);
        }
    } else {
        // Password-protected flow shows the prompt via errors.php
        $require_password = true;

        // Entering the password unlocks the paste in this session for a day
        $unlockKey = (string) (int) $paste_id;
        $unlocked = !isset($_POST['mypass'])
            && paste_access_token_ok((string) ($_SESSION['paste_unlocked'][$unlockKey] ?? ''), (int) $paste_id, $p_password);
        $form_password_error = $unlocked ? ''
            : $check_password(trim((string) ($_POST['mypass'] ?? '')), $lang['wrongpwd'] ?? 'Incorrect password.', isset($_POST['mypass']));
        if ($form_password_error === '') {
            $_SESSION['paste_unlocked'][$unlockKey] = paste_access_token((int) $paste_id, $p_password, time() + 86400);
            $_SESSION['paste_unlocked'] = array_slice($_SESSION['paste_unlocked'], -50, null, true);

            // Raw and download links last a day; the embed link lasts until the password changes
            $pt = rawurlencode($_SESSION['paste_unlocked'][$unlockKey]);
            $ptEmbed = rawurlencode(paste_access_token((int) $paste_id, $p_password, 0));
            $p_download = $mod_rewrite == '1'
                ? $baseurl . "download/$url_identifier?pt=$pt"
                : $baseurl . "paste.php?download&id=$url_identifier&pt=$pt";
            $p_raw = $mod_rewrite == '1'
                ? $baseurl . "raw/$url_identifier?pt=$pt"
                : $baseurl . "paste.php?raw&id=$url_identifier&pt=$pt";
            $p_embed = $mod_rewrite == '1'
                ? $baseurl . "embed/$url_identifier?pt=$ptEmbed"
                : $baseurl . "paste.php?embed&id=$url_identifier&pt=$ptEmbed";

            updateMyView($pdo, (int) $paste_id);
            require_once $theme . '/view.php';

            $current_views = getPasteViewCount($pdo, (int) $paste_id);
            if ($p_expiry === "SELF" && $current_views >= 2) {
                deleteMyPaste($pdo, (int) $paste_id);
            }
        } else {
            $error = $form_password_error;
            unset($_SESSION['p_password']);

            require_once $theme . '/errors.php'; // partial renders password prompt
        }
    }

    // footer
    require_once $theme . '/footer.php';

} catch (PDOException $e) {
    error_log("paste.php: Database error: " . $e->getMessage());

    // Still render a readable error page (no password box); the details stay in the log
    $error = $lang['error'] ?? 'Database error.';

    global $default_theme, $baseurl, $mod_rewrite, $pdo, $require_password;
    $require_password = false;

    $theme = 'theme/' . htmlspecialchars($default_theme ?? 'default', ENT_QUOTES, 'UTF-8');
    require_once $theme . '/header.php';
    require_once $theme . '/errors.php';
    require_once $theme . '/footer.php';
}
?>
