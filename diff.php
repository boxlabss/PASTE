<?php
/*
 * Paste $v3.2 2025/09/04 https://github.com/boxlabss/PASTE
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

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

// Highlighter bootstrap + language lists
require_once __DIR__ . '/includes/hlbootstrap.php';
require_once __DIR__ . '/includes/list_languages.php';

ini_set('display_errors','0');
ini_set('log_errors','1');
date_default_timezone_set('UTC');

// Load paste by ID, decrypting as needed. Returns ['title','content','code'] or null
function load_paste(PDO $pdo, int $id): ?array {
    try {
        $st = $pdo->prepare("SELECT id,title,content,code,encrypt FROM pastes WHERE id=? LIMIT 1");
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) return null;

        $title   = (string)$r['title'];
        $content = (string)$r['content'];

        if ((string)$r['encrypt'] === "1" && defined('SECRET')) {
            $title   = decrypt($title,   hex2bin(SECRET))   ?? $title;
            $content = decrypt($content, hex2bin(SECRET))   ?? $content;
        }

        // Stored content is HTML-escaped before encrypt; decode for diffing
        $content = html_entity_decode($content, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');

        return [
            'title'   => $title,
            'content' => $content,
            'code'    => (string)($r['code'] ?? 'text'),
        ];
    } catch (Throwable $e) {
        error_log("diff.php load_paste($id): ".$e->getMessage());
        return null;
    }
}

// Inline (word/char) diff → [leftHTML, rightHTML] with <span class="diff-inside-...">
function inline_diff(string $a, string $b): array {
    $split = static function(string $s): array {
        preg_match_all('/\s+|[^\s]+/u', $s, $m);
        return $m[0] ?: [$s];
    };
    $Aw = $split($a); $Bw = $split($b);
    $useChar = (count($Aw) <= 4 || count($Bw) <= 4);
    if ($useChar) {
        $Aw = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY);
        $Bw = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY);
    }
    $n=count($Aw); $m=count($Bw);
    $L = array_fill(0,$n+1,array_fill(0,$m+1,0));
    for($i=$n-1;$i>=0;$i--) for($j=$m-1;$j>=0;$j--) {
        $L[$i][$j]=($Aw[$i]===$Bw[$j])?($L[$i+1][$j+1]+1):max($L[$i+1][$j],$L[$i][$j+1]);
    }
    $i=0;$j=0;$left='';$right='';
    $esc = static fn($s)=>htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
    while($i<$n && $j<$m){
        if($Aw[$i]===$Bw[$j]){ $left.=$esc($Aw[$i]); $right.=$esc($Bw[$j]); $i++; $j++; }
        elseif($L[$i+1][$j] >= $L[$i][$j+1]){ $left.='<span class="diff-inside-del">'.$esc($Aw[$i]).'</span>'; $i++; }
        else { $right.='<span class="diff-inside-add">'.$esc($Bw[$j]).'</span>'; $j++; }
    }
    while($i<$n){ $left.='<span class="diff-inside-del">'.$esc($Aw[$i]).'</span>'; $i++; }
    while($j<$m){ $right.='<span class="diff-inside-add">'.$esc($Bw[$j]).'</span>'; $j++; }
    return [$left,$right];
}

/**
 * Index-based LCS diff at line level → opcodes referencing original arrays.
 * Opcodes:
 *   - ['op'=>'eq','ai'=>i,'bi'=>j]
 *   - ['op'=>'del','ai'=>i]
 *   - ['op'=>'add','bi'=>j]
 */
