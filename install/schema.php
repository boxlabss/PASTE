<?php
/*
 * Paste Installer https://github.com/boxlabss/PASTE
 *
 * Database schema shared by the installer (install.php) and the upgrader
 * (upgrade.php): helpers, the v2.x paste re-key, and paste_install_schema().
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

require_once __DIR__ . '/../includes/api_keys.php';

function tableExists(PDO $pdo, string $table): bool {
    try {
        $q = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t LIMIT 1");
        $q->execute([':t' => $table]);
        return (bool)$q->fetchColumn();
    } catch (PDOException $e) { error_log("tableExists($table): ".$e->getMessage()); return false; }
}
function getColumnDefinition(PDO $pdo, string $table, string $column): ?array {
    try {
        // Use direct query with escaped values - LIKE with prepared statements can be problematic
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
        $q = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) { error_log("getColumnDefinition($table.$column): ".$e->getMessage()); return null; }
}
function normalizeColumnDef(string $def): string {
    $def = preg_replace('/DEFAULT\s+""/i', "DEFAULT ''", $def);
    if (preg_match('/\b(TEXT|BLOB|JSON|GEOMETRY)\b/i', $def)) {
        $def = preg_replace('/\s+DEFAULT\s+\'\'/i', '', $def);
        $def = preg_replace('/\s+DEFAULT\s+""/i', '', $def);
    }
    return trim($def);
}
function ensureColumn(PDO $pdo, string $table, string $column, string $expected_def, array &$out, array &$errs): void {
    $expected_def = normalizeColumnDef($expected_def);
    $before = columnInfo($pdo, $table, $column);
    if ($before === null) {
        try {
            $pdo->exec("ALTER TABLE `$table` ADD `$column` $expected_def");
            $out[] = "Added column $table.$column.";
            return;
        } catch (PDOException $e) {
            $info = $e->errorInfo;
            if (!empty($info[1]) && (int)$info[1] === 1060) {
                try {
                    $pdo->exec("ALTER TABLE `$table` MODIFY `$column` $expected_def");
                    $out[] = "Aligned column $table.$column.";
                    return;
                } catch (PDOException $e2) {
                    $errs[] = "Failed to align $table.$column after duplicate: " . htmlspecialchars($e2->getMessage(), ENT_QUOTES, 'UTF-8');
                    error_log($e2->getMessage());
                    return;
                }
            }
            $errs[] = "Failed to add $table.$column: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            error_log($e->getMessage());
            return;
        }
    }
    if (columnMatches($before, $expected_def, tableCollation($pdo, $table))) {
        return;
    }
    try {
        $pdo->exec("ALTER TABLE `$table` MODIFY `$column` $expected_def");
        if (columnInfo($pdo, $table, $column) !== $before) {
            $out[] = "Aligned column $table.$column.";
        }
    } catch (PDOException $e) {
        error_log("Skipped modify for $table.$column: " . $e->getMessage());
    }
}

/** A column's SHOW FULL COLUMNS row (without privileges), or null when it does not exist. */
function columnInfo(PDO $pdo, string $table, string $column): ?array {
    try {
        $q = $pdo->query("SHOW FULL COLUMNS FROM `" . preg_replace('/[^a-zA-Z0-9_]/', '', $table) . "`");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (strcasecmp($row['Field'], $column) === 0) {
                unset($row['Privileges']);
                return $row;
            }
        }
    } catch (PDOException $e) {
        error_log("columnInfo($table.$column): " . $e->getMessage());
    }
    return null;
}

