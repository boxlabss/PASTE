<?php
/*
 * Paste https://github.com/boxlabss/PASTE
 *
 * Language autodetection for pastes, shared by the highlight.php and GeSHi
 * paths. Hard hints come first (PHP tags, title extension, shebang, editor
 * modeline), then Markdown, then each language is scored on its own
 * patterns. A language is only chosen when it clearly beats every language
 * outside its family; otherwise the paste is shown as plain text.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

declare(strict_types=1);

/** Interpreter names (shebangs, modelines) -> highlight ids. */
function paste_synonyms_map(): array {
    return [
        // shells
        'sh'=>'bash','bash'=>'bash','zsh'=>'bash','ksh'=>'bash','mksh'=>'bash','dash'=>'bash','ash'=>'bash','csh'=>'bash','tcsh'=>'bash','busybox'=>'bash','fish'=>'bash','sed'=>'bash',
        // node/js/ts
        'node'=>'javascript','nodejs'=>'javascript','deno'=>'javascript','bun'=>'javascript','js'=>'javascript',
        'ts'=>'typescript','tsx'=>'typescript','ts-node'=>'typescript','ts-node-script'=>'typescript',
        // python
        'python'=>'python','python2'=>'python','python3'=>'python','pypy'=>'python','pypy3'=>'python','py'=>'python',
        // perl/raku
        'perl'=>'perl','perl5'=>'perl','perl6'=>'perl','raku'=>'perl','cperl'=>'perl','rakudo'=>'perl',
        // ruby and friends
        'ruby'=>'ruby','jruby'=>'ruby','truffleruby'=>'ruby','macruby'=>'ruby','rbx'=>'ruby','crystal'=>'crystal',
        // jvm
        'groovy'=>'groovy','groovysh'=>'groovy','kscript'=>'kotlin','kotlin'=>'kotlin','scala'=>'scala','java'=>'java',
        // c family scripts
        'tcc'=>'c','rust-script'=>'rust','swift'=>'swift','go'=>'go','gorun'=>'go','dart'=>'dart','v'=>'go',
        // R, lua, php
        'rscript'=>'r','r'=>'r','lua'=>'lua','luajit'=>'lua','php'=>'php','php-cgi'=>'php',
        // functional and lisps
        'runghc'=>'haskell','runhaskell'=>'haskell','stack'=>'haskell','guile'=>'scheme','racket'=>'scheme','csi'=>'scheme',
        'chicken'=>'scheme','gosh'=>'scheme','clisp'=>'lisp','sbcl'=>'lisp','ecl'=>'lisp','emacs'=>'lisp','clojure'=>'clojure',
        'bb'=>'clojure','escript'=>'erlang','elixir'=>'elixir','iex'=>'elixir','ocaml'=>'ocaml','dotnet'=>'fsharp',
        // sci and others
        'octave'=>'matlab','julia'=>'julia','nim'=>'nimrod','nimscript'=>'nimrod','coffee'=>'coffeescript','rdmd'=>'d',
        'tcl'=>'tcl','tclsh'=>'tcl','wish'=>'tcl','expect'=>'tcl','eggdrop'=>'tcl',
        'awk'=>'awk','gawk'=>'awk','mawk'=>'awk','nawk'=>'awk',
        'pwsh'=>'powershell','powershell'=>'powershell','powershell.exe'=>'powershell',
        'qmlscene'=>'qml','qmlcachegen'=>'qml','make'=>'makefile','osascript'=>'applescript',
        // editor mode names
        'emacs-lisp'=>'lisp','elisp'=>'lisp','lisp-interaction'=>'lisp','c++'=>'cpp','shell-script'=>'bash','js2'=>'javascript',
        'cperl-mode'=>'perl','sh-mode'=>'bash','conf'=>'ini','gitconfig'=>'ini','dosini'=>'ini','vim'=>'vim','text'=>'plaintext',
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
        return $id0;
    }

    // GeSHi mapping
    static $HL_TO_GESHI = [
        'plaintext'=>'text', 'text'=>'text', 'xml'=>'xml', 'html'=>'html5', 'bash'=>'bash', 'shell'=>'bash',
        'dos'=>'dos', 'javascript'=>'javascript', 'typescript'=>'javascript', 'json'=>'javascript', 'yaml'=>'yaml',
        'ini'=>'ini', 'toml'=>'ini', 'properties'=>'properties', 'php'=>'php', 'python'=>'python', 'ruby'=>'ruby',
        'crystal'=>'ruby', 'perl'=>'perl', 'java'=>'java', 'c'=>'c', 'cpp'=>'cpp', 'csharp'=>'csharp', 'cs'=>'csharp',
        'go'=>'go', 'rust'=>'rust', 'kotlin'=>'kotlin', 'pgsql'=>'postgresql', 'sql'=>'sql', 'scss'=>'css', 'less'=>'css',
        'markdown'=>'markdown', 'powershell'=>'powershell', 'vbnet'=>'vbnet', 'vbscript'=>'vbscript', 'basic'=>'freebasic',
        'objectivec'=>'objc', 'ocaml'=>'ocaml', 'fsharp'=>'fsharp', 'haskell'=>'haskell', 'elm'=>'haskell', 'lua'=>'lua',
        'matlab'=>'matlab', 'makefile'=>'make', 'nginx'=>'nginx', 'apache'=>'apache', 'dockerfile'=>'bash',
        'vbscript-html'=>'vbscript-html', 'vhdl'=>'vhdl', 'verilog'=>'verilog', 'x86asm'=>'asm', 'armasm'=>'arm', 'mipsasm'=>'mips', 'riscv'=>'riscv',
        'r'=>'rsplus', 'tex'=>'latex', 'mirc'=>'mirc', 'hex'=>'hex', 'qml'=>'qml', 'tcl'=>'tcl', 'delphi'=>'delphi',
        'nimrod'=>'nimrod', 'mathematica'=>'mathematica', 'cmake'=>'cmake',
    ];
    if (isset($HL_TO_GESHI[$id0])) return $HL_TO_GESHI[$id0];

    // Any id with a GeSHi language file is usable as is
    if (preg_match('/^[a-z0-9_-]+$/', $id0) && is_file(__DIR__ . '/geshi/' . $id0 . '.php')) return $id0;
    return 'text';
}

/** Shebang on the first line; returns a highlight id for a known interpreter, or null. */
function paste_detect_shebang(string $s): ?string {
    if (!preg_match('/\A\s*#!\s*([^\r\n]+)/', $s, $m)) return null;
    $line = strtolower(trim($m[1]));
    if (preg_match('/\benv(?:\s+-\S+)*\s+([^\s-][^\s]*)/', $line, $em)) {
        $prog = basename($em[1]);
    } else {
        $prog = basename((string) (preg_split('/\s+/', $line)[0] ?? ''));
    }
    $prog = (string) preg_replace('/\.exe$/', '', $prog);
    $syn = paste_synonyms_map();
    if (isset($syn[$prog])) return $syn[$prog];
    $prog = (string) preg_replace('/[\d.]+$/', '', $prog);
    return $syn[$prog] ?? null;
}

/** Whether a single trimmed line reads as code rather than prose. */
function paste_line_looks_like_code(string $t): bool {
    $t = trim((string) preg_replace('/`[^`\r\n]*`/', '', $t));
    if ($t === '') return false;

    // Comment markers, brackets and line endings typical of code
    if (preg_match('~^(?://|/\*|\*/|--[ \t]|;)|[{};\\\\\[(]$|^[)\]}]+[;,]?$|^\[[\w.\- ]+\]$~', $t)) return true;

    // Variables, operators, assignments and calls
    if (preg_match('~\$[A-Za-z_{(]|[ \t](?:=|==|!=|===|:=|\+=|-=|=>|->|<-|&&|\|\|)[ \t]|^[A-Za-z_][\w.]*=\S|^[A-Za-z_][\w.:]*\(~', $t)) return true;

    // Shell command lines: background jobs, pipes, redirects, home paths, options after a command
    if (preg_match('%[ \t]&[ \t]*$|[ \t]\|[ \t]*[a-z]|[ \t][12]?>[>&]?[ \t]*[~/\w$]|(?:^|[ \t])~/|^[a-z][\w.+-]*[ \t]+-{1,2}[A-Za-z]%', $t)) return true;

    // Block keywords that end or open code
    if (preg_match('~^(?:fi|done|esac|end|else|then|do|endif|endfor|endwhile)$|;[ \t]*(?:then|do)$~', $t)) return true;
    if (preg_match('~^(?:proc|def|func|fn|function|sub)[ \t]+[\w:.]+[ \t]*[({]|^(?:if|elif|elseif|while|for|foreach|switch|catch)[ \t]*[\[({]~', $t)) return true;
    if (preg_match('~^(?:set|local|my|our|var|let|const|val|export|global|variable|unset|upvar)[ \t]+-?[\w$@%:]+[ \t]*(?:=|[\[$"\d{-]|$)~', $t)) return true;

    // Dockerfile instructions and YAML-style keys with short values
    if (preg_match('~^(?:FROM|RUN|CMD|COPY|ADD|WORKDIR|EXPOSE|ENV|ARG|ENTRYPOINT|LABEL|USER|VOLUME)[ \t]+\S~', $t)) return true;
    if (preg_match('~^[a-z_][\w.-]*:(?:[ \t]+(?:"[^"]*"|\'[^\']*\'|\S+))?$~', $t)) return true;

    // Statement keywords, unless followed by ordinary English
    return (bool) preg_match('~^(?:package|namespace|import|from|use|require|include|using|bind|alias|puts|echo|print|printf|return|source|class|module|struct|enum|interface|public|private|protected|static|server|location|listen|on)[ \t]+(?!(?:the|a|an|this|that|these|those|to|of|in|on|for|and|or|is|are|it|its|your|my|our|you|we|with|all|any|some)\b)\S~', $t);
}

/**
 * Markdown evidence for a paste.
 * Returns ['strong' => fences or tables, 'score' => structural score,
 *          'code_ratio' => share of unfenced, unindented lines that read as code, 'yaml_like' => bool].
 */
function paste_markdown_assess(string $s): array {
    if (strlen($s) > 65536) $s = substr($s, 0, 65536);
    $lines = preg_split('/\R/', $s) ?: [];

    $fences = 0; $inFence = false; $body = [];
    foreach ($lines as $line) {
        if (preg_match('/^[ \t]{0,3}(?:```|~~~)/', $line)) { $fences++; $inFence = !$inFence; continue; }
        if (!$inFence) $body[] = $line;
    }
    $table = preg_match('/^[ \t]*\|.+\|[ \t]*$/m', $s)
          && preg_match('/^[ \t]*\|?[ \t]*:?-{3,}:?[ \t]*(?:\|[ \t]*:?-{3,}:?[ \t]*)+\|?[ \t]*$/m', $s);

    // Runs of one or two "# Title" lines count as headings; longer runs and "#word" lines are comments
    $headings = 0; $code = 0; $considered = 0; $prose = 0; $run = [];
    $flush = static function () use (&$run, &$headings, &$code, &$considered): void {
        if (!$run) return;
        $atx = 0;
        foreach ($run as $t) $atx += (int) preg_match('/^#{1,6}[ \t]+\S/', $t);
        if (count($run) <= 2 && $atx === count($run)) {
            $headings += $atx;
        } else {
            $code++; $considered++;
        }
        $run = [];
    };
    foreach ($body as $line) {
        $t = trim($line);
        if ($t === '') { $flush(); continue; }
        if ($t[0] === '#') { $run[] = $t; continue; }
        $flush();
        if (preg_match('/^(?: {4}|\t)/', $line)) continue;
        if (preg_match('/^(?:[-*+][ \t]|\d+[.)][ \t]|>|\|)/', $t)) continue;
        $considered++;
        if (paste_line_looks_like_code($t)) {
            $code++;
        } elseif (preg_match_all('/\b[A-Za-z][a-z\']+\b/', $t) >= 4) {
            $prose++;
        }
    }
    $flush();

    $text  = implode("\n", $body);
    $score = 0.0;
    $headingScore = $headings ? 2 + min($headings - 1, 3) : 0;
    $score += $headingScore;
    if (preg_match('/^[^\s#>|=*+-][^\r\n]*\r?\n[ \t]{0,3}(?:={3,}|-{3,})[ \t]*$/m', $text)) $score += 2;
    if ((preg_match_all('/^[ \t]*[-*+][ \t]+\S/m', $text) ?: 0) >= 2) $score += 1;
    if ((preg_match_all('/^[ \t]*\d+[.)][ \t]+\S/m', $text) ?: 0) >= 2) $score += 1;
    if (preg_match('/^[ \t]*>[ \t]*\S/m', $text)) $score += 1;
    $spans = preg_match_all('/`[^`\r\n]+`/', $text) ?: 0;
    if ($spans) $score += $spans >= 3 ? 2 : 1;
    if (preg_match('/(?<![\w*])\*\*[^*\r\n]+\*\*(?![\w*])/', $text)) $score += 1;
    if (preg_match('/(?<![\w\])$])!?\[[^\]\r\n]+\]\([^)\s]+(?:[ \t]+"[^"\r\n]*")?\)/', $text)) $score += 2;

    return [
        'strong'     => ($fences >= 2) || $table,
        'score'      => $score,
        'code_ratio' => $considered ? $code / $considered : 0.0,
        'prose'      => $prose,
        'heading_score' => $headingScore,
        'yaml_like'  => (preg_match_all('/^\s*[A-Za-z0-9_.-]+\s*:\s+[^#\r\n]+$/m', $text) ?: 0) >= 3,
    ];
}

/** Markdown verdict from paste_markdown_assess(); $codeScore is the best language signature score. */
function paste_markdown_verdict(array $md, float $codeScore = 0.0): bool {
    if ($md['strong']) return $md['code_ratio'] < 0.5;
    // Headings alone need some prose under them, so commented config files are not Markdown
    if ($md['score'] <= $md['heading_score'] && $md['prose'] === 0) return false;
    return $md['score'] >= 2 && $md['code_ratio'] < 0.3 && !$md['yaml_like'] && $codeScore < 12;
}

/** Markdown sniff that ignores "# comment" lines in code. */
function paste_probable_markdown(string $s): bool {
    $scores = paste_score_languages($s)['scores'];
    return paste_markdown_verdict(paste_markdown_assess($s), (float) (reset($scores) ?: 0));
}

/** IRC or chat log sniff: most lines start with a timestamp and a nick or event marker. */
function paste_probable_chatlog(string $s): bool {
    if (strlen($s) > 65536) $s = substr($s, 0, 65536);
    $n = 0; $hit = 0;
    foreach (preg_split('/\R/', $s) ?: [] as $line) {
        if (trim($line) === '') continue;
        $n++;
        $ts = '(?:\[?\d{1,2}:\d{2}(?::\d{2})?\]?|\[\d{4}-\d{2}-\d{2}[ T][\d:.]+\])[ \t]+';
        if (preg_match('/^[ \t]*' . $ts . '(?:<[@+%&~ ]?[^\s>]{1,32}>|\*{1,3}[ \t]+\S|-!-|[-=<>]{2,3}[ \t]+\S)/', $line)
            || (preg_match('/^<[@+%&~]?[\w\[\]\\\\`^{}|-]{1,32}>[ \t]+\S/', $line) && strpos($line, '</') === false)) {
            $hit++;
        }
    }
    return $n >= 2 && $hit / $n >= 0.6;
}

