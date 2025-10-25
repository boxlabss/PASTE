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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License in LICENCE for more details.
 *
 *	This file is part of Paste.
 *	Bootstrap for scrivo/highlight.php
 *	Works with either layout inside /includes/Highlight:
 *	Repo root https://github.com/scrivo/highlight.php/tree/master/src/Highlight/ copied here:
 *		/includes/Highlight
 */

declare(strict_types=1);

// Determine where the Highlight library actually lives
if (!defined('HL_LIB_DIR')) {
    $candidate = __DIR__ . '/Highlight';
    define('HL_LIB_DIR', is_dir($candidate) ? $candidate : __DIR__);
}
// Back-compat alias used below
if (!defined('HL_BASE_DIR')) {
    define('HL_BASE_DIR', HL_LIB_DIR);
}

// ---------- Autoloader ----------
$autoloader_found = false;
foreach ([
    HL_LIB_DIR . '/Autoloader.php',            // preferred: includes/Highlight/Autoloader.php
    HL_LIB_DIR . '/Highlight/Autoloader.php',  // fallback for nested copies
] as $al) {
    if (is_file($al)) {
        require_once $al;
        if (class_exists('\Highlight\Autoloader')) {
            \Highlight\Autoloader::register();
            $autoloader_found = true;
            break;
        }
    }
}

if (!$autoloader_found) {
    // Minimal PSR-4 fallback for \Highlight\*
    $classRoots = [
        HL_LIB_DIR,    // e.g. includes/Highlight
        __DIR__,       // e.g. includes (in case files are flattened)
    ];
    spl_autoload_register(static function ($class) use ($classRoots) {
        if (strpos($class, 'Highlight\\') !== 0) return;
        $rel = str_replace('\\', '/', $class) . '.php'; // Highlight/Highlighter.php
        foreach ($classRoots as $root) {
            // Try flat file name (Highlighter.php), then nested (Highlight/Highlighter.php)
            $p1 = $root . '/' . basename($rel);
            if (is_file($p1)) { require $p1; return; }
            $p2 = $root . '/' . $rel;
            if (is_file($p2)) { require $p2; return; }
        }
    });
}

// ---------- Languages directory ----------
if (!defined('HL_LANG_DIR')) {
    foreach ([
        HL_LIB_DIR . '/languages',         // includes/Highlight/languages  (usual)
        __DIR__ . '/Highlight/languages',  // if HL_LIB_DIR was __DIR__
        __DIR__ . '/languages',            // very old/flat layout
    ] as $d) {
        if (is_dir($d)) { define('HL_LANG_DIR', $d); break; }
    }
    if (!defined('HL_LANG_DIR')) {
        // define something harmless; Highlighter will fail gracefully
        define('HL_LANG_DIR', HL_LIB_DIR . '/languages');
    }
}

// ---------- Factory ----------
function make_highlighter(): ?\Highlight\Highlighter {
    if (!class_exists('\Highlight\Highlighter')) return null;

    // Prefer LanguageFactory if available so we can point to HL_LANG_DIR
    if (class_exists('\Highlight\LanguageFactory')) {
        $factory = new \Highlight\LanguageFactory(HL_LANG_DIR);

        // Try setter style (newer versions)
        try {
            $hl = new \Highlight\Highlighter();
            if (method_exists($hl, 'setLanguageFactory')) {
                $hl->setLanguageFactory($factory);
                return $hl;
            }
        } catch (\Throwable $e) {
            // fall through
        }

        // Older constructor style
        try {
            return new \Highlight\Highlighter($factory);
        } catch (\Throwable $e) {
            // fall through
        }
    }

    // Last resort: plain instance (will use default languages)
    try {
        return new \Highlight\Highlighter();
    } catch (\Throwable $e) {
        return null;
    }
}