<?php
/*
 * Paste https://github.com/boxlabss/PASTE
 *
 * Account names: names reserved for content no account owns, names a new
 * account may not take, and deleting an account with everything it owns.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */
declare(strict_types=1);

require_once __DIR__ . '/img_store.php';

/** True for the name that labels guest content, in any letter case. */
function paste_is_guest_name(string $name): bool
{
    return strcasecmp(trim($name), 'Guest') === 0;
}

/** True for names no new account may use: Guest and the owner of FILEHOST text uploads. */
function paste_username_reserved(string $name): bool
{
    $filehost = defined('FILEHOST_MEMBER') ? (string)FILEHOST_MEMBER : 'irc';
    return paste_is_guest_name($name) || ($filehost !== '' && strcasecmp(trim($name), $filehost) === 0);
}

/** True when a new account may not take $name: reserved, in use, or still the owner of pastes, uploads or albums. */
function paste_username_taken(PDO $pdo, string $name): bool
{
    if (paste_username_reserved($name)) return true;
    $checks = [
        'SELECT 1 FROM users WHERE username = ? LIMIT 1',
        'SELECT 1 FROM pastes WHERE member = ? LIMIT 1',
        "SELECT 1 FROM filehost_files WHERE account = ? AND network = 'web' LIMIT 1",
        'SELECT 1 FROM img_albums WHERE account = ? LIMIT 1',
    ];
    foreach ($checks as $sql) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute([$name]);
            if ($st->fetchColumn() !== false) return true;
        } catch (PDOException $e) {
            // table not created yet
        }
    }
    return false;
}

/**
 * Delete an account with its pastes, comments, web uploads, albums and API keys.
 * For an account with a reserved name only the account and its keys go, since
 * its name also labels content that belongs to nobody.
 */
function paste_delete_account(PDO $pdo, int $userId, string $username): void
{
    $content = !paste_username_reserved($username);

    if ($content && img_ensure_table($pdo)) {
        $st = $pdo->prepare("SELECT slug FROM filehost_files WHERE account = ? AND network = ? AND paste_id IS NULL");
        $st->execute([$username, IMG_NETWORK]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $slug) {
            img_delete($pdo, (string)$slug);
        }
        $pdo->prepare("DELETE FROM img_albums WHERE account = ?")->execute([$username]);
    }

    $pdo->beginTransaction();
    try {
        if ($content) {
            $pdo->prepare("DELETE FROM paste_comments WHERE user_id = ? OR (user_id IS NULL AND username = ?)")
                ->execute([$userId, $username]);
            $pdo->prepare("DELETE FROM pastes WHERE member = ?")->execute([$username]);
        } else {
            $pdo->prepare("DELETE FROM paste_comments WHERE user_id = ?")->execute([$userId]);
        }
        try {
            $pdo->prepare("DELETE FROM api_keys WHERE user_id = ?")->execute([$userId]);
        } catch (PDOException $e) {
            // api_keys table not created yet
        }
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