/** HexDroid (.hex) script sniff: on/alias blocks whose HexDroid-only signals outweigh mIRC-only ones. */
function paste_probable_hex(string $s): bool {
    if (!preg_match('/^[ \t]*(?:on|alias)[ \t]+\S/mi', $s)) return false;

    // "on EVENT {" or "on EVENT:filter {": no mIRC level, which mIRC requires.
    $native = preg_match_all('/^[ \t]*on[ \t]+[A-Za-z]+(?::[^\r\n{]*+)?\s*\{/mi', $s) ?: 0;

    $hex_re = '/\$(?:list|map|has|keys|values|tojson|setting|re_match|re_find|re_group|re_replace|shuffle|pick|concat|clamp|idiv|padleft|capitalize)\('
            . '|\$(?:httpok|httpbody|httpstatus|httplocation|httperror|isme|isprivate|iscmd|mediaok|mediatoken)\b'
            . '|\$(?:age|screen)\.\w+'
            . '|(?:^|[{|])[ \t]*(?:http\.get|http\.post|media\.pick|media\.upload|age\.\w+|setat|rewrite|foreach[ \t]+%|view[ \t]*\{)/mi';
    $mirc_re = '/\$(?:chr|asc|iif|gettok|numtok|addtok|deltok|istok|wildtok|findtok|matchtok|puttok|instok|reptok|remtok|sorttok|hget|hfind|readini|regml|regsubex|mid|str|remove|duration|asctime|fulldate)\('
             . '|\$\+|\$\$\d'
             . '|\bvar[ \t]+%|\b(?:hadd|hmake|hfree|hdel|writeini|sockopen|sockwrite|sockread|sockclose)\b'
             . '|^[ \t]*(?:raw|ctcp)[ \t]+[^\s:]+:|^[ \t]*(?:dialog|menu)[ \t]+[^\r\n{]*\{/mi';

    $kinds = static function (string $re, string $s): int {
        if (!preg_match_all($re, $s, $m)) return 0;
        return count(array_unique(array_map(static fn($t) => strtolower(trim($t, " \t{|")), $m[0])));
    };

    $hex  = $native * 2 + $kinds($hex_re, $s);
    $mirc = $kinds($mirc_re, $s);
    return $hex >= 2 && $hex > $mirc;
}

/** Eggdrop Tcl sniff: binds or eggdrop-only commands. */
function paste_probable_eggdrop(string $s): bool {
    return (bool) preg_match('/^[ \t]*bind[ \t]+(?:pubm?|msgm?|join|part|sign|nick|mode|ctcp|raw|dcc|time|cron|evnt|kick|topc|notc)[ \t]+\S+[ \t]+\S+|\b(?:putserv|puthelp|putquick|putlog|utimer|setudef|matchattr)\b/m', $s);
}

/** Friendly label if none is provided. */
function paste_friendly_label(string $id): string {
    $t = str_replace(['-', '_'], ' ', strtolower($id));
    $t = ucwords($t);
    $t = preg_replace('/\bSql\b/i','SQL',$t);
    $t = preg_replace('/\bJson\b/i','JSON',$t);
    $t = preg_replace('/\bYaml\b/i','YAML',$t);
    $t = preg_replace('/\bXml\b/i','XML',$t);
    $t = preg_replace_callback('/\b(?:Toml|Ini|Css|Php|Html)\b/', static fn($m) => strtoupper($m[0]), $t);
    return $t;
}

/** Display label for a language id: the configured format list, then highlight.php names, then a tidy version of the id. */
function paste_lang_label(string $id): string {
    static $names = ['csharp' => 'C#', 'c' => 'C', 'html' => 'HTML', 'plaintext' => 'Plain Text', 'text' => 'Plain Text',
                     'tclegg' => 'TCL (Eggdrop)', 'objc' => 'Objective-C', 'rsplus' => 'R', 'freebasic' => 'FreeBASIC'];
    $map = $GLOBALS['geshiformats'] ?? null;
    if (is_array($map) && isset($map[$id])) return (string) $map[$id];
    if (isset($names[$id])) return $names[$id];
    if (function_exists('highlight_label_overrides')) {
        $over = highlight_label_overrides();
        if (isset($over[$id])) return $over[$id];
    }
    return paste_friendly_label($id);
}

/** Modeline detection (vim/emacs). Returns a highlight id the site can highlight, or null. */
function paste_detect_modeline(string $s): ?string {
    $id = null;
    // Vim: "vim: set ft=python", "vi: set filetype=javascript", "vim:ft=sh"
    if (preg_match('/\b(?:vi|vim):.*?\b(?:ft|filetype)\s*=\s*([a-z0-9._+-]+)/i', $s, $m)) {
        $id = strtolower($m[1]);
    // Emacs: "-*- mode: ruby -*-" or "-*- ruby -*-"
    } elseif (preg_match('/-\*-\s*(?:mode:\s*)?([a-z0-9._+-]+)\s*;?[^\r\n]*?-\*-/i', $s, $m)) {
        $id = strtolower($m[1]);
    }
    if ($id === null) return null;
    $syn = paste_synonyms_map();
    $id = $syn[$id] ?? $id;
    $known = is_file(__DIR__ . '/Highlight/languages/' . $id . '.json') || isset(paste_language_signatures()[$id]);
    return $known && !isset(paste_unhighlighted_languages()[$id]) ? $id : null;
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
        'html'=>'html','htm'=>'html','xhtml'=>'xml','xml'=>'xml','svg'=>'xml',
        'js'=>'javascript','mjs'=>'javascript','cjs'=>'javascript',
        'ts'=>'typescript','tsx'=>'typescript','jsx'=>'javascript',
        'json'=>'json','ndjson'=>'json',
        'yml'=>'yaml','yaml'=>'yaml',
        'ini'=>'ini','cfg'=>'ini','conf'=>'ini','properties'=>'properties','toml'=>'toml',
        'md'=>'markdown','markdown'=>'markdown','mkd'=>'markdown',
        'txt'=>'plaintext','log'=>'plaintext',
        'sh'=>'bash','bash'=>'bash','zsh'=>'bash','ksh'=>'bash','bat'=>'dos','cmd'=>'dos','ps1'=>'powershell',
        'py'=>'python','rb'=>'ruby','pl'=>'perl','raku'=>'perl','pm'=>'perl','t'=>'perl',
        'java'=>'java','c'=>'c','h'=>'c','cpp'=>'cpp','cc'=>'cpp','cxx'=>'cpp','hpp'=>'cpp','hh'=>'cpp','hxx'=>'cpp',
        'cs'=>'csharp','go'=>'go','rs'=>'rust','swift'=>'swift','kt'=>'kotlin','kts'=>'kotlin','scala'=>'scala',
        'sql'=>'sql','pgsql'=>'pgsql','psql'=>'pgsql',
        'makefile'=>'makefile','mk'=>'makefile',
        'psh'=>'powershell',
        'lua'=>'lua','tcl'=>'tcl','mrc'=>'mirc','qml'=>'qml','r'=>'r','vim'=>'vim','awk'=>'awk',
        'css'=>'css','scss'=>'scss','less'=>'less','diff'=>'diff','patch'=>'diff','dockerfile'=>'dockerfile',
        'tex'=>'tex','bib'=>'bibtex',
    ];
    return $MAP[$ext] ?? null;
}

/** Unified diff or patch sniff: hunk headers with file headers or a git diff line. */
function paste_probable_diff(string $s): bool {
    if (strlen($s) > 65536) $s = substr($s, 0, 65536);
    if (!preg_match('/^@@[ \t]+-\d+(?:,\d+)?[ \t]+\+\d+(?:,\d+)?[ \t]+@@/m', $s)) return false;
    return (bool) preg_match('/^(?:diff[ \t]+--git[ \t]|---[ \t]+\S[^\r\n]*\r?\n\+\+\+[ \t]+\S)/m', $s);
}

/** Fast PHP tag check to hard-lock PHP when present (diffs of PHP files excluded). */
function paste_detect_php_tag(string $s): bool {
    return preg_match('/<\?(php|=)/i', $s) && !paste_probable_diff($s);
}

/** Structural measurements used for JSON and the structured-data fallback. */
function paste_feature_extract(string $code): array {
    $len   = max(1, strlen($code));
    $lines = max(1, count(preg_split('/\R/', $code) ?: []));

    // For very large code (>500KB), sample to cap regex time
    if ($len > 500000) {
        $code = substr($code, 0, 250000) . substr($code, -250000);
        $len = strlen($code);
    }

    $json_like = false;
    $trim = ltrim($code);
    if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[') && $len < 2000000) {
        $tmp = json_decode($code, true);
        $json_like = json_last_error() === JSON_ERROR_NONE && is_array($tmp);
    }

    return [
        'lines'           => $lines,
        'brace_ratio'     => (preg_match_all('/[{}]/', $code) ?: 0) / $len,
        'semicolon_ratio' => substr_count($code, ';') / $lines,
        'tag_density'     => (preg_match_all('/<\s*\/?\s*[A-Za-z!?][^>\n]*>/', $code) ?: 0) / $lines,
        'has_preproc'     => (bool) preg_match('/^\s*#\s*(include|define|if|ifdef|ifndef|endif|pragma)\b/m', $code),
        'yaml_key_lines'  => preg_match_all('/^\s*[A-Za-z0-9_.-]+\s*:\s+[^#\r\n]+$/m', $code) ?: 0,
        'ini_headers'     => preg_match_all('/^\s*\[[^\]]+\]\s*$/m', $code) ?: 0,
        'json_like'       => $json_like,
    ];
}

/** Plain-English summary of the winning signatures. */
function paste_explain_scores(string $best, array $ranked): string {
    $hits = $ranked['hits'][$best] ?? [];
    arsort($hits);
    $why = implode(', ', array_slice(array_keys($hits), 0, 4));
    $scores = $ranked['scores'];
    $msg = sprintf('Matched %s patterns: %s (score %d).', paste_lang_label($best), $why, (int) round($scores[$best] ?? 0));
    $rest = array_slice($scores, 1, 1, true);
    if ($rest) {
        $next = (string) array_key_first($rest);
        $msg .= sprintf(' Next closest: %s (%d).', paste_lang_label($next), (int) round($rest[$next]));
    }
    return $msg;
}

/**
 * Weighted signatures per language (highlight.php ids): [pattern, weight, label].
 * The first match scores the full weight and up to four more score half each.
 * Negative weights count against a language. Ids without a highlighter (see
 * paste_unhighlighted_languages) still compete, so look-alikes are not mislabelled.
 */