function diff_lines_idx(array $A, array $B, ?callable $normalizer=null): array {
    $An = $normalizer ? array_map($normalizer, $A) : $A;
    $Bn = $normalizer ? array_map($normalizer, $B) : $B;

    $n = count($An); $m = count($Bn);
    $L = array_fill(0, $n+1, array_fill(0, $m+1, 0));
    for ($i=$n-1; $i>=0; $i--) {
        for ($j=$m-1; $j>=0; $j--) {
            $L[$i][$j] = ($An[$i] === $Bn[$j]) ? $L[$i+1][$j+1]+1 : max($L[$i+1][$j], $L[$i][$j+1]);
        }
    }
    $i=0; $j=0; $ops=[];
    while ($i<$n && $j<$m) {
        if ($An[$i] === $Bn[$j]) { $ops[] = ['op'=>'eq','ai'=>$i,'bi'=>$j]; $i++; $j++; }
        elseif ($L[$i+1][$j] >= $L[$i][$j+1]) { $ops[] = ['op'=>'del','ai'=>$i]; $i++; }
        else { $ops[] = ['op'=>'add','bi'=>$j]; $j++; }
    }
    while ($i<$n) { $ops[] = ['op'=>'del','ai'=>$i]; $i++; }
    while ($j<$m) { $ops[] = ['op'=>'add','bi'=>$j]; $j++; }
    return $ops;
}

// Build side-by-side & unified row arrays from ops (index-based).
function build_tables_idx(array $ops, array $leftLines, array $rightLines): array {
    $side=[]; $uni=[]; $li=1; $ri=1;
    foreach ($ops as $op) {
        if ($op['op']==='eq') {
            $L=(string)($leftLines[$op['ai']] ?? '');
            $R=(string)($rightLines[$op['bi']] ?? '');
            $side[]=['lno'=>$li,'rno'=>$ri,'lclass'=>'ctx','rclass'=>'ctx','lhtml'=>$L,'rhtml'=>$R,'l_intra'=>false,'r_intra'=>false];
            $uni[] =['lno'=>$li,'rno'=>$ri,'class'=>'ctx','html'=>$L,'intra'=>false];
            $li++; $ri++;
        } elseif ($op['op']==='del') {
            $L=(string)($leftLines[$op['ai']] ?? '');
            $side[]=['lno'=>$li,'rno'=>'','lclass'=>'del','rclass'=>'empty','lhtml'=>$L,'rhtml'=>'','l_intra'=>false,'r_intra'=>false];
            $uni[] =['lno'=>$li,'rno'=>'','class'=>'del','html'=>$L,'intra'=>false];
            $li++;
        } else { // add
            $R=(string)($rightLines[$op['bi']] ?? '');
            $side[]=['lno'=>'','rno'=>$ri,'lclass'=>'empty','rclass'=>'add','lhtml'=>'','rhtml'=>$R,'l_intra'=>false,'r_intra'=>false];
            $uni[] =['lno'=>'','rno'=>$ri,'class'=>'add','html'=>$R,'intra'=>false];
            $ri++;
        }
    }
    return [$side,$uni];
}

// Apply inline word/char diff across adjacent del/add in side-by-side rows.
function apply_inline_sxs(array &$sideRows): void {
    for ($i=0; $i<count($sideRows)-1; $i++) {
        $a=$sideRows[$i]; $b=$sideRows[$i+1];
        if ($a['lclass']==='del' && $b['rclass']==='add') {
            [$L,$R] = inline_diff((string)$a['lhtml'], (string)$b['rhtml']);
            $sideRows[$i]['lhtml']=$L; $sideRows[$i]['l_intra']=true;
            $sideRows[$i+1]['rhtml']=$R; $sideRows[$i+1]['r_intra']=true;
            $i++;
        }
    }
}

