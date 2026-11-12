<?php
/*************************************************************************************
 * hex.php
 * -----
 * Copyright: (c) 2026 boxlabs
 * Based on mirc.php by Alberto 'Birckin' de Areba
 * Release Version: 1.1.0
 * Date Started: 2026/11/06
 *
 * HexDroid script (.hex) language file for GeSHi.
 * Reference: https://hexdroid.org/script-reference
 *
 * CHANGES
 * -------
 * 2026/11/06 (1.1.0)
 *   -  Keywords link to the script reference
 *   -  Classes match the standard GeSHi set, for class-based themes
 * 2026/11/06 (1.0.0)
 *   -  First Release
 *
 *************************************************************************************
 *
 *     This file is part of GeSHi.
 *
 *   GeSHi is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 *   GeSHi is distributed in the hope that it will be useful,
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *   GNU General Public License for more details.
 *
 *   You should have received a copy of the GNU General Public License
 *   along with GeSHi; if not, write to the Free Software
 *   Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 ************************************************************************************/

// Commands and control flow count only as the first word of a statement: at the start of a
// line, or after {, } or |. GeSHi stores | as <PIPE>.
$hex_stmt_before = '(?:^|\n|\{|\}|<PIPE>)[ \t]*\K';
$hex_word_after = '(?![\w.%$#\-])';

// An event name follows "on " and an optional mIRC level such as "*:" or "1:". GeSHi stores & as
// &amp;.
$hex_event_before = '(?:^|\n)[ \t]*on[ \t]+(?:(?:[@!+^=$]|&amp;)*(?:\*|\d+)?:)?\K';