function paste_language_signatures(): array {
    static $sigs = null;
    if ($sigs !== null) return $sigs;

    $sigs = [
        'tcl' => [
            ['/^[ \t]*proc[ \t]+[\w:]+[ \t]+\{/m', 5, 'proc definitions'],
            ['/^[ \t]*bind[ \t]+(?:pubm?|msgm?|join|part|sign|nick|mode|ctcp|ctcr|raw|dcc|time|cron|evnt|kick|topc|notc|chon|chof|act|bot|link|disc|away|need|filt|out|log|flud|wall|chjn|chpt|splt|rejn|nkch|lost|tout|chat|note|load|unld)[ \t]+\S+[ \t]+\S+/m', 5, 'eggdrop binds'],
            ['/\b(?:putserv|puthelp|putquick|putnow|putlog|putdcc|putcmdlog|utimer|killutimer|killtimer|setudef|matchattr|botisop|isop|isvoice|onchan|chanlist|validuser|nick2hand|getchanhost|unixtime)\b/', 3, 'eggdrop commands'],
            ['/^[ \t]*package[ \t]+(?:require|provide)[ \t]+\w/m', 5, 'package require'],
            ['/^[ \t]*namespace[ \t]+(?:eval|import|export|current|path)\b/m', 4, 'namespace eval'],
            ['/\[(?:lindex|llength|lrange|lsearch|lsort|lassign|lreplace|lmap|string[ \t]+(?:trim\w*|tolower|toupper|length|match|map|range|first|last|equal|is|index|repeat|compare|reverse)|expr|clock[ \t]+\w+|regexp|regsub|split|join|info[ \t]+\w+|dict[ \t]+\w+|format|scan|catch|list|array[ \t]+\w+|file[ \t]+\w+|open|gets|subst|channel[ \t]+get|incr)\b/', 3, 'bracketed commands'],
            ['/^[ \t]*(?:if|while|foreach|for|switch)[ \t]+\{/m', 3, 'braced conditions'],
            ['/^[ \t]*set[ \t]+[\w:]+(?:\([^)\r\n]*\))?[ \t]+(?:\[|"|\{|\$|-?\d)/m', 2, 'set'],
            ['/^[ \t]*puts[ \t]+(?:-nonewline[ \t]+)?"[^"\r\n]*\$\w/m', 3, 'puts "$var"'],
            ['/^[ \t]*(?:lappend|incr|append|unset|upvar|uplevel|global|variable|lassign|dict[ \t]+(?:set|for|update|with))[ \t]+\S/m', 2, 'core commands'],
            ['/^[ \t]*puts[ \t]+(?:-nonewline[ \t]+)?(?:"|\[|\$|\{)/m', 1.5, 'puts'],
            ['/\{\*\}|\bapply[ \t]+\{|\binfo[ \t]+(?:exists|commands|procs)\b/', 4, 'tcl idioms'],
            ['/\}[ \t]*(?:else|elseif)[ \t]*\{/', 1, 'else blocks'],
            ['/;[ \t]*$/m', -0.5, ''],
        ],
        'bash' => [
            ['/^[ \t]*(?:if|elif|while|until)[ \t]+\[\[?[ \t]/m', 4, 'test brackets'],
            ['/^[ \t]*(?:fi|done|esac)[ \t]*(?:[;|&>].*)?$/m', 4, 'fi/done/esac'],
            ['/;[ \t]*(?:then|do)[ \t]*$|^[ \t]*(?:then|do)[ \t]*$/m', 3, 'then/do'],
            ['/\$\{[\w#!@*]+(?:\[[^\]\r\n]*\])?(?::?[-=+?]|##?|%%?|\/\/?|:\d)/', 3, 'parameter expansion'],
            ['/\$\([ \t]*[a-z]|\$\(\(/', 2, 'command substitution'],
            ['/^[ \t]*(?:export|readonly|declare|local|typeset)[ \t]+(?:-\w+[ \t]+)?\w+(?:=|[ \t]*$)/m', 2, 'export/local'],
            ['/^[ \t]*set[ \t]+-[euxvo]+/m', 4, 'set -e'],
            ['/^[ \t]*(?:sudo[ \t]+)?(?:echo|printf|cd|rm|mkdir|cp|mv|chmod|chown|apt(?:-get)?|yum|dnf|pacman|grep|sed|awk|curl|wget|tar|rsync|systemctl|source|exit|cat|ls|ln|touch|kill|git|docker|npm|pip3?|read|shift|trap)\b(?:[ \t]|$)/m', 1.5, 'shell commands'],
            ['/\|\|[ \t]*(?:exit|return)\b|>[ \t]*\/dev\/null|[12]?>&[12]|<<-?[ \t]*[\'"]?\w+/', 2, 'redirections'],
            ['/^[ \t]*(?:function[ \t]+)?[\w-]+[ \t]*\(\)[ \t]*\{|^[ \t]*function[ \t]+[\w-]+[ \t]*\{/m', 2.5, 'function definitions'],
            ['/^[ \t]*case[ \t]+\S+[ \t]+in[ \t]*$/m', 4, 'case/in'],
            ['/^[ \t]*[A-Za-z_]\w*=(?:"|\'|\$|[\w\/.~-])/m', 1.5, 'assignments'],
            ['/"\$[\w{@]|\$[@#?$!](?![\w{])|\$\{#?\w+\[@\]\}/', 2, 'special variables'],
            ['/\|[ \t]*(?:grep|sed|awk|sort|uniq|cut|tr|wc|head|tail|xargs|tee)\b/', 2, 'pipelines'],
            ['/^[ \t]*sudo[ \t]+\S/m', 3, 'sudo'],
            ['/^[ \t]*export[ \t]+[A-Z_][A-Z0-9_]*=|\$\{?(?:HOME|PATH|PWD|USER|SHELL|OLDPWD|HOSTNAME)\b/m', 3, 'environment'],
            ['/\[\[?[ \t]+(?:!?[ \t]*)?-[nzfdeorwxsL][ \t]|\[\[?[ \t]+"?\$\w+"?[ \t]+-(?:eq|ne|lt|gt|le|ge)[ \t]/', 3, 'test operators'],
            ['/\S[ \t]+&&[ \t]+(?:cd|sudo|make|git|npm|cp|mv|rm|mkdir|echo|\.\/)\b/', 2, '&& chains'],
        ],
        'python' => [
            ['/^[ \t]*(?:async[ \t]+)?def[ \t]+\w+[ \t]*\(.*\)[ \t]*(?:->[^:\r\n]+)?:[ \t]*(?:#.*)?$/m', 5, 'def'],
            ['/^[ \t]*class[ \t]+\w+(?:\([^)\r\n]*\))?:[ \t]*$/m', 4, 'class'],
            ['/^[ \t]*(?:import[ \t]+[\w.]+(?:[ \t]+as[ \t]+\w+)?(?:[ \t]*,[ \t]*[\w.]+)*|from[ \t]+[\w.]+[ \t]+import[ \t]+[^\r\n;]+)[ \t]*$/m', 3, 'imports'],
            ['/^[ \t]*(?:elif[ \t]+[^\r\n]+|else|try|finally|except(?:[ \t]+[^\r\n]+)?)[ \t]*:[ \t]*$/m', 3, 'block keywords'],
            ['/^[ \t]*(?:if|for|while|with)[ \t]+[^\r\n{;]+:[ \t]*$/m', 2, 'colon blocks'],
            ['/if[ \t]+__name__[ \t]*==[ \t]*[\'"]__main__[\'"]/', 6, '__main__ guard'],
            ['/\bself\.\w+/', 1.5, 'self'],
            ['/\b(?:True|False|None)\b/', 1, 'True/False/None'],
            ['/"""|\'\'\'/', 2, 'docstrings'],
            ['/\blambda\b[^:\r\n]*:/', 2, 'lambda'],
            ['/\bprint\(|\blen\(|\brange\(|\.append\(|\bf"[^"\r\n]*\{|\bf\'[^\'\r\n]*\{/', 1, 'builtins'],
            ['/\[[^\]\r\n]+[ \t]for[ \t]+\w+(?:[ \t]*,[ \t]*\w+)*[ \t]+in[ \t][^\]\r\n]+\]/', 4, 'comprehensions'],
            ['/^>>>[ \t]/m', 4, 'REPL prompts'],
            ['/^Traceback \(most recent call last\):/m', 6, 'traceback'],
            ['/^[ \t]+File "[^"\r\n]+", line \d+/m', 3, 'traceback frames'],
            ['/[;{][ \t]*$/m', -1, ''],
        ],
        'perl' => [
            ['/^[ \t]*use[ \t]+(?:strict|warnings|utf8|constant|lib|parent|base|POSIX|feature|v5[\d.]*|5\.\d+|[A-Z]\w*::[\w:]+)\b[^;\r\n]*;/m', 5, 'use pragmas'],
            ['/^[ \t]*(?:my|our|local)[ \t]+[\$@%]\w+|\bmy[ \t]*\([ \t]*[\$@%]/m', 4, 'my variables'],
            ['/^[ \t]*sub[ \t]+\w+[ \t]*\{/m', 4, 'sub'],
            ['/[=!]~[ \t]*(?:m|s|tr|y)?[\/{#|!]/', 3, 'regex binding'],
            ['/\$_\b|@_\b|@ARGV\b|%ENV\b|\$\w+\{|\$\w+->[{\[]|\$#\w+/', 1.5, 'perl variables'],
            ['/<STDIN>|<\$\w+>|\bchomp\b|\bdie[ \t]+["\']|\bqw[ \t]*[(\/{]/', 3, 'perl builtins'],
            ['/\bforeach[ \t]+my\b|\b(?:keys|values|each)[ \t]+%|\bprint[ \t]+(?:join|map|grep|scalar)\b/', 4, 'hash/loop idioms'],
            ['/\bsay[ \t]+["\'$]/', 2, 'say'],
            ['/^__(?:END|DATA)__\s*$/m', 4, '__END__'],
            ['/^[ \t]*package[ \t]+[A-Z]\w*(?:::\w+)*[ \t]*;/m', 3, 'package'],
        ],
        'ruby' => [
            ['/^[ \t]*require(?:_relative)?[ \t]+[\'"][\w\/.-]+[\'"][ \t]*$/m', 3, 'require'],
            ['/^[ \t]*def[ \t]+(?:self\.)?[\w?!]+[ \t]*(?:\([^)\r\n]*\))?[ \t]*$/m', 4, 'def'],
            ['/^[ \t]*(?:class|module)[ \t]+[A-Z][\w:]*(?:[ \t]*<[ \t]*[A-Z][\w:]*)?[ \t]*$/m', 3, 'class/module'],
            ['/^[ \t]*end[ \t]*$/m', 1.5, 'end'],
            ['/\battr_(?:reader|writer|accessor)\b|^[ \t]*(?:include|extend)[ \t]+[A-Z]\w+[ \t]*$/m', 5, 'attr/mixins'],
            ['/\b(?:do|\{)[ \t]*\|[\w, *&]+\|/', 4, 'block params'],
            ['/\.(?:each|map|select|reject|times|each_with_index|inject|upto|downto|each_slice|each_char)\b/', 1.5, 'enumerables'],
            ['/@\w+[ \t]*(?:=|\|\|=)[^=]|@@\w+/', 2, 'instance variables'],
            ['/^[ \t]*#[ \t]*frozen_string_literal:/m', 6, 'magic comment'],
            ['/\b[A-Z]\w*\.new\b|\bputs[ \t]+[\'"]|:\w+[ \t]*=>/', 1, 'ruby idioms'],
            ['/^[ \t]*(?:puts|p|pp|print)[ \t]+(?:[\w"\'\[(:]|$)/m', 1.5, 'puts'],
            ['/^[ \t]*set[ \t]+\w+[ \t]+\S|^[ \t]*puts[ \t]+"[^"#\r\n]*\$\w/m', -2, ''],
            ['/#\{[^}\r\n]+\}/', 1.5, 'interpolation'],
            ['/\.\w+[?!](?=[ \t(.\r\n]|$)/m', 2, 'predicate methods'],
            ['/\blambda[ \t]*\{|->[ \t]*\(?[\w, ]*\)?[ \t]*\{|\bproc[ \t]*\{/', 3, 'lambdas'],
            ['/\bunless\b|\belsif\b/', 1, 'unless/elsif'],
            ['/^[ \t]*[a-z_]\w*[ \t]+:[a-z_]\w*(?:[ \t]*,|[ \t]+do\b|[ \t]*=>|[ \t]*$)|^[ \t]*(?:task|desc|gem|brew|cask|source)[ \t]+["\':]/m', 2, 'DSL calls'],
            ['/\bgets\.(?:chomp|to_i|strip|split)\b|\$std(?:out|in)\b|\bSTDIN\.\w/', 3, 'gets/stdout'],
        ],
        'php' => [
            ['/\$this->\w+/', 4, '$this->'],
            ['/\$\w+[ \t]*->[ \t]*\w+/', 1.5, 'object access'],
            ['/\bfunction[ \t]+\w+[ \t]*\([^)\r\n]*\$\w+|\bfn[ \t]*\([^)\r\n]*\$\w*/', 4, 'functions with $params'],
            ['/\$_(?:GET|POST|SERVER|SESSION|COOKIE|REQUEST|FILES|ENV)\b/', 5, 'superglobals'],
            ['/\b(?:echo|isset|empty|array_\w+|str_\w+|preg_\w+|mb_\w+|htmlspecialchars|json_encode|json_decode|implode|explode|in_array|is_array|strlen|count|var_dump|print_r)[ \t]*\(/', 2, 'php builtins'],
            ['/^[ \t]*(?:namespace[ \t]+[\w\\\\]+;|use[ \t]+[A-Z]\w*(?:\\\\\w+)+(?:[ \t]+as[ \t]+\w+)?;)/m', 4, 'namespace/use'],
            ['/\$\w+[ \t]*(?:\.=|=)[ \t]*[^=]|::class\b|\barray[ \t]*\(/', 1.5, 'variables'],
            ['/\b(?:public|private|protected)[ \t]+(?:static[ \t]+)?function\b/', 5, 'methods'],
            ['/\bforeach[ \t]*\([ \t]*\$\w+(?:\[[^\]]*\])?[ \t]+as[ \t]+\$/', 6, 'foreach ... as'],
            ['/\b(?:echo|print)[ \t]+[\$"\'(][^\r\n]*;[ \t]*$|\$\w+\[\][ \t]*=|["\'][ \t]*\.[ \t]*\$\w+|\$\w+[ \t]*\.[ \t]*["\']/m', 2, 'echo/concat'],
            ['/\$[a-z_]\w*[^\r\n]*;[ \t]*$/m', 1.5, '$variables in statements'],
            ['/\bmy[ \t]+[\$@%]|\buse[ \t]+strict\b|\bset[ \t]+\w+[ \t]/', -3, ''],
        ],
        'javascript' => [
            ['/^[ \t]*(?:export[ \t]+)?(?:const|let|var)[ \t]+[\w$]+[ \t]*=/m', 1.5, 'const/let'],
            ['/^[ \t]*(?:export[ \t]+)?(?:const|let)[ \t]+[{\[][^}\]\r\n]*[}\]][ \t]*=/m', 3, 'destructuring'],
            ['/\)[ \t]*=>[ \t]*[{(\w]|\b\w+[ \t]*=>[ \t]*\{/', 2, 'arrow functions'],
            ['/\b(?:document|window|navigator|localStorage)\.\w+|\bconsole\.(?:log|error|warn|info)\(|\bJSON\.(?:parse|stringify)\(|\.addEventListener\(|\.querySelector(?:All)?\(|\bgetElementById\(/', 4, 'DOM/console'],
            ['/\bfunction[ \t]*[\w$]*[ \t]*\([^)$\r\n]*\)[ \t]*\{/', 2, 'function'],
            ['/\brequire\([\'"][^\'"\r\n]+[\'"]\)|\bmodule\.exports\b|^[ \t]*export[ \t]+(?:default|const|function|class|async)\b|^[ \t]*import[ \t]+[^\r\n]+[ \t]+from[ \t]+[\'"]/m', 3, 'modules'],
            ['/===|!==/', 1.5, 'strict equality'],
            ['/\bnew[ \t]+Promise\b|\.then\(|\bawait[ \t]+\w|\basync[ \t]+(?:function|\()/', 1.5, 'async'],
            ['/\b(?:undefined|typeof|instanceof)\b|\bnew[ \t]+(?:Array|Map|Set|Date|RegExp|Error)\(/', 1.5, 'js keywords'],
            ['/\.(?:forEach|reduce|filter|map|push|slice|splice|indexOf|toFixed|toString|join)\(/', 1, 'array methods'],
            ['/\balert\(|\bdocument\.write(?:ln)?\(|\bMath\.(?:floor|random|max|min|round|sqrt|pow)\(/', 2, 'browser/Math'],
        ],
        'typescript' => [
            ['/^[ \t]*(?:export[ \t]+)?(?:interface|type)[ \t]+\w+(?:[ \t]*<[^>\r\n]+>)?[ \t]*(?:=[^=]|\{|extends)/m', 3, 'interface/type'],
            ['/[\w)?][ \t]*:[ \t]*(?:(?:string|number|boolean|any|void|unknown|never)(?:\[\])?[ \t]*[,)=;{|]|(?:Promise|Record|Array|ReadonlyArray)<)/', 3, 'type annotations'],
            ['/\b(?:public|private|protected|readonly)[ \t]+\w+[ \t]*[:?]|\bas[ \t]+(?:string|number|const|any)\b|\benum[ \t]+\w+[ \t]*\{/', 2, 'ts modifiers'],
        ],
        'java' => [
            ['/^[ \t]*package[ \t]+[a-z]\w*(?:\.\w+)+[ \t]*;/m', 5, 'package'],
            ['/^[ \t]*import[ \t]+(?:static[ \t]+)?[a-z]\w*(?:\.\w+)+(?:\.\*)?[ \t]*;/m', 3, 'imports'],
            ['/\bSystem\.(?:out|err)\.print(?:ln|f)?\(/', 5, 'System.out'],
            ['/\b(?:public|private|protected)[ \t]+(?:(?:static|final|abstract|sealed)[ \t]+)*(?:class|interface|enum|record)[ \t]+[A-Z]/', 3, 'class declarations'],
            ['/\b(?:public|private|protected)[ \t]+(?:(?:static|final)[ \t]+)*(?:void|int|long|boolean|double|String|List<[^>\r\n]*>|Map<[^>\r\n]*>|[A-Z]\w*)[ \t]+\w+[ \t]*[(=;]/', 2, 'members'],
            ['/@(?:Override|FunctionalInterface|Autowired|Test|SuppressWarnings)\b/', 3, 'annotations'],
            ['/\bString\[\][ \t]+\w+|\bnew[ \t]+(?:ArrayList|HashMap|HashSet|LinkedList|StringBuilder|Scanner)\b|\bInteger\.parseInt\(/', 3, 'java types'],
            ['/\b(?:extends|implements)[ \t]+[A-Z]\w*/', 1, 'extends/implements'],
            ['/\b(?:public|private|protected)[ \t]+static[ \t]+(?:final[ \t]+)?(?:[A-Z]\w*|int|long|double|boolean|void|char)(?:<[^>\r\n]*>)?(?:\[\])*[ \t]+\w+[ \t]*\(/', 3, 'static methods'],
            ['/\bboolean\b|\.length\(\)|\.equals\(|\.toCharArray\(\)|\bString\.valueOf\(|\bnew[ \t]+(?:int|long|char|double|String)\[/', 2, 'java builtins'],
        ],
        'kotlin' => [
            ['/^[ \t]*(?:(?:private|public|internal|protected|override|suspend|inline|open|operator|infix|tailrec)[ \t]+)*fun[ \t]+(?:<[^>\r\n]+>[ \t]*)?[\w.`]+[ \t]*\(/m', 5, 'fun'],
            ['/^[ \t]*(?:(?:private|public|internal|protected|override|const|lateinit)[ \t]+)*val[ \t]+\w+/m', 3, 'val'],
            ['/^[ \t]*package[ \t]+[a-z]\w*(?:\.\w+)+[ \t]*$/m', 3, 'package'],
            ['/^[ \t]*(?:(?:data|sealed|open|abstract|enum|inner|private|internal)[ \t]+)*class[ \t]+\w+(?:<[^>\r\n]*>)?[ \t]*(?:\(|:[ \t]*[A-Z])/m', 3, 'class headers'],
            ['/\bwhen[ \t]*(?:\([^)\r\n]*\))?[ \t]*\{/', 3, 'when'],
            ['/[ \t]\?:[ \t]|!!\.|\b\w+\?\.\w+/', 2, 'null safety'],
            ['/\b(?:listOf|mutableListOf|mapOf|setOf|arrayOf|intArrayOf|repeat)\(|\bcompanion[ \t]+object\b|\bobject[ \t]+\w+[ \t]*[{:]|\.(?:forEach|joinToString|toInt|toList)\b/', 3, 'kotlin stdlib'],
            ['/\bfor[ \t]*\([ \t]*\w+[ \t]+in[ \t]+[^)\r\n]+\)|\b\d+[ \t]*\.\.[ \t]*\w+|\buntil\b|\bdownTo\b/', 2, 'ranges'],
            ['/\bdef[ \t]+\w+|\bfunc[ \t]+\w+/', -3, ''],
        ],
        'csharp' => [
            ['/^[ \t]*using[ \t]+(?:static[ \t]+)?[A-Z][\w.]*[ \t]*;/m', 5, 'using'],
            ['/^[ \t]*namespace[ \t]+[A-Z][\w.]*[ \t]*[{;]?[ \t]*$/m', 3, 'namespace'],
            ['/\bConsole\.(?:Write|WriteLine|ReadLine|ReadKey)\(/', 5, 'Console'],
            ['/\{[ \t]*(?:get|set|init);[ \t]*(?:(?:set|init);[ \t]*)?\}/', 5, 'properties'],
            ['/\bvar[ \t]+\w+[ \t]*=[ \t]*new\b|\bstring\[\][ \t]+\w+|\bstatic[ \t]+(?:async[ \t]+)?(?:void|Task)[ \t]+Main\b/', 3, 'c# idioms'],
            ['/\$@?"[^"\r\n]*\{/', 2, 'interpolated strings'],
            ['/\b(?:public|private|internal|protected)[ \t]+(?:(?:static|async|override|virtual|readonly)[ \t]+)*(?:void|string|int|bool|Task(?:<[^>\r\n]*>)?)[ \t]+\w+/', 1.5, 'members'],
            ['/\bforeach[ \t]*\([ \t]*var\b|\.(?:Select|Where|Aggregate|ToList|ToArray|Count)\(|\bIEnumerable<|\bList<\w+>[ \t]+\w+[ \t]*=/', 3, 'LINQ/collections'],
        ],
        'c' => [
            ['~^[ \t]*#[ \t]*include[ \t]*<(?:stdio|stdlib|string|unistd|stdint|stdbool|stddef|errno|fcntl|signal|time|math|ctype|assert|limits|locale|wchar|sys/\w+|netinet/\w+|arpa/\w+)\.h>~m', 5, 'C headers'],
            ['/^[ \t]*#[ \t]*(?:include|define|ifn?def|endif|pragma|if|elif|undef)\b/m', 2.5, 'preprocessor'],
            ['/\b(?:printf|fprintf|sprintf|snprintf|puts|malloc|calloc|realloc|free|memcpy|memset|strcpy|strlen|strcmp|fopen|fclose|scanf)[ \t]*\(/', 2, 'libc calls'],
            ['/\bint[ \t]+main[ \t]*\(/', 3, 'main'],
            ['/\btypedef[ \t]+(?:struct|enum|union)\b|\bstruct[ \t]+\w+[ \t]*\{|\bunsigned[ \t]+(?:int|char|long)\b|\bsizeof[ \t]*\(/', 2, 'c types'],
            ['/^[ \t]*(?:static[ \t]+)?(?:void|int|char|double|float|long|unsigned|size_t|bool)[ \t*]+\w+[ \t]*\([^)\r\n;]*\)[ \t]*\{?[ \t]*$/m', 2.5, 'function definitions'],
            ['/\w->\w|\bNULL\b/', 1, 'pointers'],
            ['/^[ \t]*#[ \t]*(?:ifndef|define)[ \t]+\w+_H(?:_|PP)?_?\b|^[ \t]*(?:extern[ \t]+)?(?:const[ \t]+)?(?:void|int|char|unsigned|size_t|bool|struct[ \t]+\w+|[a-z_]+_t)[ \t*]+\w+[ \t]*\([^)\r\n]*\)[ \t]*;/m', 2.5, 'headers/prototypes'],
        ],
        'cpp' => [
            ['/^[ \t]*#[ \t]*include[ \t]*<(?:iostream|vector|string|map|set|memory|algorithm|unordered_map|unordered_set|functional|sstream|fstream|thread|mutex|cstdio|cstdlib|cstring|cmath|array|utility|optional|numeric|iterator|list|deque|queue|stack|bitset|chrono|random|iomanip|tuple)>/m', 5, 'C++ headers'],
            ['/^[ \t]*#[ \t]*(?:include|define|ifn?def|endif|pragma)\b/m', 2.5, 'preprocessor'],
            ['/\bstd::\w+|\b(?:cout|cin|cerr|endl)\b/', 4, 'std::'],
            ['/\btemplate[ \t]*<|\btypename\b|\bnullptr\b|\bconstexpr\b/', 4, 'templates'],
            ['/^[ \t]*namespace[ \t]+[a-z_]\w*[ \t]*\{|\busing[ \t]+namespace\b/m', 3, 'namespace'],
            ['/\bclass[ \t]+\w+[ \t]*(?::[ \t]*(?:public|private|protected)[ \t]+\w+)?[ \t]*\{|^[ \t]*(?:public|private|protected):/m', 2, 'classes'],
            ['/\bauto[ \t]*&{0,2}[ \t]+\w+[ \t]*[:=]|\bconst[ \t]+auto\b|\bconst[ \t]+\w+(?:<[^>\r\n]*>)?[ \t]*&/', 2, 'auto/references'],
            ['/\bint[ \t]+main[ \t]*\(/', 2, 'main'],
        ],
        'objectivec' => [
            ['/^[ \t]*#[ \t]*import[ \t]+[<"]/m', 4, '#import'],
            ['/^[ \t]*@(?:interface|implementation|end|property|synthesize|protocol|autoreleasepool)\b/m', 6, '@interface/@end'],
            ['/\bNS(?:String|Log|Array|Dictionary|Object|Number|Integer|UInteger|MutableArray|MutableString)\b|@"/', 5, 'Foundation'],
            ['/\[\[\w+[ \t]+alloc\][ \t]+init|\[[A-Za-z_]\w*[ \t]+[a-z]\w*:[^\]\r\n]*\][ \t]*;|\[self[ \t]+[a-z]\w*[\]:]/', 4, 'message sends'],
            ['/^[ \t]*[-+][ \t]*\([\w *]+\)[ \t]*\w+/m', 4, 'method declarations'],
        ],
        'go' => [
            ['/^package[ \t]+\w+[ \t]*$/m', 2, 'package'],
            ['/^[ \t]*func[ \t]+(?:\([^)\r\n]*\)[ \t]*)?\w+[ \t]*\(/m', 4, 'func'],
            ['/^[ \t]*func[ \t]+\w+[ \t]*\([^)\r\n]*\w:[ \t]/m', -4, ''],
            ['~^import[ \t]+(?:\(|"[\w./-]+")~m', 4, 'import'],
            ['/\bfmt\.(?:Print|Sprint|Fprint|Errorf|Scan)\w*\(|\bhttp\.(?:HandleFunc|ListenAndServe)\(|\bstrings\.\w+\(|\bstrconv\.\w+\(/', 5, 'fmt/strings'],
            ['/\berr[ \t]*!=[ \t]*nil\b/', 4, 'err != nil'],
            ['/:=/', 1.5, ':='],
            ['/\bgo[ \t]+func\b|\bdefer[ \t]+\w|\bchan[ \t]+\w|\bmake\((?:\[\]|map|chan)|\[\][a-z]\w*\{|\bmap\[\w+\]\w+/', 3, 'goroutines/slices'],
            ['/\bfor[ \t]+\w+(?:[ \t]*,[ \t]*\w+)?[ \t]*:=[ \t]*range\b/', 5, 'range loops'],
        ],
        'rust' => [
            ['/^[ \t]*(?:pub(?:\([^)\r\n]*\))?[ \t]+)?(?:async[ \t]+)?fn[ \t]+\w+[ \t]*(?:<[^>\r\n]*>)?[ \t]*\(/m', 4, 'fn'],
            ['/\blet[ \t]+mut\b/', 4, 'let mut'],
            ['/^[ \t]*use[ \t]+(?:std|crate|super|self|core|[a-z_]+)::/m', 4, 'use paths'],
            ['/\b(?:println|print|eprintln|format|vec|panic|assert(?:_eq|_ne)?|write|writeln|macro_rules)!/', 5, 'macros'],
            ['/\bimpl(?:[ \t]*<[^>\r\n]*>)?[ \t]+\w+|\btrait[ \t]+\w+|#\[derive\(/', 4, 'impl/trait'],
            ['/&mut[ \t]|&str\b|\bOption<|\bResult<|\bSome\(|\bOk\(|\bErr\(|\.unwrap\(\)|::new\(\)|\bu(?:8|16|32|64|size)\b|\bi(?:32|64)\b/', 3, 'rust types'],
            ['/@import\(|\bconst[ \t]+std[ \t]*=/', -6, ''],
        ],
        'swift' => [
            ['/^[ \t]*import[ \t]+(?:Foundation|UIKit|SwiftUI|Combine|AppKit|XCTest|Darwin|Cocoa)[ \t]*$/m', 5, 'imports'],
            ['/\bfunc[ \t]+\w+[ \t]*(?:<[^>\r\n]*>)?\(/', 2.5, 'func'],
            ['/\bfunc[ \t]+\w+[ \t]*(?:<[^>\r\n]*>)?\([^)\r\n]*\w:[ \t]*[A-Z\[(]/', 3, 'labelled params'],
            ['/\)[ \t]*(?:async[ \t]+)?(?:throws[ \t]+)?->[ \t]*[\w\[\]?<>.()]+[ \t]*\{/', 2, 'return arrows'],
            ['/\bguard[ \t]+(?:let|var)\b|\bif[ \t]+let\b|\btry\?|\bas\?|\bas![ \t]/', 4, 'guard/if let'],
            ['/\b(?:let|var)[ \t]+\w+[ \t]*:[ \t]*[A-Z\[(][\w<>\[\]:? ,()\->]*[ \t]*(?:=|$|\{)/m', 2, 'typed bindings'],
            ['/@(?:IBOutlet|IBAction|State|Published|Binding|ObservedObject|MainActor|objc|escaping|discardableResult)\b|\bstruct[ \t]+\w+[ \t]*:[ \t]*\w+|\\\\\([\w.]+\)/', 4, 'swift idioms'],
            ['/\{[ \t]*\(?[\w$]+(?:[ \t]*,[ \t]*[\w$]+)*\)?[ \t]+in\b|\$0\b/', 4, 'closures'],
            ['/\bfor[ \t]+\w+[ \t]+in[ \t]+[\w.(]+[ \t]*(?:\.\.\.|\.\.<)|\bstride\(from:/', 4, 'ranges'],
            ['/\bcase[ \t]+\.\w+|\bprint\(|\bvar[ \t]+\w+[ \t]*=[ \t]*\[/', 1, 'swift basics'],
            ['/\bfun[ \t]+\w+|\bval[ \t]+\w+/', -3, ''],
        ],
        'lua' => [
            ['/^[ \t]*local[ \t]+(?:function[ \t]+[\w.:]+[ \t]*\(|[\w, ]+[ \t]*=[ \t])/m', 4, 'local'],
            ['/^[ \t]*(?:local[ \t]+)?function[ \t]+[\w.:]+[ \t]*\([^)$\r\n]*\)[ \t]*$/m', 4, 'function'],
            ['/\bfunction[ \t]*[\w.:]*\([^)$\r\n]*\)[^\r\n{]*\bend\b|(?:[(,=]|\b(?:return|then|do|and|or))[ \t]*#[a-z_]\w*\b(?![\w(.:-])/', 3, 'inline function/length'],
            ['/^[ \t]*(?:if|elseif)[ \t]+[^;\r\n]+[ \t]then\b/m', 2, 'if/then'],
            ['/~=|(?<!\.)\.\.(?!\.)[ \t]*[\'"\w(]/', 1.5, 'operators'],
            ['/\b(?:ipairs|pairs)\(|\btable\.(?:insert|concat|remove|sort|unpack)\(|\bstring\.(?:format|sub|find|gsub|match|rep|byte|char)\(|\bio\.write\(|\bmath\.\w+\(/', 4, 'lua stdlib'],
            ['/^[ \t]*end[ \t]*[),]?[ \t]*$/m', 1, 'end'],
            ['/--\[\[|^[ \t]*--(?!-)/m', 1, 'comments'],
            ['/\bnil\b|\bfor[ \t]+\w+[ \t]*=[ \t]*[^,\r\n]+,[^\r\n]+[ \t]do\b/', 1.5, 'nil/numeric for'],
        ],
        'sql' => [
            ['/\b(?:CREATE[ \t]+(?:OR[ \t]+REPLACE[ \t]+)?(?:TEMPORARY[ \t]+)?(?:TABLE|INDEX|UNIQUE[ \t]+INDEX|VIEW|DATABASE|SCHEMA|PROCEDURE|FUNCTION|TRIGGER|SEQUENCE)|ALTER[ \t]+TABLE|DROP[ \t]+(?:TABLE|INDEX|VIEW|DATABASE))\b/', 5, 'DDL'],
            ['/\b(?:create[ \t]+table|alter[ \t]+table)[ \t]+(?:if[ \t]+not[ \t]+exists[ \t]+)?[`"\w.]+[ \t]*\(/i', 5, 'DDL'],
            ['/\bSELECT\b[^;]{1,400}?\bFROM\b/', 5, 'SELECT ... FROM'],
            ['/\bselect[ \t]+(?:\*|[\w.`"()]+(?:[ \t]*,[ \t]*[\w.`"()]+)*)[ \t]+from[ \t]+[`"\w.]+/i', 3, 'select ... from'],
            ['/\bINSERT[ \t]+INTO[ \t]+[`"\w]|\bUPDATE[ \t]+[`"\w.]+[ \t]+SET\b|\bDELETE[ \t]+FROM[ \t]+[`"\w]/', 5, 'DML'],
            ['/\binsert[ \t]+into[ \t]+[`"\w.]+[ \t]*(?:\(|values\b)|\bdelete[ \t]+from[ \t]+[`"\w.]+[ \t]+where\b|\bupdate[ \t]+[`"\w.]+[ \t]+set[ \t]+[`"\w.]+[ \t]*=/i', 4, 'DML'],
            ['/\b(?:WHERE|GROUP[ \t]+BY|ORDER[ \t]+BY|INNER[ \t]+JOIN|LEFT[ \t]+JOIN|PRIMARY[ \t]+KEY|FOREIGN[ \t]+KEY|NOT[ \t]+NULL|AUTO_INCREMENT|DEFAULT[ \t]+CHARSET|VARCHAR\(\d+\)|INT(?:EGER)?[ \t]+UNSIGNED|UNION[ \t]+ALL|WITH[ \t]+RECURSIVE)\b/', 2, 'SQL keywords'],
            ['/^[ \t]*(?:select|from|where|(?:left|right|inner|outer|cross)[ \t]+join|join|group[ \t]+by|order[ \t]+by|having|limit|union|values)\b(?![ \t]+(?:the|a|an|this|that|my|your|our|it|is|are|was|me|you)\b)[ \t]+\S/mi', 2, 'clause lines'],
            ['/\b(?:DECLARE|BEGIN|END)\b[^;\r\n]*;|\bdbms_output\.put_line\b|\bRAISE[ \t]+NOTICE\b|\bLANGUAGE[ \t]+plpgsql\b|\$\$/i', 2, 'procedural SQL'],
            ['/^[ \t]*--[ \t]/m', 0.5, ''],
        ],
        'yaml' => [
            ['/^[\w.-]+:[ \t]*\r?\n(?:[ \t]*#[^\r\n]*\r?\n)*[ \t]+(?:-[ \t]|[\w.-]+:)/m', 4, 'nested mappings'],
            ['/^[ \t]*[\w.-]+:[ \t]+(?:"[^"\r\n]*"|\'[^\'\r\n]*\'|[^\s#{};,][^\r\n#;{}]*)(?<![,(])$/m', 0.8, 'key: value'],
            ['/^[ \t]*-[ \t]+(?:[\w.-]+:[ \t]|"|\')/m', 1.5, 'list items'],
            ['/^---[ \t]*$|^\.\.\.[ \t]*$/m', 2, 'document markers'],
            ['/^[ \t]*[\w.-]+:[ \t]+[|>][-+]?[ \t]*$/m', 4, 'block scalars'],
            ['/[;{}][ \t]*$/m', -1.5, ''],
        ],
        'toml' => [
            ['/^[ \t]*\[\[[\w.-]+\]\][ \t]*$/m', 5, 'array tables'],
            ['/^[ \t]*\[[\w.-]+\][ \t]*$/m', 1.5, 'tables'],
            ['/^[ \t]*[\w.-]+[ \t]*=[ \t]*(?:"[^"\r\n]*"|\'[^\'\r\n]*\'|\d[\d._:-]*|true|false|\[[^\r\n]*\]|\{[^}\r\n]*=[^}\r\n]*\})[ \t]*(?:#[^\r\n]*)?$/m', 2, 'typed values'],
        ],
        'ini' => [
            ['/^[ \t]*\[[^\]\r\n]+\][ \t]*$/m', 2, 'sections'],
            ['/^[ \t]*[\w.-]+[ \t]*=[ \t]*[^"\'\[{\s(][^\r\n()]*$/m', 1.5, 'unquoted values'],
            ['/^[ \t]*;/m', 2, '; comments'],
            ['/[{};][ \t]*$|^[ \t]*(?:if|for|while|def|function|return)\b/m', -2, ''],
        ],
        'nginx' => [
            ['/^[ \t]*(?:server|location|upstream|http|events|map)\b[^{;\r\n]*\{[ \t]*$/m', 2, 'blocks'],
            ['/^[ \t]*(?:listen|server_name|root|index|try_files|proxy_pass|proxy_set_header|fastcgi_pass|fastcgi_param|fastcgi_index|rewrite|add_header|ssl_certificate(?:_key)?|ssl_protocols|error_page|client_max_body_size|gzip\w*|access_log|error_log|worker_processes|worker_connections|sendfile|keepalive_timeout|expires)[ \t]+[^;\r\n]+;/m', 4, 'directives'],
            ['~^[ \t]*include[ \t]+[\w/.*-]+[ \t]*;~m', 2, 'include'],
            ['/\$(?:uri|args|host|request_uri|remote_addr|document_root|fastcgi_script_name|scheme|http_\w+|query_string)\b/', 3, 'variables'],
            ['/^[ \t]*location[ \t]+(?:[=~^*]+[ \t]+)?\S+[ \t]*\{/m', 4, 'location'],
        ],
        'apache' => [
            ['/<\/?(?:VirtualHost|Directory|DirectoryMatch|IfModule|Files|FilesMatch|Location|LocationMatch|Proxy|Limit)\b[^>\r\n]*>/', 5, 'sections'],
            ['/^[ \t]*(?:RewriteEngine|RewriteRule|RewriteCond|RewriteBase|DocumentRoot|ServerName|ServerAlias|AllowOverride|Require|Options|DirectoryIndex|Header|ErrorDocument|AddType|AddHandler|SetEnv|SetEnvIf|Redirect|RedirectMatch|Order|Deny|Allow|LoadModule|Listen|ErrorLog|CustomLog|ExpiresActive|ExpiresByType)[ \t]+/m', 4, 'directives'],
        ],
        'makefile' => [
            ['~^(?!(?:public|private|protected|default|case|else|label)\b)[\w.%$()/-]+(?:[ \t]+[\w.%$()/-]+)*[ \t]*::?(?!=)(?:[ \t]+[^\s;(|\'][^\r\n;]*)?\r?\n\t[^\s;][^\r\n]*(?<![;{}])$~m', 5, 'rules with tab recipes'],
            ['/\$\([A-Z_][A-Z0-9_]*\)|\$[<^](?!\w)|\$\((?:shell|wildcard|patsubst|addprefix|foreach|call|subst|notdir|basename)[ \t]/', 3, 'make variables'],
            ['/^\.(?:PHONY|DEFAULT|SUFFIXES|SILENT)[ \t]*:/m', 5, '.PHONY'],
            ['/^[A-Z_][A-Z0-9_]*[ \t]*(?::=|\?=|\+=|=)/m', 1, 'variable assignments'],
        ],
        'dockerfile' => [
            ['~^FROM[ \t]+[\w./:@${}-]+(?:[ \t]+AS[ \t]+\w+)?[ \t]*$~mi', 6, 'FROM'],
            ['/^(?:RUN|CMD|ENTRYPOINT|COPY|ADD|WORKDIR|EXPOSE|ENV|ARG|LABEL|USER|VOLUME|HEALTHCHECK|ONBUILD|SHELL|STOPSIGNAL)[ \t]+\S/m', 3, 'instructions'],
        ],
        'powershell' => [
            ['/\b(?:Get|Set|New|Remove|Start|Stop|Restart|Write|Read|Invoke|Test|Select|Where|ForEach|Import|Export|Out|Add|Clear|ConvertTo|ConvertFrom|Format|Measure|Sort|Group|Copy|Move|Rename|Join|Split|Wait|Enable|Disable|Install|Update|Register)-[A-Z]\w+/', 4, 'cmdlets'],
            ['/^[ \t]*param[ \t]*\(/mi', 4, 'param block'],
            ['/[\w)\]][ \t]+-(?:eq|ne|gt|lt|ge|le|like|notlike|match|notmatch|contains|notcontains|in|notin|and|or|not|replace|split|join|is|isnot|band|bor|shl|shr)[ \t]+[$\w(\'"]/i', 1.5, 'operators'],
            ['/\[(?:string|int|bool|switch|array|hashtable|PSCustomObject|datetime|object|double|long|char|byte|decimal|bigint)(?:\[\])?\][ \t]*\$\w+/i', 5, 'typed params'],
            ['/\$(?:_|PSItem|PSScriptRoot|env:\w+|true|false|null|args)\b|\$\w+\.(?:[A-Z]\w*|\w+\()/', 2, 'automatic variables'],
            ['/^[ \t]*function[ \t]+[A-Z]\w*-[A-Z]\w*/m', 4, 'verb-noun functions'],
            ['/@\(|@\{|\[\w+(?:\.\w+)*\]::\w+/', 4, '@() and [Type]::'],
            ['/\|[ \t]*(?:%|\?|foreach|where)[ \t]*\{|\bforeach[ \t]*\([ \t]*\$\w+[ \t]+in[ \t]+/i', 5, 'pipelines'],
            ['/[ \t]-[A-Z][a-z]{2,}(?:[A-Z][a-z]+)*\b/', 1.5, 'parameters'],
        ],
        'dos' => [
            ['/^[ \t]*@?echo[ \t]+(?:off|on)\b/mi', 5, 'echo off'],
            ['/^[ \t]*(?:rem\b|::)/mi', 3, 'rem'],
            ['/%~?\w+%|%%~?\w|!\w+!/', 2.5, '%vars%'],
            ['~^[ \t]*(?:set[ \t]+/[ap]|set[ \t]+"?\w*=|if[ \t]+(?:/i[ \t]+)?(?:not[ \t]+)?(?:exist|defined|errorlevel)\b|goto[ \t]+:?\w|call[ \t]+:\w|setlocal\b|endlocal\b|pause\b|exit[ \t]+/b\b|xcopy[ \t]|for[ \t]+/[flrd]\b|for[ \t]+%%)~mi', 3, 'batch commands'],
            ['/^[ \t]*:\w+[ \t]*$/m', 2, 'labels'],
            ['/^[ \t]*@?echo(?:\.|[ \t])/mi', 1, 'echo'],
        ],
        'css' => [
            ['/^[ \t]*[.#][\w-]+[^{\r\n;]*\{/m', 2, 'class/id selectors'],
            ['/^[ \t]*-?[a-z]+(?:-[a-z]+)*[ \t]*:[ \t]*[^;{}\r\n]+;[ \t]*(?:\/\*.*\*\/)?[ \t]*$/m', 1.5, 'declarations'],
            ['/@(?:media|import|keyframes|font-face|supports|charset)\b/', 4, 'at-rules'],
            ['/^[ \t]*[\w.#:*>+~\[\]="\', -]+\{[ \t]*(?:-?[a-z]+(?:-[a-z]+)*[ \t]*:[^;{}\r\n]+;[ \t]*)+\}/m', 4, 'one-line rules'],
            ['/^[ \t]*(?:color|background(?:-color)?|margin(?:-\w+)?|padding(?:-\w+)?|border(?:-\w+)?|display|width|height|font-(?:size|family|weight)|position|z-index|flex|grid|text-align|line-height|opacity|transition|transform)[ \t]*:[ \t]*[^;\r\n]+;/m', 3, 'properties'],
            ['/\b\d+(?:\.\d+)?(?:px|em|rem|vh|vw|pt)\b|#[0-9a-fA-F]{3,8}\b|\b(?:rgba?|hsla?|var|calc)\(/', 1.5, 'units/colours'],
        ],
        'scss' => [
            ['/^[ \t]*\$[\w-]+[ \t]*:[^;\r\n]+;/m', 5, '$variables'],
            ['/@(?:mixin|include|extend|use|forward|function|each)\b|&(?::[\w-]+|\.[\w-]+|-[\w-]+)/', 4, 'mixins/nesting'],
        ],
        'less' => [
            ['/^[ \t]*@[\w-]+[ \t]*:[^;\r\n]+;/m', 5, '@variables'],
            ['/^[ \t]*\.[\w-]+\([^)\r\n]*\)[ \t]*;|\bwhen[ \t]*\(|~"[^"\r\n]*"/m', 4, 'mixins'],
        ],
        'xml' => [
            ['/<!DOCTYPE[ \t]+html|<html\b|<\?xml\b/i', 6, 'document'],
            ['/<\/(?:html|head|body|div|span|p|a|ul|ol|li|table|tr|td|th|script|style|form|section|header|footer|nav|main|article|button|label|select|option|svg|g|path|video|source|template|strong|em|h[1-6])>/i', 2, 'HTML closing tags'],
            ['/<[a-zA-Z][\w:-]*(?:[ \t]+[\w:-]+(?:=(?:"[^"\r\n]*"|\'[^\'\r\n]*\'))?)*[ \t]*\/?>/', 1, 'tags'],
            ['/<\/[a-zA-Z][\w:.-]*>/', 1, 'closing tags'],
            ['/^[ \t]*<[a-zA-Z][\w:.-]*[ \t>]/m', 1, 'tag lines'],
        ],
        'diff' => [
            ['/^@@[ \t]+-\d+(?:,\d+)?[ \t]+\+\d+(?:,\d+)?[ \t]+@@/m', 6, 'hunks'],
            ['/^(?:---|\+\+\+)[ \t]+\S/m', 2, 'file headers'],
            ['/^diff[ \t]+(?:--git|-[a-z]+)[ \t]/m', 6, 'diff --git'],
            ['/^index[ \t]+[0-9a-f]+\.\.[0-9a-f]+/m', 4, 'index'],
        ],
        'r' => [
            ['/\b[\w.]+[ \t]*<-[ \t]*(?!-)/', 3, '<- assignment'],
            ['/\blibrary\([\w.]+\)|\brequire\([\w.]+\)|\bsuppressPackageStartupMessages\(/', 5, 'library()'],
            ['/[a-zA-Z_)\]]\$[A-Za-z_.]\w*/', 1, '$ columns'],
            ['/\b(?:data\.frame|read\.(?:csv|table)|write\.csv|ggplot|aes|summary|nrow|ncol|paste0?|sapply|lapply|vapply|seq_len|seq_along|is\.na|matrix|rep|cat|invisible|unlist|rev|nchar)\(/', 2, 'R functions'],
            ['/<-[ \t]*function[ \t]*\(/', 5, 'function assignment'],
            ['/%>%|%in%|%%|%\/%|%o%/', 3, 'operators'],
            ['/\bfor[ \t]*\([ \t]*\w+[ \t]+in[ \t]+[^)\r\n]+\)|\b\d+:\d+\b|\bc\(/', 1.5, 'for/in, ranges, c()'],
            ['/\b(?:TRUE|FALSE|NULL|NA|Inf)\b/', 1, 'constants'],
        ],
        'mirc' => [
            ['/^[ \t]*on[ \t]+[^:\r\n]+:[^:\r\n]+:/mi', 5, 'on level:EVENT'],
            ['/\$(?:nick|chan|me|address|site|network|server|iif|hget|gettok|numtok|chr|asc|v1|v2|null|readini|calc|rand|ctime|timestamp)\b/', 2, 'identifiers'],
            ['/^[ \t]*alias[ \t]+[\w.-]+/m', 2, 'alias'],
            ['/\bvar[ \t]+%\w+|%\w+[ \t]*=|\b(?:msg|notice|describe)[ \t]+\$(?:chan|nick)\b|\bhadd[ \t]|\bhmake[ \t]/', 3, 'commands'],
        ],
        'haskell' => [
            ['/^module[ \t]+[A-Z][\w.]*(?:[ \t]*\([^)]*\))?[ \t]+where\b/m', 6, 'module ... where'],
            ['/^import[ \t]+(?:qualified[ \t]+)?[A-Z]\w*(?:\.[A-Z]\w*)+|^import[ \t]+qualified\b/m', 4, 'imports'],
            ['/^[a-z_][\w\']*[ \t]+::[ \t]+[^\r\n]*->/m', 5, 'type signatures'],
            ['/^[a-z_][\w\']*[ \t]+::[ \t]+[A-Z(\[]/m', 2, 'type annotations'],
            ['/\bderiving[ \t]*\(|^(?:data|newtype)[ \t]+[A-Z]\w*[^=\r\n]*=|^(?:instance|class)[ \t]+[^\r\n{;]+[ \t]where[ \t]*$/m', 4, 'data/instance'],
            ['/^main[ \t]*=[ \t]*do\b|\bputStrLn\b|\bmapM_\b|\bfoldr\b|\bfoldl\'?\b|\bzipWith\b/m', 4, 'prelude'],
            ['/\\\\[\w_ ]+[ \t]*->/', 3, 'lambdas'],
            ['/\{-#?|^[ \t]*--[ \t]/m', 1, 'comments'],
            ['/[ \t]<-[ \t]/', 1, '<- binds'],
            ['/\bwhere[ \t]*$/m', 2, 'where'],
        ],
        'elm' => [
            ['/^module[ \t]+[A-Z][\w.]*[ \t]+exposing\b|^import[ \t]+[A-Z][\w.]*(?:[ \t]+as[ \t]+\w+)?[ \t]+exposing\b/m', 6, 'module exposing'],
            ['/\bHtml\.\w+|\bCmd[ \t]+Msg\b|\bSub[ \t]+Msg\b|\bBrowser\.\w+/', 4, 'elm architecture'],
        ],
        'scala' => [
            ['/^[ \t]*(?:case[ \t]+)?object[ \t]+\w+(?:[ \t]+extends[ \t]+\w+(?:[ \t]+with[ \t]+\w+)*)?[ \t]*\{/m', 4, 'object'],
            ['/^[ \t]*(?:override[ \t]+|private[ \t]+|protected[ \t]+|implicit[ \t]+)*def[ \t]+\w+(?:\[[^\]\r\n]*\])?(?:[ \t]*\([^)\r\n]*\))*[ \t]*(?::[ \t]*[^=\r\n]+)?=/m', 5, 'def ... ='],
            ['/\bcase[ \t]+class\b|\bsealed[ \t]+trait\b|^[ \t]*trait[ \t]+\w+|\bimplicit[ \t]|\bcase[ \t]+[^\r\n]*=>|\bmatch[ \t]*\{/m', 4, 'case classes/match'],
            ['/import[ \t]+scala\.|import[ \t]+[\w.]+\._|\b(?:List|Array|Option|Map|Seq|Vector|Set)\[[A-Z]/', 4, 'scala imports/generics'],
            ['/\bval[ \t]+\w+(?:[ \t]*:[ \t]*[\w\[\], ]+)?[ \t]*=/', 1, 'val'],
            ['/\bprintln\(|\.foreach\(|\.mkString\(|\byield\b/', 1.5, 'scala stdlib'],
            ['/\bfun[ \t]+\w+[ \t]*\(/', -3, ''],
        ],
        'elixir' => [
            ['/^[ \t]*defmodule[ \t]+[A-Z][\w.]*[ \t]+do\b/m', 6, 'defmodule'],
            ['/^[ \t]*defp?[ \t]+\w+[?!]?(?:\([^)\r\n]*\))?(?:[ \t]+when[ \t][^\r\n]*)?(?:,[ \t]*do:|[ \t]+do\b)/m', 5, 'def ... do'],
            ['/^[ \t]*\|>/m', 4, 'pipes'],
            ['/\bIO\.(?:puts|inspect|write)\b|\bEnum\.\w+|\bString\.(?:upcase|downcase|split|reverse|graphemes|to_integer|duplicate|pad_leading)\b|\bKernel\.\w+/', 4, 'stdlib'],
            ['/\bfn[ \t]+[^\r\n]*->[^\r\n]*\bend\b|&[A-Z]\w*\.\w+\/\d|&\w+\/\d\b|&\(&\d/', 4, 'fn/captures'],
            ['/@(?:moduledoc|doc|spec|impl)\b|%\{|\bdo:/', 3, 'attributes/maps'],
        ],
        'erlang' => [
            ['/^-(?:module|export|import|include|define|record|spec|type|behaviour|compile)\(/m', 6, 'module attributes'],
            ['/^[a-z]\w*\([^)\r\n]*\)[ \t]*(?:when[ \t][^\r\n]*)?->/m', 4, 'function clauses'],
            ['/\bio:(?:format|fwrite|put_chars)\(|\blists:\w+\(|\bstring:\w+\(/', 5, 'stdlib'],
            ['/\bfun[ \t]*\(|\bend\.[ \t]*$|~[pnsw]/m', 2, 'fun/end'],
        ],
        'clojure' => [
            ['/^\(ns[ \t]+[\w.-]+/m', 6, 'ns'],
            ['/^\(defn-?[ \t]+\S+/m', 5, 'defn'],
            ['/\(let[ \t]+\[|\(fn[ \t]+\[|\(loop[ \t]+\[|\(for[ \t]+\[|\(doseq[ \t]+\[/', 5, 'binding vectors'],
            ['/\(->>?[ \t]|\(println[ \t]|\(def[ \t]+\S|\(map[ \t]|\(reduce[ \t]|:keys\b|\(str[ \t]/', 2, 'core functions'],
        ],
        'lisp' => [
            ['/\(defun[ \t]+\S+[ \t]*\(/', 5, 'defun'],
            ['/\((?:defvar|defparameter|defconstant|setq|setf|defmacro|defstruct|defclass|defmethod)[ \t]/', 4, 'definitions'],
            ['/\(format[ \t]+(?:t|nil)[ \t]|\(loop[ \t]+for\b|\(let\*?[ \t]*\(\(|#\'\w|\(mapcar[ \t]|\(funcall[ \t]/', 4, 'common lisp'],
            ['/\(interactive\b|\(require[ \t]+\'|\(provide[ \t]+\'|\(message[ \t]+"/', 4, 'emacs lisp'],
            ['/^[ \t]*;;/m', 1, 'comments'],
            ['/\(car[ \t]|\(cdr[ \t]|\(cons[ \t]|\(lambda[ \t]*\(/', 1.5, 'list primitives'],
        ],
        'scheme' => [
            ['/^#lang[ \t]+\w+/m', 6, '#lang'],
            ['/\(define[ \t]+\(/', 5, 'define'],
            ['/\(define[ \t]+[^\s()]/', 2, 'define value'],
            ['/\(display[ \t]|\(newline\)|\(let(?:\*|rec)?[ \t]+(?:\w+[ \t]*)?\(\(|\(cond[ \t]*$|\(cond[ \t]*\[|\(else[ \t]/m', 2, 'scheme forms'],
            ['/\(car[ \t]|\(cdr[ \t]|\(cons[ \t]|\(lambda[ \t]*\(|\(null\?/', 1.5, 'list primitives'],
        ],
        'dart' => [
            ['/^import[ \t]+[\'"](?:package|dart):/m', 6, 'imports'],
            ['/@override\b|\blate[ \t]+\w|\brequired[ \t]+this\.|\bWidget\b|\bdynamic\b|\bFuture<|\basync[ \t]*\{/', 4, 'dart idioms'],
            ['/^[ \t]*(?:void|int|String|var|double|bool)?[ \t]*main\(\)[ \t]*(?:async[ \t]*)?\{/m', 3, 'main'],
            ['/\bfinal[ \t]+\w+[ \t]*=|\bvar[ \t]+\w+[ \t]*=/', 1, 'final/var'],
            ['/\'[^\'\r\n]*\$\{?[a-z]/', 3, 'string interpolation'],
            ['/\bprint\(/', 1, 'print'],
        ],
        'groovy' => [
            ['/^[ \t]*def[ \t]+\w+[ \t]*=/m', 3, 'def x ='],
            ['/^[ \t]*println[ \t]+[\'"\w$]/m', 3, 'println without parens'],
            ['/\.each(?:WithIndex)?[ \t]*\{|\.collect[ \t]*\{|\.inject\(|\{[ \t]*\w+[ \t]*->[ \t]/', 3, 'closures'],
            ['/@Grab\(|\bassert[ \t]+\w/', 4, '@Grab/assert'],
            ['/[;][ \t]*$/m', -1, ''],
        ],
        'vbnet' => [
            ['/^[ \t]*End[ \t]+(?:Sub|Function|Module|Class|If|Select|Structure|Namespace|Property|While|With|Try)\b/m', 6, 'End Sub/Function'],
            ['/^[ \t]*(?:(?:Public|Private|Shared|Friend|Protected|Overrides|Static)[ \t]+)*(?:Sub|Function)[ \t]+\w+[ \t]*\(/m', 5, 'Sub/Function'],
            ['/\bDim[ \t]+\w+(?:\(\))?(?:[ \t]+As[ \t]+\w+)?/', 5, 'Dim'],
            ['/^[ \t]*(?:Module|Class|Structure|Namespace)[ \t]+\w+[ \t]*$|^[ \t]*Imports[ \t]+System\b/m', 4, 'Module/Imports'],
            ['/\bThen[ \t]*$|\bElseIf\b|\bAndAlso\b|\bOrElse\b|^[ \t]*Next\b|^[ \t]*Wend\b|^[ \t]*Loop\b|\bMsgBox\b|\bDebug\.Print\b/m', 2, 'VB keywords'],
            ['/^[ \t]*\'/m', 1, '\' comments'],
            ['/\bConsole\.Write(?:Line)?\(|^[ \t]*(?:Module|Imports)[ \t]|\bByVal\b|\bAs[ \t]+New\b|\bString\.Format\(/m', 4, '.NET usage'],
            ['/^[ \t]*(?:Print|PRINT)[ \t]|\bDim[ \t]+(?:Shared[ \t]+)?As[ \t]/m', -3, ''],
            ['/[;{}][ \t]*$/m', -1.5, ''],
        ],
        'basic' => [
            ['/^[ \t]*\d+[ \t]+(?:PRINT|LET|GOTO|GOSUB|IF|FOR|NEXT|REM|DIM|INPUT|END|RETURN|DATA|READ|CLS)\b/mi', 6, 'line numbers'],
            ['/\bDim[ \t]+(?:Shared[ \t]+)?As[ \t]+\w+/i', 6, 'Dim As Type'],
            ['/\bDim[ \t]+(?:Shared[ \t]+)?\w+(?:\([^)\r\n]*\))?[ \t]+As[ \t]+(?:UInteger|ULong|ULongInt|LongInt|UByte|ZString)\b/i', 4, 'FreeBASIC types'],
            ['/^[ \t]*(?:Print|PRINT|print)[ \t]+(?:["\w(;,]|$)|^[ \t]*\?[ \t]+["\w]/m', 3, 'PRINT statements'],
            ['/^[ \t]*(?:Sleep|SLEEP)[ \t]*$|^[ \t]*#(?:include|lang)[ \t]+"(?:fb|fbc|qb|crt|vbcompat)|\bScreenRes\b|\bLocate[ \t]+\d|\bGOSUB\b|^[ \t]*(?:Wend|WEND)\b|\bINPUT[ \t]+"|\bEND[ \t]*$/m', 4, 'BASIC statements'],
            ['/\bConsole\.WriteLine\b|^[ \t]*Imports[ \t]|^[ \t]*Module[ \t]|\bDebug\.Print\b|\bMsgBox\b/m', -4, ''],
        ],
        'delphi' => [
            ['/^[ \t]*program[ \t]+\w+[ \t]*;/mi', 6, 'program'],
            ['/^[ \t]*(?:unit|uses)[ \t]+[\w., \t]+;/mi', 5, 'unit/uses'],
            ['/^[ \t]*begin[ \t]*$|^[ \t]*end[ \t]*[;.][ \t]*$/mi', 3, 'begin/end'],
            ['/\b(?:writeln|readln|write|inc|dec|ord|chr|length|setlength)[ \t]*\(/i', 2, 'pascal routines'],
            ['/^[ \t]*(?:procedure|function)[ \t]+[\w.]+[^;\r\n]*;/mi', 4, 'procedure/function'],
            ['/^[ \t]*var[ \t]*$|^[ \t]*var[ \t]+\w+(?:[ \t]*,[ \t]*\w+)*[ \t]*:[ \t]*\w+/mi', 4, 'var blocks'],
            ['/:=/', 1, ':='],
        ],
        'fortran' => [
            ['/^[ \t]*(?:program|PROGRAM)[ \t]+\w+[ \t]*$/m', 5, 'program'],
            ['/^[ \t]*(?:end[ \t]*(?:program|subroutine|module|do)|END[ \t]*(?:PROGRAM|SUBROUTINE|MODULE|DO))\b(?![^\r\n]*;)/m', 6, 'end program/do'],
            ['/^[ \t]*(?:end[ \t]*(?:if|select|function|type)|END[ \t]*(?:IF|SELECT|FUNCTION|TYPE))\b(?![^\r\n]*;)/m', 1.5, 'end if/function'],
            ['/\bimplicit[ \t]+none\b/i', 6, 'implicit none'],
            ['/\b(?:integer|real|character|logical|complex|double[ \t]+precision|type)[ \t]*(?:\([^)\r\n]*\))?[ \t]*(?:,[ \t]*\w+(?:\([^)\r\n]*\))?)*[ \t]*::/i', 5, 'declarations'],
            ['/\bprint[ \t]*\*[ \t]*,|\bwrite[ \t]*\([ \t]*\*|\b(?:call|CALL)[ \t]+\w+\(/', 4, 'print/write/call'],
            ['/\.(?:eq|ne|lt|gt|le|ge|and|or|not|true|false)\./i', 4, '.eq. operators'],
            ['/^[ \t]*do[ \t]+\w+[ \t]*=/mi', 2, 'do loops'],
        ],
        'matlab' => [
            ['/^[ \t]*function[ \t]+(?:\[[^\]\r\n]*\]|\w+)[ \t]*=[ \t]*\w+/mi', 6, 'function y = f'],
            ['/^[ \t]*%(?!\{)/m', 1, '% comments'],
            ['/[\w)\]][ \t]*\.(?:\*|\/|\^)[ \t]*[\w(\[]/', 3, 'element-wise operators'],
            ['/\b(?:disp|fprintf|zeros|ones|numel|linspace|num2str|isempty|plot|mod|floor|sprintf|strcat|cellfun|arrayfun|size|length|sum|rand|eye|repmat|find|any|all)[ \t]*\(/', 1.5, 'matlab functions'],
            ['/^[ \t]*end(?:for|while|if|function|switch)[ \t]*;?[ \t]*$/m', 5, 'endfor (Octave)'],
            ['/@\([\w, ]*\)/', 4, 'anonymous functions'],
            ['/^[ \t]*for[ \t]+\w+[ \t]*=[ \t]*[\w(]+[ \t]*:/m', 4, 'for i = 1:n'],
            ['/^[ \t]*end[ \t]*;?[ \t]*$/m', 0.5, 'end'],
            ['/\bfunction[ \t]+\w+[ \t]*\([^)\r\n]*\)[ \t]*\{|\blocal[ \t]+\w/', -3, ''],
        ],
        'julia' => [
            ['/::(?:Int(?:64|32)?|Float(?:64|32)|String|Vector|Array|Any|Bool|Real|Number|Integer|Char|Dict|Tuple|BigInt|AbstractString)\b/', 5, 'type annotations'],
            ['/^[ \t]*using[ \t]+\w+|^[ \t]*import[ \t]+\w+:/m', 3, 'using'],
            ['/\bmutable[ \t]+struct\b|\babstract[ \t]+type\b|^[ \t]*macro[ \t]+\w+|@(?:time|show|assert|printf|inbounds|view|test)\b/m', 4, 'julia keywords'],
            ['/\bprintln\(/', 1, 'println'],
            ['/\.\(|[ \t]\.(?:\+|\*|\^|==)|\b\w+\.\(/', 2, 'broadcasting'],
        ],
        'ocaml' => [
            ['/\blet[ \t]+rec\b/', 5, 'let rec'],
            ['/\bin[ \t]*$/m', 3, 'let ... in'],
            ['/\bmatch[ \t]+[^\r\n]+[ \t]with\b|^[ \t]*\|[ \t]*[^|\r\n]+->/m', 4, 'match/with'],
            ['/\(\*[\s\S]*?\*\)/', 2, '(* comments *)'],
            ['/;;[ \t]*$/m', 4, ';;'],
            ['/\bPrintf\.printf\b|\bprint_(?:endline|string|int|newline)\b|\bList\.(?:map|iter|fold_left|rev)\b|\bfun[ \t]+\w+[ \t]*->/', 4, 'stdlib'],
            ['/\bmodule[ \t]+\w+[ \t]*=[ \t]*struct\b/', 6, 'module struct'],
        ],
        'fsharp' => [
            ['/\bprintfn[ \t]/', 6, 'printfn'],
            ['/\blet[ \t]+mutable\b|^[ \t]*open[ \t]+System\b|\[<\w+[^>\r\n]*>\]|\bmember[ \t]+\w+\.\w+/m', 5, 'F# keywords'],
            ['/\|>|\bSeq\.\w+|\bList\.\w+|\bArray\.\w+/', 2, 'pipes/modules'],
            ['/\blet[ \t]+\w+[^=\r\n]*=/', 1, 'let'],
        ],
        'nimrod' => [
            ['/^[ \t]*proc[ \t]+\w+\*?[ \t]*(?:\[[^\]\r\n]*\])?[ \t]*\([^)\r\n]*\)(?:[ \t]*:[ \t]*[\w\[\]]+)?[ \t]*=/m', 6, 'proc ... ='],
            ['/\bseq\[|\bnewSeq\b|\bdiscard\b|\bstrutils\b|\bsequtils\b|\.\.<[ \t]*\w/', 3, 'nim idioms'],
            ['/^[ \t]*(?:var|let|const)[ \t]+\w+[ \t]*(?::[ \t]*\w+)?[ \t]*=?/m', 1, 'var/let'],
            ['/^[ \t]*import[ \t]+\w+(?:[ \t]*,[ \t]*\w+)*[ \t]*$/m', 1, 'import'],
        ],
        'coffeescript' => [
            ['/^[ \t]*[\w.@]+[ \t]*=[ \t]*(?:\([\w, @=.]*\)[ \t]*)?[-=]>/m', 6, 'function assignment'],
            ['/[-=]>[ \t]*$/m', 2, 'arrows'],
            ['/^[ \t]*class[ \t]+\w+(?:[ \t]+extends[ \t]+\w+)?[ \t]*$/m', 2, 'class'],
            ['/\bunless\b|\bthen\b|#\{[^}\r\n]+\}|\bconsole\.log[ \t]+[\'"\w]/', 1.5, 'coffee idioms'],
        ],
        'd' => [
            ['/^import[ \t]+std\.\w+(?:[ \t]*:[^;\r\n]*)?;/m', 6, 'import std'],
            ['/\bwriteln\(|\bwritefln\(|\w!\(|\w!\w|\bimmutable\b|\bforeach[ \t]*\([ \t]*\w+[ \t]*;/', 3, 'D idioms'],
            ['/\bauto[ \t]+\w+[ \t]*=|\bmodule[ \t]+[\w.]+;/', 1, 'auto/module'],
        ],
        'autohotkey' => [
            ['/^[ \t]*#(?:NoEnv|SingleInstance|Persistent|Include|Warn|Requires|IfWinActive|MaxThreads)\b/m', 6, 'directives'],
            ['/^[#!^+*~$<>]*\w{1,15}::[ \t]*(?:$|\{|;|[A-Z]\w*,)|^:[*?]*:[^:\r\n]+::/m', 4, 'hotkeys'],
            ['/\bA_(?:Index|LoopField|LoopFileName|Now|ScriptDir|Space|Tab|Hour|Min|Sec|TickCount|Clipboard|WorkingDir)\b|\b(?:MsgBox|Loop|Send|Sleep|SetTimer|Gui|FileRead|FileAppend|StringSplit|StringReplace|SetFormat)[ \t]*,/', 4, 'commands'],
            ['/%\w+%|:=/', 1, 'variables'],
        ],
        'smalltalk' => [
            ['/\bTranscript[ \t]+(?:show|display|cr)\b/', 6, 'Transcript'],
            ['/\bifTrue:|\bifFalse:|\bdo:[ \t]*\[|\bcollect:[ \t]*\[|\binject:|\bto:[ \t]*\w+[ \t]+do:|\bwhileTrue:/', 5, 'keyword messages'],
            ['/^[ \t]*\|[ \t]*\w+(?:[ \t]+\w+)*[ \t]*\|/m', 2, 'temporaries'],
            ['/\^[ \t]*\w|\][ \t]*value\b/', 2, 'returns/blocks'],
        ],
        'prolog' => [
            ['/^[a-z]\w*(?:\([^)\r\n]*\))?[ \t]*:-/m', 6, 'rules'],
            ['/^:-[ \t]*\w/m', 5, 'directives'],
            ['/\[[A-Z_]\w*[ \t]*\|[ \t]*[A-Z_]\w*\]|\b[A-Z]\w*[ \t]+is[ \t]+[A-Z\d(]|\bwrite(?:ln)?\([^)\r\n]*\)[ \t]*,|\bformat\([\'"][^\r\n]*~[wapsd]/', 3, 'prolog builtins'],
        ],
        'awk' => [
            ['/^[ \t]*BEGIN[ \t]*\{|^[ \t]*END[ \t]*\{/m', 6, 'BEGIN/END'],
            ['/\$(?:\d+|NF)\b|\bNR\b|\bNF\b|\bOFS\b|\bFS[ \t]*=/', 3, 'fields'],
            ['/^[ \t]*\/[^\/\r\n]+\/[ \t]*\{|\bgsub\(|\bsubstr\(|\bsplit\([^,\r\n]+,/m', 2, 'patterns/functions'],
        ],
        'x86asm' => [
            ['/^[ \t]*(?:[\w.]+:[ \t]*)?(?:mov[a-z]*|push[a-z]?|pop[a-z]?|lea[a-z]?|add[a-z]?|sub[a-z]?|xor[a-z]?|cmp[a-z]?|inc[a-z]?|dec[a-z]?|imul|idiv|shl|shr|test[a-z]?|and[a-z]?|or[a-z]?)[ \t]+(?:(?:byte|word|dword|qword)[ \t]+(?:ptr[ \t]+)?)?[\[%]?(?:[er]?(?:ax|bx|cx|dx|si|di|sp|bp)|r(?:8|9|1[0-5])[dwb]?|[abcd][lh])\b/mi', 4, 'register instructions'],
            ['/^[ \t]*(?:call|jmp|je|jne|jz|jnz|jg|jl|jge|jle|ja|jb|loop)[ \t]+[\w.]+[ \t]*(?:;.*)?$|\bint[ \t]+0x80\b|^[ \t]*syscall\b/mi', 2, 'jumps/syscalls'],
            ['/^[ \t]*(?:section|segment)[ \t]+\.?(?:text|data|bss)|^[ \t]*\.(?:text|data|bss|globl|global|intel_syntax|code\d+)\b|^[ \t]*global[ \t]+_?\w+/mi', 5, 'sections'],
            ['/^[ \t]*[\w.]+:[ \t]*(?:;.*)?$/m', 1, 'labels'],
            ['/^[ \t]*(?:[\w.]+:?[ \t]+)?(?:db|dw|dd|dq|resb|resw|resd|resq|times)[ \t]+\S/mi', 3, 'data'],
            ['/-?\d+\((?:x\d+|zero|ra|sp|t\d|s\d+|a\d|\$\w+)\)|^[ \t]*(?:[\w.]+:[ \t]*)?(?:addi|li|la|lw|sw|ecall)[ \t]/mi', -4, ''],
        ],
        'armasm' => [
            ['/^[ \t]*(?:[\w.]+:[ \t]*)?(?:ldr[a-z]*|str[a-z]*|ldm[a-z]*|stm[a-z]*|bl|blx|bx|mov[a-z]*|add[a-z]*|sub[a-z]*|cmp|mul|push|pop|adr|cbn?z|orr|eor|lsl|lsr)[ \t]+(?:[rxw](?:1[0-5]|[12]?[0-9]|30)\b|sp\b|lr\b|pc\b|\{|\[)/mi', 4, 'register instructions'],
            ['/^[ \t]*(?:b|bl|beq|bne|bgt|blt|bge|ble|bhi|blo|svc|swi)[ \t]+[\w.#]+[ \t]*(?:[@;\/].*)?$/mi', 2, 'branches'],
            ['/,[ \t]*#-?(?:0x)?[\da-f]+\b|^[ \t]*\.(?:global|text|data|arm|thumb|syntax|align|asciz|word)\b/mi', 3, 'immediates/directives'],
            ['/^[ \t]*@/m', 1, '@ comments'],
            ['/^[ \t]*(?:[\w.]+:[ \t]*)?(?:addi|li|la|lw|sw|lb|sb|lh|sh|ecall|jalr|bnez|beqz)[ \t]|-?\d+\((?:x\d+|zero|ra|sp|t\d|s\d+|a\d)\)|\$(?:t\d|zero|ra|sp)\b/mi', -4, ''],
        ],
        'riscv' => [
            ['/^[ \t]*(?:[\w.$]+:[ \t]*)?(?:addi?w?|subw?|lui|auipc|jalr?|beqz?|bnez?|bltu?|bgeu?|bgtu?|bleu?|bltz|bgez|blez|bgtz|l[bhwd]u?|s[bhwd]|slli?w?|srli?w?|srai?w?|andi?|ori?|xori?|slti?u?|mul(?:h|hsu|hu|w)?|divu?w?|remu?w?|li|la|lla|mv|not|negw?|seqz|snez|csrr[wsc]?i?|f(?:lw|sw|ld|sd)|f(?:add|sub|mul|div|mv|cvt|eq|lt|le|min|max|sqrt)\.[\w.]+)[ \t]+(?:x(?:[12]?\d|3[01])|zero|ra|sp|gp|tp|t[0-6]|s(?:1[01]|\d)|a[0-7]|fp|f(?:[12]?\d|3[01])|f[ts](?:1[01]|\d)|fa[0-7])[ \t]*,/m', 4, 'register instructions'],
            ['/(?<![\w.])-?(?:0x[\da-f]+|\d+)\((?:x(?:[12]?\d|3[01])|zero|ra|sp|gp|tp|t[0-6]|s(?:1[01]|\d)|a[0-7]|fp)\)/', 4, 'offset(register) addressing'],
            ['/^[ \t]*(?:[\w.$]+:[ \t]*)?(?:ecall|ebreak|mret|sret|fence\.i)[ \t]*(?:#.*)?$|\b(?:auipc|jalr|addiw|lwu|sext\.w|beqz|bnez|bltz|bgez|blez|bgtz)[ \t]+(?:x(?:[12]?\d|3[01])|zero|ra|sp|gp|tp|t[0-6]|s(?:1[01]|\d)|a[0-7]|fp)[ \t]*,/m', 5, 'RISC-V instructions'],
            ['/%(?:hi|lo|pcrel_hi|pcrel_lo|tprel_hi)\(/', 5, '%hi/%lo'],
            ['/^[ \t]*\.(?:text|data|rodata|bss|globl|global|word|half|byte|dword|align|asciz|string|section|option)\b/m', 1, 'directives'],
            ['/\$(?:t\d|s\d|a\d|v[01]|zero|ra|sp|fp|gp|k[01]|at|\d+)\b|\[[^\]\r\n]*\]|,[ \t]*#|,[ \t]*%[a-z]/', -4, ''],
        ],
        'mipsasm' => [
            ['/^[ \t]*(?:[\w.]+:[ \t]*)?(?:(?:addi?u?|subu?|l[bhw]u?|s[bhw]|lui|la|li|move|beqz?|bnez?|bgtz|blez|bltz|bgez|b[gl][te]|mult?u?|divu?|sllv?|srlv?|srav?|slti?u?|andi?|ori?|xori?|nor)[ \t]+\$(?:t[0-9]|s[0-8]|a[0-3]|v[01]|zero|ra|sp|fp|gp|k[01]|at|[12]?\d|3[01])[ \t]*,|(?:jr|jalr|mfhi|mflo)[ \t]+\$(?:t[0-9]|s[0-8]|a[0-3]|v[01]|zero|ra|sp|fp|gp|k[01]|at|[12]?\d|3[01])\b)/m', 5, 'register instructions'],
            ['/(?<![\w.])-?\d+\(\$(?:t[0-9]|s[0-8]|a[0-3]|v[01]|zero|ra|sp|fp|gp|k[01]|at|[12]?\d|3[01])\)/', 4, 'offset($reg) addressing'],
            ['/^[ \t]*\.(?:asciiz|kdata|ktext)\b/m', 3, 'MIPS directives'],
            ['/^[ \t]*syscall[ \t]*(?:#.*)?$/m', 2, 'syscall'],
            ['/^[ \t]*\.(?:text|data|globl|word|space|align|byte|half)\b/m', 1, 'directives'],
            ['/,[ \t]*%[a-z]|%[re]?[abcd]x\b|\[[^\]\r\n]*\]/', -4, ''],
        ],
        'ada' => [
            ['/^[ \t]*with[ \t]+[\w.]+(?:[ \t]*,[ \t]*[\w.]+)*;|^[ \t]*use[ \t]+[\w.]+;/mi', 5, 'with/use'],
            ['/^[ \t]*(?:procedure|function|package(?:[ \t]+body)?)[ \t]+[\w.]+[^;\r\n]*\bis\b/mi', 6, 'procedure ... is'],
            ['/\bPut_Line[ \t]*\(|\b\w+\'(?:Image|Range|First|Last|Length|Value|Succ|Pred)\b/', 5, 'Ada attributes'],
            ['/\bend[ \t]+(?:loop|if|case|record)[ \t]*;|\bend[ \t]+\w+[ \t]*;/i', 3, 'end loop/name'],
        ],
        'haxe' => [
            ['/\bstatic[ \t]+(?:public[ \t]+)?function[ \t]+main\(\)|\btrace\(|\bSys\.println\(|\bStd\.\w+\(/', 5, 'haxe idioms'],
            ['/\bvar[ \t]+\w+[ \t]*:[ \t]*[A-Z]\w*|\bfunction[ \t]+\w+\([^)\r\n]*:[ \t]*[A-Z]/', 2, 'typed vars'],
        ],
        'mathematica' => [
            ['/\w\[[^\]\r\n]*_[^\]\r\n]*\][ \t]*:=/', 6, 'definitions'],
            ['/\b(?:Table|Module|Print|Range|Select|Plot|StringJoin|ToString|Function|Total|Length|Apply|Map|Fold|Nest|Do|If|While|Block|Sort|Reverse|IntegerDigits|FromDigits|StringLength|Part)\[/', 3, 'builtins'],
            ['/\w\[\[[^\]\r\n]+\]\]|\w[ \t]*\/@[ \t]*\w|#[ \t]*&|&[ \t]*(?:\]|$|\/@|@)/m', 3, 'part/pure functions'],
        ],
        'maxima' => [
            ['/\bblock\(\[|\bmakelist\(|\bthru\b|\bratsimp\(|\bfactor\(/', 5, 'maxima idioms'],
            ['/\$[ \t]*$/m', 3, '$ terminators'],
        ],
        'cmake' => [
            ['/^[ \t]*(?:cmake_minimum_required|add_executable|add_library|target_link_libraries|target_include_directories|find_package|add_subdirectory|include_directories)[ \t]*\(/mi', 6, 'commands'],
            ['/^[ \t]*(?:endif|endforeach|endfunction|endmacro)[ \t]*\(\)/mi', 5, 'end blocks'],
            ['/^[ \t]*(?:set|project|message|option)[ \t]*\((?:CMAKE_|[A-Z_]+[ \t]|STATUS|WARNING|FATAL_ERROR)/m', 3, 'set()/message()'],
        ],
        'protobuf' => [
            ['/^[ \t]*syntax[ \t]*=[ \t]*"proto[23]"/m', 6, 'syntax'],
            ['/^[ \t]*(?:optional|required|repeated)[ \t]+\w+[ \t]+\w+[ \t]*=[ \t]*\d+/m', 6, 'fields'],
            ['/^[ \t]*(?:message|service)[ \t]+[A-Z]\w*[ \t]*\{|^[ \t]*rpc[ \t]+\w+[ \t]*\(/m', 3, 'messages'],
        ],
        'tex' => [
            ['/\\\\(?:documentclass|usepackage|begin\{|end\{|section\*?\{|subsection\*?\{|item\b|textbf\{|emph\{|label\{|ref\{|cite\{|frac\{|newcommand|def\\\\|maketitle|chapter\{|includegraphics)/', 4, 'commands'],
            ['/^[ \t]*%/m', 0.5, '% comments'],
        ],
        'glsl' => [
            ['/#version[ \t]+\d+|\bgl_(?:Position|FragColor|FragCoord|VertexID)\b|\bprecision[ \t]+(?:highp|mediump|lowp)\b/', 6, 'GLSL builtins'],
            ['/\b(?:uniform|varying|attribute)[ \t]+\w+|\b(?:in|out)[ \t]+(?:vec[234]|mat[234]|float)\b|\bvec[234]\(|\bsampler2D\b|\btexture2?D?\(/', 4, 'shader types'],
        ],
        'verilog' => [
            ['/^[ \t]*module[ \t]+\w+[ \t]*(?:#[ \t]*\()?[\s\S]{0,200}?\(/m', 3, 'module'],
            ['/\bendmodule\b/', 6, 'endmodule'],
            ['/\b(?:always[ \t]*@|assign[ \t]|posedge|negedge|reg[ \t]|wire[ \t]|input[ \t]|output[ \t])/', 3, 'hardware keywords'],
            ['/\b\d+\'[bhdo][\da-fA-FxXzZ_]+/', 5, 'sized literals'],
        ],
        'vhdl' => [
            ['/\blibrary[ \t]+ieee\b|\buse[ \t]+ieee\./i', 6, 'library ieee'],
            ['/\b(?:entity|architecture)[ \t]+\w+[ \t]+(?:is|of)\b|\bstd_logic(?:_vector)?\b|\bprocess[ \t]*\(|\bport[ \t]*\(/i', 5, 'entity/architecture'],
        ],
        'puppet' => [
            ['/\b(?:package|service|file|exec|user|group|cron)[ \t]*\{[ \t]*[\'"$]/', 6, 'resources'],
            ['/\bensure[ \t]*=>|^[ \t]*(?:class|define)[ \t]+[a-z]\w*::[\w:]+|^[ \t]*node[ \t]+[\'"]/m', 3, 'ensure/class'],
        ],
        'nix' => [
            ['/\{[ \t]*[\w, ?]*\.\.\.[ \t]*\}[ \t]*:|\bpkgs\.\w+|\bmkDerivation\b|\bstdenv\b|\bfetchurl\b/', 6, 'nix idioms'],
        ],
        'nsis' => [
            ['/^[ \t]*(?:SectionEnd|FunctionEnd|OutFile|InstallDir|RequestExecutionLevel|!include|!define|!insertmacro|!macro)\b/m', 6, 'NSIS commands'],
            ['/^[ \t]*(?:Section|Function)[ \t]+["\w.][^{\r\n]*$/m', 2, 'Section/Function'],
        ],
        // Recognised so they are not mistaken for something else; highlighted as plain text
        'zig' => [
            ['/@import\(|\bconst[ \t]+std[ \t]*=|\bpub[ \t]+fn[ \t]+main\(\)[ \t]*!?void\b|\bcomptime\b|\btry[ \t]+\w+\.\w+\(|\berrdefer\b/', 6, 'zig idioms'],
            ['/\]const[ \t]+u8\b|\bstd\.debug\.print\b|\b[iu](?:8|16|32|64|size)\b/', 3, 'zig types'],
        ],
        'cobol' => [
            ['/\b(?:IDENTIFICATION|PROCEDURE|DATA|ENVIRONMENT)[ \t]+DIVISION\b|\bWORKING-STORAGE[ \t]+SECTION\b|\bPIC(?:TURE)?[ \t]+[9XSAV(]/i', 6, 'divisions/PIC'],
            ['/\bSTOP[ \t]+RUN\b|\bDISPLAY[ \t]+["\w]|\bPERFORM[ \t]+\w/i', 3, 'statements'],
        ],
        'forth' => [
            ['/^:[ \t]+\S+[^\r\n]*;[ \t]*(?:\\\\.*)?$/m', 5, 'word definitions'],
            ['/\b(?:dup|drop|swap|over|rot|emit|cr)\b[^\r\n]*\b(?:dup|drop|swap|over|rot|emit|cr|\.)\b|\(\s[^)\r\n]*--[^)\r\n]*\)/i', 4, 'stack words'],
        ],
        'seed7' => [
            ['/^[ \t]*\$[ \t]*include[ \t]+"\w+\.s7i"/m', 20, 'include "seed7_05.s7i"'],
            ['/\bconst[ \t]+(?:proc|func[ \t]+\w+):[ \t]*\w+[^\r\n]*\bis[ \t]+func\b/', 8, 'const proc/func ... is func'],
        ],
        'v' => [
            ['/\bmut[ \t]+\w+[ \t]*:=|\bprintln\(\'|^[ \t]*fn[ \t]+\w+\([^)\r\n]*\)[ \t]*\??[a-z]\w*[ \t]*\{/m', 4, 'V idioms'],
        ],
        'wren' => [
            ['/\bSystem\.(?:print|write)(?:All)?\(|\bconstruct[ \t]+new\(|\bFiber\.new\b|^[ \t]*import[ \t]+"[\w\/.-]+"[ \t]+for\b/m', 6, 'Wren idioms'],
        ],
        'pli' => [
            ['/\bput[ \t]+(?:skip[ \t]+)?(?:list|edit|data)\b|\bproc(?:edure)?[ \t]+options[ \t]*\([ \t]*main|\b(?:declare|dcl)[ \t]+\w+[ \t]+(?:fixed|float|char|bit)\b/i', 6, 'PL/I statements'],
        ],
        'rexx' => [
            ['/^[ \t]*(?:say|parse[ \t]+(?:arg|var|pull))\b|\bdo[ \t]+\w+[ \t]*=[ \t]*\d+[ \t]+to\b|\bend[ \t]*\/\*/mi', 4, 'rexx statements'],
        ],
    ];
    return $sigs;
}

