<?php
/*
 * Paste $v3.3 https://github.com/boxlabss/PASTE
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

// Themed error handling
paste_enable_themed_errors();

// Highlighter engine selection
if (($highlighter ?? 'geshi') === 'geshi') {
    require_once __DIR__ . '/includes/geshi.php';
} else {
    require_once __DIR__ . '/includes/hlbootstrap.php';
}
require_once __DIR__ . '/includes/list_languages.php';

ini_set('display_errors','0');
ini_set('log_errors','1');
date_default_timezone_set('UTC');

/* =========================================================
 * Data access
 * =======================================================*/

// Load paste by ID, decrypting as needed. Returns ['title','content','code'] or null
function load_paste(PDO $pdo, int $id): ?array {
    try {
        $st = $pdo->prepare("SELECT id,title,content,code,encrypt FROM pastes WHERE id=? LIMIT 1");
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) return null;

        $title   = (string)$r['title'];
        $content = (string)$r['content'];
        $code    = (string)($r['code'] ?? 'autodetect');

        if ((string)$r['encrypt'] === "1" && defined('SECRET')) {
            $title   = decrypt($title,   hex2bin(SECRET))   ?? $title;
            $content = decrypt($content, hex2bin(SECRET))   ?? $content;
        }

        // Stored content is HTML-escaped before encrypt; decode for diffing
        $content = html_entity_decode($content, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');

        return [
            'title'   => $title,
            'content' => $content,
            'code'    => $code,
        ];
    } catch (Throwable $e) {
        error_log("diff.php load_paste($id): ".$e->getMessage());
        return null;
    }
}

/* =============================================================
 * Diff core — xdiff fast path + Myers fallback (index opcodes)
 * ===========================================================*/

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

/* =========================================================
 * Inline word/char diff for adjacent del/add rows
 * =======================================================*/

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

/* =========================================================
 * Build table models (side-by-side / unified)
 * =======================================================*/

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