/** Default collation of a table. */
function tableCollation(PDO $pdo, string $table): string {
    try {
        $q = $pdo->prepare("SELECT TABLE_COLLATION FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $q->execute([$table]);
        return strtolower((string)$q->fetchColumn());
    } catch (PDOException $e) {
        return '';
    }
}

/** True when a column already has definition $def. Anything it cannot read counts as different. */
function columnMatches(array $meta, string $def, string $tableCollation): bool {
    $def = trim(preg_replace('/\s+/', ' ', $def));
    if (!preg_match("/^([a-z]+)((?:\\((?:[^()']|'(?:[^']|'')*')*\\))?)((?: (?:unsigned|zerofill))*)(.*)$/i", $def, $m)) {
        return false;
    }
    $type = static function (string $base, string $args, string $mods): string {
        $base = strtolower($base);
        if ($base === 'bool' || $base === 'boolean') {
            $base = 'tinyint';
            $args = '(1)';
        }
        $base = $base === 'integer' ? 'int' : $base;
        $args = preg_replace('/\s*,\s*/', ',', $args);
        if (in_array($base, ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true) && !($base === 'tinyint' && $args === '(1)')) {
            $args = '';
        }
        return $base . strtolower($args) . strtolower(rtrim($mods));
    };
    if (!preg_match('/^([a-z]+)((?:\(.*\))?)((?: (?:unsigned|zerofill))*)$/i', trim((string)$meta['Type']), $t)
        || $type($m[1], $m[2], $m[3]) !== $type($t[1], $t[2], $t[3])) {
        return false;
    }

    $notNull = false;
    $default = null;
    $auto = false;
    $onUpdate = false;
    $collate = null;
    $rest = trim($m[4]);
    while ($rest !== '') {
        if (preg_match('/^NOT NULL\b\s*/i', $rest, $x)) {
            $notNull = true;
        } elseif (preg_match('/^NULL\b\s*/i', $rest, $x)) {
            $notNull = false;
        } elseif (preg_match("/^DEFAULT\s+('(?:[^']|'')*'|NULL\b|CURRENT_TIMESTAMP(?:\\(\\))?|-?\d+(?:\.\d+)?)\s*/i", $rest, $x)) {
            $v = $x[1];
            if ($v[0] === "'") {
                $default = str_replace("''", "'", substr($v, 1, -1));
            } elseif (strcasecmp($v, 'NULL') === 0) {
                $default = null;
            } elseif (stripos($v, 'CURRENT_TIMESTAMP') === 0) {
                $default = 'current_timestamp';
            } else {
                $default = $v;
            }
        } elseif (preg_match('/^AUTO_INCREMENT\b\s*/i', $rest, $x)) {
            $auto = true;
        } elseif (preg_match('/^ON UPDATE\s+CURRENT_TIMESTAMP(?:\(\))?\s*/i', $rest, $x)) {
            $onUpdate = true;
        } elseif (preg_match('/^COLLATE\s+(\w+)\s*/i', $rest, $x)) {
            $collate = strtolower($x[1]);
        } else {
            return false;
        }
        $rest = substr($rest, strlen($x[0]));
    }

    if (($meta['Null'] === 'NO') !== ($notNull || $auto)) return false;
    $have = $meta['Default'];
    if ($have !== null && preg_match('/^current_timestamp(\(\))?$/i', (string)$have)) {
        $have = 'current_timestamp';
    }
    if ($have !== $default) return false;
    if ((stripos((string)$meta['Extra'], 'auto_increment') !== false) !== $auto) return false;
    if ((stripos((string)$meta['Extra'], 'on update') !== false) !== $onUpdate) return false;
    if ($meta['Collation'] !== null && strtolower((string)$meta['Collation']) !== ($collate ?? $tableCollation)) return false;
    return true;
}

/** PDO connection used by the installer and upgrader. */
function installer_connect(string $dbhost, string $dbname, string $dbuser, string $dbpassword): PDO {
    $pdo = new PDO("mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpassword, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    // relax only the two legacy-unfriendly modes
    try {
        $m  = (string)$pdo->query("SELECT @@SESSION.sql_mode")->fetchColumn();
        $m2 = array_filter(array_map('trim', explode(',', $m)), function ($x) {
            return !in_array($x, ['NO_ZERO_DATE','NO_ZERO_IN_DATE'], true);
        });
        $pdo->exec("SET SESSION sql_mode='" . implode(',', $m2) . "'");
    } catch (Throwable $e) { /* ignore */ }
    return $pdo;
}
function indexExists(PDO $pdo, string $table, string $index): bool {
    try {
        $q = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = :k");
        $q->execute([':k' => $index]);
        return (bool)$q->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { error_log("indexExists($table,$index): ".$e->getMessage()); return false; }
}
function fkExists(PDO $pdo, string $table, string $fk): bool {
    try {
        $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
        $q  = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=:db AND TABLE_NAME=:tbl AND CONSTRAINT_NAME=:fk");
        $q->execute([':db'=>$db, ':tbl'=>$table, ':fk'=>$fk]);
        return (bool)$q->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { error_log("fkExists($table,$fk): ".$e->getMessage()); return false; }
}
function ensureEngineAndCharset(PDO $pdo, string $table, array &$out, array &$errs, string $engine='InnoDB', string $charset='utf8mb4', string $collate='utf8mb4_unicode_ci'): void {
    try {
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $q  = $pdo->prepare("SELECT ENGINE, TABLE_COLLATION FROM information_schema.tables WHERE table_schema=:s AND table_name=:t");
        $q->execute([':s'=>$db, ':t'=>$table]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;
        if (strcasecmp($row['ENGINE'] ?? '', $engine) !== 0) {
            try { $pdo->exec("ALTER TABLE `$table` ENGINE=$engine"); $out[]="Converted $table to $engine."; } catch (PDOException $e) { $errs[]="Failed engine $table: ".htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'); }
        }
        $curColl = $row['TABLE_COLLATION'] ?? '';
        if (stripos($curColl, $collate) === false) {
            try { $pdo->exec("ALTER TABLE `$table` CONVERT TO CHARACTER SET $charset COLLATE $collate"); $out[]="Converted $table to $charset/$collate."; } catch (PDOException $e) { $errs[]="Failed charset $table: ".htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'); }
        }
    } catch (PDOException $e) { error_log("ensureEngineAndCharset($table): ".$e->getMessage()); }
}
function ensureDateType(PDO $pdo, string $table, string $column, string $targetType, array &$out, array &$errs): void {
    $meta = getColumnDefinition($pdo, $table, $column);
    if (!$meta) return;

    // Check if column is already the target type
    $currentType = strtoupper($meta['Type'] ?? '');
    if (strpos($currentType, strtoupper($targetType)) === 0) {
        // Already correct type, skip
        return;
    }

    $tmp = $column . '_tmp_' . substr(md5($table.$column), 0, 6);
    try {
        // Drop unique indexes that include this column to avoid duplicate errors during conversion
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $idxQuery = $pdo->prepare("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND NON_UNIQUE = 0 AND INDEX_NAME != 'PRIMARY'");
        $idxQuery->execute([$db, $table, $column]);
        $indexes = $idxQuery->fetchAll(PDO::FETCH_COLUMN);

        foreach ($indexes as $idx) {
            try {
                $pdo->exec("ALTER TABLE `$table` DROP INDEX `$idx`");
            } catch (PDOException $e) {
                // Index might not exist, continue
            }
        }

        $pdo->exec("ALTER TABLE `$table` ADD `$tmp` $targetType NULL DEFAULT NULL");
        if ($targetType === 'DATE') {
            $pdo->exec("UPDATE `$table` SET `$tmp` = CASE
                WHEN `$column` REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN STR_TO_DATE(`$column`, '%Y-%m-%d')
                WHEN `$column` REGEXP '^[0-9]{10}$' THEN DATE(FROM_UNIXTIME(`$column`))
                WHEN `$column` REGEXP '^[0-9]{2}/[0-9]{2}/[0-9]{4}$' THEN STR_TO_DATE(`$column`, '%m/%d/%Y')
                ELSE NULL END");
        } else {
            $pdo->exec("UPDATE `$table` SET `$tmp` = CASE
                WHEN `$column` REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}( [0-9]{2}:[0-9]{2}:[0-9]{2})?$' THEN STR_TO_DATE(`$column`, '%Y-%m-%d %H:%i:%s')
                WHEN `$column` REGEXP '^[0-9]{10}$' THEN FROM_UNIXTIME(`$column`)
                WHEN `$column` REGEXP '^[0-9]{2}/[0-9]{2}/[0-9]{4}( [0-9]{2}:[0-9]{2}(:[0-9]{2})?)?$' THEN STR_TO_DATE(`$column`, '%m/%d/%Y %H:%i:%s')
                ELSE NULL END");
        }
        $pdo->exec("UPDATE `$table` SET `$tmp` = COALESCE(`$tmp`, NOW()) WHERE `$tmp` IS NULL");
        $pdo->exec("ALTER TABLE `$table` DROP COLUMN `$column`, CHANGE `$tmp` `$column` $targetType NOT NULL");
        $out[] = "Converted $table.$column to $targetType.";
    } catch (PDOException $e) {
        $errs[] = "Failed to convert $table.$column to $targetType: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        try { $pdo->exec("ALTER TABLE `$table` DROP COLUMN `$tmp`"); } catch (Throwable $e2) {}
    }
}

// ---- crypto helpers (v2.2 → v3.x) ----
function hex_or_raw_key(string $input): string {
    $t = trim($input);
    if ($t === '') return '';
    if (ctype_xdigit($t) && (strlen($t) % 2 === 0)) {
        $b = @hex2bin($t);
        if ($b !== false) return $b;
    }
    return $t;
}
function decrypt_v22_strict(string $value, string $oldKeyRaw): ?string {
    $cipher = 'AES-256-CBC';
    $ivlen  = openssl_cipher_iv_length($cipher);
    $zeroIv = str_repeat("\0", $ivlen);

    $pt = @openssl_decrypt($value, $cipher, $oldKeyRaw);
    if ($pt !== false && $pt !== '') return $pt;

    $decoded = base64_decode($value, true);
    if ($decoded !== false) {
        $pt = @openssl_decrypt($decoded, $cipher, $oldKeyRaw, OPENSSL_RAW_DATA, $zeroIv);
        if ($pt !== false && $pt !== '') return $pt;
    }

    // some installs used md5($sec_key)
    $md5bin = md5($oldKeyRaw, true);
    $pt = @openssl_decrypt($value, $cipher, $md5bin);
    if ($pt !== false && $pt !== '') return $pt;
    if ($decoded !== false) {
        $pt = @openssl_decrypt($decoded, $cipher, $md5bin, OPENSSL_RAW_DATA, $zeroIv);
        if ($pt !== false && $pt !== '') return $pt;
    }

    $md5hex = md5($oldKeyRaw, false);
    $pt = @openssl_decrypt($value, $cipher, $md5hex);
    if ($pt !== false && $pt !== '') return $pt;
    if ($decoded !== false) {
        $pt = @openssl_decrypt($decoded, $cipher, $md5hex, OPENSSL_RAW_DATA, $zeroIv);
        if ($pt !== false && $pt !== '') return $pt;
    }
    return null;
}
function decrypt_v31_with_key(string $value, string $keyBin): ?string {
    $decoded = base64_decode($value, true);
    if ($decoded === false) return null;
    $cipher = 'AES-256-CBC';
    $ivlen  = openssl_cipher_iv_length($cipher);
    $hmacLen = 32;
    if (strlen($decoded) < $ivlen + $hmacLen) return null;
    $iv  = substr($decoded, 0, $ivlen);
    $hmc = substr($decoded, $ivlen, $hmacLen);
    $ct  = substr($decoded, $ivlen + $hmacLen);
    $calc = hash_hmac('sha256', $ct, $keyBin, true);
    if (!hash_equals($hmc, $calc)) return null;
    $pt = openssl_decrypt($ct, $cipher, $keyBin, OPENSSL_RAW_DATA, $iv);
    return ($pt !== false) ? $pt : null;
}
function encrypt_v31_with_key(string $plaintext, string $keyBin): string {
    $cipher = 'AES-256-CBC';
    $ivlen  = openssl_cipher_iv_length($cipher);
    $iv = random_bytes($ivlen);
    $ct = openssl_encrypt($plaintext, $cipher, $keyBin, OPENSSL_RAW_DATA, $iv);
    $h = hash_hmac('sha256', $ct, $keyBin, true);
    return base64_encode($iv . $h . $ct);
}
function migrate_encrypted_pastes(PDO $pdo, string $oldKeyInput, string $newKeyHex): array {
    $res = ['checked'=>0,'converted'=>0,'skipped'=>0,'failed'=>0,'errors'=>[]];

    if (!extension_loaded('openssl')) { $res['errors'][]='OpenSSL extension not available.'; return $res; }
    $oldKeyRaw = hex_or_raw_key($oldKeyInput);
    if ($oldKeyRaw === '') { $res['errors'][]='Old $sec_key not provided.'; return $res; }
    $newKeyBin = @hex2bin(trim($newKeyHex));
    if ($newKeyBin === false) { $res['errors'][]='New $sec_key is not valid hex.'; return $res; }

    $total = (int)$pdo->query("SELECT COUNT(*) FROM pastes WHERE encrypt='1'")->fetchColumn();
    if ($total === 0) return $res;

    $batch = 500;
    for ($offset = 0; $offset < $total; $offset += $batch) {
        $q = $pdo->prepare("SELECT id, content FROM pastes WHERE encrypt='1' ORDER BY id ASC LIMIT :lim OFFSET :off");
        $q->bindValue(':lim', $batch, PDO::PARAM_INT);
        $q->bindValue(':off', $offset, PDO::PARAM_INT);
        $q->execute();
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) break;

        $pdo->beginTransaction();
        foreach ($rows as $r) {
            $res['checked']++;
            $id  = (int)$r['id'];
            $enc = (string)$r['content'];

            // already in new format with new key? skip
            if (decrypt_v31_with_key($enc, $newKeyBin) !== null) { $res['skipped']++; continue; }

            // try legacy
            $plain = decrypt_v22_strict($enc, $oldKeyRaw);
            if ($plain === null) { $res['failed']++; $res['errors'][] = "ID $id: could not decrypt with old key."; continue; }

            // re-encrypt new format
            $reb = encrypt_v31_with_key($plain, $newKeyBin);
            $u = $pdo->prepare("UPDATE pastes SET content=:c WHERE id=:id");
            $u->execute([':c'=>$reb, ':id'=>$id]);
            $res['converted']++;
        }
        $pdo->commit();
    }

    return $res;
}

/**
 * Create or bring up to date every table Paste uses. Options:
 *   upgrade        true to leave admin accounts, the base URL and deleted pages alone
 *   admin_user     admin to create when missing (null: none)
 *   admin_pass     password hash for that admin
 *   baseurl        site URL to store (null: keep the stored one)
 *   sec_key        current encryption key (hex)
 *   old_sec_key    v2.x key to re-encrypt old pastes with ('' to skip)
 *   generate_slugs give pastes without a slug a random one
 *   img_choice     'yes', 'no' or 'keep' for /img uploads; img_guests, img_encrypt for 'yes'
 * Returns ['output' => [...], 'errors' => [...]].
 */
function paste_install_schema(PDO $pdo, array $opt): array
{
    $output         = [];
    $errors         = [];
    $upgrade        = !empty($opt['upgrade']);
    $admin_user     = $opt['admin_user'] ?? null;
    $admin_pass     = (string)($opt['admin_pass'] ?? '');
    $baseurl        = $opt['baseurl'] ?? null;
    $sec_key        = (string)($opt['sec_key'] ?? '');
    $old_sec_key_in = (string)($opt['old_sec_key'] ?? '');
    $generateSlugs  = !empty($opt['generate_slugs']);
    $img_choice     = $opt['img_choice'] ?? 'keep';
    $img_guests     = !empty($opt['img_guests']);
    $img_encrypt    = !empty($opt['img_encrypt']);
    $date           = date('Y-m-d H:i:s');

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

    // admin
    if (!tableExists($pdo, 'admin')) {
        $pdo->exec("CREATE TABLE admin (
            id INT NOT NULL AUTO_INCREMENT,
            user VARCHAR(250) NOT NULL UNIQUE,
            pass VARCHAR(250) NOT NULL,
            email VARCHAR(255) NOT NULL DEFAULT 'admin@example.com',
            reset_code VARCHAR(32) DEFAULT NULL,
            reset_expiry DATETIME DEFAULT NULL,
            permissions VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = 'admin table created.';
    } else {
        ensureEngineAndCharset($pdo,'admin',$output,$errors);
        ensureColumn($pdo,'admin','id',   "INT NOT NULL AUTO_INCREMENT", $output,$errors);
        ensureColumn($pdo,'admin','user', "VARCHAR(250) NOT NULL",       $output,$errors);
        ensureColumn($pdo,'admin','pass', "VARCHAR(250) NOT NULL",       $output,$errors);
        ensureColumn($pdo,'admin','email',"VARCHAR(255) NOT NULL DEFAULT 'admin@example.com'",$output,$errors);
        ensureColumn($pdo,'admin','reset_code', "VARCHAR(32) DEFAULT NULL", $output,$errors);
        ensureColumn($pdo,'admin','reset_expiry', "DATETIME DEFAULT NULL", $output,$errors);
        ensureColumn($pdo,'admin','permissions', "VARCHAR(255) DEFAULT NULL", $output,$errors);
        if (!indexExists($pdo,'admin','user')) {
            try { $pdo->exec("ALTER TABLE admin ADD UNIQUE KEY `user` (user)"); } catch (PDOException $e) { error_log($e->getMessage()); }
        }
    }
    // seed admin (not on upgrades)
    if ($admin_user !== null) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin WHERE user=:u");
            $stmt->execute([':u'=>$admin_user]);
            if ((int)$stmt->fetchColumn() === 0) {
                $ins = $pdo->prepare("INSERT INTO admin (user, pass, email) VALUES (:u,:p,:e)");
                $ins->execute([':u'=>$admin_user, ':p'=>$admin_pass, ':e'=>'admin@yourdomain.com']);
                $output[] = 'Admin user inserted.';
            } else {
                $output[] = 'Admin user already exists, skipping insertion.';
            }
        } catch (PDOException $e) {
            $errors[] = 'Failed to insert admin user: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    }

    // admin_history
    if (!tableExists($pdo,'admin_history')) {
        $pdo->exec("CREATE TABLE admin_history (
            id INT NOT NULL AUTO_INCREMENT,
            admin_id INT DEFAULT NULL,
            last_date DATETIME NOT NULL,
            ip VARCHAR(45) NOT NULL,
            user_agent VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY(id),
            KEY idx_admin_id (admin_id),
            CONSTRAINT fk_admin_history_admin FOREIGN KEY (admin_id) REFERENCES admin(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = 'admin_history table created.';
    } else {
        ensureEngineAndCharset($pdo,'admin_history',$output,$errors);
        ensureColumn($pdo,'admin_history','id',         "INT NOT NULL AUTO_INCREMENT", $output,$errors);
        ensureColumn($pdo,'admin_history','admin_id',   "INT DEFAULT NULL", $output,$errors);
        ensureDateType($pdo,'admin_history','last_date','DATETIME',$output,$errors);
        ensureColumn($pdo,'admin_history','ip',         "VARCHAR(45) NOT NULL", $output,$errors);
        ensureColumn($pdo,'admin_history','user_agent', "VARCHAR(255) NOT NULL DEFAULT ''", $output,$errors);
        if (!indexExists($pdo,'admin_history','idx_admin_id')) {
            try { $pdo->exec("ALTER TABLE admin_history ADD KEY idx_admin_id (admin_id)"); } catch (PDOException $e) { error_log($e->getMessage()); }
        }
        if (!fkExists($pdo,'admin_history','fk_admin_history_admin')) {
            try { $pdo->exec("ALTER TABLE admin_history ADD CONSTRAINT fk_admin_history_admin FOREIGN KEY (admin_id) REFERENCES admin(id) ON DELETE SET NULL"); } catch (PDOException $e) { error_log($e->getMessage()); }
        }
    }

    // site_info
    if (!tableExists($pdo,'site_info')) {
        $pdo->exec("CREATE TABLE site_info (
            id INT NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            des MEDIUMTEXT,
            keyword MEDIUMTEXT,
            site_name VARCHAR(255) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            twit VARCHAR(255) DEFAULT NULL,
            face VARCHAR(255) DEFAULT NULL,
            gplus VARCHAR(255) DEFAULT NULL,
            ga VARCHAR(255) DEFAULT NULL,
            additional_scripts TEXT,
            baseurl TEXT NOT NULL,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $stmt = $pdo->prepare("INSERT INTO site_info (title,des,keyword,site_name,email,twit,face,gplus,ga,additional_scripts,baseurl) VALUES (:title,:des,:keyword,:site_name,:email,:twit,:face,:gplus,:ga,:scripts,:baseurl)");
        $stmt->execute([
            ':title'=>'Paste',
            ':des'=>'Paste can store text, source code, or sensitive data for a set period of time.',
            ':keyword'=>'paste,pastebin.com,pastebin,text,paste,online paste',
            ':site_name'=>'Paste',
            ':email'=>'admin@yourdomain.com',
            ':twit'=>'https://x.com/',
            ':face'=>'https://www.facebook.com/',
            ':gplus'=>'',
            ':ga'=>'',
            ':scripts'=>'',
            ':baseurl'=>$baseurl ?? ''
        ]);
        $output[] = 'site_info table created & seeded.';
    } else {
        ensureEngineAndCharset($pdo,'site_info',$output,$errors);
        ensureColumn($pdo,'site_info','id',   "INT NOT NULL AUTO_INCREMENT",$output,$errors);
        ensureColumn($pdo,'site_info','title',"VARCHAR(255) NOT NULL",$output,$errors);
        ensureColumn($pdo,'site_info','des',  "MEDIUMTEXT",$output,$errors);
        ensureColumn($pdo,'site_info','keyword',"MEDIUMTEXT",$output,$errors);
        ensureColumn($pdo,'site_info','site_name',"VARCHAR(255) NOT NULL",$output,$errors);
        ensureColumn($pdo,'site_info','email',"VARCHAR(255)",$output,$errors);
        ensureColumn($pdo,'site_info','twit',"VARCHAR(255)",$output,$errors);
        ensureColumn($pdo,'site_info','face',"VARCHAR(255)",$output,$errors);
        ensureColumn($pdo,'site_info','gplus',"VARCHAR(255)",$output,$errors);
        ensureColumn($pdo,'site_info','ga',"VARCHAR(255)",$output,$errors);
        ensureColumn($pdo,'site_info','additional_scripts',"TEXT",$output,$errors);
        ensureColumn($pdo,'site_info','baseurl',"TEXT NOT NULL",$output,$errors);
        if ($baseurl !== null) {
            try {
                $pdo->prepare("UPDATE site_info SET baseurl=:b WHERE id=1")->execute([':b'=>$baseurl]);
                $output[] = 'Updated baseurl in site_info.';
            } catch (PDOException $e) {
                $errors[] = 'Failed to update baseurl: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            }
        }
    }

    // site_permissions
    if (!tableExists($pdo,'site_permissions')) {
        $pdo->exec("CREATE TABLE site_permissions (
            id INT NOT NULL AUTO_INCREMENT,
            disableguest VARCHAR(10) NOT NULL DEFAULT 'off',
            siteprivate  VARCHAR(10) NOT NULL DEFAULT 'off',
            hiderecentpastes VARCHAR(10) NOT NULL DEFAULT 'off',
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT INTO site_permissions (disableguest, siteprivate, hiderecentpastes) VALUES ('off','off','off')");
        $output[] = 'site_permissions table created & seeded.';
    } else {
        ensureEngineAndCharset($pdo,'site_permissions',$output,$errors);
        ensureColumn($pdo,'site_permissions','id',             "INT NOT NULL AUTO_INCREMENT",$output,$errors);
        ensureColumn($pdo,'site_permissions','disableguest',   "VARCHAR(10) NOT NULL DEFAULT 'off'",$output,$errors);
        ensureColumn($pdo,'site_permissions','siteprivate',    "VARCHAR(10) NOT NULL DEFAULT 'off'",$output,$errors);
        ensureColumn($pdo,'site_permissions','hiderecentpastes', "VARCHAR(10) NOT NULL DEFAULT 'off'",$output,$errors);
    }

    // interface
    if (!tableExists($pdo,'interface')) {
        $pdo->exec("CREATE TABLE interface (
            id INT NOT NULL AUTO_INCREMENT,
            theme VARCHAR(50) NOT NULL DEFAULT 'default',
            lang  VARCHAR(50) NOT NULL DEFAULT 'en.php',
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT INTO interface (theme, lang) VALUES ('default','en.php')");
        $output[] = 'interface table created & seeded.';
    } else {
        ensureEngineAndCharset($pdo,'interface',$output,$errors);
        ensureColumn($pdo,'interface','id',   "INT NOT NULL AUTO_INCREMENT",$output,$errors);
        ensureColumn($pdo,'interface','theme',"VARCHAR(50) NOT NULL DEFAULT 'default'",$output,$errors);
        ensureColumn($pdo,'interface','lang', "VARCHAR(50) NOT NULL DEFAULT 'en.php'",$output,$errors);
    }

    // pastes
    if (!tableExists($pdo,'pastes')) {
        $pdo->exec("CREATE TABLE pastes (
            id INT NOT NULL AUTO_INCREMENT,
            slug VARCHAR(32) DEFAULT NULL,
            parent_id INT DEFAULT NULL,
            title   VARCHAR(255) NOT NULL DEFAULT 'Untitled',
            content LONGTEXT NOT NULL,
            visible VARCHAR(10) NOT NULL DEFAULT '0',
            code    VARCHAR(50) NOT NULL DEFAULT 'text',
            expiry  VARCHAR(50) DEFAULT NULL,
            password VARCHAR(255) NOT NULL DEFAULT 'NONE',
            encrypt  VARCHAR(1)   NOT NULL DEFAULT '0',
            member   VARCHAR(255) NOT NULL DEFAULT 'Guest',
            date DATETIME NOT NULL,
            ip   VARCHAR(45) NOT NULL,
            now_time VARCHAR(50) DEFAULT NULL,
            s_date   DATE DEFAULT NULL,
            PRIMARY KEY(id),
            UNIQUE KEY idx_pastes_slug (slug),
            KEY idx_pastes_parent (parent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = 'pastes table created.';
    } else {
        ensureEngineAndCharset($pdo,'pastes',$output,$errors);
        ensureColumn($pdo,'pastes','id',       "INT NOT NULL AUTO_INCREMENT",$output,$errors);
        ensureColumn($pdo,'pastes','slug',     "VARCHAR(32) DEFAULT NULL",$output,$errors);
        ensureColumn($pdo,'pastes','parent_id',"INT DEFAULT NULL",$output,$errors);
        ensureColumn($pdo,'pastes','title',    "VARCHAR(255) NOT NULL DEFAULT 'Untitled'",$output,$errors);
        ensureColumn($pdo,'pastes','content',  "LONGTEXT NOT NULL",$output,$errors);
        ensureColumn($pdo,'pastes','visible',  "VARCHAR(10) NOT NULL DEFAULT '0'",$output,$errors);
        ensureColumn($pdo,'pastes','code',     "VARCHAR(50) NOT NULL DEFAULT 'text'",$output,$errors);
        ensureColumn($pdo,'pastes','expiry',   "VARCHAR(50)",$output,$errors);
        ensureColumn($pdo,'pastes','password', "VARCHAR(255) NOT NULL DEFAULT 'NONE'",$output,$errors);
        ensureColumn($pdo,'pastes','encrypt',  "VARCHAR(1) NOT NULL DEFAULT '0'",$output,$errors);
        ensureColumn($pdo,'pastes','member',   "VARCHAR(255) NOT NULL DEFAULT 'Guest'",$output,$errors);
        ensureDateType($pdo,'pastes','date','DATETIME',$output,$errors);
        ensureColumn($pdo,'pastes','ip',       "VARCHAR(45) NOT NULL",$output,$errors);
        ensureColumn($pdo,'pastes','now_time', "VARCHAR(50)",$output,$errors);
        ensureDateType($pdo,'pastes','s_date','DATE',$output,$errors);
        if (!indexExists($pdo,'pastes','idx_pastes_slug')) {
            try { $pdo->exec("ALTER TABLE pastes ADD UNIQUE KEY idx_pastes_slug (slug)"); $output[] = 'Added unique index on pastes.slug'; }
            catch (PDOException $e) { error_log($e->getMessage()); }
        }
        if (!indexExists($pdo,'pastes','idx_pastes_parent')) {
            try { $pdo->exec("ALTER TABLE pastes ADD KEY idx_pastes_parent (parent_id)"); $output[] = 'Added index on pastes.parent_id'; }
            catch (PDOException $e) { error_log($e->getMessage()); }
        }
        if (!$upgrade && getColumnDefinition($pdo,'pastes','views')) {
            $output[] = "Note: the old pastes.views column is no longer used; views are counted in paste_views.";
        }
    }

    // paste_views
    if (!tableExists($pdo,'paste_views')) {
        $pdo->exec("CREATE TABLE paste_views (
            id INT NOT NULL AUTO_INCREMENT,
            paste_id INT NOT NULL,
            ip VARCHAR(45) NOT NULL,
            view_date DATE NOT NULL,
            PRIMARY KEY(id),
            UNIQUE KEY unique_paste_ip_date (paste_id, ip, view_date),
            KEY idx_paste_id (paste_id),
            KEY idx_view_date (view_date),
            CONSTRAINT paste_views_ibfk_1 FOREIGN KEY (paste_id) REFERENCES pastes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = 'paste_views table created.';
    } else {
        ensureEngineAndCharset($pdo,'paste_views',$output,$errors);
        ensureColumn($pdo,'paste_views','id',        "INT NOT NULL AUTO_INCREMENT",$output,$errors);
        ensureColumn($pdo,'paste_views','paste_id',  "INT NOT NULL",$output,$errors);
        ensureColumn($pdo,'paste_views','ip',        "VARCHAR(45) NOT NULL",$output,$errors);
        ensureDateType($pdo,'paste_views','view_date','DATE',$output,$errors);
        if (!indexExists($pdo,'paste_views','unique_paste_ip_date')) { try { $pdo->exec("ALTER TABLE paste_views ADD UNIQUE KEY unique_paste_ip_date (paste_id, ip, view_date)"); } catch (PDOException $e) { error_log($e->getMessage()); } }
        if (!indexExists($pdo,'paste_views','idx_paste_id'))        { try { $pdo->exec("ALTER TABLE paste_views ADD KEY idx_paste_id (paste_id)"); } catch (PDOException $e) { error_log($e->getMessage()); } }
        if (!indexExists($pdo,'paste_views','idx_view_date'))       { try { $pdo->exec("ALTER TABLE paste_views ADD KEY idx_view_date (view_date)"); } catch (PDOException $e) { error_log($e->getMessage()); } }
        if (!fkExists($pdo,'paste_views','paste_views_ibfk_1'))     { try { $pdo->exec("ALTER TABLE paste_views ADD CONSTRAINT paste_views_ibfk_1 FOREIGN KEY (paste_id) REFERENCES pastes(id) ON DELETE CASCADE"); } catch (PDOException $e) { error_log($e->getMessage()); } }
    }

    // paste_comments (threaded)
    if (!tableExists($pdo,'paste_comments')) {
        $pdo->exec("CREATE TABLE paste_comments (
            id INT NOT NULL AUTO_INCREMENT,
            paste_id INT NOT NULL,
            parent_id INT DEFAULT NULL,
            user_id INT DEFAULT NULL,
            username VARCHAR(50) NOT NULL,
            body TEXT NOT NULL,
            body_html_cached TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip VARCHAR(45) NOT NULL,
            deleted_at DATETIME DEFAULT NULL,
            PRIMARY KEY(id),
            KEY idx_paste_time (paste_id, created_at),
            KEY idx_parent (paste_id, parent_id, created_at),
            CONSTRAINT fk_comments_paste  FOREIGN KEY (paste_id)  REFERENCES pastes(id) ON DELETE CASCADE,
            CONSTRAINT fk_comments_parent FOREIGN KEY (parent_id) REFERENCES paste_comments(id) ON DELETE CASCADE,
            CONSTRAINT fk_comments_user   FOREIGN KEY (user_id)   REFERENCES users(id)  ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = 'paste_comments table created (threaded).';
    } else {
        ensureEngineAndCharset($pdo,'paste_comments',$output,$errors);
        ensureColumn($pdo,'paste_comments','id',              "INT NOT NULL AUTO_INCREMENT",$output,$errors);
        ensureColumn($pdo,'paste_comments','paste_id',        "INT NOT NULL",$output,$errors);
        ensureColumn($pdo,'paste_comments','parent_id',       "INT DEFAULT NULL",$output,$errors);
        ensureColumn($pdo,'paste_comments','user_id',         "INT DEFAULT NULL",$output,$errors);
        ensureColumn($pdo,'paste_comments','username',        "VARCHAR(50) NOT NULL",$output,$errors);
        ensureColumn($pdo,'paste_comments','body',            "TEXT NOT NULL",$output,$errors);
        ensureColumn($pdo,'paste_comments','body_html_cached',"TEXT NULL",$output,$errors);
        ensureColumn($pdo,'paste_comments','ip',              "VARCHAR(45) NOT NULL",$output,$errors);
        ensureColumn($pdo,'paste_comments','deleted_at',      "DATETIME DEFAULT NULL",$output,$errors);
        ensureColumn($pdo,'paste_comments','created_at',      "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",$output,$errors);
        if (!indexExists($pdo,'paste_comments','idx_paste_time')) { try { $pdo->exec("ALTER TABLE paste_comments ADD KEY idx_paste_time (paste_id, created_at)"); } catch (PDOException $e) { error_log($e->getMessage()); } }
        if (!indexExists($pdo,'paste_comments','idx_parent'))     { try { $pdo->exec("ALTER TABLE paste_comments ADD KEY idx_parent (paste_id, parent_id, created_at)"); } catch (PDOException $e) { error_log($e->getMessage()); } }
        if (!fkExists($pdo,'paste_comments','fk_comments_paste'))  { try { $pdo->exec("ALTER TABLE paste_comments ADD CONSTRAINT fk_comments_paste FOREIGN KEY (paste_id) REFERENCES pastes(id) ON DELETE CASCADE"); } catch (PDOException $e) { error_log($e->getMessage()); } }
        if (!fkExists($pdo,'paste_comments','fk_comments_parent')) { try { $pdo->exec("ALTER TABLE paste_comments ADD CONSTRAINT fk_comments_parent FOREIGN KEY (parent_id) REFERENCES paste_comments(id) ON DELETE CASCADE"); } catch (PDOException $e) { error_log($e->getMessage()); } }
        if (!fkExists($pdo,'paste_comments','fk_comments_user'))   { try { $pdo->exec("ALTER TABLE paste_comments ADD CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL"); } catch (PDOException $e) { error_log($e->getMessage()); } }
    }

    // visitor_ips
    if (!tableExists($pdo,'visitor_ips')) {
        $pdo->exec("CREATE TABLE visitor_ips (
            id INT NOT NULL AUTO_INCREMENT,
            ip VARCHAR(45) NOT NULL,
            visit_date DATE NOT NULL,
            PRIMARY KEY(id),
            UNIQUE KEY idx_ip_date (ip, visit_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = 'visitor_ips table created.';
    } else {
        ensureEngineAndCharset($pdo,'visitor_ips',$output,$errors);
        ensureColumn($pdo,'visitor_ips','id',        "INT NOT NULL AUTO_INCREMENT",$output,$errors);
        ensureColumn($pdo,'visitor_ips','ip',        "VARCHAR(45) NOT NULL",$output,$errors);
        ensureDateType($pdo,'visitor_ips','visit_date','DATE',$output,$errors);
        if (!indexExists($pdo,'visitor_ips','idx_ip_date')) { try { $pdo->exec("ALTER TABLE visitor_ips ADD UNIQUE KEY idx_ip_date (ip, visit_date)"); } catch (PDOException $e) { error_log($e->getMessage()); }
        }
    }

    // users
    if (!tableExists($pdo,'users')) {
        $pdo->exec("CREATE TABLE users (
            id INT NOT NULL AUTO_INCREMENT,
            oauth_uid VARCHAR(255) DEFAULT NULL,
            username VARCHAR(50) NOT NULL UNIQUE,
            username_locked TINYINT(1) NOT NULL DEFAULT '1',
            email_id VARCHAR(255) NOT NULL,
            full_name VARCHAR(255) NOT NULL,
            platform VARCHAR(50) NOT NULL,
            password VARCHAR(255) DEFAULT '',
            verified ENUM('0','1','2') NOT NULL DEFAULT '0',
            picture VARCHAR(255) DEFAULT 'NONE',
            date DATETIME NOT NULL,
            ip VARCHAR(45) NOT NULL,
            last_ip VARCHAR(45) DEFAULT NULL,
            refresh_token VARCHAR(255) DEFAULT NULL,
            token VARCHAR(512) DEFAULT NULL,
            verification_code VARCHAR(32) DEFAULT NULL,
            reset_code VARCHAR(32) DEFAULT NULL,
            reset_expiry DATETIME DEFAULT NULL,
            remember_token VARCHAR(64) DEFAULT NULL,
            spam_exempt TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = 'users table created.';
    } else {
        ensureEngineAndCharset($pdo,'users',$output,$errors);
        ensureColumn($pdo,'users','id',               "INT NOT NULL AUTO_INCREMENT",$output,$errors);
        ensureColumn($pdo,'users','oauth_uid',        "VARCHAR(255) DEFAULT NULL",$output,$errors);
        ensureColumn($pdo,'users','username',         "VARCHAR(50) NOT NULL",$output,$errors);
        ensureColumn($pdo,'users','username_locked',  "TINYINT(1) NOT NULL DEFAULT '1'",$output,$errors);
        ensureColumn($pdo,'users','email_id',         "VARCHAR(255) NOT NULL",$output,$errors);
        ensureColumn($pdo,'users','full_name',        "VARCHAR(255) NOT NULL",$output,$errors);
        ensureColumn($pdo,'users','platform',         "VARCHAR(50) NOT NULL",$output,$errors);
        ensureColumn($pdo,'users','password',         "VARCHAR(255) DEFAULT ''",$output,$errors);
        ensureColumn($pdo,'users','verified',         "ENUM('0','1','2') NOT NULL DEFAULT '0'",$output,$errors);
        ensureColumn($pdo,'users','picture',          "VARCHAR(255) DEFAULT 'NONE'",$output,$errors);
        ensureDateType($pdo,'users','date','DATETIME',$output,$errors);
        ensureColumn($pdo,'users','ip',               "VARCHAR(45) NOT NULL",$output,$errors);
        ensureColumn($pdo,'users','last_ip',          "VARCHAR(45) DEFAULT NULL",$output,$errors);
        ensureColumn($pdo,'users','refresh_token',    "VARCHAR(255) DEFAULT NULL",$output,$errors);
        ensureColumn($pdo,'users','token',            "VARCHAR(512) DEFAULT NULL",$output,$errors);
        ensureColumn($pdo,'users','verification_code',"VARCHAR(32) DEFAULT NULL",$output,$errors);
        ensureColumn($pdo,'users','reset_code',       "VARCHAR(32) DEFAULT NULL",$output,$errors);
        ensureColumn($pdo,'users','reset_expiry',     "DATETIME DEFAULT NULL",$output,$errors);
        ensureColumn($pdo,'users','remember_token',   "VARCHAR(64) DEFAULT NULL",$output,$errors);
        ensureColumn($pdo,'users','spam_exempt',      "TINYINT(1) NOT NULL DEFAULT 0",$output,$errors);
        if (!indexExists($pdo,'users','username')) {
            try { $pdo->exec("ALTER TABLE users ADD UNIQUE KEY `username` (username)"); } catch (PDOException $e) { error_log($e->getMessage()); }
        }
    }

    // ban_user
    if (!tableExists($pdo,'ban_user')) {
        $pdo->exec("CREATE TABLE ban_user (
            id INT NOT NULL AUTO_INCREMENT,
            ip VARCHAR(45) NOT NULL,
            last_date DATETIME NOT NULL,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = 'ban_user table created.';
    } else {
        ensureEngineAndCharset($pdo,'ban_user',$output,$errors);
        ensureColumn($pdo,'ban_user','id',        "INT NOT NULL AUTO_INCREMENT",$output,$errors);
        ensureColumn($pdo,'ban_user','ip',        "VARCHAR(45) NOT NULL",$output,$errors);
        ensureDateType($pdo,'ban_user','last_date','DATETIME',$output,$errors);
    }

    // mail
    if (!tableExists($pdo,'mail')) {
        $pdo->exec("CREATE TABLE mail (
            id INT NOT NULL AUTO_INCREMENT,
            verification VARCHAR(20) NOT NULL DEFAULT 'enabled',
            smtp_host VARCHAR(255) DEFAULT '',
            smtp_username VARCHAR(255) DEFAULT '',
            smtp_password VARCHAR(255) DEFAULT '',
            smtp_port VARCHAR(10) DEFAULT '',
            protocol VARCHAR(20) NOT NULL DEFAULT '2',
            auth VARCHAR(20) NOT NULL DEFAULT 'true',
            socket VARCHAR(20) NOT NULL DEFAULT 'tls',
            oauth_client_id VARCHAR(255) DEFAULT NULL,
            oauth_client_secret VARCHAR(255) DEFAULT NULL,
            oauth_refresh_token VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT INTO mail (verification, smtp_host, smtp_username, smtp_password, smtp_port, protocol, auth, socket, oauth_client_id, oauth_client_secret, oauth_refresh_token)
            VALUES ('enabled','smtp.gmail.com','','','587','2','true','tls',NULL,NULL,NULL)");
        $output[] = 'mail table created & seeded.';
    } else {
        ensureEngineAndCharset($pdo,'mail',$output,$errors);
        ensureColumn($pdo,'mail','id',                 "INT NOT NULL AUTO_INCREMENT",$output,$errors);
        ensureColumn($pdo,'mail','verification',       "VARCHAR(20) NOT NULL DEFAULT 'enabled'",$output,$errors);
        ensureColumn($pdo,'mail','smtp_host',          "VARCHAR(255) DEFAULT ''",$output,$errors);
        ensureColumn($pdo,'mail','smtp_username',      "VARCHAR(255) DEFAULT ''",$output,$errors);
        ensureColumn($pdo,'mail','smtp_password',      "VARCHAR(255) DEFAULT ''",$output,$errors);
        ensureColumn($pdo,'mail','smtp_port',          "VARCHAR(10) DEFAULT ''",$output,$errors);
        ensureColumn($pdo,'mail','protocol',           "VARCHAR(20) NOT NULL DEFAULT '2'",$output,$errors);
        ensureColumn($pdo,'mail','auth',               "VARCHAR(20) NOT NULL DEFAULT 'true'",$output,$errors);
        ensureColumn($pdo,'mail','socket',             "VARCHAR(20) NOT NULL DEFAULT 'tls'",$output,$errors);
        ensureColumn($pdo,'mail','oauth_client_id',    "VARCHAR(255) DEFAULT NULL",$output,$errors);
        ensureColumn($pdo,'mail','oauth_client_secret',"VARCHAR(255) DEFAULT NULL",$output,$errors);
        ensureColumn($pdo,'mail','oauth_refresh_token',"VARCHAR(255) DEFAULT NULL",$output,$errors);
    }

    // mail_log
    if (!tableExists($pdo,'mail_log')) {
        $pdo->exec("CREATE TABLE mail_log (
            id INT NOT NULL AUTO_INCREMENT,
            email VARCHAR(255) NOT NULL,
            sent_at DATETIME NOT NULL,
            type ENUM('verification','reset','test') NOT NULL,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = 'mail_log table created.';
    } else {
        ensureEngineAndCharset($pdo,'mail_log',$output,$errors);
        ensureColumn($pdo,'mail_log','id',     "INT NOT NULL AUTO_INCREMENT",$output,$errors);
        ensureColumn($pdo,'mail_log','email',  "VARCHAR(255) NOT NULL",$output,$errors);
        ensureDateType($pdo,'mail_log','sent_at','DATETIME',$output,$errors);
        ensureColumn($pdo,'mail_log','type',   "ENUM('verification','reset','test') NOT NULL",$output,$errors);
    }

    // pages
    if (!tableExists($pdo,'pages')) {
        $pdo->exec("CREATE TABLE pages (
            id INT NOT NULL AUTO_INCREMENT,
            last_date DATETIME NOT NULL,
            page_name VARCHAR(255) NOT NULL,
            page_title MEDIUMTEXT NOT NULL,
            page_content LONGTEXT,
            location ENUM('','header','footer','both') NOT NULL DEFAULT '',
            nav_parent INT DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            external_url VARCHAR(500) DEFAULT NULL,
            PRIMARY KEY(id),
            KEY idx_pages_location (location),
            KEY idx_pages_navparent (nav_parent),
            KEY idx_pages_active (is_active),
            CONSTRAINT fk_pages_navparent FOREIGN KEY (nav_parent) REFERENCES pages(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $ins = $pdo->prepare("INSERT INTO pages (last_date, page_name, page_title, page_content, location, nav_parent, sort_order, is_active, external_url)
                              VALUES (:d1,'contact','Contact',:c1,'footer',NULL,0,1,NULL),
                                     (:d2,'terms','Terms of Service',:c2,'footer',NULL,1,1,NULL),
                                     (:d3,'api','API Documentation',NULL,'footer',NULL,2,1,'api-docs.php')");
        $ins->execute([
            ':d1'=>$date, ':d2'=>$date, ':d3'=>$date,
            ':c1'=>'<h1>Contact Us</h1><p>Email: <a href="mailto:admin@example.com">admin@example.com</a></p>',
            ':c2'=>'<h1>Terms of Service</h1><p>Replace this with your actual terms.</p>'
        ]);
        $output[] = 'pages table created & seeded.';
    } else {
        ensureEngineAndCharset($pdo,'pages',$output,$errors);
        ensureColumn($pdo,'pages','id',           "INT NOT NULL AUTO_INCREMENT",$output,$errors);
        ensureDateType($pdo,'pages','last_date','DATETIME',$output,$errors);
        ensureColumn($pdo,'pages','page_name',    "VARCHAR(255) NOT NULL",$output,$errors);
        ensureColumn($pdo,'pages','page_title',   "MEDIUMTEXT NOT NULL",$output,$errors);
        ensureColumn($pdo,'pages','page_content', "LONGTEXT",$output,$errors);
        ensureColumn($pdo,'pages','location',     "ENUM('','header','footer','both') NOT NULL DEFAULT ''",$output,$errors);
        ensureColumn($pdo,'pages','nav_parent',   "INT DEFAULT NULL",$output,$errors);
        ensureColumn($pdo,'pages','sort_order',   "INT NOT NULL DEFAULT 0",$output,$errors);
        ensureColumn($pdo,'pages','is_active',    "TINYINT(1) NOT NULL DEFAULT 1",$output,$errors);
        ensureColumn($pdo,'pages','external_url', "VARCHAR(500) DEFAULT NULL",$output,$errors);
        if (!indexExists($pdo,'pages','idx_pages_location')) { try { $pdo->exec("ALTER TABLE pages ADD KEY idx_pages_location (location)"); } catch (PDOException $e) { error_log($e->getMessage()); } }
        if (!indexExists($pdo,'pages','idx_pages_navparent')) { try { $pdo->exec("ALTER TABLE pages ADD KEY idx_pages_navparent (nav_parent)"); } catch (PDOException $e) { error_log($e->getMessage()); } }
        if (!indexExists($pdo,'pages','idx_pages_active')) { try { $pdo->exec("ALTER TABLE pages ADD KEY idx_pages_active (is_active)"); } catch (PDOException $e) { error_log($e->getMessage()); } }
        if (!fkExists($pdo,'pages','fk_pages_navparent')) { try { $pdo->exec("ALTER TABLE pages ADD CONSTRAINT fk_pages_navparent FOREIGN KEY (nav_parent) REFERENCES pages(id) ON DELETE SET NULL"); } catch (PDOException $e) { error_log($e->getMessage()); } }

        // Ensure default pages exist (not on upgrades, so deleted pages stay deleted)
        if (!$upgrade) {
            try {
                $chk = $pdo->query("SELECT COUNT(*) FROM pages WHERE page_name IN ('contact','terms')");
                if ($chk && (int)$chk->fetchColumn() < 2) {
                    $ins = $pdo->prepare("INSERT IGNORE INTO pages (last_date, page_name, page_title, page_content, location, nav_parent, sort_order, is_active)
                                          VALUES (:d1,'contact','Contact',:c1,'footer',NULL,0,1),
                                                 (:d2,'terms','Terms of Service',:c2,'footer',NULL,1,1)");
                    $ins->execute([
                        ':d1'=>$date, ':d2'=>$date,
                        ':c1'=>'<h1>Contact Us</h1><p>Email: <a href="mailto:admin@example.com">admin@example.com</a></p>',
                        ':c2'=>'<h1>Terms of Service</h1><p>Replace this with your actual terms.</p>'
                    ]);
                    $output[] = 'Default Contact/Terms pages ensured.';
                }
            } catch (Throwable $e) { /* ignore */ }
        }
    }

    // page_view
    if (!tableExists($pdo,'page_view')) {
        $pdo->exec("CREATE TABLE page_view (
            id INT NOT NULL AUTO_INCREMENT,
            date DATE NOT NULL,
            tpage INT UNSIGNED NOT NULL DEFAULT 0,
            tvisit INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = 'page_view table created.';
    } else {
        ensureEngineAndCharset($pdo,'page_view',$output,$errors);
        ensureColumn($pdo,'page_view','id',    "INT NOT NULL AUTO_INCREMENT",$output,$errors);
        ensureDateType($pdo,'page_view','date','DATE',$output,$errors);
        ensureColumn($pdo,'page_view','tpage', "INT UNSIGNED NOT NULL DEFAULT 0",$output,$errors);
        ensureColumn($pdo,'page_view','tvisit',"INT UNSIGNED NOT NULL DEFAULT 0",$output,$errors);
    }

    // ads
    if (!tableExists($pdo,'ads')) {
        $pdo->exec("CREATE TABLE ads (
            id INT NOT NULL AUTO_INCREMENT,
            text_ads TEXT,
            ads_1 TEXT,
            ads_2 TEXT,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT INTO ads (text_ads, ads_1, ads_2) VALUES ('','','')");
        $output[] = 'ads table created & seeded.';
    } else {
        ensureEngineAndCharset($pdo,'ads',$output,$errors);
        ensureColumn($pdo,'ads','id',       "INT NOT NULL AUTO_INCREMENT",$output,$errors);
        ensureColumn($pdo,'ads','text_ads', "TEXT",$output,$errors);
        ensureColumn($pdo,'ads','ads_1',    "TEXT",$output,$errors);
        ensureColumn($pdo,'ads','ads_2',    "TEXT",$output,$errors);
    }

    // sitemap_options
    if (!tableExists($pdo,'sitemap_options')) {
        $pdo->exec("CREATE TABLE sitemap_options (
            id INT NOT NULL AUTO_INCREMENT,
            priority  VARCHAR(10) NOT NULL DEFAULT '0.9',
            changefreq VARCHAR(20) NOT NULL DEFAULT 'daily',
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT INTO sitemap_options (priority, changefreq) VALUES ('0.9','daily')");
        $output[] = 'sitemap_options table created & seeded.';
    } else {
        ensureEngineAndCharset($pdo,'sitemap_options',$output,$errors);
        ensureColumn($pdo,'sitemap_options','id',        "INT NOT NULL AUTO_INCREMENT",$output,$errors);
        ensureColumn($pdo,'sitemap_options','priority',  "VARCHAR(10) NOT NULL DEFAULT '0.9'",$output,$errors);
        ensureColumn($pdo,'sitemap_options','changefreq',"VARCHAR(20) NOT NULL DEFAULT 'daily'",$output,$errors);
    }

    // captcha
    if (!tableExists($pdo,'captcha')) {
        $pdo->exec("CREATE TABLE captcha (
            id INT NOT NULL AUTO_INCREMENT,
            cap_e VARCHAR(10) NOT NULL DEFAULT 'off',
            mode VARCHAR(50) NOT NULL DEFAULT 'Normal',
            recaptcha_version ENUM('v2','v3') DEFAULT 'v2',
            mul VARCHAR(10) NOT NULL DEFAULT 'off',
            allowed TEXT NOT NULL,
            color VARCHAR(7) NOT NULL DEFAULT '#000000',
            recaptcha_sitekey TEXT,
            recaptcha_secretkey TEXT,
            turnstile_sitekey TEXT,
            turnstile_secretkey TEXT,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT INTO captcha (cap_e, mode, recaptcha_version, mul, allowed, color, recaptcha_sitekey, recaptcha_secretkey, turnstile_sitekey, turnstile_secretkey)
                    VALUES ('off','normal','v2','off','ABCDEFGHIJKLMNOPQRSTUVYXYZabcdefghijklmnopqrstuvwxyz0123456789','#000000','','','','')");
        $output[] = 'captcha table created & seeded.';
    } else {
        ensureEngineAndCharset($pdo,'captcha',$output,$errors);
        ensureColumn($pdo,'captcha','id',                 "INT NOT NULL AUTO_INCREMENT",$output,$errors);
        ensureColumn($pdo,'captcha','cap_e',              "VARCHAR(10) NOT NULL DEFAULT 'off'",$output,$errors);
        ensureColumn($pdo,'captcha','mode',               "VARCHAR(50) NOT NULL DEFAULT 'Normal'",$output,$errors);
        ensureColumn($pdo,'captcha','recaptcha_version',  "ENUM('v2','v3') DEFAULT 'v2'",$output,$errors);
        ensureColumn($pdo,'captcha','mul',                "VARCHAR(10) NOT NULL DEFAULT 'off'",$output,$errors);
        ensureColumn($pdo,'captcha','allowed',            "TEXT NOT NULL",$output,$errors);
        ensureColumn($pdo,'captcha','color',              "VARCHAR(7) NOT NULL DEFAULT '#000000'",$output,$errors);
        ensureColumn($pdo,'captcha','recaptcha_sitekey',  "TEXT",$output,$errors);
        ensureColumn($pdo,'captcha','recaptcha_secretkey',"TEXT",$output,$errors);
        ensureColumn($pdo,'captcha','turnstile_sitekey', "TEXT",$output,$errors);
        ensureColumn($pdo,'captcha','turnstile_secretkey',"TEXT",$output,$errors);
    }

    // paste_options (for slug URL settings and other paste-related options)
    if (!tableExists($pdo,'paste_options')) {
        $pdo->exec("CREATE TABLE paste_options (
            id INT NOT NULL AUTO_INCREMENT,
            option_name VARCHAR(100) NOT NULL,
            option_value TEXT,
            PRIMARY KEY(id),
            UNIQUE KEY idx_option_name (option_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Insert default settings for slug URLs
        $pdo->exec("INSERT INTO paste_options (option_name, option_value) VALUES
            ('url_mode', 'slug'),
            ('slug_length', '8')");
        $output[] = 'paste_options table created & seeded with default slug settings.';
    } else {
        ensureEngineAndCharset($pdo,'paste_options',$output,$errors);
        ensureColumn($pdo,'paste_options','id',           "INT NOT NULL AUTO_INCREMENT",$output,$errors);
        ensureColumn($pdo,'paste_options','option_name',  "VARCHAR(100) NOT NULL",$output,$errors);
        ensureColumn($pdo,'paste_options','option_value', "TEXT",$output,$errors);
        if (!indexExists($pdo,'paste_options','idx_option_name')) {
            try { $pdo->exec("ALTER TABLE paste_options ADD UNIQUE KEY idx_option_name (option_name)"); }
            catch (PDOException $e) { error_log($e->getMessage()); }
        }
        // Ensure default options exist
        $check = $pdo->prepare("SELECT COUNT(*) FROM paste_options WHERE option_name = ?");
        $check->execute(['url_mode']);
        if ((int)$check->fetchColumn() === 0) {
            $pdo->exec("INSERT INTO paste_options (option_name, option_value) VALUES ('url_mode', 'slug')");
            $output[] = 'Added default url_mode option.';
        }
        $check->execute(['slug_length']);
        if ((int)$check->fetchColumn() === 0) {
            $pdo->exec("INSERT INTO paste_options (option_name, option_value) VALUES ('slug_length', '8')");
            $output[] = 'Added default slug_length option.';
        }
    }

    // ---- Image and video uploads (/img) ----
    require_once __DIR__ . '/../includes/img_store.php';
    $filesBefore   = tableExists($pdo,'filehost_files') ? getColumnDefinition($pdo,'filehost_files','encrypted') !== null : null;
    $reportsBefore = tableExists($pdo,'img_reports');
    $albumsBefore  = tableExists($pdo,'img_albums');
    if (img_ensure_table($pdo)) {
        if ($filesBefore === null)  { $output[] = 'filehost_files table created.'; }
        if ($filesBefore === false) { $output[] = 'Added encrypted upload columns to filehost_files.'; }
        if (!$reportsBefore)        { $output[] = 'img_reports table created.'; }
        if (!$albumsBefore)         { $output[] = 'img_albums table created.'; }
    } else {
        $errors[] = 'Could not create the upload tables; /img creates them on first use instead.';
    }
    if ($img_choice === 'yes') {
        img_save_settings($pdo, ['img_enabled' => 1, 'img_guests' => $img_guests ? 1 : 0, 'img_encrypt_default' => $img_encrypt ? 1 : 0]);
        $output[] = 'Image and video uploads turned on' . ($img_guests ? ' for members and guests' : ' for members') . ', '
            . ($img_encrypt ? 'encrypted in the browser by default.' : 'encrypted with their links as keys.');
    } elseif ($img_choice === 'no') {
        img_save_settings($pdo, ['img_enabled' => 0]);
        $output[] = 'Image and video uploads turned off (turn them on later in Admin &gt; Uploads).';
    } elseif (!$upgrade) {
        $output[] = 'Image and video uploads: current setting kept (' . ((int)img_settings($pdo, true)['img_enabled'] === 1 ? 'on' : 'off') . ').';
    }

    // ---- Drop entries whose files are gone ----
    try {
        $storeDir = filehost_storage_dir();
        $pruned = filecrypt_prune_missing($pdo, $storeDir);
        if ($pruned['removed'] > 0) {
            $output[] = "Removed {$pruned['removed']} upload entries whose files no longer exist.";
        }
        if ($pruned['held']) {
            $errors[] = "{$pruned['missing']} uploads have no file, but the storage folder <code>" . htmlspecialchars($storeDir, ENT_QUOTES, 'UTF-8')
                . '</code> is empty, so their entries were kept. If that is the right folder, remove them in Admin &gt; Uploads.';
        }
    } catch (Throwable $e) {
        $errors[] = 'Checking stored uploads: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }

    // ---- IRC FILEHOST: single-use token ids ----
    if (!tableExists($pdo,'filehost_jti')) {
        $pdo->exec("CREATE TABLE filehost_jti (
            jti CHAR(64) COLLATE utf8mb4_bin NOT NULL,
            exp INT UNSIGNED NOT NULL,
            account VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL,
            created DATETIME NOT NULL,
            PRIMARY KEY (jti),
            KEY exp (exp)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = 'filehost_jti table created.';
    }

    // api_keys (user API keys for paste submission)
    if (!tableExists($pdo,'api_keys')) {
        $pdo->exec("CREATE TABLE api_keys (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            api_key VARCHAR(64) NOT NULL,
            name VARCHAR(100) DEFAULT 'Default',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            key_prefix VARCHAR(16) DEFAULT NULL,
            scopes VARCHAR(100) DEFAULT NULL,
            PRIMARY KEY(id),
            UNIQUE KEY idx_api_key (api_key),
            KEY idx_user_id (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = 'api_keys table created.';

        // Add API settings to paste_options
        $pdo->exec("INSERT IGNORE INTO paste_options (option_name, option_value) VALUES
            ('api_enabled', '1'),
            ('api_keys_per_user', '5'),
            ('api_rate_limit', '60')");
        $output[] = 'API settings added.';
    } else {
        ensureEngineAndCharset($pdo,'api_keys',$output,$errors);
        ensureColumn($pdo,'api_keys','id',          "INT NOT NULL AUTO_INCREMENT",$output,$errors);
        ensureColumn($pdo,'api_keys','user_id',     "INT NOT NULL",$output,$errors);
        ensureColumn($pdo,'api_keys','api_key',     "VARCHAR(64) NOT NULL",$output,$errors);
        ensureColumn($pdo,'api_keys','name',        "VARCHAR(100) DEFAULT 'Default'",$output,$errors);
        ensureColumn($pdo,'api_keys','created_at',  "DATETIME DEFAULT CURRENT_TIMESTAMP",$output,$errors);
        ensureColumn($pdo,'api_keys','last_used_at',"DATETIME DEFAULT NULL",$output,$errors);
        ensureColumn($pdo,'api_keys','is_active',   "TINYINT(1) DEFAULT 1",$output,$errors);
        ensureColumn($pdo,'api_keys','key_prefix',  "VARCHAR(16) DEFAULT NULL",$output,$errors);
        ensureColumn($pdo,'api_keys','scopes',      "VARCHAR(100) DEFAULT NULL",$output,$errors);
        $hashed = api_keys_hash_stored($pdo);
        if ($hashed > 0) {
            $output[] = "Stored $hashed API keys as hashes; the keys themselves keep working.";
        }
    }

    // api_rate_limits (API requests per key and minute)
    if (!tableExists($pdo,'api_rate_limits')) {
        $pdo->exec("CREATE TABLE api_rate_limits (
            bucket VARCHAR(64) NOT NULL,
            window_start INT NOT NULL,
            hits INT NOT NULL DEFAULT 0,
            PRIMARY KEY (bucket, window_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = 'api_rate_limits table created.';
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    // ---- Spam filter: settings ----
    if (!tableExists($pdo,'spam_settings')) {
        $pdo->exec("CREATE TABLE spam_settings (
            id TINYINT NOT NULL DEFAULT 1,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            block_threshold INT NOT NULL DEFAULT 100,
            review_threshold INT NOT NULL DEFAULT 50,
            max_links INT NOT NULL DEFAULT 4,
            link_weight INT NOT NULL DEFAULT 12,
            link_ratio_pct INT NOT NULL DEFAULT 35,
            link_ratio_weight INT NOT NULL DEFAULT 30,
            min_length INT NOT NULL DEFAULT 24,
            short_weight INT NOT NULL DEFAULT 15,
            caps_weight INT NOT NULL DEFAULT 10,
            guest_weight INT NOT NULL DEFAULT 10,
            dnsbl_enabled TINYINT(1) NOT NULL DEFAULT 0,
            dnsbl_zones TEXT,
            dnsbl_weight INT NOT NULL DEFAULT 40,
            sfs_enabled TINYINT(1) NOT NULL DEFAULT 0,
            sfs_weight INT NOT NULL DEFAULT 50,
            sfs_confidence INT NOT NULL DEFAULT 30,
            ip_cache_ttl INT NOT NULL DEFAULT 3600,
            log_allowed TINYINT(1) NOT NULL DEFAULT 0,
            uribl_enabled TINYINT(1) NOT NULL DEFAULT 0,
            uribl_zones TEXT,
            uribl_weight INT NOT NULL DEFAULT 50,
            bayes_enabled TINYINT(1) NOT NULL DEFAULT 1,
            bayes_min_docs INT NOT NULL DEFAULT 5,
            bayes_spam_pct INT NOT NULL DEFAULT 90,
            bayes_spam_weight INT NOT NULL DEFAULT 60,
            bayes_ham_pct INT NOT NULL DEFAULT 10,
            bayes_ham_weight INT NOT NULL DEFAULT 30,
            trap_enabled TINYINT(1) NOT NULL DEFAULT 1,
            trap_weight INT NOT NULL DEFAULT 150,
            trap_min_seconds INT NOT NULL DEFAULT 3,
            trap_fast_weight INT NOT NULL DEFAULT 30,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT INTO spam_settings (id, dnsbl_zones, uribl_zones) VALUES
            (1, 'zen.spamhaus.org\nbl.spamcop.net\ndnsbl.dronebl.org', 'dbl.spamhaus.org\nmulti.surbl.org')");
        $output[] = 'spam_settings table created.';
    } else {
        ensureEngineAndCharset($pdo,'spam_settings',$output,$errors);
        ensureColumn($pdo,'spam_settings','enabled',          "TINYINT(1) NOT NULL DEFAULT 0",$output,$errors);
        ensureColumn($pdo,'spam_settings','block_threshold',  "INT NOT NULL DEFAULT 100",$output,$errors);
        ensureColumn($pdo,'spam_settings','review_threshold', "INT NOT NULL DEFAULT 50",$output,$errors);
        ensureColumn($pdo,'spam_settings','max_links',        "INT NOT NULL DEFAULT 4",$output,$errors);
        ensureColumn($pdo,'spam_settings','link_weight',      "INT NOT NULL DEFAULT 12",$output,$errors);
        ensureColumn($pdo,'spam_settings','link_ratio_pct',   "INT NOT NULL DEFAULT 35",$output,$errors);
        ensureColumn($pdo,'spam_settings','link_ratio_weight',"INT NOT NULL DEFAULT 30",$output,$errors);
        ensureColumn($pdo,'spam_settings','min_length',       "INT NOT NULL DEFAULT 24",$output,$errors);
        ensureColumn($pdo,'spam_settings','short_weight',     "INT NOT NULL DEFAULT 15",$output,$errors);
        ensureColumn($pdo,'spam_settings','caps_weight',      "INT NOT NULL DEFAULT 10",$output,$errors);
        ensureColumn($pdo,'spam_settings','guest_weight',     "INT NOT NULL DEFAULT 10",$output,$errors);
        ensureColumn($pdo,'spam_settings','dnsbl_enabled',    "TINYINT(1) NOT NULL DEFAULT 0",$output,$errors);
        ensureColumn($pdo,'spam_settings','dnsbl_zones',      "TEXT",$output,$errors);
        ensureColumn($pdo,'spam_settings','dnsbl_weight',     "INT NOT NULL DEFAULT 40",$output,$errors);
        ensureColumn($pdo,'spam_settings','sfs_enabled',      "TINYINT(1) NOT NULL DEFAULT 0",$output,$errors);
        ensureColumn($pdo,'spam_settings','sfs_weight',       "INT NOT NULL DEFAULT 50",$output,$errors);
        ensureColumn($pdo,'spam_settings','sfs_confidence',   "INT NOT NULL DEFAULT 30",$output,$errors);
        ensureColumn($pdo,'spam_settings','ip_cache_ttl',     "INT NOT NULL DEFAULT 3600",$output,$errors);
        ensureColumn($pdo,'spam_settings','log_allowed',      "TINYINT(1) NOT NULL DEFAULT 0",$output,$errors);
        ensureColumn($pdo,'spam_settings','uribl_enabled',    "TINYINT(1) NOT NULL DEFAULT 0",$output,$errors);
        ensureColumn($pdo,'spam_settings','uribl_zones',      "TEXT",$output,$errors);
        ensureColumn($pdo,'spam_settings','uribl_weight',     "INT NOT NULL DEFAULT 50",$output,$errors);
        ensureColumn($pdo,'spam_settings','bayes_enabled',    "TINYINT(1) NOT NULL DEFAULT 1",$output,$errors);
        ensureColumn($pdo,'spam_settings','bayes_min_docs',   "INT NOT NULL DEFAULT 5",$output,$errors);
        ensureColumn($pdo,'spam_settings','bayes_spam_pct',   "INT NOT NULL DEFAULT 90",$output,$errors);
        ensureColumn($pdo,'spam_settings','bayes_spam_weight', "INT NOT NULL DEFAULT 60",$output,$errors);
        ensureColumn($pdo,'spam_settings','bayes_ham_pct',    "INT NOT NULL DEFAULT 10",$output,$errors);
        ensureColumn($pdo,'spam_settings','bayes_ham_weight', "INT NOT NULL DEFAULT 30",$output,$errors);
        ensureColumn($pdo,'spam_settings','trap_enabled',     "TINYINT(1) NOT NULL DEFAULT 1",$output,$errors);
        ensureColumn($pdo,'spam_settings','trap_weight',      "INT NOT NULL DEFAULT 150",$output,$errors);
        ensureColumn($pdo,'spam_settings','trap_min_seconds', "INT NOT NULL DEFAULT 3",$output,$errors);
        ensureColumn($pdo,'spam_settings','trap_fast_weight', "INT NOT NULL DEFAULT 30",$output,$errors);
        try { $pdo->exec("UPDATE spam_settings SET uribl_zones = 'dbl.spamhaus.org\nmulti.surbl.org' WHERE uribl_zones IS NULL"); } catch (PDOException $e) {}
        try { $pdo->exec("INSERT IGNORE INTO spam_settings (id, dnsbl_zones) VALUES (1, 'zen.spamhaus.org\nbl.spamcop.net\ndnsbl.dronebl.org')"); } catch (PDOException $e) {}
    }

    // ---- Spam filter: rules ----
    if (!tableExists($pdo,'spam_rules')) {
        $pdo->exec("CREATE TABLE spam_rules (
            id INT NOT NULL AUTO_INCREMENT,
            rule_type ENUM('keyword','regex','domain') NOT NULL DEFAULT 'keyword',
            pattern VARCHAR(255) NOT NULL,
            weight INT NOT NULL DEFAULT 50,
            target ENUM('content','title','both') NOT NULL DEFAULT 'both',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            note VARCHAR(255) DEFAULT NULL,
            created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY idx_spam_rules_enabled (enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = 'spam_rules table created.';
    } else {
        ensureEngineAndCharset($pdo,'spam_rules',$output,$errors);
        ensureColumn($pdo,'spam_rules','rule_type',"ENUM('keyword','regex','domain') NOT NULL DEFAULT 'keyword'",$output,$errors);
        ensureColumn($pdo,'spam_rules','pattern',  "VARCHAR(255) NOT NULL",$output,$errors);
        ensureColumn($pdo,'spam_rules','weight',   "INT NOT NULL DEFAULT 50",$output,$errors);
        ensureColumn($pdo,'spam_rules','target',   "ENUM('content','title','both') NOT NULL DEFAULT 'both'",$output,$errors);
        ensureColumn($pdo,'spam_rules','enabled',  "TINYINT(1) NOT NULL DEFAULT 1",$output,$errors);
        ensureColumn($pdo,'spam_rules','note',     "VARCHAR(255) DEFAULT NULL",$output,$errors);
    }

    // ---- Spam filter: log ----
    if (!tableExists($pdo,'spam_log')) {
        $pdo->exec("CREATE TABLE spam_log (
            id INT NOT NULL AUTO_INCREMENT,
            created DATETIME NOT NULL,
            ip VARCHAR(45) NOT NULL,
            member VARCHAR(255) NOT NULL DEFAULT 'Guest',
            score INT NOT NULL DEFAULT 0,
            action VARCHAR(10) NOT NULL DEFAULT 'allow',
            reasons TEXT,
            title VARCHAR(255) DEFAULT NULL,
            excerpt VARCHAR(500) DEFAULT NULL,
            PRIMARY KEY(id),
            KEY idx_spam_log_created (created),
            KEY idx_spam_log_ip (ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = 'spam_log table created.';
    } else {
        ensureEngineAndCharset($pdo,'spam_log',$output,$errors);
    }

    // ---- Spam filter: moderation queue ----
    if (!tableExists($pdo,'spam_queue')) {
        $pdo->exec("CREATE TABLE spam_queue (
            id INT NOT NULL AUTO_INCREMENT,
            created DATETIME NOT NULL,
            ip VARCHAR(45) NOT NULL,
            member VARCHAR(255) NOT NULL DEFAULT 'Guest',
            score INT NOT NULL DEFAULT 0,
            reasons TEXT,
            title VARCHAR(255) DEFAULT NULL,
            excerpt VARCHAR(500) DEFAULT NULL,
            payload LONGTEXT NOT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            PRIMARY KEY(id),
            KEY idx_spam_queue_status (status),
            KEY idx_spam_queue_created (created)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = 'spam_queue table created.';
    } else {
        ensureEngineAndCharset($pdo,'spam_queue',$output,$errors);
    }

    // ---- Spam filter: IP reputation cache ----
    if (!tableExists($pdo,'spam_ip_cache')) {
        $pdo->exec("CREATE TABLE spam_ip_cache (
            id INT NOT NULL AUTO_INCREMENT,
            ip VARCHAR(45) NOT NULL,
            score INT NOT NULL DEFAULT 0,
            sources VARCHAR(255) DEFAULT NULL,
            checked_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            UNIQUE KEY idx_spam_ip (ip),
            KEY idx_spam_ip_exp (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = 'spam_ip_cache table created.';
    } else {
        ensureEngineAndCharset($pdo,'spam_ip_cache',$output,$errors);
    }

    // ---- Spam filter: learned filter ----
    if (!tableExists($pdo,'spam_tokens')) {
        $pdo->exec("CREATE TABLE spam_tokens (
            token VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            spam INT UNSIGNED NOT NULL DEFAULT 0,
            ham INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY(token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = 'spam_tokens table created.';
    } else {
        ensureEngineAndCharset($pdo,'spam_tokens',$output,$errors);
    }
    if (!tableExists($pdo,'spam_learned')) {
        $pdo->exec("CREATE TABLE spam_learned (
            hash CHAR(64) NOT NULL,
            label ENUM('spam','ham') NOT NULL,
            created DATETIME NOT NULL,
            PRIMARY KEY(hash),
            KEY idx_spam_learned_label (label)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = 'spam_learned table created.';
    } else {
        ensureEngineAndCharset($pdo,'spam_learned',$output,$errors);
    }


    // ---- Generate slugs for existing pastes that don't have them ----
    if ($generateSlugs) {
        try {
            // Check if there are pastes without slugs
            $stmt = $pdo->query("SELECT COUNT(*) FROM pastes WHERE slug IS NULL OR slug = ''");
            $pastesWithoutSlugs = (int)$stmt->fetchColumn();

            $stmt = $pdo->query("SELECT COUNT(*) FROM pastes");
            $totalPastes = (int)$stmt->fetchColumn();

            if ($pastesWithoutSlugs > 0) {
                $output[] = "Found $pastesWithoutSlugs pastes without slugs (out of $totalPastes total). Generating slugs...";

                // Get slug length from paste_options
                $slugLength = 8;
                try {
                    $stmt = $pdo->query("SELECT option_value FROM paste_options WHERE option_name = 'slug_length'");
                    $row = $stmt->fetch();
                    if ($row && is_numeric($row['option_value'])) {
                        $slugLength = max(4, min(32, (int)$row['option_value']));
                    }
                } catch (PDOException $e) {
                    // Use default
                }

                // Charset for slugs (excludes ambiguous characters)
                $charset = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
                $charsetLen = strlen($charset);

                // Generate slugs in batches
                $batchSize = 100;
                $updated = 0;
                $collisions = 0;

                do {
                    $stmt = $pdo->query("SELECT id FROM pastes WHERE slug IS NULL OR slug = '' LIMIT $batchSize");
                    $pastes = $stmt->fetchAll(PDO::FETCH_COLUMN);

                    foreach ($pastes as $pasteId) {
                        $attempts = 0;
                        $maxAttempts = 10;
                        $currentLength = $slugLength;

                        do {
                            // Generate random slug
                            $slug = '';
                            for ($i = 0; $i < $currentLength; $i++) {
                                $slug .= $charset[random_int(0, $charsetLen - 1)];
                            }

                            // Check for collision
                            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM pastes WHERE slug = ?");
                            $checkStmt->execute([$slug]);
                            $exists = (int)$checkStmt->fetchColumn() > 0;

                            if (!$exists) {
                                // Update the paste with the new slug
                                $updateStmt = $pdo->prepare("UPDATE pastes SET slug = ? WHERE id = ?");
                                $updateStmt->execute([$slug, $pasteId]);
                                $updated++;
                                break;
                            }

                            $attempts++;
                            $collisions++;

                            // Increase length on collision
                            if ($attempts >= $maxAttempts / 2) {
                                $currentLength++;
                            }
                        } while ($attempts < $maxAttempts);
                    }
                } while (count($pastes) === $batchSize);

                $output[] = "Generated slugs for $updated pastes" . ($collisions > 0 ? " ($collisions collisions resolved)" : "") . ".";

                // Regenerate sitemap with new slug URLs (functions.php opens its own connection from the config values)
                global $dbhost, $dbname, $dbuser, $dbpassword;
                $installerPdo = $pdo;
                require_once(__DIR__ . '/../includes/functions.php');
                $pdo = $installerPdo;
                $sitemapResult = regenerateSitemap($pdo);
                if ($sitemapResult['success']) {
                    $output[] = "Sitemap regenerated with {$sitemapResult['count']} URLs.";
                } else {
                    $output[] = "Sitemap regeneration skipped: " . ($sitemapResult['message'] ?? 'unknown error');
                }
            } else {
                if (!$upgrade) $output[] = "All $totalPastes pastes already have slugs - no generation needed.";
            }
        } catch (PDOException $e) {
            error_log("install.php: Slug generation error: " . $e->getMessage());
            $errors[] = 'Slug generation warning: ' . $e->getMessage();
        }
    } else {
        if (!$upgrade) $output[] = 'Slug generation skipped (option not selected).';
    }

    // ---- optional re-key legacy encrypted pastes ----
    if ($old_sec_key_in !== '') {
        try {
            $mig = migrate_encrypted_pastes($pdo, $old_sec_key_in, $sec_key);
            $output[] = sprintf('Re-key summary — checked: %d, converted: %d, skipped: %d, failed: %d',
                $mig['checked'], $mig['converted'], $mig['skipped'], $mig['failed']);
            if (!empty($mig['errors'])) { $errors = array_merge($errors, $mig['errors']); }
        } catch (Throwable $e) {
            $errors[] = 'Re-key failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    } else {
        if (!$upgrade) $output[] = 'Re-key step skipped (no old $sec_key provided).';
    }


    return ['output' => $output, 'errors' => $errors];
}