/** Signature ids that have no highlighter: a confident match is shown as plain text, named in the explanation. */
function paste_unhighlighted_languages(): array {
    return ['zig' => 'Zig', 'cobol' => 'COBOL', 'forth' => 'Forth', 'rexx' => 'REXX', 'seed7' => 'Seed7', 'v' => 'V', 'wren' => 'Wren', 'pli' => 'PL/I'];
}

/** Language families; a close score between members of one family is not treated as doubt. */
function paste_language_family(string $id): string {
    static $fam = [
        'c' => 'c', 'cpp' => 'c', 'objectivec' => 'c', 'glsl' => 'c',
        'javascript' => 'js', 'typescript' => 'js',
        'sql' => 'sql', 'pgsql' => 'sql',
        'lisp' => 'lisp', 'scheme' => 'lisp', 'clojure' => 'lisp',
        'ocaml' => 'ml', 'fsharp' => 'ml',
        'haskell' => 'hs', 'elm' => 'hs',
        'vbnet' => 'vb', 'vbscript' => 'vb', 'basic' => 'vb',
        'css' => 'css', 'scss' => 'css', 'less' => 'css',
        'ini' => 'conf', 'toml' => 'conf', 'properties' => 'conf',
        'x86asm' => 'asm', 'armasm' => 'asm', 'mipsasm' => 'asm', 'riscv' => 'asm',
        'ruby' => 'ruby', 'crystal' => 'ruby',
    ];
    return $fam[$id] ?? $id;
}