function apply_inline_unified(array &$uniRows): void {
    for ($i = 0; $i < count($uniRows) - 1; $i++) {
        $a = $uniRows[$i];
        $b = $uniRows[$i+1];
        if (($a['class'] ?? '') === 'del' && ($b['class'] ?? '') === 'add') {
            $leftRaw  = (string)$a['html'];
            $rightRaw = (string)$b['html'];
            [$L, $R] = inline_diff($leftRaw, $rightRaw);
            $uniRows[$i]['html'] = $L; $uniRows[$i]['intra'] = true;
            $uniRows[$i+1]['html'] = $R; $uniRows[$i+1]['intra'] = true;
            $i++; // skip the add-row we just processed
        }
    }
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

/* =========================================================
 * Unified diff download — xdiff preferred; robust fallback
 * =======================================================*/

// If $normalizer is provided (e.g. ignore_ws=1), we force the fallback so we can respect normalization.
function unified_diff_download(
    string $left,
    string $right,
    string $nameA='a',
    string $nameB='b',
    int $ctx=3,
    ?callable $normalizer=null
): string {
    // Sanitize within each hunk: ensure every body line has a valid prefix.
    $sanitize_udiff = static function(string $ud): string {
        // Standardize newlines to \n first
        $ud = str_replace(["\r\n", "\r"], "\n", $ud);

        // For each hunk, prefix any non-prefixed line with a single space.
        // Valid prefixes are: ' ', '+', '-', '\'
        $ud = preg_replace_callback(
            '/(^@@[^\n]*\n)(.*?)(?=(^@@|\z))/ms',
            static function ($m) {
                $head = $m[1];
                $body = $m[2];
                $body = preg_replace('/^(?![ +\-\\\\]).*$/m', ' $0', $body);
                return $head . $body;
            },
            $ud
        );

        // Guarantee a trailing newline
        if ($ud !== '' && substr($ud, -1) !== "\n") $ud .= "\n";
        return $ud;
    };

    $force_fallback = ($normalizer !== null);

    if (!$force_fallback && function_exists('xdiff_string_diff')) {
        // PHP's xdiff signature: (old, new, context=3, minimal=false)
        $ud = xdiff_string_diff($left, $right, $ctx, false);
        if ($ud !== false) {
            $ts  = gmdate('Y-m-d H:i:s O');
            $hdr = "--- {$nameA}\t{$ts}\n+++ {$nameB}\t{$ts}\n";
            // Rewrite the top headers to stable names; keep the rest verbatim
            $ud  = preg_replace('~^--- .*\R\+\+\+ .*\R~', $hdr, $ud, 1);
            return $sanitize_udiff($ud);
        }
        // fall through to manual generator if xdiff failed
    }

    // -------- Manual fallback (respects $normalizer) --------
    $a = preg_split("/\R/u", $left)  ?: [$left];
    $b = preg_split("/\R/u", $right) ?: [$right];

    $ops = diff_lines_idx($a, $b, $normalizer);

    $hunks = [];
    $open  = false; $eqBuf = [];
    $ai = 0; $bi = 0;
    $h = ['a0'=>0,'b0'=>0,'lines'=>[]];

    $flush = static function() use (&$hunks, &$h) {
        if (!$h['lines']) return;
        $alen=0; $blen=0;
        foreach ($h['lines'] as $ln) {
            $c = $ln[0] ?? '';
            if ($c === ' ' || $c === '-') $alen++;
            if ($c === ' ' || $c === '+') $blen++;
        }
        $hdr = "@@ -" . ($h['a0']+1) . ($alen===1?'':",".$alen)
             . " +" . ($h['b0']+1) . ($blen===1?'':",".$blen) . " @@\n";
        $hunks[] = $hdr . implode('', array_map(static fn($s)=>$s."\n", $h['lines']));
        $h = ['a0'=>0,'b0'=>0,'lines'=>[]];
    };

    foreach ($ops as $op) {
        $type = $op['op'] ?? 'eq';
        if ($type === 'eq') {
            $line = rtrim((string)$a[$op['ai']], "\r"); // keep spaces; strip CR
            $eqBuf[] = $line;
            if ($open && count($eqBuf) > $ctx) {
                for ($i=0; $i<$ctx; $i++) $h['lines'][] = ' ' . $eqBuf[$i];
                $flush();
                $open  = false;
                $eqBuf = array_slice($eqBuf, -$ctx);
            }
            $ai++; $bi++;
            continue;
        }

        if (!$open) {
            $h['a0'] = $ai; $h['b0'] = $bi;
            foreach (array_slice($eqBuf, -$ctx) as $ln) $h['lines'][] = ' ' . $ln;
            $open  = true;
            $eqBuf = [];
        }

        if ($type === 'del') {
            $h['lines'][] = '-' . rtrim((string)$a[$op['ai']], "\r");
            $ai++;
        } else { // add
            $h['lines'][] = '+' . rtrim((string)$b[$op['bi']], "\r");
            $bi++;
        }
    }

    if ($open) {
        foreach (array_slice($eqBuf, 0, $ctx) as $ln) $h['lines'][] = ' ' . $ln;
        $flush();
    }

    $ts = gmdate('Y-m-d H:i:s O');
    $out = "--- {$nameA}\t{$ts}\n+++ {$nameB}\t{$ts}\n" . implode('', $hunks);
    return $out;
}

/* =========================================================
 * Highlighter line renderer (highlight.php or GeSHi)
 * =======================================================*/
function hl_render_line(string $text, string $lang='text'): string {
    global $highlighter, $engine;
    static $hl = null;            // highlight.php
    static $geshi = null;         // GeSHi instance
    static $geshiLang = null;
    static $geshiPath = null;

    $mode = $engine ?? $highlighter ?? 'geshi';
    $esc  = static fn($s)=>htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');

    if ($mode === 'highlight') {
        if ($hl === null && function_exists('make_highlighter')) $hl = make_highlighter();
        if ($hl) {
            try {
                $L = strtolower((string)$lang);
                if ($L && !in_array($L,['autodetect','text','plaintext'],true)) {
                    $res = $hl->highlight($L, $text);
                    return '<span class="hljs">'.$res->value.'</span>';
                }
                $res = $hl->highlightAuto($text);
                return '<span class="hljs">'.$res->value.'</span>';
            } catch (Throwable $e) { /* fall through */ }
        }
        return $esc($text);
    }

    if ($mode === 'geshi') {
        $L = strtolower((string)$lang);
        if ($L === '' || $L === 'autodetect' || $L === 'text' || $L === 'plaintext' || !class_exists('GeSHi'))
            return $esc($text);
        if ($geshiPath === null) {
            $p = __DIR__ . '/includes/geshi';
            $geshiPath = is_dir($p) ? $p : null;
        }
        if ($geshi === null || $geshiLang !== $L) {
            $geshi = new GeSHi('', $L, $geshiPath);
            $geshiLang = $L;
            if (defined('GESHI_HEADER_NONE')) $geshi->set_header_type(GESHI_HEADER_NONE);
            if (method_exists($geshi, 'enable_classes'))       $geshi->enable_classes(true);
            if (method_exists($geshi, 'enable_keyword_links')) $geshi->enable_keyword_links(false);
        }
        $geshi->set_source($text);
        try { return '<span class="geshi">'.$geshi->parse_code().'</span>'; }
        catch (Throwable $e) { return $esc($text); }
    }
    return $esc($text);
}

/* =========================================================
 * Change counts for badges
 * =======================================================*/
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

        // immediately-adjacent opposite run
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

/* =========================================================
 * Page inputs & bootstrap
 * =======================================================*/

$left  = '';
$right = '';
$leftLabel  = 'Old';
$rightLabel = 'New';
$leftLangFromDB  = null;
$rightLangFromDB = null;

// Minimal site/bootstrap so header/footer look right
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

    // ads
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

if ($lid) { $p = load_paste($pdo, $lid); if ($p){ $left=$p['content'];  $leftLabel='Paste #'.$lid;  $leftLangFromDB  = (string)$p['code']; } }
if ($rid) { $p = load_paste($pdo, $rid); if ($p){ $right=$p['content']; $rightLabel='Paste #'.$rid; $rightLangFromDB = (string)$p['code']; } }

/* ---------- POST inputs (compare/swap; download keeps buffers) ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action     = (string)($_POST['action'] ?? 'compare');

    $left       = (string)($_POST['left_text']  ?? $left);
    $right      = (string)($_POST['right_text'] ?? $right);
    $leftLabel  = trim((string)($_POST['left_label']  ?? $leftLabel))  ?: $leftLabel;
    $rightLabel = trim((string)($_POST['right_label'] ?? $rightLabel)) ?: $rightLabel;

    if ($action === 'swap') {
        [$left, $right] = [$right, $left];
        [$leftLabel, $rightLabel] = [$rightLabel, $leftLabel];
    }

    // Persist buffers (+ language picks) to session for GET toggles
    $_SESSION['diff_buffers'] = [
        'left'        => $left,
        'right'       => $right,
        'left_label'  => $leftLabel,
        'right_label' => $rightLabel,
        'time'        => time(),
    ];
    if (isset($_POST['lang']))       $_SESSION['diff_lang_single'] = (string)$_POST['lang'];
    if (isset($_POST['left_lang']))  $_SESSION['diff_lang_left']   = (string)$_POST['left_lang'];
    if (isset($_POST['right_lang'])) $_SESSION['diff_lang_right']  = (string)$_POST['right_lang'];
}

/* ---------- If no POST and no paste IDs, load last buffers from session ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !$lid && !$rid && !empty($_SESSION['diff_buffers'])) {
    $buf = $_SESSION['diff_buffers'];
    $left       = (string)($buf['left']        ?? $left);
    $right      = (string)($buf['right']       ?? $right);
    $leftLabel  = (string)($buf['left_label']  ?? $leftLabel);
    $rightLabel = (string)($buf['right_label'] ?? $rightLabel);
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

/* ---------- Picked languages (DB-seeded, request override, GeSHi resolve) ---------- */
$req_single = $_POST['lang']       ?? $_GET['lang']       ?? ($_SESSION['diff_lang_single'] ?? null);
$req_left   = $_POST['left_lang']  ?? $_GET['left_lang']  ?? ($_SESSION['diff_lang_left']   ?? null);
$req_right  = $_POST['right_lang'] ?? $_GET['right_lang'] ?? ($_SESSION['diff_lang_right']  ?? null);

$normalize = static function($id) use ($engine, $alias_map): string {
    $id = strtolower(trim((string)$id));
    if ($id === '' || $id === 'auto' || $id === 'autodetect') return 'autodetect';
    $id = $alias_map[$id] ?? $id;
    if (function_exists('paste_normalize_lang')) {
        $id = paste_normalize_lang($id, $engine === 'highlight' ? 'highlight' : 'geshi', null) ?: $id;
    }
    return $id;
};

$seedL = $normalize($leftLangFromDB  ?? '');
$seedR = $normalize($rightLangFromDB ?? '');

// Decide effective picks
if ($req_left !== null || $req_right !== null) {
    // Dual dropdowns present > respect each, fallback to DB seeds, then autodetect
    $lang_left  = $normalize($req_left  ?? $seedL  ?: 'autodetect');
    $lang_right = $normalize($req_right ?? $seedR ?: 'autodetect');
} elseif ($req_single !== null) {
    // Single dropdown > both sides use the same
    $picked = $normalize($req_single);
    $lang_left = $lang_right = $picked;
} else {
    // No request > seed from DB (prefer a concrete shared value)
    if ($seedL !== 'autodetect' && $seedL !== '' && $seedL === $seedR) {
        $lang_left = $lang_right = $seedL;
    } elseif ($seedL !== 'autodetect' && $seedL !== '') {
        $lang_left = $lang_right = $seedL;
    } elseif ($seedR !== 'autodetect' && $seedR !== '') {
        $lang_left = $lang_right = $seedR;
    } else {
        $lang_left = $lang_right = 'autodetect';
    }
}

/* For GeSHi, resolve 'autodetect' > concrete id so it actually highlights */
if ($engine === 'geshi' && function_exists('paste_autodetect_language')) {
    $resolver = static function(string $text) {
        $det = paste_autodetect_language($text, 'geshi', null);
        return !empty($det['id']) ? strtolower((string)$det['id']) : 'text';
    };
    if ($lang_left === 'autodetect' || $lang_left === 'text' || $lang_left === 'plaintext') {
        $lang_left = $resolver($left !== '' ? $left : $right);
    }
    if ($lang_right === 'autodetect' || $lang_right === 'text' || $lang_right === 'plaintext') {
        $lang_right = $resolver($right !== '' ? $right : $left);
    }
}

/* Labels for the toolbar */
$lang_left_label  = $language_map[$lang_left]  ?? ucfirst($lang_left  ?: 'Autodetect');
$lang_right_label = $language_map[$lang_right] ?? ucfirst($lang_right ?: 'Autodetect');

/* highlight.php default style (header reads $hl_style) */
if ($engine === 'highlight') {
    $hl_style = $hl_style ?? 'hybrid.css';
}

/* ---------- View options ---------- */
$view_mode = ($_GET['view'] ?? 'side') === 'unified' ? 'unified' : 'side';
$wrap      = isset($_GET['wrap'])      ? (int)$_GET['wrap']      : 0;
$lineno    = isset($_GET['lineno'])    ? (int)$_GET['lineno']    : 1;

/* ---------- Ignore trailing whitespace (toggle via ?ignore_ws=1) ---------- */
$ignore_ws = isset($_GET['ignore_ws']) ? (int)$_GET['ignore_ws'] : 0;
$normalizer = $ignore_ws ? static fn($s) => rtrim($s, " \t") : null;

/* ---------- Only-changes + context (server toggle + cookie persistence) ---------- */
$only_changes = (int)($_GET['only']    ?? ($_COOKIE['diff_only']    ?? 0));
$context_opt  =       ($_GET['context'] ?? ($_COOKIE['diff_context'] ?? ($only_changes ? '3' : 'all')));
$context_opt  = is_string($context_opt) ? strtolower($context_opt) : 'all';
if (isset($_GET['only'])) {
    setcookie('diff_only', (string)$only_changes, [
        'expires'  => time() + 60*60*24*30,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}
if (isset($_GET['context'])) {
    setcookie('diff_context', (string)$context_opt, [
        'expires'  => time() + 60*60*24*30,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

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

/* ---------- Download (.diff or .patch) ---------- */
$dl = $_GET['download'] ?? null;
if ($dl !== null) {
    // names from labels; sanitize for safety
    $safe  = static fn($s) => preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$s) ?: 'file.txt';
    $fileA = $safe($leftLabel  ?: 'Old');
    $fileB = $safe($rightLabel ?: 'New');

    $engineHdr = (function_exists('xdiff_string_diff') && !$normalizer) ? 'xdiff' : 'myers';

    // Clean headers
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('X-Diff-Engine: ' . $engineHdr);
    header('X-Diff-Ignore-WS: ' . ($ignore_ws ? '1' : '0'));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    if ($dl === 'patch') {
        $patch = git_patch_download($left, $right, $fileA, $fileB, 3, $normalizer);
        header('Content-Type: text/x-patch; charset=utf-8');
        header('Content-Disposition: attachment; filename="change.patch"');
        echo $patch;
        exit;
    }

    // default: unified diff;
    $ud = unified_diff_download($left, $right, $fileA, $fileB, 3, $normalizer);
    header('Content-Type: text/x-diff; charset=utf-8');
    header('Content-Disposition: attachment; filename="paste.diff"');
    echo $ud;
    exit;
}

// Git-style patch wrapper around unified diff
function git_patch_download(
    string $left,
    string $right,
    string $nameA='left.txt',
    string $nameB='right.txt',
    int $ctx=3,
    ?callable $normalizer=null
): string {
    // Build a standard unified diff body first
    $ud = unified_diff_download($left, $right, $nameA, $nameB, $ctx, $normalizer);

    // Rewrite top headers to "a/" and "b/" like git expects
    $ud = preg_replace('~^--- [^\n]*\n\+\+\+ [^\n]*\n~', "--- a/{$nameA}\n+++ b/{$nameB}\n", $ud, 1);

    // Prepend the "diff --git" header
    $hdr = "diff --git a/{$nameA} b/{$nameB}\n";
    return $hdr . $ud;
}

/* ---------- Compute opcodes ---------- */
$leftLines  = preg_split("/\R/u", $left);
$rightLines = preg_split("/\R/u", $right);
if ($leftLines === false)  $leftLines  = [$left];
if ($rightLines === false) $rightLines = [$right];

$ops = diff_lines_idx($leftLines, $rightLines, $normalizer);

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
$GLOBALS['diff_changes_mod']    = $mods;
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
    apply_inline_unified($uniRows);
}

/* ---------- Server-side "Only changes" filter with context ---------- */
function filter_rows_with_context(array $rows, callable $isChange, ?int $ctx): array {
    if ($ctx === null) return $rows; // 'all'
    $n = count($rows);
    if ($n === 0) return $rows;

    // Collect change indexes
    $chg = [];
    for ($i=0; $i<$n; $i++) if ($isChange($rows[$i])) $chg[] = $i;
    if (!$chg) return []; // no changes

    // Build merged keep ranges
    $ranges = [];
    $start = max(0, $chg[0] - $ctx);
    $end   = min($n-1, $chg[0] + $ctx);
    for ($k=1; $k<count($chg); $k++) {
        $cs = max(0, $chg[$k] - $ctx);
        $ce = min($n-1, $chg[$k] + $ctx);
        if ($cs <= $end + 1) {
            // merge
            $end = max($end, $ce);
        } else {
            $ranges[] = [$start, $end];
            $start = $cs; $end = $ce;
        }
    }
    $ranges[] = [$start, $end];

    // Extract
    $out = [];
    foreach ($ranges as [$a,$b]) {
        for ($i=$a; $i<=$b; $i++) $out[] = $rows[$i];
    }
    return $out;
}

if ($only_changes) {
    $ctxNum = null;
    if ($context_opt !== 'all') {
        $ctxNum = max(0, (int)$context_opt);
    }
    if ($ctxNum !== null) {
        // Side-by-side: a row is a change if either side isn't 'ctx'
        $sideRows = filter_rows_with_context(
            $sideRows,
            static function(array $r): bool {
                return (($r['lclass'] ?? 'ctx') !== 'ctx') || (($r['rclass'] ?? 'ctx') !== 'ctx');
            },
            $ctxNum
        );
        // Unified: change if class is add/del
        $uniRows = filter_rows_with_context(
            $uniRows,
            static function(array $r): bool {
                $c = $r['class'] ?? 'ctx'; return ($c === 'add' || $c === 'del');
            },
            $ctxNum
        );
    }
}

/* ---------- Expose engine + toggle info to theme and headers ---------- */
$engine_label = (function_exists('xdiff_string_diff') && !$normalizer) ? 'xdiff' : 'myers';
header('X-Diff-Engine: '.$engine_label);
header('X-Diff-Ignore-WS: '.($ignore_ws ? '1':'0'));

// Convenience strings the theme can show in the toolbar:
$diff_engine_badge = '<span class="badge bg-secondary" title="Diff engine">'.$engine_label.'</span>';
$ignore_ws_on      = (bool)$ignore_ws;

// Build toggle URL for ignore_ws
$qs = $_GET;
$qs['ignore_ws'] = $ignore_ws ? 0 : 1;
$ignore_ws_toggle_url = strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($qs);

/* ---------- Render theme ---------- */
$themeDir = 'theme/' . htmlspecialchars($default_theme ?? 'default', ENT_QUOTES, 'UTF-8');

// expose split pct and toolbar data to the view
$GLOBALS['split_pct']           = $split_pct;
$GLOBALS['diff_engine_badge']   = $diff_engine_badge;
$GLOBALS['ignore_ws_on']        = $ignore_ws_on;
$GLOBALS['ignore_ws_toggle']    = $ignore_ws_toggle_url;

// also expose language picks/labels for the dropdowns
$GLOBALS['lang_left']           = $lang_left;
$GLOBALS['lang_right']          = $lang_right;
$GLOBALS['lang_left_label']     = $lang_left_label;
$GLOBALS['lang_right_label']    = $lang_right_label;
// maps used by theme to build selects
$GLOBALS['language_map']        = $language_map;
$GLOBALS['popular_langs']       = $popular_langs;

// Expose buffers and labels for theme inputs
$GLOBALS['left']        = $left;
$GLOBALS['right']       = $right;
$GLOBALS['leftLabel']   = $leftLabel;
$GLOBALS['rightLabel']  = $rightLabel;
$GLOBALS['view_mode']   = $view_mode;
$GLOBALS['wrap']        = $wrap;
$GLOBALS['lineno']      = $lineno;
$GLOBALS['sideRows']    = $sideRows;
$GLOBALS['uniRows']     = $uniRows;

// Expose Only/Context to the theme
$GLOBALS['only_changes'] = (int)$only_changes;
$GLOBALS['context']      = (string)$context_opt;

require_once $themeDir . '/header.php';
require_once $themeDir . '/diff.php';
require_once $themeDir . '/footer.php';
