<?php
/*
 * FILEHOST: lossless metadata stripping for uploaded images.
 *
 * Phones put GPS coordinates, device serials and timestamps in EXIF; an
 * IRC upload should not carry them.  The bytes of the picture itself are
 * left untouched: JPEG APP1..APP15 and COM segments, PNG ancillary text /
 * EXIF chunks and WebP EXIF / XMP chunks are removed, nothing is re-encoded
 * (a GD/Imagick round trip would be lossy and would drop animation).
 * Anything not understood is returned as it was.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */
declare(strict_types=1);

/**
 * Strip metadata from an image body when the type is one we know how to
 * handle losslessly.  Returns [body, stripped?].
 */
function filehost_strip_metadata(string $body, string $mime): array
{
    switch ($mime) {
        case 'image/jpeg':
            $out = filehost_strip_jpeg($body);
            break;
        case 'image/png':
            $out = filehost_strip_png($body);
            break;
        case 'image/webp':
            $out = filehost_strip_webp($body);
            break;
        default:
            return [$body, false];
    }
    return $out === null ? [$body, false] : [$out, $out !== $body];
}

/**
 * JPEG: drop APP1-APP15 (EXIF, XMP, ICC, Photoshop...) and COM segments.
 * APP0 (JFIF) and APP14 (Adobe colour transform) stay: decoders need them.
 */
function filehost_strip_jpeg(string $in): ?string
{
    $len = strlen($in);
    if ($len < 4 || substr($in, 0, 2) !== "\xFF\xD8") {
        return null;
    }
    $out = "\xFF\xD8";
    $pos = 2;
    while ($pos + 4 <= $len) {
        if ($in[$pos] !== "\xFF") {
            return null;                              /* not where a marker should be */
        }
        $marker = ord($in[$pos + 1]);
        if ($marker === 0xD8 || ($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
            $out .= substr($in, $pos, 2);             /* standalone markers */
            $pos += 2;
            continue;
        }
        if ($marker === 0xDA) {
            $out .= substr($in, $pos);                /* start of scan: the rest is image data */
            return $out;
        }
        $seglen = (ord($in[$pos + 2]) << 8) | ord($in[$pos + 3]);
        if ($seglen < 2 || $pos + 2 + $seglen > $len) {
            return null;
        }
        $keep = !(($marker >= 0xE1 && $marker <= 0xEF && $marker !== 0xEE) || $marker === 0xFE);
        if ($keep) {
            $out .= substr($in, $pos, 2 + $seglen);
        }
        $pos += 2 + $seglen;
    }
    return null;
}

/**
 * PNG: drop eXIf, tEXt, zTXt, iTXt, tIME and the private/ancillary chunks
 * that carry metadata; keep everything the decoder needs (including acTL /
 * fcTL / fdAT so APNG stays animated).
 */
function filehost_strip_png(string $in): ?string
{
    $sig = "\x89PNG\r\n\x1a\n";
    $len = strlen($in);
    if ($len < 8 || substr($in, 0, 8) !== $sig) {
        return null;
    }
    static $drop = ['eXIf' => 1, 'tEXt' => 1, 'zTXt' => 1, 'iTXt' => 1, 'tIME' => 1, 'dSIG' => 1];
    $out = $sig;
    $pos = 8;
    while ($pos + 12 <= $len) {
        $clen = unpack('N', substr($in, $pos, 4))[1];
        $type = substr($in, $pos + 4, 4);
        $total = 12 + $clen;
        if ($pos + $total > $len) {
            return null;
        }
        if (!isset($drop[$type])) {
            $out .= substr($in, $pos, $total);
        }
        $pos += $total;
        if ($type === 'IEND') {
            return $out;
        }
    }
    return null;
}

/**
 * WebP (RIFF): drop EXIF and XMP chunks and clear their flags in VP8X.
 * Animation (ANIM/ANMF) and the ICC profile are kept.
 */
function filehost_strip_webp(string $in): ?string
{
    $len = strlen($in);
    if ($len < 12 || substr($in, 0, 4) !== 'RIFF' || substr($in, 8, 4) !== 'WEBP') {
        return null;
    }
    $chunks = '';
    $pos = 12;
    while ($pos + 8 <= $len) {
        $type = substr($in, $pos, 4);
        $clen = unpack('V', substr($in, $pos + 4, 4))[1];
        $padded = $clen + ($clen & 1);
        if ($pos + 8 + $padded > $len) {
            return null;
        }
        $chunk = substr($in, $pos, 8 + $padded);
        if ($type === 'EXIF' || $type === 'XMP ') {
            /* dropped */
        } elseif ($type === 'VP8X' && $clen >= 1) {
            $flags = ord($chunk[8]) & ~0x0C;          /* clear EXIF (0x08) and XMP (0x04) */
            $chunk[8] = chr($flags);
            $chunks .= $chunk;
        } else {
            $chunks .= $chunk;
        }
        $pos += 8 + $padded;
    }
    return 'RIFF' . pack('V', 4 + strlen($chunks)) . 'WEBP' . $chunks;
}