/**
 * Score languages by their signatures.
 * Returns ['scores' => [id => score] high to low, 'hits' => [id => [label => points]]].
 */
function paste_score_languages(string $code): array {
    if (strlen($code) > 65536) $code = substr($code, 0, 65536);

    $scores = []; $hits = [];
    foreach (paste_language_signatures() as $id => $sigs) {
        $s = 0.0;
        foreach ($sigs as [$re, $w, $label]) {
            $n = preg_match_all($re, $code) ?: 0;
            if (!$n) continue;
            $pts = $w * (1 + 0.5 * (min($n, 5) - 1));
            $s += $pts;
            if ($label !== '' && $pts > 0) $hits[$id][$label] = ($hits[$id][$label] ?? 0) + $pts;
        }
        $scores[$id] = $s;
    }

    // TypeScript, SCSS and Less extend their base language when their own markers are present
    foreach (['typescript' => 'javascript', 'scss' => 'css', 'less' => 'css'] as $ext => $base) {
        $scores[$ext] = $scores[$ext] >= 3 ? $scores[$ext] + $scores[$base] : 0;
    }

    $scores = array_filter($scores, static fn($v) => $v > 0);
    arsort($scores);
    return ['scores' => $scores, 'hits' => $hits];
}

/**
 * Pick a language from signature scores, or null when none clearly leads.
 * The leader must beat the best language outside its family by a margin
 * that is wider for low scores, where a few matches decide less.
 */
