<?php
/*
 * Paste $v3.3 2025/10/24 https://github.com/boxlabss/PASTE
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See LICENCE for details.
 *
 *  This file is part of Paste.
 *  Helpers to enumerate languages for both highlight.php and GeSHi.
 *  We never execute grammar files; for highlight.php we read JSON metadata when available.
 *
 */

declare(strict_types=1);

require_once __DIR__ . '/hlbootstrap.php';

/* ------------------------------------------------------------
 * Active engine helper
 * ---------------------------------------------------------- */

function paste_current_engine(): string {
    $h = strtolower($GLOBALS['highlighter'] ?? 'geshi');
    return $h === 'highlight' ? 'highlight' : 'geshi';
}

/* ------------------------------------------------------------
 * highlight.php discovery + labels
 * ---------------------------------------------------------- */

// highlight.php languages directory
function highlight_lang_dir(): string {
    if (defined('HL_LANG_DIR') && is_dir(HL_LANG_DIR)) {
        return HL_LANG_DIR;
    }
    return __DIR__ . '/Highlight/languages';
}

// Label overrides for nicer display names (highlight.php ids)
function highlight_label_overrides(): array {
    return [
        '1c' => '1C','abnf' => 'ABNF','accesslog' => 'Access Log','actionscript' => 'ActionScript',
        'ada' => 'Ada','angelscript' => 'AngelScript','apache' => 'Apache','applescript' => 'AppleScript',
        'arcade' => 'Arcade','arduino' => 'Arduino','armasm' => 'ARM Assembly','aspectj' => 'AspectJ',
        'asciidoc' => 'AsciiDoc','autohotkey' => 'AutoHotkey','autoit' => 'AutoIt','avrasm' => 'AVR Assembly',
        'awk' => 'Awk','axapta' => 'Axapta','bash' => 'Bash','basic' => 'BASIC','bnf' => 'BNF',
        'brainfuck' => 'Brainfuck','cal' => 'C/AL',"capnproto" => "Cap'n Proto",'ceylon' => 'Ceylon',
        'clean' => 'Clean','clojure' => 'Clojure','clojure-repl' => 'Clojure REPL','cmake' => 'CMake',
        'coffeescript' => 'CoffeeScript','cos' => 'Caché Object Script','cpp' => 'C++','crmsh' => 'crmsh',
        'crystal' => 'Crystal','cs' => 'C#','csp' => 'CSP','css' => 'CSS','dart' => 'Dart','d' => 'D',
        'delphi' => 'Delphi (Object Pascal)','diff' => 'Diff','django' => 'Django','dns' => 'DNS Zone',
        'dockerfile' => 'Dockerfile','dos' => 'DOS/Batch','dts' => 'Device Tree (DTS)','dust' => 'Dust',
        'ebnf' => 'EBNF','dsconfig' => 'dsconfig','elixir' => 'Elixir','elm' => 'Elm','erb' => 'ERB',
        'erlang' => 'Erlang','erlang-repl' => 'Erlang REPL','excel' => 'Excel','fix' => 'FIX','flix' => 'Flix',
        'fortran' => 'Fortran','gams' => 'GAMS','gauss' => 'GAUSS','gcode' => 'G-code','gherkin' => 'Gherkin',
        'glsl' => 'GLSL','gml' => 'GML (GameMaker)','go' => 'Go','golo' => 'Golo','gradle' => 'Gradle',
        'groovy' => 'Groovy','haml' => 'Haml','handlebars' => 'Handlebars','haskell' => 'Haskell','haxe' => 'Haxe',
        'hsp' => 'HSP (Hot Soup Processor)','htmlbars' => 'HTMLBars','http' => 'HTTP','hy' => 'Hy',
        'inform7' => 'Inform 7','ini' => 'INI','irpf90' => 'IRPF90','isbl' => 'ISBL','java' => 'Java',
        'javascript' => 'JavaScript','jboss-cli' => 'JBoss CLI','json' => 'JSON','julia' => 'Julia',
        'julia-repl' => 'Julia REPL','kotlin' => 'Kotlin','lasso' => 'Lasso','leaf' => 'Leaf','less' => 'Less',
        'ldif' => 'LDIF','lisp' => 'Lisp','livecodeserver' => 'LiveCode Server','livescript' => 'LiveScript',
        'llvm' => 'LLVM IR','lsl' => 'LSL (Linden Scripting)','lua' => 'Lua','makefile' => 'Makefile',
        'mathematica' => 'Mathematica (Wolfram)','matlab' => 'MATLAB','maxima' => 'Maxima','mel' => 'MEL (Maya)',
        'mercury' => 'Mercury','mipsasm' => 'MIPS Assembly','mirc' => 'mIRC Scripting','mizar' => 'Mizar',
        'mojolicious' => 'Mojolicious','monkey' => 'Monkey','moonscript' => 'MoonScript','n1ql' => 'N1QL',
        'nginx' => 'Nginx','nimrod' => 'Nim (Nimrod)','nix' => 'Nix','nsis' => 'NSIS','objectivec' => 'Objective-C',
        'ocaml' => 'OCaml','openscad' => 'OpenSCAD','oxygene' => 'Oxygene','parser3' => 'Parser3','perl' => 'Perl',
        'pf' => 'PF (Packet Filter)','pgsql' => 'PostgreSQL','php' => 'PHP','plaintext' => 'Plain Text',
        'pony' => 'Pony','powershell' => 'PowerShell','processing' => 'Processing','profile' => 'Profile',
        'protobuf' => 'Protocol Buffers','properties' => 'Properties','prolog' => 'Prolog','puppet' => 'Puppet',
        'purebasic' => 'PureBasic','python' => 'Python','q' => 'Q (kdb+)','qml' => 'QML','r' => 'R',
        'reasonml' => 'ReasonML','rib' => 'RenderMan RIB','roboconf' => 'Roboconf',
        'rsl' => 'RenderMan Shader Language','routeros' => 'RouterOS (MikroTik)','ruby' => 'Ruby',
        'ruleslanguage' => 'Rules Language','rust' => 'Rust','sas' => 'SAS','scala' => 'Scala','scilab' => 'Scilab',
        'scheme' => 'Scheme','scss' => 'SCSS','shell' => 'Shell Session','smalltalk' => 'Smalltalk','smali' => 'Smali',
        'sml' => 'SML (Standard ML)','sqf' => 'SQF','sql' => 'SQL','stan' => 'Stan','stata' => 'Stata',
        'step21' => 'STEP Part 21','stylus' => 'Stylus','subunit' => 'SubUnit','swift' => 'Swift',
        'taggerscript' => 'TaggerScript','tap' => 'TAP','tcl' => 'TCL','tex' => 'TeX','thrift' => 'Thrift',
        'tp' => 'TP','twig' => 'Twig','typescrip' => 'TypeScript', 'typescript' => 'TypeScript','vala' => 'Vala',
        'vbnet' => 'VB.NET','vbscript' => 'VBScript','vbscript-html' => 'VBScript (HTML)','vhdl' => 'VHDL',
        'verilog' => 'Verilog','vim' => 'Vim Script','x86asm' => 'x86 Assembly','xl' => 'XL','xml' => 'HTML/XML',
        'xquery' => 'XQuery','yaml' => 'YAML','zephir' => 'Zephir',
    ];
}

