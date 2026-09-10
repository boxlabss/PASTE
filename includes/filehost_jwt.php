<?php
/*
 * FILEHOST: verification of IRCv3 draft/authtoken JWTs (ES256).
 *
 * The IRC server signs a compact JWT per upload request; this file checks
 * it against the network's public key (PEM, from the ircd's STATS
 * authtoken) and the expected issuer / audience.  No dependencies beyond
 * ext/openssl.  See docs/filehost.md.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */
declare(strict_types=1);

/**
 * Decode unpadded base64url.  Returns null on malformed input.
 */
function filehost_b64url_decode(string $s): ?string
{
    if ($s === '' || preg_match('/[^A-Za-z0-9_-]/', $s)) {
        return null;
    }
    $pad = strlen($s) % 4;
    if ($pad === 1) {
        return null;
    }
    if ($pad) {
        $s .= str_repeat('=', 4 - $pad);
    }
    $out = base64_decode(strtr($s, '-_', '+/'), true);
    return $out === false ? null : $out;
}

/**
 * Wrap a 64-byte r||s ECDSA signature as DER for openssl_verify().
 */
function filehost_ecdsa_raw_to_der(string $raw): ?string
{
    if (strlen($raw) !== 64) {
        return null;
    }
    $int = static function (string $n): string {
        $n = ltrim($n, "\x00");
        if ($n === '' || (ord($n[0]) & 0x80)) {
            $n = "\x00" . $n;
        }
        return "\x02" . chr(strlen($n)) . $n;
    };
    $body = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
    return "\x30" . chr(strlen($body)) . $body;
}

/**
 * Verify an ES256 authtoken JWT.
 *
 * @param string $jwt   compact JWT from the Authorization: Bearer header
 * @param string $pem   public key (SPKI PEM)
 * @param string $iss   expected issuer (the IRC network name)
 * @param string $aud   expected audience (this endpoint's URL, byte for byte)
 * @param int    $now   current unix time
 * @param int    $skew  tolerated clock skew in seconds
 * @return array|string the claims array on success, else an error code:
 *         malformed | alg | signature | issuer | audience | expired |
 *         not_yet_valid | claims
 */
function filehost_verify_jwt(string $jwt, string $pem, string $iss, string $aud,
                             int $now, int $skew = 60): array|string
{
    if (strlen($jwt) > 4096) {
        return 'malformed';
    }
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return 'malformed';
    }
    [$h64, $p64, $s64] = $parts;
    $hjson = filehost_b64url_decode($h64);
    $pjson = filehost_b64url_decode($p64);
    $sig = filehost_b64url_decode($s64);
    if ($hjson === null || $pjson === null || $sig === null) {
        return 'malformed';
    }
    $header = json_decode($hjson, true);
    if (!is_array($header) || ($header['alg'] ?? '') !== 'ES256') {
        return 'alg';                       /* refuse before touching the key */
    }
    $der = filehost_ecdsa_raw_to_der($sig);
    if ($der === null) {
        return 'signature';
    }
    $key = openssl_pkey_get_public($pem);
    if ($key === false) {
        return 'signature';
    }
    $rc = openssl_verify($h64 . '.' . $p64, $der, $key, OPENSSL_ALGO_SHA256);
    if ($rc !== 1) {
        return 'signature';
    }
    $claims = json_decode($pjson, true);
    if (!is_array($claims)) {
        return 'claims';
    }
    foreach (['iss', 'aud', 'sub', 'jti'] as $k) {
        if (!isset($claims[$k]) || !is_string($claims[$k])) {
            return 'claims';
        }
    }
    foreach (['iat', 'exp'] as $k) {
        if (!isset($claims[$k]) || !is_int($claims[$k])) {
            return 'claims';
        }
    }
    if (!hash_equals($iss, $claims['iss'])) {
        return 'issuer';
    }
    if (!hash_equals($aud, $claims['aud'])) {
        return 'audience';
    }
    if ($claims['exp'] <= $now - $skew) {
        return 'expired';
    }
    if ($claims['iat'] > $now + $skew) {
        return 'not_yet_valid';
    }
    if (!preg_match('/^[0-9a-f]{16,64}$/', $claims['jti'])) {
        return 'claims';
    }
    if ($claims['sub'] === '' || strlen($claims['sub']) > 64) {
        return 'claims';
    }
    return $claims;
}