// Proper unified .diff (xdiff if present; else POSIX-ish fallback)
function unified_diff_download(string $left, string $right, string $nameA='a', string $nameB='b', int $ctx=3): string {
    if (function_exists('xdiff_string_diff')) {
        $flags = 0;
        $ud = xdiff_string_diff($left, $right, $ctx, $flags);
        if ($ud !== false) {
            $ts = gmdate('Y-m-d H:i:s O');
            $hdr = "--- {$nameA}\t{$ts}\n+++ {$nameB}\t{$ts}\n";
            $ud = preg_replace('~^--- .*\R\+\+\+ .*\R~', $hdr, $ud, 1);
            return $ud;
        }
    }

    // Manual fallback
    $aOrig = preg_split("/\R/u", $left);
    $bOrig = preg_split("/\R/u", $right);
    if ($aOrig === false) $aOrig = [$left];
    if ($bOrig === false) $bOrig = [$right];

    $ops = diff_lines_idx($aOrig, $bOrig, null);

    $hunks = [];
    $flush = static function (&$hunks, &$buf) {
        if (empty($buf['lines'])) return;
        $oldLen = max(0, $buf['oldLen']);
        $newLen = max(0, $buf['newLen']);
        $h = "@@ -{$buf['oldStart']}" . ($oldLen===1?'':",$oldLen")
           . " +{$buf['newStart']}" . ($newLen===1?'':",$newLen") . " @@\n";
        $h .= implode('', $buf['lines']);
        $hunks[] = $h;
        $buf = ['oldStart'=>0,'newStart'=>0,'oldLen'=>0,'newLen'=>0,'lines'=>[],'open'=>false];
    };

    $buf = ['oldStart'=>0,'newStart'=>0,'oldLen'=>0,'newLen'=>0,'lines'=>[],'open'=>false];
    $ctxAhead = 0;
    $ai=1; $bi=1;

    $grab_context = static function($aOrig, $bOrig, $ai, $bi, $ctx) use (&$buf) {
        $startA = max(1, $ai - $ctx);
        $startB = max(1, $bi - $ctx);
        $buf['oldStart'] = $startA;
        $buf['newStart'] = $startB;
        for ($k=0; $k<($ai-$startA); $k++) {
            $buf['lines'][] = ' ' . rtrim((string)$aOrig[$startA-1+$k], "\r") . "\n";
        }
        $buf['oldLen'] += ($ai-$startA);
        $buf['newLen'] += ($bi-$startB);
    };

    foreach ($ops as $op) {
        if ($op['op'] === 'eq') {
            if ($buf['open']) {
                if ($ctxAhead < $ctx) {
                    $line = rtrim((string)$aOrig[$op['ai']], "\r");
                    $buf['lines'][] = ' ' . $line . "\n";
                    $buf['oldLen']++; $buf['newLen']++; $ctxAhead++;
                } else {
                    $flush($hunks, $buf);
                    $ctxAhead = 0;
                }
            }
            $ai++; $bi++;
        } elseif ($op['op'] === 'del') {
            if (!$buf['open']) { $buf['open']=true; $ctxAhead=0; $grab_context($aOrig, $bOrig, $ai, $bi, $ctx); }
            $line = rtrim((string)$aOrig[$op['ai']], "\r");
            $buf['lines'][] = '-' . $line . "\n";
            $buf['oldLen']++; $ai++;
        } else { // add
            if (!$buf['open']) { $buf['open']=true; $ctxAhead=0; $grab_context($aOrig, $bOrig, $ai, $bi, $ctx); }
            $line = rtrim((string)$bOrig[$op['bi']], "\r");
            $buf['lines'][] = '+' . $line . "\n";
            $buf['newLen']++; $bi++;
        }
    }
    if ($buf['open']) $flush($hunks, $buf);

    $ts = gmdate('Y-m-d H:i:s O');
    $out  = "--- {$nameA}\t{$ts}\n+++ {$nameB}\t{$ts}\n";
    $out .= implode('', $hunks);
    return $out;
}

// Render a single line with highlight.php if available (else plain-escaped).
function hl_render_line(string $text, string $lang='text'): string {
    global $highlighter;
    static $hl = null;

    $esc = static fn($s)=>htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');

    if (($highlighter ?? 'geshi') === 'highlight') {
        if ($hl === null) $hl = make_highlighter();
        if ($hl) {
            try {
                if ($lang && !in_array(strtolower($lang), ['autodetect','text','plaintext'], true)) {
                    $res = $hl->highlight($lang, $text);
                    return '<span class="hljs">'.$res->value.'</span>';
                }
                $res = $hl->highlightAuto($text);
                return '<span class="hljs">'.$res->value.'</span>';
            } catch (Throwable $e) { /* fall through */ }
        }
    }
    return $esc($text);
}

