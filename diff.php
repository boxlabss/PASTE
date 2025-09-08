<?php
/*
 * Paste $v3.2 2025/09/08 https://github.com/boxlabss/PASTE
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
// Error handling
paste_enable_themed_errors();

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

// Inline (word/char) diff > [leftHTML, rightHTML] with <span class="diff-inside-...">
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
 * Index-based diff at line level > opcodes referencing original arrays.
 * Opcodes:
 *   - ['op'=>'eq','ai'=>i,'bi'=>j]
 *   - ['op'=>'del','ai'=>i]
 *   - ['op'=>'add','bi'=>j]
 *
 *   1) xdiff accelerator (parse unified diff) if available
 *   2) Myers O((N+M)D) fallback in pure PHP
 */
function diff_lines_idx(array $A, array $B, ?callable $normalizer=null): array {
    $An = $normalizer ? array_map($normalizer, $A) : $A;
    $Bn = $normalizer ? array_map($normalizer, $B) : $B;

    if (function_exists('xdiff_string_diff')) {
        return _diff_idx_via_xdiff($An, $Bn);
    }
    return _diff_idx_via_myers($An, $Bn);
}

/* ---------- Fast path: use xdiff to compute unified diff, parse to opcodes ---------- */
function _diff_idx_via_xdiff(array $A, array $B): array {
    $N = count($A); $M = count($B);

    // Join as text; add trailing newline to stabilize EOF handling
    $left  = ($N ? implode("\n", $A) : '') . "\n";
    $right = ($M ? implode("\n", $B) : '') . "\n";

    // Use context for stable headers + ' ' lines; non-minimal for speed
    $ctx = 3;
    $ud = xdiff_string_diff($left, $right, $ctx, false);
    if ($ud === false) {
        // Trivial fallback: align common prefix, then tail adds/dels
        $ops = [];
        $eq = min($N, $M);
        for ($i=0; $i<$eq; $i++) $ops[] = ['op'=>'eq','ai'=>$i,'bi'=>$i];
        for ($i=$eq; $i<$N; $i++) $ops[] = ['op'=>'del','ai'=>$i];
        for ($j=$eq; $j<$M; $j++) $ops[] = ['op'=>'add','bi'=>$j];
        return $ops;
    }
    if ($ud === '') {
        $ops = [];
        for ($i=0; $i<min($N,$M); $i++) $ops[] = ['op'=>'eq','ai'=>$i,'bi'=>$i];
        for ($i=$M; $i<$N; $i++) $ops[] = ['op'=>'del','ai'=>$i];
        for ($j=$N; $j<$M; $j++) $ops[] = ['op'=>'add','bi'=>$j];
        return $ops;
    }

    $ops = [];
    $ai = 0; $bi = 0;

    $lines = preg_split("/\R/u", $ud);
    foreach ($lines as $ln) {
        if ($ln === '' && $ln !== '0') continue;

        // Hunk header: @@ -aStart,aLen +bStart,bLen @@
        if (($ln[0] ?? '') === '@' &&
            preg_match('/^@@\s+-([0-9]+)(?:,([0-9]+))?\s+\+([0-9]+)(?:,([0-9]+))?\s+@@/', $ln, $m)) {

            $startA = max(0, (int)$m[1] - 1);  // convert to 0-based
            $startB = max(0, (int)$m[3] - 1);

            // Between hunks: move forward by equal lines only
            $gap = min(max(0, $startA - $ai), max(0, $startB - $bi));
            for ($k=0; $k<$gap; $k++) { $ops[] = ['op'=>'eq','ai'=>$ai,'bi'=>$bi]; $ai++; $bi++; }
            continue;
        }

        $tag = $ln[0] ?? '';
        if ($tag === ' ') {                   // context (equal)
            $ops[] = ['op'=>'eq','ai'=>$ai,'bi'=>$bi];  $ai++; $bi++;
        } elseif ($tag === '-') {             // deletion
            $ops[] = ['op'=>'del','ai'=>$ai];           $ai++;
        } elseif ($tag === '+') {             // addition
            $ops[] = ['op'=>'add','bi'=>$bi];                    $bi++;
        } elseif ($tag === '\\') {
            // "\ No newline at end of file" > ignore
        } else {
            // headers '---' / '+++' or noise > ignore
        }
    }

    // Trailing equals after the last hunk
    $tailEq = min($N - $ai, $M - $bi);
    for ($k=0; $k<$tailEq; $k++) { $ops[] = ['op'=>'eq','ai'=>$ai,'bi'=>$bi]; $ai++; $bi++; }
    for (; $ai<$N; $ai++) $ops[] = ['op'=>'del','ai'=>$ai];
    for (; $bi<$M; $bi++) $ops[] = ['op'=>'add','bi'=>$bi];

    return $ops;
}

