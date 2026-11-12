<?php
/*
 * Documentation Index
 * shows available docs
 *
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

// Check if requesting a specific doc
$doc = $_GET['doc'] ?? 'API';

// only allow alphanumeric doc names
$doc = preg_replace('/[^a-zA-Z0-9_-]/', '', $doc);

$doc_file = __DIR__ . '/' . $doc . '.md';

if (file_exists($doc_file)) {
    // for simplicity just serve the .md
    header('Content-Type: text/markdown; charset=utf-8');
    readfile($doc_file);
    exit;
}

// List available docs
header('Content-Type: text/html; charset=utf-8');
?>
