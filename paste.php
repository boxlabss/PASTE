<?php
/*
 * Paste $v3.2 2025/09/01 https://github.com/boxlabss/PASTE
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
declare(strict_types=1);

require_once 'includes/session.php';
require_once 'config.php';

// Load highlighter engine libs conditionally
if (($highlighter ?? 'geshi') === 'geshi') {
    require_once 'includes/geshi.php';
} else {
    require_once __DIR__ . '/includes/hlbootstrap.php';
}

require_once 'includes/functions.php';

// ensure these are visible to all included templates (header/footer/sidebar)
global $pdo, $mod_rewrite;

// default to avoid notices if config hasn't set it (DB can override later)
if (!isset($mod_rewrite)) {
    $mod_rewrite = '0';
}

$path             = 'includes/geshi/';                        // GeSHi language files
$parsedown_path   = 'includes/Parsedown/Parsedown.php';       // Markdown
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

// Fallback paste_normalize_lang() if the function isn't provided elsewhere.
// This is intentionally conservative: prefer not to change IDs unless the
// highlighter/engine-specific helper (if present) returns something better.
if (!function_exists('paste_normalize_lang')) {
    /**
     * paste_normalize_lang($lang, $engine, $highlighter)
     * - $lang: requested language id (string)
     * - $engine: 'highlight' or 'geshi'
     * - $highlighter: optional highlighter instance (may expose listLanguages())
     *
     * Return canonical normalized id for the engine, or null/empty if no change.
     */
    function paste_normalize_lang(string $lang, string $engine = 'highlight', $hl = null) {
        // Basic alias normalisation (small set) — keep this minimal; map_to_hl_lang already covers many aliases.
        $aliases = [
            'py' => 'python',
            'py3' => 'python',
            'python3' => 'python',
            'c++' => 'cpp',
            'c#' => 'csharp',
            'sh' => 'bash',
        ];
        $l = strtolower($lang);
        if (isset($aliases[$l])) $l = $aliases[$l];

        // If highlighter instance exposes listLanguages(), prefer a language ID it knows.
        if ($hl && is_object($hl) && method_exists($hl, 'listLanguages')) {
            $set = array_map('strtolower', (array)$hl->listLanguages());
            if (in_array($l, $set, true)) {
                return $l;
            }
            // Try a few reasonable fallbacks
            if ($l === 'pgsql' && in_array('sql', $set, true)) return 'sql';
            if ($l === 'plaintext' && in_array('text', $set, true)) return 'text';
        }

        // Otherwise return the (possibly alias-mapped) id (caller may accept null/empty as "no change")
        return $l;
    }
}

// Lightweight heuristic to detect Python code to avoid misclassifying as Markdown
function is_probable_python(string $code): bool {
    // Look for common python tokens and constructs
    if (preg_match('/^\s*(def|class)\s+[A-Za-z_]\w*\s*\(.*\)\s*:/m', $code)) return true;
    if (preg_match('/^\s*import\s+[A-Za-z0-9_.]+/m', $code)) return true;
    if (preg_match('/^\s*from\s+[A-Za-z0-9_.]+\s+import\s+/m', $code)) return true;
    if (preg_match('/\bself\b/', $code) && preg_match('/:\s*$/m', $code)) return true;
    if (preg_match('/\b(lambda|yield|async|await)\b/', $code)) return true;
    // Negative/weak checks left out so we don't over-trigger.
    return false;
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
        'make'=>'makefile','mk'=>'makefile','mak'=>'makefile','gradle'=>'gradle','dockerfile'=>'dockerfile'
    ];

    $hlId = $ext2hl[$ext] ?? null;
    if ($hlId === null) return null;

    if ($engine === 'highlight') {
        if ($hl && method_exists($hl, 'listLanguages')) {
            $set = array_map('strtolower', (array)$hl->listLanguages());
            if (in_array($hlId, $set, true)) return $hlId;
            if ($hlId === 'pgsql' && in_array('sql', $set, true)) return 'sql';
            if ($hlId === 'plaintext' && in_array('text', $set, true)) return 'text';
            return null;
        }
        return $hlId;
    }

    // GeSHi: normalize if helper exists
    if (function_exists('paste_normalize_lang')) {
        $g = paste_normalize_lang($hlId, 'geshi', null);
        return $g ?: null;
    }
    return null;
}