/* ---------- Fallback: Myers O((N+M)D) with path reconstruction ---------- */
function _diff_idx_via_myers(array $A, array $B): array {
    $N = count($A); $M = count($B);

    if ($N === 0 && $M === 0) return [];
    if ($N === 0) { $ops=[]; for($j=0;$j<$M;$j++) $ops[]=['op'=>'add','bi'=>$j]; return $ops; }
    if ($M === 0) { $ops=[]; for($i=0;$i<$N;$i++) $ops[]=['op'=>'del','ai'=>$i]; return $ops; }

    $max = $N + $M;
    $off = $max;
    $V = array_fill(0, 2 * $max + 1, 0);
    $trace = [];

    $Dend = 0;
    for ($d = 0; $d <= $max; $d++) {
        $trace[$d] = $V;

        for ($k = -$d; $k <= $d; $k += 2) {
            if ($k == -$d || ($k != $d && $V[$off + $k - 1] < $V[$off + $k + 1])) {
                $x = $V[$off + $k + 1];       // down (insert B)
            } else {
                $x = $V[$off + $k - 1] + 1;   // right (delete A)
            }
            $y = $x - $k;

            while ($x < $N && $y < $M && $A[$x] === $B[$y]) { $x++; $y++; }

            $V[$off + $k] = $x;

            if ($x >= $N && $y >= $M) {
                $trace[$d] = $V;
                $Dend = $d;
                break 2;
            }
        }
    }

    $ops = [];
    $x = $N; $y = $M;
    for ($d = $Dend; $d > 0; $d--) {
        $Vprev = $trace[$d-1];
        $k  = $x - $y;

        $down = ($k == -$d) || ($k != $d && $Vprev[$off + $k - 1] < $Vprev[$off + $k + 1]);
        $kPrev   = $down ? $k + 1 : $k - 1;
        $xStart  = $down ? $Vprev[$off + $kPrev] : $Vprev[$off + $kPrev] + 1;
        $yStart  = $xStart - $kPrev;

        while ($x > $xStart && $y > $yStart) { $x--; $y--; $ops[] = ['op'=>'eq','ai'=>$x,'bi'=>$y]; }

        if ($down) {
            $yStart--;
            $ops[] = ['op'=>'add','bi'=>$yStart];
        } else {
            $xStart--;
            $ops[] = ['op'=>'del','ai'=>$xStart];
        }

        $x = $xStart; $y = $yStart;
    }

    while ($x > 0 && $y > 0) { $x--; $y--; $ops[] = ['op'=>'eq','ai'=>$x,'bi'=>$y]; }
    while ($x > 0) { $x--; $ops[] = ['op'=>'del','ai'=>$x]; }
    while ($y > 0) { $y--; $ops[] = ['op'=>'add','bi'=>$y]; }

    return array_reverse($ops);
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
        // Use non-minimal for speed; we rewrite headers below
        $ud = xdiff_string_diff($left, $right, $ctx, false);
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
                if ($ctxAhead < 3) {
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
            if (!$buf['open']) { $buf['open']=true; $ctxAhead=0; $grab_context($aOrig, $bOrig, $ai, $bi, 3); }
            $line = rtrim((string)$aOrig[$op['ai']], "\r");
            $buf['lines'][] = '-' . $line . "\n";
            $buf['oldLen']++; $ai++;
        } else { // add
            if (!$buf['open']) { $buf['open']=true; $ctxAhead=0; $grab_context($aOrig, $bOrig, $ai, $bi, 3); }
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
$leftLabel  = 'Old';
$rightLabel = 'New';

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

/* ---------- Ignore trailing whitespace (toggle via ?ignore_ws=1) ---------- */
$ignore_ws = isset($_GET['ignore_ws']) ? (int)$_GET['ignore_ws'] : 0;
$normalizer = $ignore_ws ? static fn($s) => rtrim($s, " \t") : null;

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
    $nameA = $leftLabel  ?: 'Old';
    $nameB = $rightLabel ?: 'New';
    $ud = unified_diff_download($left, $right, $nameA, $nameB, 3);
    // Surface engine in headers for debug
    header('X-Diff-Engine: '.(function_exists('xdiff_string_diff') ? 'xdiff' : 'myers'));
    header('X-Diff-Ignore-WS: '.($ignore_ws ? '1':'0'));
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

$ops = diff_lines_idx($leftLines, $rightLines, $normalizer);

/* ---------- change counts & exposure ----------
 * Treat an adjacent del-run followed by an add-run (or vice-versa) as "mods".
 * Each paired line counts as 1 change in the total.
 * Returns: [$adds, $dels, $mods, $total, $no_changes]
 */
function compute_change_counts(array $ops): array {
    $adds = 0; $dels = 0; $mods = 0;
    $n = count($ops);
    for ($i = 0; $i < $n; ) {
        $op = $ops[$i]['op'] ?? 'eq';
        if ($op !== 'add' && $op !== 'del') { $i++; continue; }

        // First run (all adds or all dels)
        $t1 = $op;
        $c1 = 0;
        $j  = $i;
        while ($j < $n && ($ops[$j]['op'] ?? 'eq') === $t1) { $c1++; $j++; }

        // Optional immediately-adjacent opposite run
        $t2 = ($t1 === 'add') ? 'del' : 'add';
        $c2 = 0;
        $k  = $j;
        while ($k < $n && ($ops[$k]['op'] ?? 'eq') === $t2) { $c2++; $k++; }

        // Pair min(c1,c2) as modifications
        $pair = min($c1, $c2);
        $mods += $pair;

        if ($t1 === 'add') {
            $adds += $c1 - $pair;
            $dels += $c2 - $pair;
        } else {
            $dels += $c1 - $pair;
            $adds += $c2 - $pair;
        }

        // Advance past both runs
        $i = ($c2 > 0) ? $k : $j;
    }

    $total = $adds + $dels + $mods;   // modified lines count as 1
    $no_changes = ($total === 0);
    return [$adds, $dels, $mods, $total, $no_changes];
}

/* ---------- change counts (mods collapse -/+ into 1) ---------- */
[$adds, $dels, $mods, $changed_total, $no_changes] = compute_change_counts($ops);

header('X-Diff-No-Changes: ' . ($no_changes ? '1' : '0'));
header('X-Diff-Change-Add: ' . $adds);
header('X-Diff-Change-Del: ' . $dels);
header('X-Diff-Change-Mod: ' . $mods);
header('X-Diff-Change-Total: ' . $changed_total);

$GLOBALS['diff_no_changes']     = $no_changes;
$GLOBALS['diff_changes_add']    = $adds;
$GLOBALS['diff_changes_del']    = $dels;
$GLOBALS['diff_changes_mod']    = $mods;         // available if you want a separate badge
$GLOBALS['diff_changes_total']  = $changed_total; // theme uses this for ±T

/* ---------- Build tables server-side ---------- */
[$sideRows, $uniRows] = build_tables_idx($ops, $leftLines, $rightLines);

/* ---------- Limit expensive inline diff pass for very large diffs ---------- */
$perform_inline = true;
$totalBytes = strlen($left) + strlen($right);
if (count($sideRows) > 4000 || $totalBytes > 4*1024*1024) {
    $perform_inline = false;
}
if ($perform_inline) {
    apply_inline_sxs($sideRows);
}

/* ---------- Expose engine + toggle info to theme and headers ---------- */
$engine_is_xdiff = function_exists('xdiff_string_diff');
$engine_label    = $engine_is_xdiff ? 'xdiff' : 'myers';
header('X-Diff-Engine: '.$engine_label);
header('X-Diff-Ignore-WS: '.($ignore_ws ? '1':'0'));

// Convenience strings the theme can show in the toolbar:
$diff_engine_badge = '<span class="badge bg-secondary" title="Diff engine">'.$engine_label.'</span>';
$ignore_ws_on      = (bool)$ignore_ws;

// Build toggle URL for ignore_ws (preserve other query params)
$qs = $_GET;
$qs['ignore_ws'] = $ignore_ws ? 0 : 1;
$ignore_ws_toggle_url = strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($qs);

/* ---------- Render theme ---------- */
$themeDir = 'theme/' . htmlspecialchars($default_theme ?? 'default', ENT_QUOTES, 'UTF-8');

// expose split pct to the view if needed by JS
$GLOBALS['split_pct'] = $split_pct;

// badges/toggle url for the theme
$GLOBALS['diff_engine_badge']  = $diff_engine_badge;
$GLOBALS['ignore_ws_on']       = $ignore_ws_on;
$GLOBALS['ignore_ws_toggle']   = $ignore_ws_toggle_url;

require_once $themeDir . '/header.php';
require_once $themeDir . '/diff.php';
require_once $themeDir . '/footer.php';