// Build a nice label from an id, honouring overrides.
function highlight_build_label(string $id): string {
    static $over = null;
    $over ??= highlight_label_overrides();
    if (isset($over[$id])) return $over[$id];

    $t = str_replace(['-', '_'], ' ', $id);
    $t = ucwords($t);
    $t = preg_replace('/\bSql\b/i','SQL',$t);
    $t = preg_replace('/\bJson\b/i','JSON',$t);
    $t = preg_replace('/\bYaml\b/i','YAML',$t);
    $t = preg_replace('/\bXml\b/i','XML',$t);
    return $t;
}

/**
 * Discover highlight.php languages. Reads JSON grammar metadata when present.
 * Returns list: [['id','name','filename','aliases'=>[]], ...]
 */
function highlight_supported_languages(): array {
    $dir = highlight_lang_dir();
    if (!is_dir($dir)) return [];

    $files = glob($dir . '/*.{php,json}', GLOB_BRACE) ?: [];
    $out = [];

    foreach ($files as $f) {
        $id      = pathinfo($f, PATHINFO_FILENAME);
        $name    = null;
        $aliases = [];

        if (str_ends_with(strtolower($f), '.json')) {
            $raw = @file_get_contents($f);
            if ($raw !== false) {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    if (!empty($data['name']) && is_string($data['name'])) $name = $data['name'];
                    if (!empty($data['aliases']) && is_array($data['aliases'])) {
                        $aliases = array_values(array_filter(array_map('strval', $data['aliases'])));
                    }
                }
            }
        }

        $out[] = [
            'id'       => $id,
            'name'     => $name ?: highlight_build_label($id),
            'filename' => basename($f),
            'aliases'  => $aliases,
        ];
    }

    usort($out, static function($a,$b){
        $c = strcasecmp($a['name'],$b['name']);
        return $c !== 0 ? $c : strcasecmp($a['id'],$b['id']);
    });
    return $out;
}