$language_data = array (
    'LANG_NAME' => 'HexDroid Script',
    'COMMENT_SINGLE' => array(1 => ';'),
    'COMMENT_MULTI' => array(),
    'CASE_KEYWORDS' => GESHI_CAPS_NO_CHANGE,
    'QUOTEMARKS' => array('"'),
    // .hex strings have no escapes.
    'ESCAPE_CHAR' => '',
    'KEYWORDS' => array(
        // Control flow
        1 => array(
            'if', 'elseif', 'else', 'while', 'foreach', 'break', 'continue', 'return',
            'halt',
            ),
        // Commands
        2 => array(
            'set', 'unset', 'inc', 'dec', 'push', 'setat', 'echo', 'msg',
            'raw', 'rewrite', 'signal', 'timer', 'toast', 'sidebar', 'log', 'debug',
            'view', 'decorate', 'action', 'http.get', 'http.post', 'media.pick', 'media.upload', 'age.join',
            'age.host', 'age.send', 'age.local', 'age.seal', 'age.close', 'age.purge', 'me', 'join',
            'part', 'cycle', 'topic', 'mode', 'invite', 'kick', 'knock', 'names',
            'who', 'whois', 'whowas', 'list', 'notice', 'ctcp', 'nick', 'away',
            'back', 'op', 'deop', 'voice', 'devoice', 'ban', 'unban', 'ignore',
            'unignore',
            ),
        // Functions, after $ and before (
        3 => array(
            'len', 'lower', 'upper', 'left', 'right', 'substr', 'replace', 'trim',
            'contains', 'indexof', 'repeat', 'pad', 'padleft', 'capitalize', 'title', 'urlencode',
            'calc', 'mod', 'idiv', 'int', 'round', 'ceil', 'abs', 'pow',
            'clamp', 'min', 'max', 'rand', 're_match', 're_find', 're_group', 're_replace',
            'list', 'map', 'get', 'has', 'keys', 'values', 'split', 'join',
            'sort', 'reverse', 'slice', 'concat', 'find', 'count', 'sum', 'range',
            'pick', 'shuffle', 'json', 'tojson', 'setting', 'age.rand', 'age.sha', 'age.ready',
            'age.owner', 'age.members', 'age.fp',
            ),
        // Fields, after $
        4 => array(
            'me', 'nick', 'chan', 'buffer', 'target', 'network', 'text', 'isme',
            'isprivate', 'iscmd', 'code', 'whois', 'account', 'victim', 'mode', 'opnick',
            'vnick', 'hnick', 'banmask', 'newnick', 'isserver', 'appactive', 'filename', 'filesize',
            'path', 'httpok', 'httpstatus', 'httpbody', 'httplocation', 'httperror', 'mediaok', 'mediatoken',
            'medianame', 'mediamime', 'mediasize', 'from', 'data', 'fp', 'ctime', 'ticks',
            'time', 'screen.landscape', 'screen.width', 'screen.height', 'screen.tv', 'age.me',
            ),
        // Events, after "on "
        5 => array(
            'LOAD', 'TEXT', 'ACTION', 'INPUT', 'NUMERIC', 'JOIN', 'PART', 'QUIT',
            'KICK', 'MODE', 'OP', 'DEOP', 'VOICE', 'DEVOICE', 'HELP', 'DEHELP',
            'BAN', 'UNBAN', 'RAWMODE', 'TOPIC', 'INVITE', 'CTCP', 'CTCPREPLY', 'NOTICE',
            'SNOTICE', 'NOTIFY', 'UNOTIFY', 'CONNECT', 'DISCONNECT', 'APPACTIVE', 'FILERCVD', 'FILESENT',
            'NICK', 'SIGNAL', 'START',
            ),
        // Word operators
        6 => array(
            'isin', 'iswm',
            ),
        ),
    'SYMBOLS' => array(
        '(', ')', '{', '}', '[', ']',
        '==', '!=', '<=', '>=', '<', '>', '&&', '||', '|'
        ),
    'CASE_SENSITIVE' => array(
        GESHI_COMMENTS => false,
        1 => false,
        2 => false,
        3 => false,
        4 => false,
        5 => false,
        6 => false,
        ),
    'STYLES' => array(
        'KEYWORDS' => array(
            1 => 'color: #000000; font-weight: bold;',
            2 => 'color: #990000; font-weight: bold;',
            3 => 'color: #7A3E9D;',
            4 => 'color: #000099;',
            5 => 'color: #AA00AA; font-weight: bold;',
            6 => 'color: #008800; font-weight: bold;',
            ),
        'COMMENTS' => array(
            1 => 'color: #808080; font-style: italic;',
            ),
        'ESCAPE_CHAR' => array(
            ),
        'BRACKETS' => array(
            0 => 'color: #FF0000;',
            ),
        'STRINGS' => array(
            0 => 'color: #0000FF;',
            ),
        'NUMBERS' => array(
            0 => 'color: #AA5500;',
            ),
        'METHODS' => array(
            ),
        'SYMBOLS' => array(
            0 => 'color: #FF0000;',
            ),
        'REGEXPS' => array(
            0 => 'color: #0066CC; font-weight: bold;',
            1 => 'color: #000000; font-weight: bold;',
            2 => 'color: #AA00AA;',
            3 => 'color: #7A3E9D;',
            4 => 'color: #000099;',
            5 => 'color: #0066CC;',
            6 => 'color: #000099; font-style: italic;',
            7 => 'color: #888800;',
            8 => 'color: #990000;',
            9 => 'color: #007777;',
            10 => 'color: #AA5500;',
            ),
        'SCRIPT' => array(
            )
        ),
    'URLS' => array(
        1 => 'https://hexdroid.org/script-reference#kw-{FNAMEL}',
        2 => 'https://hexdroid.org/script-reference#cmd-{FNAMEL}',
        3 => 'https://hexdroid.org/script-reference#fn-{FNAMEL}',
        4 => 'https://hexdroid.org/script-reference#field-{FNAMEL}',
        5 => 'https://hexdroid.org/script-reference#ev-{FNAMEL}',
        6 => 'https://hexdroid.org/script-reference#op-{FNAMEL}',
        ),
    'OOLANG' => false,
    'OBJECT_SPLITTERS' => array(),
    // GESHI_CLASS picks a standard class, so a theme that styles only those still colours them.
    'REGEXPS' => array(
        // Alias name
        0 => array(
            GESHI_SEARCH => '(^|\n)([ \t]*)(alias)([ \t]+)([\w.\-]+)',
            GESHI_REPLACE => '\\5',
            GESHI_MODIFIERS => 'i',
            GESHI_BEFORE => '\\1\\2\\3\\4',
            GESHI_AFTER => '',
            GESHI_CLASS => 'me1'
            ),
        // on, alias
        1 => array(
            GESHI_SEARCH => '(^|\n)([ \t]*)(on|alias)(?=[ \t])',
            GESHI_REPLACE => '\\3',
            GESHI_MODIFIERS => 'i',
            GESHI_BEFORE => '\\1\\2',
            GESHI_AFTER => '',
            GESHI_CLASS => 'kw1'
            ),
        // Signal name in SIGNAL:name
        2 => array(
            GESHI_SEARCH => '(SIGNAL\|>(?:<\/a>)?:)([\w.\-]+)',
            GESHI_REPLACE => '\\2',
            GESHI_MODIFIERS => 'i',
            GESHI_BEFORE => '\\1',
            GESHI_AFTER => '',
            GESHI_CLASS => 'me1'
            ),
        // The $ of a known function
        3 => array(
            GESHI_SEARCH => '\$(?=(?:<\|UR1\|"[^"]*">)?<\|\/3\/>)',
            GESHI_REPLACE => '\\0',
            GESHI_MODIFIERS => '',
            GESHI_BEFORE => '',
            GESHI_AFTER => '',
            GESHI_CLASS => 'kw3'
            ),
        // The $ of a known field
        4 => array(
            GESHI_SEARCH => '\$(?=(?:<\|UR1\|"[^"]*">)?<\|\/4\/>)',
            GESHI_REPLACE => '\\0',
            GESHI_MODIFIERS => '',
            GESHI_BEFORE => '',
            GESHI_AFTER => '',
            GESHI_CLASS => 'kw4'
            ),
        // Any other $name, such as an alias used as a function
        5 => array(
            GESHI_SEARCH => '(?<![\w$])\$[A-Za-z_][\w.]*(?<!\.)',
            GESHI_REPLACE => '\\0',
            GESHI_MODIFIERS => '',
            GESHI_BEFORE => '',
            GESHI_AFTER => '',
            GESHI_CLASS => 'me1'
            ),
        // $1, $2-, $1-3, $0
        6 => array(
            GESHI_SEARCH => '\$\d+(?:-\d*)?',
            GESHI_REPLACE => '\\0',
            GESHI_MODIFIERS => '',
            GESHI_BEFORE => '',
            GESHI_AFTER => '',
            GESHI_CLASS => 'me2'
            ),
        // %variable
        7 => array(
            GESHI_SEARCH => '%[A-Za-z_][\w.]*(?<!\.)',
            GESHI_REPLACE => '\\0',
            GESHI_MODIFIERS => '',
            GESHI_BEFORE => '',
            GESHI_AFTER => '',
            GESHI_CLASS => 're0'
            ),
        // -flag, as in set -l and echo -a
        8 => array(
            GESHI_SEARCH => '(?<=[ \t])-[A-Za-z]+(?![\w.%$-])',
            GESHI_REPLACE => '\\0',
            GESHI_MODIFIERS => '',
            GESHI_BEFORE => '',
            GESHI_AFTER => '',
            GESHI_CLASS => 'kw2'
            ),
        // #channel
        9 => array(
            GESHI_SEARCH => '(?<![&\w#])#[\w.\-]+',
            GESHI_REPLACE => '\\0',
            GESHI_MODIFIERS => '',
            GESHI_BEFORE => '',
            GESHI_AFTER => '',
            GESHI_CLASS => 'nu0'
            ),
        // true, false
        10 => array(
            GESHI_SEARCH => '(?<![\w$%.\-])(?:true|false)(?![\w.\-])',
            GESHI_REPLACE => '\\0',
            GESHI_MODIFIERS => '',
            GESHI_BEFORE => '',
            GESHI_AFTER => '',
            GESHI_CLASS => 'nu0'
            ),
        ),
    'STRICT_MODE_APPLIES' => GESHI_NEVER,
    'SCRIPT_DELIMITERS' => array(
        ),
    'HIGHLIGHT_STRICT_BLOCK' => array(
        ),
    'PARSER_CONTROL' => array(
        'ENABLE_FLAGS' => array(
            'NUMBERS' => GESHI_ALWAYS,
            ),
        'KEYWORDS' => array(
            1 => array(
                'DISALLOWED_BEFORE' => $hex_stmt_before,
                'DISALLOWED_AFTER' => $hex_word_after,
                ),
            2 => array(
                'DISALLOWED_BEFORE' => $hex_stmt_before,
                'DISALLOWED_AFTER' => $hex_word_after,
                ),
            3 => array(
                'DISALLOWED_BEFORE' => '(?<=\$)',
                'DISALLOWED_AFTER' => '(?=\()',
                ),
            4 => array(
                'DISALLOWED_BEFORE' => '(?<=\$)',
                'DISALLOWED_AFTER' => '(?![\w(])(?!\.\w)',
                ),
            5 => array(
                'DISALLOWED_BEFORE' => $hex_event_before,
                'DISALLOWED_AFTER' => '(?=[\s:{]|$)',
                ),
            6 => array(
                'DISALLOWED_BEFORE' => '(?<=\s)',
                'DISALLOWED_AFTER' => '(?=\s)',
                ),
            ),
        ),
);
