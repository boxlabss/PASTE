<?php
/*************************************************************************************
 * mips.php
 * ------
 * Copyright: (c) 2026 Paste (https://github.com/boxlabss/PASTE)
 * Release Version: 1.0.9.1
 *
 * MIPS assembly (MIPS32 with the MARS and SPIM pseudo-instructions) language file for GeSHi.
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
 ************************************************************************************/

$language_data = array (
    'LANG_NAME' => 'MIPS Assembly',
    'COMMENT_SINGLE' => array(1 => '#', 2 => '//'),
    'COMMENT_MULTI' => array('/*' => '*/'),
    'CASE_KEYWORDS' => GESHI_CAPS_NO_CHANGE,
    'QUOTEMARKS' => array("'", '"'),
    'ESCAPE_CHAR' => '\\',
    'KEYWORDS' => array(
        /* Instructions */
        1 => array(
            'add', 'addi', 'addiu', 'addu', 'and', 'andi', 'beq', 'bgez',
            'bgezal', 'bgtz', 'blez', 'bltz', 'bltzal', 'bne', 'break', 'clo',
            'clz', 'div', 'divu', 'eret', 'j', 'jal', 'jalr', 'jr',
            'lb', 'lbu', 'lh', 'lhu', 'll', 'lui', 'lw', 'lwl',
            'lwr', 'madd', 'maddu', 'mfhi', 'mflo', 'movn', 'movz', 'msub',
            'msubu', 'mthi', 'mtlo', 'mul', 'mult', 'multu', 'nop', 'nor',
            'or', 'ori', 'sb', 'sc', 'sh', 'sll', 'sllv', 'slt',
            'slti', 'sltiu', 'sltu', 'sra', 'srav', 'srl', 'srlv', 'sub',
            'subu', 'sw', 'swl', 'swr', 'sync', 'syscall', 'teq', 'tne',
            'xor', 'xori'
            ),
        /* Pseudo-instructions */
        2 => array(
            'abs', 'b', 'bal', 'beqz', 'bge', 'bgeu', 'bgt', 'bgtu',
            'ble', 'bleu', 'blt', 'bltu', 'bnez', 'la', 'li', 'move',
            'neg', 'negu', 'not', 'rem', 'remu', 'seq', 'sge', 'sgt',
            'sle', 'sne', 'ulw', 'usw'
            ),
        /* Floating point and coprocessor instructions */
        3 => array(
            'abs.d', 'abs.s', 'add.d', 'add.s', 'bc1f', 'bc1t', 'c.eq.d', 'c.eq.s',
            'c.le.d', 'c.le.s', 'c.lt.d', 'c.lt.s', 'cvt.d.s', 'cvt.d.w', 'cvt.s.d', 'cvt.s.w',
            'cvt.w.d', 'cvt.w.s', 'div.d', 'div.s', 'ldc1', 'lwc1', 'mfc0', 'mfc1',
            'mov.d', 'mov.s', 'mtc0', 'mtc1', 'mul.d', 'mul.s', 'neg.d', 'neg.s',
            'sdc1', 'sqrt.d', 'sqrt.s', 'sub.d', 'sub.s', 'swc1'
            ),
        ),
    'SYMBOLS' => array(
        0 => array('(', ')', ',', '+', '-', '*', '/', '%', ':')
        ),
    'CASE_SENSITIVE' => array(
        GESHI_COMMENTS => false,
        1 => false,
        2 => false,
        3 => false,
        ),
    'STYLES' => array(
        'KEYWORDS' => array(
            1 => 'color: #00007f; font-weight: bold;',
            2 => 'color: #0000ff;',
            3 => 'color: #00007f;',
            ),
        'COMMENTS' => array(
            1 => 'color: #666666; font-style: italic;',
            2 => 'color: #666666; font-style: italic;',
            'MULTI' => 'color: #666666; font-style: italic;'
            ),
        'ESCAPE_CHAR' => array(
            0 => 'color: #000099; font-weight: bold;'
            ),
        'BRACKETS' => array(
            0 => 'color: #009900; font-weight: bold;'
            ),
        'STRINGS' => array(
            0 => 'color: #7f007f;'
            ),
        'NUMBERS' => array(
            0 => 'color: #ff0000;'
            ),
        'METHODS' => array(
            ),
        'SYMBOLS' => array(
            0 => 'color: #339933;'
            ),
        'REGEXPS' => array(
            0 => 'color: #46aa03; font-weight: bold;',
            1 => 'color: #800080;',
            2 => 'color: #b00040;'
            ),
        'SCRIPT' => array(
            )
        ),
    'URLS' => array(
        1 => '',
        2 => '',
        3 => '',
        ),
    'NUMBERS' =>
        GESHI_NUMBER_INT_BASIC |
        GESHI_NUMBER_HEX_PREFIX |
        GESHI_NUMBER_BIN_PREFIX_0B,
    'OOLANG' => false,
    'OBJECT_SPLITTERS' => array(
        ),
    'REGEXPS' => array(
        /* Labels */
        0 => array(
            GESHI_SEARCH => '(^|\n)([ \t]*)([A-Za-z_.$][A-Za-z0-9_.$]*)(:)',
            GESHI_REPLACE => '\\3',
            GESHI_MODIFIERS => '',
            GESHI_BEFORE => '\\1\\2',
            GESHI_AFTER => '\\4'
            ),
        /* Directives */
        1 => '(?<![A-Za-z0-9_])\.(?:end_macro|include|global|extern|double|asciiz|kdata|ktext|globl|float|ascii|space|align|macro|text|data|word|half|byte|set|ent|end|eqv)(?![A-Za-z0-9_])',
        /* Registers */
        2 => '\\$(?:zero|at|v[01]|a[0-3]|t[0-9]|s[0-8]|k[01]|gp|sp|fp|ra|f?[12]?[0-9]|f?3[01])(?![A-Za-z0-9_])',
        ),
    'STRICT_MODE_APPLIES' => GESHI_NEVER,
    'SCRIPT_DELIMITERS' => array(
        ),
    'HIGHLIGHT_STRICT_BLOCK' => array(
        ),
    'TAB_WIDTH' => 8,
    'PARSER_CONTROL' => array(
        'KEYWORDS' => array(
            'DISALLOWED_BEFORE' => "(?<![a-zA-Z0-9\$_\.])",
            'DISALLOWED_AFTER' => "(?![a-zA-Z0-9_\.])"
        )
    )
);
