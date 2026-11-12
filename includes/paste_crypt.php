<?php
/*
 * Paste https://github.com/boxlabss/PASTE
 *
 * Server-side encryption of stored pastes.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

declare(strict_types=1);

// Encrypt pastes with AES-256-CBC from our randomly generated $sec_key
function encrypt(string $value, string $sec_key): string
{
    $cipher = "AES-256-CBC";
    $ivlen = openssl_cipher_iv_length($cipher);
    $iv = openssl_random_pseudo_bytes($ivlen);
    $encrypted = openssl_encrypt($value, $cipher, $sec_key, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) {
        throw new RuntimeException('Encryption failed.');
    }
    $hmac = hash_hmac('sha256', $encrypted, $sec_key, true);
    return base64_encode($iv . $hmac . $encrypted);
}

function decrypt(string $value, string $sec_key): ?string
{
    $decoded = base64_decode($value, true);
    if ($decoded === false) {
        return null;
    }
    $cipher = "AES-256-CBC";
    $ivlen = openssl_cipher_iv_length($cipher);
    $sha256len = 32;
    if (strlen($decoded) < $ivlen + $sha256len) {
        return null;
    }
    $iv = substr($decoded, 0, $ivlen);
    $hmac = substr($decoded, $ivlen, $sha256len);
    $encrypted = substr($decoded, $ivlen + $sha256len);
    $calculated_hmac = hash_hmac('sha256', $encrypted, $sec_key, true);
    if (!hash_equals($hmac, $calculated_hmac)) {
        return null;
    }
    $decrypted = openssl_decrypt($encrypted, $cipher, $sec_key, OPENSSL_RAW_DATA, $iv);
    return $decrypted !== false ? $decrypted : null;
}