function paste_pick_language(array $scores): ?string {
    $best = (string) (array_key_first($scores) ?? '');
    if ($best === '') return null;
    $top = (float) $scores[$best];
    $family = paste_language_family($best);
    $rival = 0.0;
    foreach ($scores as $id => $v) {
        if ($id !== $best && paste_language_family((string) $id) !== $family) { $rival = (float) $v; break; }
    }
    if ($top >= 12)  return $top >= 1.3 * $rival ? $best : null;
    if ($top >= 6)   return $top >= 1.6 * $rival ? $best : null;
    if ($top >= 3.5) return $top >= 2.5 * $rival ? $best : null;
    return null;
}

/** Highlight id for clearly structured data (XML, YAML, INI) when no language signature decided; null otherwise. */
function paste_structured_fallback(string $code, array $f): ?string {
    $trim = ltrim($code);
    if ($trim !== '' && $trim[0] === '<' && $f['tag_density'] > 0.3 && !$f['has_preproc']) return 'xml';
    if ($f['yaml_key_lines'] >= 3 && $f['semicolon_ratio'] < 0.05 && $f['brace_ratio'] < 0.01
        && $f['yaml_key_lines'] >= 0.4 * $f['lines']) return 'yaml';
    if ($f['ini_headers'] > 0 && $f['brace_ratio'] < 0.01 && preg_match_all('/^[ \t]*[\w.-]+[ \t]*=/m', $code) >= 2) return 'ini';
    return null;
}