// id => label for highlight.php (adds Autodetect, Markdown, Text; hides 'plaintext')
function highlight_language_map(array $langs): array {
    $map = [
        'autodetect' => 'Autodetect Language',
        'markdown'   => 'Markdown',
        'text'       => 'Plain Text',
    ];
    foreach ($langs as $L) {
        $id = strtolower($L['id']);
        if ($id === 'plaintext') continue; // expose as 'text'
        $map[$id] = $L['name'];
    }
    return $map;
}

// alias => id for highlight.php (uses JSON aliases, maps text->plaintext)
function highlight_alias_map(array $langs): array {
    $alias = [
        'auto'       => 'autodetect',
        'autodetect' => 'autodetect',
        'text'       => 'plaintext',
    ];
    foreach ($langs as $L) {
        $id = strtolower($L['id']);
        $alias[$id] = $id;
        foreach ((array)($L['aliases'] ?? []) as $a) {
            $alias[strtolower($a)] = $id;
        }
    }
    return $alias;
}

/* ------------------------------------------------------------
 * GeSHi labels
 * ---------------------------------------------------------- */

// Full id => label map for GeSHi (classic list).
function geshi_language_map(): array {
    return [
        '4cs'=>'GADV 4CS','6502acme'=>'ACME Cross Assembler','6502kickass'=>'Kick Assembler','6502tasm'=>'TASM/64TASS 1.46',
        '68000devpac'=>'HiSoft Devpac ST 2','abap'=>'ABAP','actionscript'=>'ActionScript','actionscript3'=>'ActionScript 3',
        'ada'=>'Ada','aimms'=>'AIMMS3','algol68'=>'ALGOL 68','apache'=>'Apache','applescript'=>'AppleScript','arm'=>'ARM Assembler',
        'asm'=>'ASM','asp'=>'ASP','asymptote'=>'Asymptote','autoconf'=>'Autoconf','autohotkey'=>'Autohotkey','autoit'=>'AutoIt',
        'avisynth'=>'AviSynth','awk'=>'Awk','bascomavr'=>'BASCOM AVR','bash'=>'BASH','basic4gl'=>'Basic4GL','bf'=>'Brainfuck',
        'bibtex'=>'BibTeX','blitzbasic'=>'BlitzBasic','bnf'=>'BNF','boo'=>'Boo','c'=>'C','c_loadrunner'=>'C (LoadRunner)',
        'c_mac'=>'C for Macs','c_winapi'=>'C (WinAPI)','caddcl'=>'CAD DCL','cadlisp'=>'CAD Lisp','cfdg'=>'CFDG','cfm'=>'ColdFusion',
        'chaiscript'=>'ChaiScript','chapel'=>'Chapel','cil'=>'CIL','clojure'=>'Clojure','cmake'=>'CMake','cobol'=>'COBOL',
        'coffeescript'=>'CoffeeScript','cpp'=>'C++','cpp-qt'=>'C++ (with QT extensions)','cpp-winapi'=>'C++ (WinAPI)','csharp'=>'C#',
        'css'=>'CSS','cuesheet'=>'Cuesheet','d'=>'D','dcl'=>'DCL','dcpu16'=>'DCPU-16 Assembly','dcs'=>'DCS','delphi'=>'Delphi',
        'diff'=>'Diff-output','div'=>'DIV','dos'=>'DOS','dot'=>'dot','e'=>'E','ecmascript'=>'ECMAScript','eiffel'=>'Eiffel',
        'email'=>'eMail (mbox)','epc'=>'EPC','erlang'=>'Erlang','euphoria'=>'Euphoria','ezt'=>'EZT','f1'=>'Formula One','falcon'=>'Falcon',
        'fo'=>'FO (abas-ERP)','fortran'=>'Fortran','freebasic'=>'FreeBasic','fsharp'=>'F#','gambas'=>'GAMBAS','gdb'=>'GDB','genero'=>'Genero',
        'genie'=>'Genie','gettext'=>'GNU Gettext','glsl'=>'glSlang','gml'=>'GML','gnuplot'=>'GNUPlot','go'=>'Go','groovy'=>'Groovy',
        'gwbasic'=>'GwBasic','haskell'=>'Haskell','haxe'=>'Haxe','hicest'=>'HicEst','hq9plus'=>'HQ9+','html4strict'=>'HTML 4.01','html5'=>'HTML 5',
        'icon'=>'Icon','idl'=>'Uno Idl','ini'=>'INI','inno'=>'Inno Script','intercal'=>'INTERCAL','io'=>'IO','ispfpanel'=>'ISPF Panel','j'=>'J',
        'java'=>'Java','java5'=>'Java 5','javascript'=>'JavaScript','jcl'=>'JCL','jquery'=>'jQuery','kixtart'=>'KiXtart','klonec'=>'KLone C',
        'klonecpp'=>'KLone C++','latex'=>'LaTeX','lb'=>'Liberty BASIC','ldif'=>'LDIF','lisp'=>'Lisp','llvm'=>'LLVM','locobasic'=>'Locomotive Basic',
        'logtalk'=>'Logtalk','lolcode'=>'LOLcode','lotusformulas'=>'Lotus Notes @Formulas','lotusscript'=>'LotusScript','lscript'=>'Lightwave Script',
        'lsl2'=>'Linden Script','lua'=>'LUA','m68k'=>'Motorola 68000 Assembler','magiksf'=>'MagikSF','make'=>'GNU make','mapbasic'=>'MapBasic',
        'markdown'=>'Markdown','matlab'=>'Matlab M','mirc'=>'mIRC Scripting','mmix'=>'MMIX','modula2'=>'Modula-2','modula3'=>'Modula-3',
        'mpasm'=>'Microchip Assembler','mxml'=>'MXML','mysql'=>'MySQL','nagios'=>'Nagios','netrexx'=>'NetRexx','newlisp'=>'NewLisp','nginx'=>'Nginx',
        'nsis'=>'NSIS','oberon2'=>'Oberon-2','objc'=>'Objective-C','objeck'=>'Objeck','ocaml'=>'Ocaml','ocaml-brief'=>'OCaml (Brief)',
        'octave'=>'GNU/Octave','oobas'=>'OpenOffice.org Basic','oorexx'=>'ooRexx','oracle11'=>'Oracle 11 SQL','oracle8'=>'Oracle 8 SQL',
        'oxygene'=>'Oxygene (Delphi Prism)','oz'=>'OZ','parasail'=>'ParaSail','parigp'=>'PARI/GP','pascal'=>'Pascal','pcre'=>'PCRE','per'=>'Per (forms)',
        'perl'=>'Perl','perl6'=>'Perl 6','pf'=>'OpenBSD Packet Filter','php'=>'PHP','php-brief'=>'PHP (Brief)','pic16'=>'PIC16 Assembler','pike'=>'Pike',
        'pixelbender'=>'Pixel Bender','pli'=>'PL/I','plsql'=>'PL/SQL','postgresql'=>'PostgreSQL','povray'=>'POVRAY','powerbuilder'=>'PowerBuilder',
        'powershell'=>'PowerShell','proftpd'=>'ProFTPd config','progress'=>'Progress','prolog'=>'Prolog','properties'=>'Properties','providex'=>'ProvideX',
        'purebasic'=>'PureBasic','pycon'=>'Python (console mode)','pys60'=>'Python for S60','python'=>'Python','qbasic'=>'QuickBASIC','racket'=>'Racket',
        'rails'=>'Ruby on Rails','rbs'=>'RBScript','rebol'=>'REBOL','reg'=>'Microsoft REGEDIT','rexx'=>'Rexx','robots'=>'robots.txt',
        'rpmspec'=>'RPM Specification File','rsplus'=>'R / S+','ruby'=>'Ruby','sas'=>'SAS','scala'=>'Scala','scheme'=>'Scheme','scilab'=>'SciLab',
        'scl'=>'SCL','sdlbasic'=>'sdlBasic','smalltalk'=>'Smalltalk','smarty'=>'Smarty','spark'=>'SPARK','sparql'=>'SPARQL','sql'=>'SQL',
        'stonescript'=>'StoneScript','systemverilog'=>'SystemVerilog','tcl'=>'TCL','teraterm'=>'Tera Term Macro','text'=>'Plain Text','thinbasic'=>'thinBasic',
        'tsql'=>'T-SQL','typoscript'=>'TypoScript','unicon'=>'Unicon','upc'=>'UPC','urbi'=>'Urbi','unrealscript'=>'Unreal Script','vala'=>'Vala',
        'vb'=>'Visual Basic','vbnet'=>'VB.NET','vbscript'=>'VB Script','vedit'=>'Vedit Macro','verilog'=>'Verilog','vhdl'=>'VHDL','vim'=>'Vim',
        'visualfoxpro'=>'Visual FoxPro','visualprolog'=>'Visual Prolog','whitespace'=>'Whitespace','whois'=>'WHOIS (RPSL format)','winbatch'=>'WinBatch',
        'xbasic'=>'XBasic','xml'=>'XML','xorg_conf'=>'Xorg Config','xpp'=>'X++','yaml'=>'YAML','z80'=>'ZiLOG Z80 Assembler','zxbasic'=>'ZXBasic'
    ];
}