/* =========================================================
 * Page inputs
 * =======================================================*/

$left  = '';
$right = '';
$leftLabel  = 'Left';
$rightLabel = 'Right';

/* ---------- Minimal site/bootstrap so header/footer look right ---------- */
$mod_rewrite = $mod_rewrite ?? '0';
$baseurl     = $baseurl     ?? '/';
$site_name   = $site_name   ?? '';
$title       = 'Diff';
$ges_style   = '';          // keep themes CSS-only
$ads_1 = $ads_2 = $text_ads = ''; // optional ad slots

try {
    // site_info
    $stmt = $pdo->query("SELECT * FROM site_info WHERE id='1'");
    if ($stmt) {
        $si = $stmt->fetch() ?: [];
        $title     = trim($si['title'] ?? $title);
        $des       = trim($si['des'] ?? '');
        $baseurl   = rtrim(trim($si['baseurl'] ?? $baseurl), '/') . '/';
        $keyword   = trim($si['keyword'] ?? '');
        $site_name = trim($si['site_name'] ?? $site_name);
        $email     = trim($si['email'] ?? '');
        $twit      = trim($si['twit'] ?? '');
        $face      = trim($si['face'] ?? '');
        $gplus     = trim($si['gplus'] ?? '');
        $ga        = trim($si['ga'] ?? '');
        $additional_scripts = trim($si['additional_scripts'] ?? '');
        if (isset($si['mod_rewrite']) && $si['mod_rewrite'] !== '') {
            $mod_rewrite = (string)$si['mod_rewrite'];
        }
    }

    // interface
    $stmt = $pdo->query("SELECT * FROM interface WHERE id='1'");
    if ($stmt) {
        $iface = $stmt->fetch() ?: [];
        $default_lang  = trim($iface['lang']  ?? 'en.php');
        $default_theme = trim($iface['theme'] ?? 'default');
        if (is_file(__DIR__ . "/langs/$default_lang")) {
            require_once __DIR__ . "/langs/$default_lang";
        }
    } else {
        $default_theme = $default_theme ?? 'default';
    }

    // permissions
    $stmt = $pdo->query("SELECT * FROM site_permissions WHERE id='1'");
    if ($stmt) {
        $perm = $stmt->fetch() ?: [];
        $disableguest = trim($perm['disableguest'] ?? 'off');
        $siteprivate  = trim($perm['siteprivate']  ?? 'off');
    }

    // ads (optional)
    $stmt = $pdo->query("SELECT * FROM ads WHERE id='1'");
    if ($stmt) {
        $ads  = $stmt->fetch() ?: [];
        $text_ads = trim($ads['text_ads'] ?? '');
        $ads_1    = trim($ads['ads_1'] ?? '');
        $ads_2    = trim($ads['ads_2'] ?? '');
    }
} catch (Throwable $e) {
    // keep sane defaults, but don't break the page
    error_log('diff.php bootstrap: ' . $e->getMessage());
    $default_theme = $default_theme ?? 'default';
}

/* ---------- Paste IDs from query (supports ?a & ?b) ---------- */
$lid = isset($_GET['a']) ? (int)$_GET['a'] : (isset($_GET['left_id']) ? (int)$_GET['left_id'] : 0);
$rid = isset($_GET['b']) ? (int)$_GET['b'] : (isset($_GET['right_id']) ? (int)$_GET['right_id'] : 0);

if ($lid) { $p = load_paste($pdo, $lid); if ($p){ $left=$p['content'];  $leftLabel='Paste #'.$lid; } }
if ($rid) { $p = load_paste($pdo, $rid); if ($p){ $right=$p['content']; $rightLabel='Paste #'.$rid; } }