/** Build an autodetect result for the engine from a highlight id. */
function paste_autodetect_result(string $hlId, string $source, string $engine, $hl, string $code = '', string $explain = ''): array {
    $unhighlighted = paste_unhighlighted_languages();
    if (isset($unhighlighted[$hlId])) {
        $id = ($engine === 'highlight') ? 'plaintext' : 'text';
        $explain = sprintf('Looks like %s, which has no highlighter here, so it is shown as plain text. %s', $unhighlighted[$hlId], $explain);
        return ['id' => $id, 'label' => 'Plain Text', 'source' => $source, 'explain' => trim($explain)];
    }
    if ($engine === 'geshi') {
        $id = paste_normalize_lang($hlId, 'geshi', null);
        if ($id === 'tcl' && $code !== '' && paste_probable_eggdrop($code) && is_file(__DIR__ . '/geshi/tclegg.php')) $id = 'tclegg';
    } else {
        $id = paste_normalize_lang($hlId, 'highlight', $hl);
    }
    if ($id === '') $id = ($engine === 'highlight') ? 'plaintext' : 'text';

    $out = ['id' => $id, 'label' => paste_lang_label($id), 'source' => $source];
    if ($explain !== '') $out['explain'] = $explain;
    return $out;
}

/**
 * One entry-point: detect language.
 * Returns: ['id' => engine id, 'label' => display, 'source' => 'filename|modeline|shebang|php-tag|markdown|heuristic|fallback', 'explain' => optional]
 * $engine: 'highlight' or 'geshi'
 *
 * NOTE: Uses global $p_title (paste title) for filename extension hints.
 */