// alias => id for GeSHi (identity + common extensions + convenience)
function geshi_alias_map(array $geshi_map): array {
    $alias = [
        'auto' => 'autodetect','autodetect' => 'autodetect',
        'text' => 'text',
        // common file extensions / shorthands
        'md'=>'markdown','mkd'=>'markdown','mkdown'=>'markdown','mdown'=>'markdown',
        'yml'=>'yaml','htm'=>'html5','html'=>'html5','xhtml'=>'html5',
        'js'=>'javascript','es6'=>'ecmascript','ts'=>'javascript', // TS closest fit
        'ps1'=>'powershell','pwsh'=>'powershell',
        'pgsql'=>'postgresql','postgres'=>'postgresql',
        'sh'=>'bash','zsh'=>'bash','ksh'=>'bash','dash'=>'bash','csh'=>'bash','tcsh'=>'bash','shell'=>'bash','shellscript'=>'bash',
        'pl'=>'perl','rb'=>'ruby','py'=>'python','r'=>'rsplus','rscript'=>'rsplus',
        'x86asm'=>'asm','nasm'=>'asm','masm'=>'asm',
        'mrc'=>'mirc',
    ];
    // identities for available ids
    foreach (array_keys($geshi_map) as $id) {
        $alias[strtolower($id)] = strtolower($id);
    }
    // keep only aliases that point to available ids
    $available = array_change_key_case($geshi_map, CASE_LOWER);
    foreach ($alias as $a => $id) {
        if (!isset($available[$id])) unset($alias[$a]);
    }
    return $alias;
}

/* ------------------------------------------------------------
 * Popular subsets
 * ---------------------------------------------------------- */

function paste_popular_formats_highlight(): array {
    return [
        'autodetect','markdown','text','xml','css','javascript','json','yaml','php','python','sql','pgsql',
        'java','c','csharp','cpp','bash','go','ruby','rust','typescript','kotlin',
    ];
}

function paste_popular_formats_geshi(): array {
    return [
        'autodetect','markdown','text','html4strict','html5','css','javascript','php','perl',
        'python','postgresql','sql','xml','java','c','csharp','cpp',
    ];
}
?>