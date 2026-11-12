<?php
/*************************************************************************************
 * riscv.php
 * ------
 * Copyright: (c) 2026 Paste (https://github.com/boxlabss/PASTE)
 * Release Version: 1.0.9.1
 *
 * RISC-V assembly (RV32/RV64 base, M, A, F, D and Zicsr, with the standard pseudo-instructions) language file for GeSHi.
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
    'LANG_NAME' => 'RISC-V Assembly',
    'COMMENT_SINGLE' => array(1 => '#', 2 => '//'),
    'COMMENT_MULTI' => array('/*' => '*/'),
    'CASE_KEYWORDS' => GESHI_CAPS_NO_CHANGE,
    'QUOTEMARKS' => array("'", '"'),
    'ESCAPE_CHAR' => '\\',
    'KEYWORDS' => array(
        /* Base, multiply and atomic instructions */
        1 => array(
            'add', 'addi', 'addiw', 'addw', 'amoadd.d', 'amoadd.d.aq', 'amoadd.d.aqrl', 'amoadd.d.rl',
            'amoadd.w', 'amoadd.w.aq', 'amoadd.w.aqrl', 'amoadd.w.rl', 'amoand.d', 'amoand.d.aq', 'amoand.d.aqrl', 'amoand.d.rl',
            'amoand.w', 'amoand.w.aq', 'amoand.w.aqrl', 'amoand.w.rl', 'amomax.d', 'amomax.d.aq', 'amomax.d.aqrl', 'amomax.d.rl',
            'amomax.w', 'amomax.w.aq', 'amomax.w.aqrl', 'amomax.w.rl', 'amomaxu.d', 'amomaxu.d.aq', 'amomaxu.d.aqrl', 'amomaxu.d.rl',
            'amomaxu.w', 'amomaxu.w.aq', 'amomaxu.w.aqrl', 'amomaxu.w.rl', 'amomin.d', 'amomin.d.aq', 'amomin.d.aqrl', 'amomin.d.rl',
            'amomin.w', 'amomin.w.aq', 'amomin.w.aqrl', 'amomin.w.rl', 'amominu.d', 'amominu.d.aq', 'amominu.d.aqrl', 'amominu.d.rl',
            'amominu.w', 'amominu.w.aq', 'amominu.w.aqrl', 'amominu.w.rl', 'amoor.d', 'amoor.d.aq', 'amoor.d.aqrl', 'amoor.d.rl',
            'amoor.w', 'amoor.w.aq', 'amoor.w.aqrl', 'amoor.w.rl', 'amoswap.d', 'amoswap.d.aq', 'amoswap.d.aqrl', 'amoswap.d.rl',
            'amoswap.w', 'amoswap.w.aq', 'amoswap.w.aqrl', 'amoswap.w.rl', 'amoxor.d', 'amoxor.d.aq', 'amoxor.d.aqrl', 'amoxor.d.rl',
            'amoxor.w', 'amoxor.w.aq', 'amoxor.w.aqrl', 'amoxor.w.rl', 'and', 'andi', 'auipc', 'beq',
            'bge', 'bgeu', 'blt', 'bltu', 'bne', 'div', 'divu', 'divuw',
            'divw', 'jal', 'jalr', 'lb', 'lbu', 'ld', 'lh', 'lhu',
            'lr.d', 'lr.d.aq', 'lr.d.aqrl', 'lr.d.rl', 'lr.w', 'lr.w.aq', 'lr.w.aqrl', 'lr.w.rl',
            'lui', 'lw', 'lwu', 'mul', 'mulh', 'mulhsu', 'mulhu', 'mulw',
            'or', 'ori', 'rem', 'remu', 'remuw', 'remw', 'sb', 'sc.d',
            'sc.d.aq', 'sc.d.aqrl', 'sc.d.rl', 'sc.w', 'sc.w.aq', 'sc.w.aqrl', 'sc.w.rl', 'sd',
            'sh', 'sll', 'slli', 'slliw', 'sllw', 'slt', 'slti', 'sltiu',
            'sltu', 'sra', 'srai', 'sraiw', 'sraw', 'srl', 'srli', 'srliw',
            'srlw', 'sub', 'subw', 'sw', 'xor', 'xori'
            ),
        /* Pseudo-instructions */
        2 => array(
            'beqz', 'bgez', 'bgt', 'bgtu', 'bgtz', 'ble', 'bleu', 'blez',
            'bltz', 'bnez', 'call', 'csrc', 'csrci', 'csrr', 'csrs', 'csrsi',
            'csrw', 'csrwi', 'fabs.d', 'fabs.s', 'fmv.d', 'fmv.s', 'fneg.d', 'fneg.s',
            'frcsr', 'frflags', 'frrm', 'fscsr', 'fsflags', 'fsrm', 'j', 'jr',
            'la', 'li', 'lla', 'mv', 'neg', 'negw', 'nop', 'not',
            'rdcycle', 'rdcycleh', 'rdinstret', 'rdinstreth', 'rdtime', 'rdtimeh', 'ret', 'seqz',
            'sext.w', 'sgtz', 'sltz', 'snez', 'tail', 'zext.b'
            ),
        /* Floating point, CSR and system instructions */
        3 => array(
            'csrrc', 'csrrci', 'csrrs', 'csrrsi', 'csrrw', 'csrrwi', 'ebreak', 'ecall',
            'fadd.d', 'fadd.s', 'fclass.d', 'fclass.s', 'fcvt.d.l', 'fcvt.d.lu', 'fcvt.d.s', 'fcvt.d.w',
            'fcvt.d.wu', 'fcvt.l.d', 'fcvt.l.s', 'fcvt.lu.d', 'fcvt.lu.s', 'fcvt.s.d', 'fcvt.s.l', 'fcvt.s.lu',
            'fcvt.s.w', 'fcvt.s.wu', 'fcvt.w.d', 'fcvt.w.s', 'fcvt.wu.d', 'fcvt.wu.s', 'fdiv.d', 'fdiv.s',
            'fence', 'fence.i', 'fence.tso', 'feq.d', 'feq.s', 'fld', 'fle.d', 'fle.s',
            'flt.d', 'flt.s', 'flw', 'fmadd.d', 'fmadd.s', 'fmax.d', 'fmax.s', 'fmin.d',
            'fmin.s', 'fmsub.d', 'fmsub.s', 'fmul.d', 'fmul.s', 'fmv.d.x', 'fmv.w.x', 'fmv.x.d',
            'fmv.x.w', 'fnmadd.d', 'fnmadd.s', 'fnmsub.d', 'fnmsub.s', 'fsd', 'fsgnj.d', 'fsgnj.s',
            'fsgnjn.d', 'fsgnjn.s', 'fsgnjx.d', 'fsgnjx.s', 'fsqrt.d', 'fsqrt.s', 'fsub.d', 'fsub.s',
            'fsw', 'mret', 'sfence.vma', 'sret', 'uret', 'wfi'
            ),
        /* Registers */
        4 => array(
            'a0', 'a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7',
            'f0', 'f1', 'f10', 'f11', 'f12', 'f13', 'f14', 'f15',
            'f16', 'f17', 'f18', 'f19', 'f2', 'f20', 'f21', 'f22',
            'f23', 'f24', 'f25', 'f26', 'f27', 'f28', 'f29', 'f3',
            'f30', 'f31', 'f4', 'f5', 'f6', 'f7', 'f8', 'f9',
            'fa0', 'fa1', 'fa2', 'fa3', 'fa4', 'fa5', 'fa6', 'fa7',
            'fp', 'fs0', 'fs1', 'fs10', 'fs11', 'fs2', 'fs3', 'fs4',
            'fs5', 'fs6', 'fs7', 'fs8', 'fs9', 'ft0', 'ft1', 'ft10',
            'ft11', 'ft2', 'ft3', 'ft4', 'ft5', 'ft6', 'ft7', 'ft8',
            'ft9', 'gp', 'pc', 'ra', 's0', 's1', 's10', 's11',
            's2', 's3', 's4', 's5', 's6', 's7', 's8', 's9',
            'sp', 't0', 't1', 't2', 't3', 't4', 't5', 't6',
            'tp', 'x0', 'x1', 'x10', 'x11', 'x12', 'x13', 'x14',
            'x15', 'x16', 'x17', 'x18', 'x19', 'x2', 'x20', 'x21',
            'x22', 'x23', 'x24', 'x25', 'x26', 'x27', 'x28', 'x29',
            'x3', 'x30', 'x31', 'x4', 'x5', 'x6', 'x7', 'x8',
            'x9', 'zero'
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
        4 => false,
        ),
    'STYLES' => array(
        'KEYWORDS' => array(
            1 => 'color: #00007f; font-weight: bold;',
            2 => 'color: #0000ff;',
            3 => 'color: #00007f;',
            4 => 'color: #b00040;',
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
        4 => '',
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
        1 => '(?<![A-Za-z0-9_])\.(?:attribute|end_macro|section|p2align|include|rodata|global|balign|string|option|extern|double|globl|local|align|dword|asciz|ascii|space|macro|ident|lcomm|endif|float|text|data|weak|word|half|byte|quad|zero|endm|type|size|file|comm|else|data|bss|equ|set|eqv|if)(?![A-Za-z0-9_])',
        /* Registers */
        2 => '%(?:hi|lo|pcrel_hi|pcrel_lo|tprel_hi|tprel_lo|tprel_add|got_pcrel_hi)(?=\\()',
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