/* ---------- POST inputs (compare / download keeps buffers) ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $left       = (string)($_POST['left_text']  ?? $left);
    $right      = (string)($_POST['right_text'] ?? $right);
    $leftLabel  = trim((string)($_POST['left_label']  ?? $leftLabel))  ?: $leftLabel;
    $rightLabel = trim((string)($_POST['right_label'] ?? $rightLabel)) ?: $rightLabel;
}

/* ---------- Language engine + maps ---------- */
$engine = function_exists('paste_current_engine') ? paste_current_engine() : ($highlighter ?? 'geshi');

if ($engine === 'highlight') {
    $langs         = highlight_supported_languages();
    $language_map  = highlight_language_map($langs);
    $alias_map     = highlight_alias_map($langs);
    $popular_langs = paste_popular_formats_highlight();
} else {
    $language_map  = geshi_language_map();
    $alias_map     = geshi_alias_map($language_map);
    $popular_langs = paste_popular_formats_geshi();
}

/* ---------- Picked languages ---------- */
$lang_left  = strtolower((string)($_POST['left_lang']  ?? $_GET['left_lang']  ?? 'autodetect'));
$lang_right = strtolower((string)($_POST['right_lang'] ?? $_GET['right_lang'] ?? 'autodetect'));
$lang_left  = $alias_map[$lang_left]  ?? 'autodetect';
$lang_right = $alias_map[$lang_right] ?? 'autodetect';

$lang_left_label  = $language_map[$lang_left]  ?? ucfirst($lang_left);
$lang_right_label = $language_map[$lang_right] ?? ucfirst($lang_right);

/* ---------- highlight.php default style (no picker) ---------- */
if (($highlighter ?? 'geshi') === 'highlight') {
    $hl_style = $hl_style ?? 'hybrid.css'; // header.php reads this
}

/* ---------- View options ---------- */
$view_mode = ($_GET['view'] ?? 'side') === 'unified' ? 'unified' : 'side';
$wrap      = isset($_GET['wrap'])      ? (int)$_GET['wrap']      : 0;
$lineno    = isset($_GET['lineno'])    ? (int)$_GET['lineno']    : 1;

/* ---------- Persisted split percentage ---------- */
$split_pct = 50.0;
if (isset($_POST['split_pct'])) {
    $split_pct = (float)$_POST['split_pct'];
} elseif (isset($_COOKIE['diffSplitPct'])) {
    $split_pct = (float)$_COOKIE['diffSplitPct'];
}
$split_pct = max(20.0, min(80.0, (float)$split_pct));
setcookie('diffSplitPct', (string)$split_pct, [
    'expires'  => time() + 60*60*24*30,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => false,
    'samesite' => 'Lax',
]);

/* ---------- Download unified diff ---------- */
if (isset($_GET['download']) && $_GET['download'] === '1') {
    $nameA = $leftLabel  ?: 'Left';
    $nameB = $rightLabel ?: 'Right';
    $ud = unified_diff_download($left, $right, $nameA, $nameB, 3);
    header('Content-Type: text/x-diff; charset=utf-8');
    header('Content-Disposition: attachment; filename="paste.diff"');
    echo $ud;
    exit;
}

/* ---------- Compute opcodes ---------- */
$leftLines  = preg_split("/\R/u", $left);
$rightLines = preg_split("/\R/u", $right);
if ($leftLines === false)  $leftLines  = [$left];
if ($rightLines === false) $rightLines = [$right];

$ops = diff_lines_idx($leftLines, $rightLines, null);

/* ---------- Build tables server-side ---------- */
[$sideRows, $uniRows] = build_tables_idx($ops, $leftLines, $rightLines);
apply_inline_sxs($sideRows);

/* ---------- Render theme ---------- */
$themeDir = 'theme/' . htmlspecialchars($default_theme ?? 'default', ENT_QUOTES, 'UTF-8');

// expose split pct to the view if needed by JS
$GLOBALS['split_pct'] = $split_pct;

require_once $themeDir . '/header.php';
require_once $themeDir . '/diff.php';
require_once $themeDir . '/footer.php';