function is_probable_php_tag(string $code): bool {
    return (bool) preg_match('/<\?(php|=)/i', $code);
}

// --- Safe themed error renderers (header -> errors -> footer) ---
function themed_error_render(string $msg, int $http_code = 404, bool $show_password_form = false): void {
    global $default_theme, $lang, $baseurl, $site_name, $pdo, $mod_rewrite, $require_password, $paste_id;

    $site_name   = $site_name   ?? '';
    $p_title     = $lang['error'] ?? 'Error';
    $enablegoog  = 'no';
    $enablefb    = 'no';

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
if (isset($_GET['id']) && $_GET['id'] !== '') {
    $paste_id = (int) trim((string) $_GET['id']);
} elseif (isset($_POST['id']) && $_POST['id'] !== '') {
    $paste_id = (int) trim((string) $_POST['id']);
}

try {
    // site_info
    $stmt = $pdo->query("SELECT * FROM site_info WHERE id='1'");
    $si   = $stmt->fetch() ?: [];
    $title       = trim($si['title'] ?? '');
    $des         = trim($si['des'] ?? '');
    $baseurl     = rtrim(trim($si['baseurl'] ?? ''), '/') . '/';
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
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $siteprivate === "on") {
        $privatesite = "on";
    }

    // page views (best effort)
    $date = date('Y-m-d');
    try {
        $stmt = $pdo->prepare("SELECT id, tpage, tvisit FROM page_view WHERE date = ?");
        $stmt->execute([$date]);
        $pv = $stmt->fetch();
        if ($pv) {
            $page_view_id = (int) $pv['id'];
            $tpage  = (int) $pv['tpage'] + 1;
            $tvisit = (int) $pv['tvisit'];

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM visitor_ips WHERE ip = ? AND visit_date = ?");
            $stmt->execute([$ip, $date]);
            if ((int) $stmt->fetchColumn() === 0) {
                $tvisit++;
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

    // ads
    $stmt = $pdo->query("SELECT * FROM ads WHERE id='1'");
    $ads  = $stmt->fetch() ?: [];
    $text_ads = trim($ads['text_ads'] ?? '');
    $ads_1    = trim($ads['ads_1'] ?? '');
    $ads_2    = trim($ads['ads_2'] ?? '');

    // Guard ID
    if (!$paste_id) {
        render_error_and_exit($lang['notfound'] ?? 'Paste not found.');
    }

    // load paste
    $stmt = $pdo->prepare("SELECT * FROM pastes WHERE id = ?");
    $stmt->execute([$paste_id]);
    if ($stmt->rowCount() === 0) {
        render_error_and_exit($lang['notfound'] ?? 'Paste not found.');
    }
    $row = $stmt->fetch();

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
    if ($p_expiry !== "NULL" && $p_expiry !== "SELF") {
        $input_time = (int) $p_expiry;
        if ($input_time > 0 && $input_time < time()) {
            render_error_and_exit($lang['expired'] ?? 'This paste has expired.');
        }
    }

    // decrypt if needed
    if ($p_encrypt === "1") {
        if (!defined('SECRET')) {
            render_error_and_exit(($lang['error'] ?? 'Error') . ': Missing SECRET.', '403');
        }
        $dec = decrypt($p_content, hex2bin(SECRET));
        if ($dec === null || $dec === '') {
            render_error_and_exit(($lang['error'] ?? 'Error') . ': Decryption failed.', '403');
        }
        $p_content = $dec;
    }
    $op_content = trim(htmlspecialchars_decode($p_content));

    // download/raw/embed
    if (isset($_GET['download'])) {
        if ($p_password === "NONE" || (isset($_GET['password']) && password_verify((string) $_GET['password'], $p_password))) {
            doDownload((int) $paste_id, $p_title, $op_content, $p_code);
            exit;
        }
        render_password_required_and_exit(
            isset($_GET['password'])
                ? ($lang['wrongpassword'] ?? 'Incorrect password.')
                : ($lang['pwdprotected'] ?? 'This paste is password-protected.')
        );
    }

    if (isset($_GET['raw'])) {
        if ($p_password === "NONE" || (isset($_GET['password']) && password_verify((string) $_GET['password'], $p_password))) {
            rawView((int) $paste_id, $p_title, $op_content, $p_code);
            exit;
        }
        render_password_required_and_exit(
            isset($_GET['password'])
                ? ($lang['wrongpassword'] ?? 'Incorrect password.')
                : ($lang['pwdprotected'] ?? 'This paste is password-protected.')
        );
    }

    if (isset($_GET['embed'])) {
        if ($p_password === "NONE" || (isset($_GET['password']) && password_verify((string) $_GET['password'], $p_password))) {
            // Embed view is standalone; we pass empty $ges_style as we don't inject CSS here.
            embedView((int) $paste_id, $p_title, $p_content, $p_code, $title, $baseurl, $ges_style, $lang);
            exit;
        }
        render_password_required_and_exit(
            isset($_GET['password'])
                ? ($lang['wrongpassword'] ?? 'Incorrect password.')
                : ($lang['pwdprotected'] ?? 'This paste is password-protected.')
        );
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
    $p_code_explain = ''; // will be filled when we detect

    // Important: skip treating as Markdown when the content looks like Python
    if ($p_code === "markdown" && !is_probable_python($p_content)) {
        // ---------- Markdown (keep using Parsedown, safe) ----------
        require_once $parsedown_path;
        $Parsedown = new Parsedown();

        $md_input = htmlspecialchars_decode($p_content);

        // Disable raw HTML and sanitize URLs during Markdown rendering
        if (method_exists($Parsedown, 'setSafeMode')) {
            $Parsedown->setSafeMode(true);
            if (method_exists($Parsedown, 'setMarkupEscaped')) {
                $Parsedown->setMarkupEscaped(true);
            }
        } else {
            // Fallback for very old Parsedown: escape raw HTML tags BEFORE parsing
            $md_input = preg_replace_callback('/<[^>]*>/', static function($m){
                return htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8');
            }, $md_input);
        }

        $rendered  = $Parsedown->text($md_input);
        $p_content = '<div class="md-body">'.sanitize_allowlist_html($rendered).'</div>';

        $p_code_effective = 'markdown';
        $p_code_label     = $geshiformats['markdown'] ?? 'Markdown';
        $p_code_source    = 'explicit';
        $p_code_explain   = 'Language was explicitly chosen by the author.';

    } else {
        // ---------- Code (choose engine) ----------
        $code_input = htmlspecialchars_decode($p_content);

        if (($highlighter ?? 'geshi') === 'highlight') {
            // ---- Highlight.php ----
            $hlId     = map_to_hl_lang($p_code);          // map geshi -> hljs ids
            $use_auto = ($hlId === 'auto' || $hlId === 'autodetect' || $hlId === '' || $hlId === 'text');

            try {
                $hl = function_exists('make_highlighter') ? make_highlighter() : null;
                if (!$hl) { throw new \RuntimeException('Highlighter not available'); }

                // After creating $hl, allow engine-specific normalisation if available
                if ($hl && function_exists('paste_normalize_lang')) {
                    // paste_normalize_lang should accept (lang, engine, highlighter) and
                    // return a normalized language id (or null/false on no change).
                    $norm = paste_normalize_lang($hlId, 'highlight', $hl);
                    if (!empty($norm)) {
                        $hlId = $norm;
                    }
                }

                // 1) Filename strong hint if auto
                if ($use_auto) {
                    $fname = paste_pick_from_filename($p_title ?? '', 'highlight', $hl);
                    if ($fname) {
                        $langTry          = $fname;
                        $p_code_source    = 'filename';
                        $p_code_explain   = paste_build_explain('filename', $p_title ?? '', $code_input, $langTry);
                        $res              = $hl->highlight($langTry, $code_input);
                        $inner            = $res->value ?: htmlspecialchars($code_input, ENT_QUOTES, 'UTF-8');
                        $p_content        = hl_wrap_with_lines($inner, $langTry, $highlight);
                        $p_code_effective = $langTry;
                        $p_code_label     = $geshiformats[$langTry] ?? (function_exists('paste_friendly_label') ? paste_friendly_label($langTry) : strtoupper($langTry));
                        goto HL_DONE;
                    }
                }

                // 2) PHP tag hard rule if auto
                if ($use_auto && is_probable_php_tag($code_input)) {
                    $langTry          = 'php';
                    $p_code_source    = 'php-tag';
                    $p_code_explain   = paste_build_explain('php-tag', $p_title ?? '', $code_input, $langTry);
                    $res              = $hl->highlight($langTry, $code_input);
                    $inner            = $res->value ?: htmlspecialchars($code_input, ENT_QUOTES, 'UTF-8');
                    $p_content        = hl_wrap_with_lines($inner, $langTry, $highlight);
                    $p_code_effective = $langTry;
                    $p_code_label     = $geshiformats[$langTry] ?? 'PHP';
                    goto HL_DONE;
                }

                if ($use_auto && function_exists('paste_autodetect_language')) {
                    // Unified autodetect
                    $det = paste_autodetect_language($code_input, 'highlight', $hl);
                    $langTry          = $det['id'];
                    $p_code_effective = $langTry;
                    $p_code_label     = $geshiformats[$langTry] ?? $det['label'];
                    $p_code_source    = $det['source'];
                    $p_code_explain   = $det['explain'] ?? '';
                    if ($p_code_explain === '') {
                        $p_code_explain = paste_build_explain($p_code_source, $p_title ?? '', $code_input, $langTry);
                    }

                    // If autodetect gave 'markdown' but sample looks like Python, override
                    if ($langTry === 'markdown' && is_probable_python($code_input)) {
                        $langTry = 'python';
                        $p_code_explain = 'Heuristic override: detected Python tokens (def/import/self) so choosing python over markdown.';
                        $p_code_effective = $langTry;
                        $p_code_label = $geshiformats[$langTry] ?? 'Python';
                    }

                    if ($langTry === 'markdown') {
                        // Render Markdown via Parsedown
                        require_once $parsedown_path;
                        $Parsedown = new Parsedown();
                        if (method_exists($Parsedown, 'setSafeMode')) {
                            $Parsedown->setSafeMode(true);
                            if (method_exists($Parsedown, 'setMarkupEscaped')) $Parsedown->setMarkupEscaped(true);
                        }
                        $rendered  = $Parsedown->text($code_input);
                        $p_content = '<div class="md-body">' . sanitize_allowlist_html($rendered) . '</div>';
                    } else {
                        $res   = $hl->highlight($langTry, $code_input);
                        $inner = $res->value ?: htmlspecialchars($code_input, ENT_QUOTES, 'UTF-8');
                        $p_content = hl_wrap_with_lines($inner, $langTry, $highlight);
                    }
                } else {
                    // Explicit language requested OR fallback to built-in auto
                    $langTry = function_exists('paste_normalize_lang') ? paste_normalize_lang($hlId, 'highlight', $hl) : $hlId;
                    $p_code_source = 'explicit';
                    try {
                        $res = $hl->highlight($langTry, $code_input);
                    } catch (\Throwable $e) {
                        // Fallbacks: pgsql -> sql, otherwise shared autodetect
                        if ($langTry === 'pgsql') {
                            $res = $hl->highlight('sql', $code_input);
                        } elseif (function_exists('paste_autodetect_language')) {
                            $det = paste_autodetect_language($code_input, 'highlight', $hl);
                            $langTry          = $det['id'];
                            $p_code_label     = $geshiformats[$langTry] ?? $det['label'];
                            $p_code_source    = $det['source'];
                            $p_code_explain   = $det['explain'] ?? '';
                            if ($p_code_explain === '') {
                                $p_code_explain = paste_build_explain($p_code_source, $p_title ?? '', $code_input, $langTry);
                            }

                            if ($langTry === 'markdown' && is_probable_python($code_input)) {
                                $langTry = 'python';
                                $p_code_explain = 'Heuristic override: detected Python tokens (def/import/self) so choosing python over markdown.';
                                $p_code_label = $geshiformats[$langTry] ?? 'Python';
                            }

                            if ($langTry === 'markdown') {
                                require_once $parsedown_path;
                                $Parsedown = new Parsedown();
                                if (method_exists($Parsedown, 'setSafeMode')) {
                                    $Parsedown->setSafeMode(true);
                                    if (method_exists($Parsedown, 'setMarkupEscaped')) $Parsedown->setMarkupEscaped(true);
                                }
                                $rendered         = $Parsedown->text($code_input);
                                $p_content        = '<div class="md-body">' . sanitize_allowlist_html($rendered) . '</div>';
                                $p_code_effective = 'markdown';
                                goto HL_DONE;
                            } else {
                                $res = $hl->highlight($langTry, $code_input);
                            }
                        } else {
                            // last resort: hljs auto
                            $p_code_source = 'hljs';
                            $res    = $hl->highlightAuto($code_input);
                            $langTry = strtolower((string)($res->language ?? $langTry));
                            $p_code_explain = paste_build_explain('hljs', $p_title ?? '', $code_input, $langTry ?: 'plaintext');
                        }
                    }
                    $inner            = $res->value ?: htmlspecialchars($code_input, ENT_QUOTES, 'UTF-8');
                    $p_content        = hl_wrap_with_lines($inner, $langTry, $highlight);
                    $p_code_effective = $langTry;
                    $p_code_label     = $geshiformats[$langTry] ?? (function_exists('paste_friendly_label') ? paste_friendly_label($langTry) : strtoupper($langTry));
                }
                HL_DONE: ;
            } catch (\Throwable $t) {
                // Last resort: plain escaped
                $esc              = htmlspecialchars($code_input, ENT_QUOTES, 'UTF-8');
                $p_content        = hl_wrap_with_lines($esc, 'plaintext', $highlight);
                $p_code_effective = 'plaintext';
                $p_code_label     = $geshiformats['plaintext'] ?? 'Plain Text';
                $p_code_source    = $p_code_source ?? 'fallback';
                $p_code_explain   = $p_code_explain ?: paste_build_explain('fallback', $p_title ?? '', $code_input, 'plaintext');
            }

        } else {
            // ---- GeSHi ----
            $use_auto = ($p_code === 'auto' || $p_code === 'autodetect' || $p_code === '' || $p_code === 'text');
            $lang_for_geshi = $p_code;

            // 1) Filename hint if auto
            if ($use_auto) {
                $fname = paste_pick_from_filename($p_title ?? '', 'geshi', null);
                if ($fname) {
                    $lang_for_geshi   = $fname;
                    $p_code_effective = $lang_for_geshi;
                    $p_code_label     = $geshiformats[$lang_for_geshi] ?? (function_exists('paste_friendly_label') ? paste_friendly_label($lang_for_geshi) : strtoupper($lang_for_geshi));
                    $p_code_source    = 'filename';
                    $p_code_explain   = paste_build_explain('filename', $p_title ?? '', $code_input, $lang_for_geshi);
                }
            }
            // 2) PHP tag hard rule if auto and not already set by filename
            if ($use_auto && empty($p_code_source) && is_probable_php_tag($code_input)) {
                $lang_for_geshi   = function_exists('paste_normalize_lang') ? paste_normalize_lang('php', 'geshi', null) : 'php';
                $p_code_effective = $lang_for_geshi;
                $p_code_label     = $geshiformats[$lang_for_geshi] ?? 'PHP';
                $p_code_source    = 'php-tag';
                $p_code_explain   = paste_build_explain('php-tag', $p_title ?? '', $code_input, $lang_for_geshi);
            }

            if ($use_auto && empty($p_code_source) && function_exists('paste_autodetect_language')) {
                $det = paste_autodetect_language($code_input, 'geshi', null);
                $lang_for_geshi   = $det['id'];                // GeSHi id (mapped)
                $p_code_effective = $lang_for_geshi;
                $p_code_label     = $det['label'];
                $p_code_source    = $det['source'];
                $p_code_explain   = $det['explain'] ?? '';
                if ($p_code_explain === '') {
                    $p_code_explain = paste_build_explain($p_code_source, $p_title ?? '', $code_input, $lang_for_geshi);
                }

                // If autodetect gives markdown but sample looks like python, override
                if ($lang_for_geshi === 'markdown' && is_probable_python($code_input)) {
                    $lang_for_geshi = 'python';
                    $p_code_explain = 'Heuristic override: detected Python tokens (def/import/self) so choosing python over markdown.';
                    $p_code_label = $geshiformats[$lang_for_geshi] ?? 'Python';
                    $p_code_effective = $lang_for_geshi;
                }

                if ($lang_for_geshi === 'markdown') {
                    // For Markdown, keep Parsedown path for consistent rendering
                    require_once $parsedown_path;
                    $Parsedown = new Parsedown();
                    if (method_exists($Parsedown, 'setSafeMode')) {
                        $Parsedown->setSafeMode(true);
                        if (method_exists($Parsedown, 'setMarkupEscaped')) $Parsedown->setMarkupEscaped(true);
                    }
                    $rendered  = $Parsedown->text($code_input);
                    $p_content = '<div class="md-body">' . sanitize_allowlist_html($rendered) . '</div>';
                    goto GESHI_DONE;
                }
            } elseif ($use_auto && empty($p_code_source) && function_exists('paste_probable_markdown') && paste_probable_markdown($code_input)) {
                // Minimal fallback
                require_once $parsedown_path;
                $Parsedown = new Parsedown();
                if (method_exists($Parsedown, 'setSafeMode')) {
                    $Parsedown->setSafeMode(true);
                    if (method_exists($Parsedown, 'setMarkupEscaped')) $Parsedown->setMarkupEscaped(true);
                }
                $rendered         = $Parsedown->text($code_input);
                $p_content        = '<div class="md-body">' . sanitize_allowlist_html($rendered) . '</div>';
                $p_code_effective = 'markdown';
                $p_code_label     = 'Markdown';
                $p_code_source    = 'markdown';
                $p_code_explain   = "Markdown probability based on headings/lists/links; rendering as Markdown.";
                goto GESHI_DONE;
            }

            $geshi = new GeSHi($code_input, $lang_for_geshi, $path);

            // Use classes, not inline CSS; let theme CSS style everything
            if (method_exists($geshi, 'enable_classes')) $geshi->enable_classes();
            if (method_exists($geshi, 'set_header_type')) $geshi->set_header_type(GESHI_HEADER_DIV);

            // Line numbers (NORMAL to avoid rollovers). No inline style.
            if (method_exists($geshi, 'enable_line_numbers')) $geshi->enable_line_numbers(GESHI_NORMAL_LINE_NUMBERS);
            if (!empty($highlight) && method_exists($geshi, 'highlight_lines_extra')) {
                $geshi->highlight_lines_extra($highlight);
            }

            // force plain integer formatting
            if (method_exists($geshi, 'set_line_number_format')) {
                $geshi->set_line_number_format('%d', 0);
            }

            // Parse HTML (class-based markup)
            $p_content = $geshi->parse_code();

            // Add a class to the requested lines so theme CSS can style them
            if (!empty($highlight)) {
                $p_content = geshi_add_line_highlight_class($p_content, $highlight, 'hljs-hl');
            }

            // No stylesheet injection here; theme CSS handles it.
            $ges_style = '';

            // Effective values for UI
            $p_code_effective = $lang_for_geshi;
            if (!isset($p_code_label)) {
                $p_code_label = $geshiformats[$p_code_effective] ?? (function_exists('paste_friendly_label') ? paste_friendly_label($p_code_effective) : strtoupper($p_code_effective));
            }

            GESHI_DONE: ;
        }
    }

    // ======= New comment submit (PRG) — supports parent_id for replies =======
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

            // Backward/forward compatible call to addPasteComment()
            $okId = null;
            try {
                if (function_exists('addPasteComment')) {
                    $rf = new ReflectionFunction('addPasteComment');
                    if ($rf->getNumberOfParameters() >= 7) {
                        // Signature with parent_id
                        $okId = addPasteComment(
                            $pdo,
                            (int)$paste_id,
                            $uid ?: null,
                            $uname,
                            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                            (string)($_POST['comment_body'] ?? ''),
                            $parent_id
                        );
                    } else {
                        // no parent support
                        $okId = addPasteComment(
                            $pdo,
                            (int)$paste_id,
                            $uid ?: null,
                            $uname,
                            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                            (string)($_POST['comment_body'] ?? '')
                        );
                    }
                }
            } catch (Throwable $e) {
                error_log('add_comment error: ' . $e->getMessage());
                $okId = null;
            }

            if ($okId) {
                // Avoid resubmit on refresh
                $to = ($mod_rewrite == '1')
                    ? $baseurl . $paste_id . '#comments'
                    : $baseurl . 'paste.php?id=' . (int)$paste_id . '#comments';
                header('Location: ' . $to);
                exit;
            }
            $comment_error = 'Could not add comment.';
        }
    }

    // ... rest of file unchanged (delete comment, header/footer, comments fetch, etc.) ...
    // For brevity I haven't repeated the rest of the unchanged code here; your original file continues
    // after this point unchanged (delete comment handlers, header require, view rendering, footer).
    //
    // If you prefer I can send the full file with absolutely every line repeated; this copy intentionally
    // focuses on the sections that were changed to fix the Python-vs-Markdown issue.

    // header
    $theme = 'theme/' . htmlspecialchars($default_theme, ENT_QUOTES, 'UTF-8');
    require_once $theme . '/header.php';

    // Fetch comments (tree if available), then decorate
    if (function_exists('getPasteCommentsTree')) {
        $comments = getPasteCommentsTree($pdo, (int)$paste_id);
        // decorate recursively
        $decorate = function (&$node) use (&$decorate, $pdo) {
            $node['body_html'] = render_comment_html((string)($node['body'] ?? ''));
            $node['can_delete'] = isset($_SESSION['username']) && isset($_SESSION['csrf_token']) && (function() use ($pdo, $node) {
                $uid = 0;
                try {
                    $q = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                    $q->execute([$_SESSION['username']]);
                    $uid = (int)($q->fetchColumn() ?: 0);
                } catch (Throwable $e) { $uid = 0; }
                return userOwnsComment($pdo, (int)$node['id'], $uid, (string)$_SESSION['username']);
            })();
            if (!empty($node['children'])) {
                foreach ($node['children'] as &$ch) $decorate($ch);
                unset($ch);
            }
        };
        foreach ($comments as &$c) $decorate($c);
        unset($c);
    } else {
        // flat fetch
        $comments = getPasteComments($pdo, (int)$paste_id, 200, 0);
        foreach ($comments as &$c) {
            $c['body_html'] = render_comment_html((string)$c['body']);
            $c['can_delete'] = isset($_SESSION['username']) && isset($_SESSION['csrf_token']) && (function() use ($pdo, $c) {
                $uid = 0;
                try {
                    $q = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                    $q->execute([$_SESSION['username']]);
                    $uid = (int)($q->fetchColumn() ?: 0);
                } catch (Throwable $e) { $uid = 0; }
                return userOwnsComment($pdo, (int)$c['id'], $uid, (string)$_SESSION['username']);
            })();
        }
        unset($c);
    }

    // Carry any error from PRG redirect
    if ($comment_error === '' && isset($_GET['c_err']) && $_GET['c_err'] !== '') {
        $comment_error = (string)$_GET['c_err'];
    }

    // view OR password prompt
    if ($p_password === "NONE") {
        updateMyView($pdo, (int) $paste_id);

        $p_download = $mod_rewrite == '1' ? $baseurl . "download/$paste_id" : $baseurl . "paste.php?download&id=$paste_id";
        $p_raw      = $mod_rewrite == '1' ? $baseurl . "raw/$paste_id"      : $baseurl . "paste.php?raw&id=$paste_id";
        $p_embed    = $mod_rewrite == '1' ? $baseurl . "embed/$paste_id"    : $baseurl . "paste.php?embed&id=$paste_id";

        require_once $theme . '/view.php';

        // View-once (SELF) cleanup after increment
        $current_views = getPasteViewCount($pdo, (int) $paste_id);
        if ($p_expiry === "SELF" && $current_views >= 2) {
            deleteMyPaste($pdo, (int) $paste_id);
        }
    } else {
        // Password-protected flow shows the prompt via errors.php
        $require_password = true;

        $p_password_input = isset($_POST['mypass'])
            ? trim((string) $_POST['mypass'])
            : (string) ($_SESSION['p_password'] ?? '');

        // Prebuild convenience links that carry the typed password
        $p_download = $mod_rewrite == '1'
            ? $baseurl . "download/$paste_id?password=" . rawurlencode($p_password_input)
            : $baseurl . "paste.php?download&id=$paste_id&password=" . rawurlencode($p_password_input);
        $p_raw = $mod_rewrite == '1'
            ? $baseurl . "raw/$paste_id?password=" . rawurlencode($p_password_input)
            : $baseurl . "paste.php?raw&id=$paste_id&password=" . rawurlencode($p_password_input);
        $p_embed = $mod_rewrite == '1'
            ? $baseurl . "embed/$paste_id?password=" . rawurlencode($p_password_input)
            : $baseurl . "paste.php?embed&id=$paste_id&password=" . rawurlencode($p_password_input);

        if ($p_password_input !== '' && password_verify($p_password_input, $p_password)) {
            updateMyView($pdo, (int) $paste_id);
            require_once $theme . '/view.php';

            $current_views = getPasteViewCount($pdo, (int) $paste_id);
            if ($p_expiry === "SELF" && $current_views >= 2) {
                deleteMyPaste($pdo, (int) $paste_id);
            }
        } else {
            $error = $p_password_input !== ''
                ? ($lang['wrongpwd'] ?? 'Incorrect password.')
                : ($lang['pwdprotected'] ?? 'This paste is password-protected.');
            $_SESSION['p_password'] = $p_password_input;

            require_once $theme . '/errors.php'; // partial renders password prompt
        }
    }

    // footer
    require_once $theme . '/footer.php';

} catch (PDOException $e) {
    error_log("paste.php: Database error: " . $e->getMessage());

    // Still render a readable error page (no password box)
    $error = ($lang['error'] ?? 'Database error.') . ': ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');

    global $default_theme, $baseurl, $mod_rewrite, $pdo, $require_password;
    $require_password = false;

    $theme = 'theme/' . htmlspecialchars($default_theme ?? 'default', ENT_QUOTES, 'UTF-8');
    require_once $theme . '/header.php';
    require_once $theme . '/errors.php';
    require_once $theme . '/footer.php';
}
?>