function paste_autodetect_language(string $code, string $engine = 'highlight', $hl = null): array {
    global $p_title;
    $plain = ($engine === 'highlight') ? 'plaintext' : 'text';

    // 0) Hard locks
    if (paste_probable_diff($code)) {
        return paste_autodetect_result('diff', 'heuristic', $engine, $hl, '', 'Unified diff hunks (@@ -a,b +c,d @@) with file headers.');
    }
    if (paste_detect_php_tag($code)) {
        return paste_autodetect_result('php', 'php-tag', $engine, $hl);
    }

    // 1) Filename extension from title
    if ($p_title && ($extId = paste_detect_from_title_ext($p_title, $code))) {
        return paste_autodetect_result($extId, 'filename', $engine, $hl, $code);
    }

    // 2) Shebang
    if ($lang = paste_detect_shebang($code)) {
        return paste_autodetect_result($lang, 'shebang', $engine, $hl, $code);
    }

    // 3) Editor modelines
    if ($lang = paste_detect_modeline($code)) {
        return paste_autodetect_result($lang, 'modeline', $engine, $hl, $code);
    }

    // 3b) HexDroid script; highlight.php has no hex grammar, so it gets mIRC, the closest
    if (paste_probable_hex($code)) {
        if ($engine === 'geshi') {
            return ['id'=>'hex', 'label'=>'HexDroid Scripting', 'source'=>'heuristic'];
        }
        return paste_autodetect_result('mirc', 'heuristic', $engine, $hl);
    }

    // 4) Valid JSON
    $F = paste_feature_extract($code);
    if ($F['json_like']) {
        return paste_autodetect_result('json', 'heuristic', $engine, $hl);
    }

    // 5) Chat and IRC logs
    if (paste_probable_chatlog($code)) {
        return ['id' => $plain, 'label' => 'Plain Text', 'source' => 'heuristic', 'explain' => 'Most lines start with a timestamp and a nick, so this reads as a chat log.'];
    }

    // 6) Markdown, unless "# lines" are comments in code
    $ranked = paste_score_languages($code);
    $best = (string) (array_key_first($ranked['scores']) ?? '');
    if (paste_markdown_verdict(paste_markdown_assess($code), (float) ($ranked['scores'][$best] ?? 0))) {
        return ['id'=>'markdown', 'label'=>'Markdown', 'source'=>'markdown'];
    }

    // 7) Language signatures, when one language clearly leads
    $pick = paste_pick_language($ranked['scores']);
    if ($pick !== null) {
        return paste_autodetect_result($pick, 'heuristic', $engine, $hl, $code, paste_explain_scores($pick, $ranked));
    }

    // 8) Clearly structured data
    if (($data = paste_structured_fallback($code, $F)) !== null) {
        return paste_autodetect_result($data, 'heuristic', $engine, $hl, $code);
    }

    // 9) Plain text: nothing stood out
    $explain = 'No language stood out, so this is shown as plain text.';
    if ($best !== '') {
        $explain = sprintf('No language stood out (closest: %s, score %d), so this is shown as plain text. Pick a language or add a file extension to the title to highlight it.',
            paste_lang_label(paste_normalize_lang($best, $engine, $hl)), (int) round($ranked['scores'][$best]));
    }
    return ['id' => $plain, 'label' => 'Plain Text', 'source' => 'fallback', 'explain' => $explain];
}
