<?php
/*
 * Paste https://github.com/boxlabss/PASTE
 *
 * API keys and request limits. A key is stored as its SHA-256 hash, with the
 * first characters kept for display. A key's scopes say what it can do;
 * NULL means every scope.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

/** Scopes a key can have: key => [label, description]. */
const API_SCOPES = [
    'read'   => ['Read', 'Get, list and search pastes, and see your account details'],
    'paste'  => ['Pastes', 'Create, edit and delete your pastes'],
    'upload' => ['Uploads', 'Upload images and videos, and delete your uploads'],
];

/** Characters of a key kept in the clear for display. */
const API_KEY_PREFIX = 8;

/** Hash a key is stored and looked up by. */
function api_key_hash(string $key): string
{
    return hash('sha256', $key);
}

/** True when api_keys has the key_prefix and scopes columns. $set records that they were just added. */
function api_keys_ready(PDO $pdo, ?bool $set = null): bool
{
    static $ready = null;
    if ($set !== null) $ready = $set;
    if ($ready === null) {
        try {
            $ready = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
                                       AND table_name = 'api_keys' AND column_name IN ('key_prefix', 'scopes')")->fetchColumn() === 2;
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * Add the key_prefix and scopes columns when missing, then hash any key
 * still stored in the clear. Returns false if the database user cannot.
 */
function api_keys_ensure(PDO $pdo): bool
{
    if (!api_keys_ready($pdo)) {
        try {
            $cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'api_keys'")
                        ->fetchAll(PDO::FETCH_COLUMN);
            if (!$cols) return false;
            if (!in_array('key_prefix', $cols, true)) {
                $pdo->exec("ALTER TABLE api_keys ADD COLUMN key_prefix VARCHAR(16) DEFAULT NULL");
            }
            if (!in_array('scopes', $cols, true)) {
                $pdo->exec("ALTER TABLE api_keys ADD COLUMN scopes VARCHAR(100) DEFAULT NULL");
            }
        } catch (PDOException $e) {
            error_log('api_keys_ensure: ' . $e->getMessage());
            return false;
        }
        api_keys_ready($pdo, true);
        api_keys_hash_stored($pdo);
    }
    return true;
}

/** Hash keys stored in the clear (rows without a prefix). Returns how many. */
function api_keys_hash_stored(PDO $pdo): int
{
    try {
        return (int)$pdo->exec("UPDATE api_keys SET key_prefix = LEFT(api_key, " . API_KEY_PREFIX . "), api_key = SHA2(api_key, 256)
                                WHERE key_prefix IS NULL");
    } catch (PDOException $e) {
        error_log('api_keys_hash_stored: ' . $e->getMessage());
        return 0;
    }
}

/** Scope keys from a stored value, or null for every scope. */
function api_parse_scopes(?string $stored): ?array
{
    if ($stored === null) return null;
    return array_values(array_intersect(array_keys(API_SCOPES), array_map('trim', explode(',', $stored))));
}

/** Stored value for a list of scopes: null when every scope is given. */
function api_store_scopes(array $scopes): ?string
{
    $scopes = array_values(array_intersect(array_keys(API_SCOPES), $scopes));
    return count($scopes) === count(API_SCOPES) ? null : implode(',', $scopes);
}

/** Labels of a key's scopes for display. */
function api_scope_summary(?array $scopes): string
{
    if ($scopes === null) return 'Everything';
    return implode(', ', array_map(static fn(string $s): string => API_SCOPES[$s][0], $scopes)) ?: 'Nothing';
}

/** Create a key for a user. Returns the key, which is not stored. */
function api_key_create(PDO $pdo, int $userId, string $name, array $scopes): string
{
    $key = bin2hex(random_bytes(32));
    if (api_keys_ready($pdo)) {
        $pdo->prepare("INSERT INTO api_keys (user_id, api_key, key_prefix, scopes, name) VALUES (?, ?, ?, ?, ?)")
            ->execute([$userId, api_key_hash($key), substr($key, 0, API_KEY_PREFIX), api_store_scopes($scopes), $name]);
    } else {
        $pdo->prepare("INSERT INTO api_keys (user_id, api_key, name) VALUES (?, ?, ?)")->execute([$userId, $key, $name]);
    }
    return $key;
}

/** Replace a key with a new one, keeping its name and scopes. Returns the new key. */
function api_key_regenerate(PDO $pdo, int $keyId): string
{
    $key = bin2hex(random_bytes(32));
    if (api_keys_ready($pdo)) {
        $pdo->prepare("UPDATE api_keys SET api_key = ?, key_prefix = ?, last_used_at = NULL WHERE id = ?")
            ->execute([api_key_hash($key), substr($key, 0, API_KEY_PREFIX), $keyId]);
    } else {
        $pdo->prepare("UPDATE api_keys SET api_key = ?, last_used_at = NULL WHERE id = ?")->execute([$key, $keyId]);
    }
    return $key;
}

/** A user's keys for display: id, name, key_prefix, scopes (parsed), created_at, last_used_at, is_active. */
function api_keys_for_user(PDO $pdo, int $userId): array
{
    $cols = api_keys_ready($pdo) ? 'key_prefix, scopes' : 'LEFT(api_key, ' . API_KEY_PREFIX . ') AS key_prefix, NULL AS scopes';
    $st = $pdo->prepare("SELECT id, name, $cols, created_at, last_used_at, is_active FROM api_keys WHERE user_id = ? ORDER BY created_at DESC");
    $st->execute([$userId]);
    return array_map(static function (array $r): array {
        $r['scopes'] = api_parse_scopes($r['scopes']);
        return $r;
    }, $st->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * The active key matching $key with its user: key_id, user_id, username,
 * email, verified, scopes (null: every scope). Null when there is none.
 */
function api_key_lookup(PDO $pdo, string $key): ?array
{
    if ($key === '' || strlen($key) > 200) return null;
    $ready = api_keys_ready($pdo);
    $st = $pdo->prepare("SELECT ak.id AS key_id, ak.user_id, " . ($ready ? 'ak.scopes' : 'NULL AS scopes') . ",
                                u.username, u.email_id AS email, u.verified
                         FROM api_keys ak JOIN users u ON ak.user_id = u.id
                         WHERE ak.api_key = ? AND ak.is_active = 1");
    $st->execute([$ready ? api_key_hash($key) : $key]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['scopes'] = api_parse_scopes($row['scopes']);
    return $row;
}

/** True when a looked-up key has $scope. */
function api_key_can(array $key, string $scope): bool
{
    return $key['scopes'] === null || in_array($scope, $key['scopes'], true);
}

/* ============================ Request limits ============================ */

/** Create the api_rate_limits table when missing. */
function api_rate_ensure(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $exists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'api_rate_limits'")
                           ->fetchColumn() > 0;
        if (!$exists) {
            $pdo->exec("CREATE TABLE api_rate_limits (
                bucket VARCHAR(64) NOT NULL,
                window_start INT NOT NULL,
                hits INT NOT NULL DEFAULT 0,
                PRIMARY KEY (bucket, window_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        $ok = true;
    } catch (PDOException $e) {
        error_log('api_rate_ensure: ' . $e->getMessage());
        $ok = false;
    }
    return $ok;
}

/**
 * Hits in the current $window-second window of $bucket, after adding one
 * when $count is true. Returns null when the table is unavailable.
 */
function api_rate(PDO $pdo, string $bucket, int $window, bool $count = true): ?int
{
    if (!api_rate_ensure($pdo)) return null;
    $start = intdiv(time(), $window) * $window;
    try {
        if ($count) {
            $pdo->prepare("INSERT INTO api_rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)
                           ON DUPLICATE KEY UPDATE hits = hits + 1")->execute([$bucket, $start]);
            if (random_int(1, 200) === 1) {
                $pdo->prepare("DELETE FROM api_rate_limits WHERE window_start < ?")->execute([time() - 86400]);
            }
        }
        $st = $pdo->prepare("SELECT hits FROM api_rate_limits WHERE bucket = ? AND window_start = ?");
        $st->execute([$bucket, $start]);
        return (int)$st->fetchColumn();
    } catch (PDOException $e) {
        error_log('api_rate: ' . $e->getMessage());
        return null;
    }
}

/** Unix time the current $window-second window ends. */
function api_rate_reset(int $window): int
{
    return intdiv(time(), $window) * $window + $window;
}
