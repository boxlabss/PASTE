<?php
/*
 * Paste $v3.2 2025/09/01 https://github.com/boxlabss/PASTE
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See LICENCE for more details.
 */

// Set default timezone
date_default_timezone_set('UTC');
ob_start();
header('Content-Type: application/json; charset=utf-8');

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'  => 'error',
            'message' => 'Fatal error: ' . htmlspecialchars($e['message'], ENT_QUOTES, 'UTF-8') .
                         ' in ' . htmlspecialchars(basename($e['file']), ENT_QUOTES, 'UTF-8') .
                         ':' . (int)$e['line']
        ]);
    }
});

ini_set('display_errors', '0');
ini_set('log_errors', '1');

$config_file = '../config.php';
if (!file_exists($config_file)) {
    ob_end_clean();
    error_log('install.php: config.php not found');
    echo json_encode(['status' => 'error', 'message' => 'config.php not found. Run configure.php first.']);
    exit;
}

try {
    require_once $config_file; // expects: $dbhost,$dbname,$dbuser,$dbpassword,$enablegoog,$enablefb,$enablesmtp,$sec_key
} catch (Throwable $e) {
    ob_end_clean();
    error_log('install.php: include config.php failed: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Failed to include config.php: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]);
    exit;
}

$critical_errors = [];
$warnings = [];
if (!file_exists('../theme/default/login.php')) {
    $critical_errors[] = 'Missing required file: ../theme/default/login.php';
}
$needsOAuth = (!empty($enablegoog) && $enablegoog === 'yes') || (!empty($enablefb) && $enablefb === 'yes');
if ($needsOAuth) {
    if (!file_exists('../oauth/google.php'))  { $warnings[] = 'OAuth enabled but missing ../oauth/google.php'; }
    if (!file_exists('../oauth/vendor/autoload.php')) { $warnings[] = 'OAuth enabled: Composer autoload missing in /oauth. Run: <code>cd oauth && composer require google/apiclient:^2.12 league/oauth2-client</code>'; }
}
if (!empty($enablesmtp) && $enablesmtp === 'yes') {
    if (!file_exists('../mail/mail.php')) { $warnings[] = 'SMTP enabled but missing ../mail/mail.php'; }
    if (!file_exists('../mail/vendor/autoload.php')) { $warnings[] = 'SMTP enabled: Composer autoload missing in /mail. Run: <code>cd mail && composer require phpmailer/phpmailer</code>'; }
}
if ($critical_errors) {
    ob_end_clean();
    error_log('install.php: ' . implode(' | ', $critical_errors));
    echo json_encode(['status' => 'error', 'message' => implode('<br>', $critical_errors)]);
    exit;
}

$admin_user = isset($_POST['admin_user']) ? trim((string)$_POST['admin_user']) : '';
$admin_pass_raw = isset($_POST['admin_pass']) ? (string)$_POST['admin_pass'] : '';
$admin_pass = $admin_pass_raw !== '' ? password_hash($admin_pass_raw, PASSWORD_DEFAULT) : '';
$date = date('Y-m-d H:i:s');

if ($admin_user !== '' && !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $admin_user)) {
    ob_end_clean();
    error_log('install.php: invalid admin username');
    echo json_encode(['status' => 'error', 'message' => 'Username must be 3–50 chars: letters, digits, dot, underscore, dash.']);
    exit;
}
if ($admin_user === '' || $admin_pass_raw === '') {
    ob_end_clean();
    error_log('install.php: missing admin creds');
    echo json_encode(['status' => 'error', 'message' => 'Please provide both admin username and password.']);
    exit;
}

// derive baseurl (site root, not /install/)
$base_path = rtrim(dirname($_SERVER['PHP_SELF'], 2), '/') . '/'; // / when script is /install/install.php
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
$baseurl = $scheme . $host . $base_path;

// ---------- DB connect ----------
try {
    $pdo = new PDO("mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    // soften strict modes that can break legacy TIMESTAMP/DATETIME defaults
    // (keep user's global modes intact)
    try {
        $stmt = $pdo->query("SELECT @@SESSION.sql_mode");
        $m = (string)$stmt->fetchColumn();
        $m2 = array_filter(array_map('trim', explode(',', $m)), function($x) {
            return !in_array($x, ['NO_ZERO_DATE','NO_ZERO_IN_DATE']);
        });
        $pdo->exec("SET SESSION sql_mode='" . implode(',', $m2) . "'");
    } catch (Throwable $e) { /* ..... */ }
} catch (PDOException $e) {
    ob_end_clean();
    error_log('install.php: DB connect failed: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]);
    exit;
}

// ---------- Helpers ----------
function tableExists(PDO $pdo, string $table): bool {
    try {
        $q = $pdo->prepare(
            "SELECT 1
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :t
             LIMIT 1"
        );
        $q->execute([':t' => $table]);
        return (bool) $q->fetchColumn();
    } catch (PDOException $e) {
        error_log("install.php: tableExists($table): " . $e->getMessage());
        return false;
    }
}

function getColumnDefinition(PDO $pdo, string $table, string $column): ?array {
    try {
        $q = $pdo->prepare(
            "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
                    COLLATION_NAME, CHARACTER_SET_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :t
               AND COLUMN_NAME = :c
             LIMIT 1"
        );
        $q->execute([':t' => $table, ':c' => $column]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) {
        error_log("install.php: getColumnDefinition($table.$column): " . $e->getMessage());
        return null;
    }
}

/** normalize column definition snippets for MySQL quirks (ANSI_QUOTES etc.) */
function normalizeColumnDef(string $def): string {
    // DEFAULT "" -> DEFAULT ''
    $def = preg_replace('/DEFAULT\s+""/i', "DEFAULT ''", $def);

    // If it's a TEXT/BLOB/JSON/GEOMETRY type, strip any DEFAULT '' entirely
    if (preg_match('/\b(TEXT|BLOB|JSON|GEOMETRY)\b/i', $def)) {
        $def = preg_replace('/\s+DEFAULT\s+\'\'/i', '', $def);
        $def = preg_replace('/\s+DEFAULT\s+""/i', '', $def);
    }

    // MySQL accepts both "MODIFY" and "MODIFY COLUMN"; keep it simple
    return trim($def);
}

/**
 * Idempotent column ensure:
 *  - If column missing: ADD.
 *  - If exists: MODIFY to expected_def.
 *  - If ADD hits duplicate (1060), immediately try MODIFY (in case of race/driver oddities).
 */
function ensureColumn(PDO $pdo, string $table, string $column, string $expected_def, array &$output, array &$errors): void {
    $expected_def = normalizeColumnDef($expected_def);
    $exists = (bool) getColumnDefinition($pdo, $table, $column);

    if (!$exists) {
        try {
            $pdo->exec("ALTER TABLE `$table` ADD `$column` $expected_def");
            $output[] = "Added column $table.$column.";
            return;
        } catch (PDOException $e) {
            // 1060 duplicate column -> race or detection quirk: try MODIFY
            $info = $e->errorInfo;
            if (!empty($info[1]) && (int)$info[1] === 1060) {
                try {
                    $pdo->exec("ALTER TABLE `$table` MODIFY `$column` $expected_def");
                    $output[] = "Aligned column $table.$column.";
                    return;
                } catch (PDOException $e2) {
                    $errors[] = "Failed to align $table.$column after duplicate: " . htmlspecialchars($e2->getMessage(), ENT_QUOTES, 'UTF-8');
                    error_log("install.php: ensureColumn($table.$column) duplicate->modify failed: " . $e2->getMessage());
                    return;
                }
            }
            // Any other error: record and continue
            $errors[] = "Failed to add $table.$column: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            error_log("install.php: Failed to add $table.$column: " . $e->getMessage());
            return;
        }
    }

    // Exists -> try to align
    try {
        $pdo->exec("ALTER TABLE `$table` MODIFY `$column` $expected_def");
        $output[] = "Aligned column $table.$column.";
    } catch (PDOException $e) {
        // Not fatal; we keep going
        error_log("install.php: Skipped modify for $table.$column: " . $e->getMessage());
    }
}

function indexExists(PDO $pdo, string $table, string $index): bool {
    try {
        $q = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = :k");
        $q->execute([':k' => $index]);
        return (bool) $q->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("install.php: indexExists($table,$index): " . $e->getMessage());
        return false;
    }
}

function fkExists(PDO $pdo, string $table, string $fk): bool {
    try {
        $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
        $sql = "SELECT CONSTRAINT_NAME
                FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = :db
                  AND CONSTRAINT_NAME = :fk
                  AND TABLE_NAME = :tbl";
        $q = $pdo->prepare($sql);
        $q->execute([':db' => $db, ':fk' => $fk, ':tbl' => $table]);
        return (bool) $q->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("install.php: fkExists($table,$fk): " . $e->getMessage());
        return false;
    }
}

function ensureEngineAndCharset(PDO $pdo, string $table, array &$output, array &$errors, string $engine = 'InnoDB', string $charset = 'utf8mb4', string $collate = 'utf8mb4_unicode_ci'): void {
    try {
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $q = $pdo->prepare("SELECT ENGINE, TABLE_COLLATION FROM information_schema.tables WHERE table_schema=:s AND table_name=:t");
        $q->execute([':s' => $db, ':t' => $table]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;
        $curEngine = $row['ENGINE'] ?? '';
        $curColl  = $row['TABLE_COLLATION'] ?? '';
        if (strcasecmp($curEngine, $engine) !== 0) {
            try {
                $pdo->exec("ALTER TABLE `$table` ENGINE=$engine");
                $output[] = "Converted $table to $engine.";
            } catch (PDOException $e) {
                $errors[] = "Failed to convert $table to $engine: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
                error_log("install.php: engine $table: " . $e->getMessage());
            }
        }
        if (stripos((string)$curColl, $collate) === false) {
            try {
                $pdo->exec("ALTER TABLE `$table` CONVERT TO CHARACTER SET $charset COLLATE $collate");
                $output[] = "Converted $table to $charset/$collate.";
            } catch (PDOException $e) {
                $errors[] = "Failed charset/col $table: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
                error_log("install.php: charset $table: " . $e->getMessage());
            }
        }
    } catch (PDOException $e) {
        error_log("install.php: ensureEngineAndCharset($table): " . $e->getMessage());
    }
}

/** Convert legacy TEXT/VARCHAR date column to DATE/DATETIME safely. */
function ensureDateType(PDO $pdo, string $table, string $column, string $targetType, array &$output, array &$errors): void {
    $meta = getColumnDefinition($pdo, $table, $column);
    if (!$meta) return; // column missing is handled elsewhere
    $type = strtolower((string)($meta['Type'] ?? ''));
    $needs = ($targetType === 'DATE') ? (stripos($type, 'date') === false || stripos($type, 'datetime') !== false) : (stripos($type, 'datetime') === false);
    if (!$needs) return;

    $tmp = $column . '_tmp_' . substr(md5($table.$column), 0, 6);
    try {
        $pdo->exec("ALTER TABLE `$table` ADD `$tmp` $targetType NULL DEFAULT NULL");
        // best-effort parsing for common formats + unix seconds
        if ($targetType === 'DATE') {
            $pdo->exec("UPDATE `$table` SET `$tmp` = CASE
                WHEN `$column` REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN STR_TO_DATE(`$column`, '%Y-%m-%d')
                WHEN `$column` REGEXP '^[0-9]{10}$' THEN DATE(FROM_UNIXTIME(`$column`))
                WHEN `$column` REGEXP '^[0-9]{2}/[0-9]{2}/[0-9]{4}$' THEN STR_TO_DATE(`$column`, '%m/%d/%Y')
                ELSE NULL END");
        } else { // DATETIME
            $pdo->exec("UPDATE `$table` SET `$tmp` = CASE
                WHEN `$column` REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}( [0-9]{2}:[0-9]{2}:[0-9]{2})?$' THEN STR_TO_DATE(`$column`, '%Y-%m-%d %H:%i:%s')
                WHEN `$column` REGEXP '^[0-9]{10}$' THEN FROM_UNIXTIME(`$column`)
                WHEN `$column` REGEXP '^[0-9]{2}/[0-9]{2}/[0-9]{4}( [0-9]{2}:[0-9]{2}(:[0-9]{2})?)?$' THEN STR_TO_DATE(`$column`, '%m/%d/%Y %H:%i:%s')
                ELSE NULL END");
        }
        // fill any NULLs with NOW() to satisfy NOT NULL that follows
        $pdo->exec("UPDATE `$table` SET `$tmp` = COALESCE(`$tmp`, NOW()) WHERE `$tmp` IS NULL");
        $pdo->exec("ALTER TABLE `$table` DROP COLUMN `$column`, CHANGE `$tmp` `$column` $targetType NOT NULL");
        $output[] = "Converted $table.$column to $targetType.";
    } catch (PDOException $e) {
        $errors[] = "Failed to convert $table.$column to $targetType: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        error_log("install.php: ensureDateType $table.$column: " . $e->getMessage());
        // clean temp if exists
        try { $pdo->exec("ALTER TABLE `$table` DROP COLUMN `$tmp`"); } catch (Throwable $e2) {}
    }
}

// legacy v2.2 → v3.1 crypto helpers
function hex_or_raw_key(string $input): string {
    $t = trim($input);
    if ($t === '') return '';
    if (ctype_xdigit($t) && (strlen($t) % 2 === 0)) {
        $bin = @hex2bin($t);
        if ($bin !== false) return $bin;
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
    if (!extension_loaded('openssl')) { $res['errors'][] = 'OpenSSL extension not available.'; return $res; }
    $oldKeyRaw = hex_or_raw_key($oldKeyInput);
    if ($oldKeyRaw === '') { $res['errors'][] = 'Old $sec_key not provided.'; return $res; }
    $newKeyBin = @hex2bin(trim($newKeyHex));
    if ($newKeyBin === false) { $res['errors'][] = 'New $sec_key is not valid hex.'; return $res; }
    $total = (int)$pdo->query("SELECT COUNT(*) FROM pastes WHERE encrypt='1'")->fetchColumn();
    if ($total === 0) return $res;
    $batch = 500;
    for ($offset=0; $offset<$total; $offset+=$batch) {
        $q = $pdo->prepare('SELECT id, content FROM pastes WHERE encrypt=\'1\' ORDER BY id ASC LIMIT :lim OFFSET :off');
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
            if (decrypt_v31_with_key($enc, $newKeyBin) !== null) { $res['skipped']++; continue; }
            $plain = decrypt_v22_strict($enc, $oldKeyRaw);
            if ($plain === null) { $res['failed']++; $res['errors'][] = "ID $id: could not decrypt with old key."; continue; }
            $reb = encrypt_v31_with_key($plain, $newKeyBin);
            $u = $pdo->prepare('UPDATE pastes SET content=:c WHERE id=:id');
            $u->execute([':c'=>$reb, ':id'=>$id]);
            $res['converted']++;
        }
        $pdo->commit();
    }
    return $res;
}

$output = [];
$errors = [];

try {
    // We may need engine/charset changes before adding FKs
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

    // ---- core tables ----
    if (!tableExists($pdo, 'admin')) {
        $pdo->exec("CREATE TABLE admin (
            id INT NOT NULL AUTO_INCREMENT,
            user VARCHAR(250) NOT NULL UNIQUE,
            pass VARCHAR(250) NOT NULL,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[]='admin table created.';
    } else {
        ensureEngineAndCharset($pdo,'admin',$output,$errors);
        ensureColumn($pdo,'admin','id',  "INT NOT NULL AUTO_INCREMENT", $output,$errors);
        ensureColumn($pdo,'admin','user',"VARCHAR(250) NOT NULL",       $output,$errors);
        ensureColumn($pdo,'admin','pass',"VARCHAR(250) NOT NULL",       $output,$errors);
        if (!indexExists($pdo,'admin','user')) { try { $pdo->exec('ALTER TABLE admin ADD UNIQUE KEY `user` (user)'); } catch (PDOException $e) { error_log($e->getMessage()); } }
    }
    // admin user seed
    try {
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM admin WHERE user=:u');
        $stmt->execute([':u'=>$admin_user]);
        if ((int)$stmt->fetchColumn()===0) {
            $ins=$pdo->prepare('INSERT INTO admin (user,pass) VALUES (:u,:p)');
            $ins->execute([':u'=>$admin_user, ':p'=>$admin_pass]);
            $output[]='Admin user inserted.';
        } else {
            $output[]='Admin user already exists, skipping insertion.';
        }
    } catch (PDOException $e) { $errors[]='Failed to insert admin user: '.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'); }

    if (!tableExists($pdo,'admin_history')) {
        $pdo->exec("CREATE TABLE admin_history (
            id INT NOT NULL AUTO_INCREMENT,
            last_date DATETIME NOT NULL,
            ip VARCHAR(45) NOT NULL,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[]='admin_history table created.';
    } else {
        ensureEngineAndCharset($pdo,'admin_history',$output,$errors);
        ensureColumn($pdo,'admin_history','id',       'INT NOT NULL AUTO_INCREMENT',$output,$errors);
        ensureColumn($pdo,'admin_history','ip',       'VARCHAR(45) NOT NULL',$output,$errors);
        ensureDateType($pdo,'admin_history','last_date','DATETIME',$output,$errors);
    }

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
        $stmt=$pdo->prepare('INSERT INTO site_info (title,des,keyword,site_name,email,twit,face,gplus,ga,additional_scripts,baseurl) VALUES (:title,:des,:keyword,:site_name,:email,:twit,:face,:gplus,:ga,:scripts,:baseurl)');
        $stmt->execute([
            ':title'=>'Paste',
            ':des'=>'Paste can store text, source code, or sensitive data for a set period of time.',
            ':keyword'=>'paste,pastebin.com,pastebin,text,paste,online paste',
            ':site_name'=>'Paste', ':email'=>'admin@yourdomain.com', ':twit'=>'https://x.com/', ':face'=>'https://www.facebook.com/', ':gplus'=>'', ':ga'=>'', ':scripts'=>'', ':baseurl'=>$baseurl
        ]);
        $output[]='site_info table created & seeded.';
    } else {
        ensureEngineAndCharset($pdo,'site_info',$output,$errors);
        ensureColumn($pdo,'site_info','id',   'INT NOT NULL AUTO_INCREMENT',$output,$errors);
        ensureColumn($pdo,'site_info','title','VARCHAR(255) NOT NULL',$output,$errors);
        ensureColumn($pdo,'site_info','des',  'MEDIUMTEXT',$output,$errors);
        ensureColumn($pdo,'site_info','keyword','MEDIUMTEXT',$output,$errors);
        ensureColumn($pdo,'site_info','site_name','VARCHAR(255) NOT NULL',$output,$errors);
        ensureColumn($pdo,'site_info','email','VARCHAR(255)',$output,$errors);
        ensureColumn($pdo,'site_info','twit','VARCHAR(255)',$output,$errors);
        ensureColumn($pdo,'site_info','face','VARCHAR(255)',$output,$errors);
        ensureColumn($pdo,'site_info','gplus','VARCHAR(255)',$output,$errors);
        ensureColumn($pdo,'site_info','ga','VARCHAR(255)',$output,$errors);
        ensureColumn($pdo,'site_info','additional_scripts','TEXT',$output,$errors);
        ensureColumn($pdo,'site_info','baseurl','TEXT NOT NULL',$output,$errors);
        try { $pdo->prepare('UPDATE site_info SET baseurl=:b WHERE id=1')->execute([':b'=>$baseurl]); $output[]='Updated baseurl in site_info.'; } catch (PDOException $e) { $errors[]='Failed to update baseurl: '.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'); }
    }

    if (!tableExists($pdo,'site_permissions')) {
        $pdo->exec("CREATE TABLE site_permissions (
            id INT NOT NULL AUTO_INCREMENT,
            disableguest VARCHAR(10) NOT NULL DEFAULT 'off',
            siteprivate  VARCHAR(10) NOT NULL DEFAULT 'off',
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT INTO site_permissions (disableguest, siteprivate) VALUES ('off','off')");
        $output[]='site_permissions table created & seeded.';
    } else {
        ensureEngineAndCharset($pdo,'site_permissions',$output,$errors);
        ensureColumn($pdo,'site_permissions','id','INT NOT NULL AUTO_INCREMENT',$output,$errors);
        ensureColumn($pdo,'site_permissions','disableguest',"VARCHAR(10) NOT NULL DEFAULT 'off'",$output,$errors);
        ensureColumn($pdo,'site_permissions','siteprivate', "VARCHAR(10) NOT NULL DEFAULT 'off'",$output,$errors);
    }

    if (!tableExists($pdo,'interface')) {
        $pdo->exec("CREATE TABLE interface (
            id INT NOT NULL AUTO_INCREMENT,
            theme VARCHAR(50) NOT NULL DEFAULT 'default',
            lang  VARCHAR(50) NOT NULL DEFAULT 'en.php',
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT INTO interface (theme, lang) VALUES ('default','en.php')");
        $output[]='interface table created & seeded.';
    } else {
        ensureEngineAndCharset($pdo,'interface',$output,$errors);
        ensureColumn($pdo,'interface','id','INT NOT NULL AUTO_INCREMENT',$output,$errors);
        ensureColumn($pdo,'interface','theme',"VARCHAR(50) NOT NULL DEFAULT 'default'",$output,$errors);
        ensureColumn($pdo,'interface','lang', "VARCHAR(50) NOT NULL DEFAULT 'en.php'",$output,$errors);
    }

    if (!tableExists($pdo,'pastes')) {
        $pdo->exec("CREATE TABLE pastes (
            id INT NOT NULL AUTO_INCREMENT,
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
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[]='pastes table created.';
    } else {
        ensureEngineAndCharset($pdo,'pastes',$output,$errors); // converts legacy MyISAM/latin1
        ensureColumn($pdo,'pastes','id','INT NOT NULL AUTO_INCREMENT',$output,$errors);
        ensureColumn($pdo,'pastes','title',"VARCHAR(255) NOT NULL DEFAULT 'Untitled'",$output,$errors);
        ensureColumn($pdo,'pastes','content','LONGTEXT NOT NULL',$output,$errors);
        ensureColumn($pdo,'pastes','visible',"VARCHAR(10) NOT NULL DEFAULT '0'",$output,$errors);
        ensureColumn($pdo,'pastes','code',   "VARCHAR(50) NOT NULL DEFAULT 'text'",$output,$errors);
        ensureColumn($pdo,'pastes','expiry', 'VARCHAR(50)',$output,$errors);
        ensureColumn($pdo,'pastes','password',"VARCHAR(255) NOT NULL DEFAULT 'NONE'",$output,$errors);
        ensureColumn($pdo,'pastes','encrypt', "VARCHAR(1) NOT NULL DEFAULT '0'",$output,$errors);
        ensureColumn($pdo,'pastes','member',  "VARCHAR(255) NOT NULL DEFAULT 'Guest'",$output,$errors);
        ensureDateType($pdo,'pastes','date','DATETIME',$output,$errors);
        ensureColumn($pdo,'pastes','ip',     'VARCHAR(45) NOT NULL',$output,$errors);
        ensureColumn($pdo,'pastes','now_time','VARCHAR(50)',$output,$errors);
        ensureDateType($pdo,'pastes','s_date','DATE',$output,$errors);
        if (getColumnDefinition($pdo,'pastes','views')) { $output[]="Note: 'views' column exists (deprecated) — using paste_views table."; }
    }

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
        $output[]='paste_views table created.';
    } else {
        ensureEngineAndCharset($pdo,'paste_views',$output,$errors);
        ensureColumn($pdo,'paste_views','id','INT NOT NULL AUTO_INCREMENT',$output,$errors);
        ensureColumn($pdo,'paste_views','paste_id','INT NOT NULL',$output,$errors);
        ensureColumn($pdo,'paste_views','ip','VARCHAR(45) NOT NULL',$output,$errors);
        ensureDateType($pdo,'paste_views','view_date','DATE',$output,$errors);
        if (!indexExists($pdo,'paste_views','unique_paste_ip_date')) { try { $pdo->exec('ALTER TABLE paste_views ADD UNIQUE KEY unique_paste_ip_date (paste_id, ip, view_date)'); } catch (PDOException $e) { error_log($e->getMessage()); } }
        if (!indexExists($pdo,'paste_views','idx_paste_id')) { try { $pdo->exec('ALTER TABLE paste_views ADD KEY idx_paste_id (paste_id)'); } catch (PDOException $e) { error_log($e->getMessage()); } }
        if (!indexExists($pdo,'paste_views','idx_view_date')) { try { $pdo->exec('ALTER TABLE paste_views ADD KEY idx_view_date (view_date)'); } catch (PDOException $e) { error_log($e->getMessage()); } }
        if (!fkExists($pdo,'paste_views','paste_views_ibfk_1')) { try { $pdo->exec('ALTER TABLE paste_views ADD CONSTRAINT paste_views_ibfk_1 FOREIGN KEY (paste_id) REFERENCES pastes(id) ON DELETE CASCADE'); } catch (PDOException $e) { error_log($e->getMessage()); } }
    }

    // ---- COMMENTS (threaded + HTML cache) ----
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
        $output[]='paste_comments table created (threaded).';
    } else {
        ensureEngineAndCharset($pdo,'paste_comments',$output,$errors);
        ensureColumn($pdo,'paste_comments','id','INT NOT NULL AUTO_INCREMENT',$output,$errors);
        ensureColumn($pdo,'paste_comments','paste_id','INT NOT NULL',$output,$errors);
        ensureColumn($pdo,'paste_comments','parent_id','INT DEFAULT NULL',$output,$errors);
        ensureColumn($pdo,'paste_comments','user_id','INT DEFAULT NULL',$output,$errors);
        ensureColumn($pdo,'paste_comments','username','VARCHAR(50) NOT NULL',$output,$errors);
        ensureColumn($pdo,'paste_comments','body','TEXT NOT NULL',$output,$errors);
        ensureColumn($pdo,'paste_comments','body_html_cached','TEXT NULL',$output,$errors);
        ensureColumn($pdo,'paste_comments','ip','VARCHAR(45) NOT NULL',$output,$errors);
        ensureColumn($pdo,'paste_comments','deleted_at','DATETIME DEFAULT NULL',$output,$errors);
        // Important: created_at MUST have DEFAULT for comment posting without explicit value
        ensureColumn($pdo,'paste_comments','created_at','DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',$output,$errors);
        if (!indexExists($pdo,'paste_comments','idx_paste_time')) { try { $pdo->exec('ALTER TABLE paste_comments ADD KEY idx_paste_time (paste_id, created_at)'); } catch (PDOException $e) { error_log($e->getMessage()); } }
        if (!indexExists($pdo,'paste_comments','idx_parent')) { try { $pdo->exec('ALTER TABLE paste_comments ADD KEY idx_parent (paste_id, parent_id, created_at)'); } catch (PDOException $e) { error_log($e->getMessage()); } }
        if (!fkExists($pdo,'paste_comments','fk_comments_paste')) { try { $pdo->exec('ALTER TABLE paste_comments ADD CONSTRAINT fk_comments_paste FOREIGN KEY (paste_id) REFERENCES pastes(id) ON DELETE CASCADE'); } catch (PDOException $e) { error_log($e->getMessage()); } }
        if (!fkExists($pdo,'paste_comments','fk_comments_parent')) { try { $pdo->exec('ALTER TABLE paste_comments ADD CONSTRAINT fk_comments_parent FOREIGN KEY (parent_id) REFERENCES paste_comments(id) ON DELETE CASCADE'); } catch (PDOException $e) { error_log($e->getMessage()); } }
        if (!fkExists($pdo,'paste_comments','fk_comments_user')) { try { $pdo->exec('ALTER TABLE paste_comments ADD CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL'); } catch (PDOException $e) { error_log($e->getMessage()); } }
        $output[] = 'paste_comments table aligned to threaded schema.';
    }

    if (!tableExists($pdo,'visitor_ips')) {
        $pdo->exec("CREATE TABLE visitor_ips (
            id INT NOT NULL AUTO_INCREMENT,
            ip VARCHAR(45) NOT NULL,
            visit_date DATE NOT NULL,
            PRIMARY KEY(id),
            UNIQUE KEY idx_ip_date (ip, visit_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[]='visitor_ips table created.';
    } else {
        ensureEngineAndCharset($pdo,'visitor_ips',$output,$errors);
        ensureColumn($pdo,'visitor_ips','id','INT NOT NULL AUTO_INCREMENT',$output,$errors);
        ensureColumn($pdo,'visitor_ips','ip','VARCHAR(45) NOT NULL',$output,$errors);
        ensureDateType($pdo,'visitor_ips','visit_date','DATE',$output,$errors);
        if (!indexExists($pdo,'visitor_ips','idx_ip_date')) { try { $pdo->exec('ALTER TABLE visitor_ips ADD UNIQUE KEY idx_ip_date (ip, visit_date)'); } catch (PDOException $e) { error_log($e->getMessage()); } }
    }

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
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[]='users table created.';
    } else {
        ensureEngineAndCharset($pdo,'users',$output,$errors); // converts legacy MyISAM/latin1
        ensureColumn($pdo,'users','id','INT NOT NULL AUTO_INCREMENT',$output,$errors);
        ensureColumn($pdo,'users','oauth_uid','VARCHAR(255) DEFAULT NULL',$output,$errors);
        ensureColumn($pdo,'users','username','VARCHAR(50) NOT NULL',$output,$errors);
        ensureColumn($pdo,'users','username_locked',"TINYINT(1) NOT NULL DEFAULT '1'",$output,$errors);
        ensureColumn($pdo,'users','email_id','VARCHAR(255) NOT NULL',$output,$errors);
        ensureColumn($pdo,'users','full_name','VARCHAR(255) NOT NULL',$output,$errors);
        ensureColumn($pdo,'users','platform','VARCHAR(50) NOT NULL',$output,$errors);
        ensureColumn($pdo,'users','password','VARCHAR(255) DEFAULT \"\"',$output,$errors);
        ensureColumn($pdo,'users','verified',"ENUM('0','1','2') NOT NULL DEFAULT '0'",$output,$errors);
        ensureColumn($pdo,'users','picture',"VARCHAR(255) DEFAULT 'NONE'",$output,$errors);
        ensureDateType($pdo,'users','date','DATETIME',$output,$errors); // from TEXT → DATETIME
        ensureColumn($pdo,'users','ip','VARCHAR(45) NOT NULL',$output,$errors);
        ensureColumn($pdo,'users','last_ip','VARCHAR(45) DEFAULT NULL',$output,$errors);
        ensureColumn($pdo,'users','refresh_token','VARCHAR(255) DEFAULT NULL',$output,$errors);
        ensureColumn($pdo,'users','token','VARCHAR(512) DEFAULT NULL',$output,$errors);
        ensureColumn($pdo,'users','verification_code','VARCHAR(32) DEFAULT NULL',$output,$errors);
        ensureColumn($pdo,'users','reset_code','VARCHAR(32) DEFAULT NULL',$output,$errors);
        ensureColumn($pdo,'users','reset_expiry','DATETIME DEFAULT NULL',$output,$errors);
        ensureColumn($pdo,'users','remember_token','VARCHAR(64) DEFAULT NULL',$output,$errors);
        if (!indexExists($pdo,'users','username')) { try { $pdo->exec('ALTER TABLE users ADD UNIQUE KEY `username` (username)'); } catch (PDOException $e) { error_log($e->getMessage()); } }
    }

    if (!tableExists($pdo,'ban_user')) {
        $pdo->exec("CREATE TABLE ban_user (
            id INT NOT NULL AUTO_INCREMENT,
            ip VARCHAR(45) NOT NULL,
            last_date DATETIME NOT NULL,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[]='ban_user table created.';
    } else {
        ensureEngineAndCharset($pdo,'ban_user',$output,$errors);
        ensureColumn($pdo,'ban_user','id','INT NOT NULL AUTO_INCREMENT',$output,$errors);
        ensureColumn($pdo,'ban_user','ip','VARCHAR(45) NOT NULL',$output,$errors);
        ensureDateType($pdo,'ban_user','last_date','DATETIME',$output,$errors);
    }

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
        $output[]='mail table created & seeded.';
    } else {
        ensureEngineAndCharset($pdo,'mail',$output,$errors);
        ensureColumn($pdo,'mail','id','INT NOT NULL AUTO_INCREMENT',$output,$errors);
        ensureColumn($pdo,'mail','verification',"VARCHAR(20) NOT NULL DEFAULT 'enabled'",$output,$errors);
        ensureColumn($pdo,'mail','smtp_host','VARCHAR(255) DEFAULT \"\"',$output,$errors);
        ensureColumn($pdo,'mail','smtp_username','VARCHAR(255) DEFAULT \"\"',$output,$errors);
        ensureColumn($pdo,'mail','smtp_password','VARCHAR(255) DEFAULT \"\"',$output,$errors);
        ensureColumn($pdo,'mail','smtp_port','VARCHAR(10) DEFAULT \"\"',$output,$errors);
        ensureColumn($pdo,'mail','protocol',"VARCHAR(20) NOT NULL DEFAULT '2'",$output,$errors);
        ensureColumn($pdo,'mail','auth',    "VARCHAR(20) NOT NULL DEFAULT 'true'",$output,$errors);
        ensureColumn($pdo,'mail','socket',  "VARCHAR(20) NOT NULL DEFAULT 'tls'",$output,$errors);
        ensureColumn($pdo,'mail','oauth_client_id','VARCHAR(255) DEFAULT NULL',$output,$errors);
        ensureColumn($pdo,'mail','oauth_client_secret','VARCHAR(255) DEFAULT NULL',$output,$errors);
        ensureColumn($pdo,'mail','oauth_refresh_token','VARCHAR(255) DEFAULT NULL',$output,$errors);
    }

    if (!tableExists($pdo,'mail_log')) {
        $pdo->exec("CREATE TABLE mail_log (
            id INT NOT NULL AUTO_INCREMENT,
            email VARCHAR(255) NOT NULL,
            sent_at DATETIME NOT NULL,
            type ENUM('verification','reset','test') NOT NULL,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[]='mail_log table created.';
    } else {
        ensureEngineAndCharset($pdo,'mail_log',$output,$errors);
        ensureColumn($pdo,'mail_log','id','INT NOT NULL AUTO_INCREMENT',$output,$errors);
        ensureColumn($pdo,'mail_log','email','VARCHAR(255) NOT NULL',$output,$errors);
        ensureDateType($pdo,'mail_log','sent_at','DATETIME',$output,$errors);
        ensureColumn($pdo,'mail_log','type',"ENUM('verification','reset','test') NOT NULL",$output,$errors);
    }

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
            PRIMARY KEY(id),
            KEY idx_pages_location (location),
            KEY idx_pages_navparent (nav_parent),
            KEY idx_pages_active (is_active),
            CONSTRAINT fk_pages_navparent FOREIGN KEY (nav_parent) REFERENCES pages(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $ins=$pdo->prepare('INSERT INTO pages (last_date, page_name, page_title, page_content, location, nav_parent, sort_order, is_active) VALUES (:d1,\'contact\',\'Contact\',:c1,\'footer\',NULL,0,1),(:d2,\'terms\',\'Terms of Service\',:c2,\'footer\',NULL,1,1)');
        $ins->execute([':d1'=>$date, ':d2'=>$date, ':c1'=>'<h1>Contact Us</h1><p>Email: <a href="mailto:admin@example.com">admin@example.com</a></p>', ':c2'=>'<h1>Terms of Service</h1><p>Replace this with your actual terms.</p>']);
        $output[]='pages table created & seeded.';
    } else {
        ensureEngineAndCharset($pdo,'pages',$output,$errors);
        ensureColumn($pdo,'pages','id','INT NOT NULL AUTO_INCREMENT',$output,$errors);
        ensureDateType($pdo,'pages','last_date','DATETIME',$output,$errors);
        ensureColumn($pdo,'pages','page_name','VARCHAR(255) NOT NULL',$output,$errors);
        ensureColumn($pdo,'pages','page_title','MEDIUMTEXT NOT NULL',$output,$errors);
        ensureColumn($pdo,'pages','page_content','LONGTEXT',$output,$errors);
        ensureColumn($pdo,'pages','location',"ENUM('','header','footer','both') NOT NULL DEFAULT ''",$output,$errors);
        ensureColumn($pdo,'pages','nav_parent','INT DEFAULT NULL',$output,$errors);
        ensureColumn($pdo,'pages','sort_order','INT NOT NULL DEFAULT 0',$output,$errors);
        ensureColumn($pdo,'pages','is_active','TINYINT(1) NOT NULL DEFAULT 1',$output,$errors);
        if (!indexExists($pdo,'pages','idx_pages_location')) { try { $pdo->exec('ALTER TABLE pages ADD KEY idx_pages_location (location)'); } catch (PDOException $e) { error_log($e->getMessage()); } }
        if (!indexExists($pdo,'pages','idx_pages_navparent')) { try { $pdo->exec('ALTER TABLE pages ADD KEY idx_pages_navparent (nav_parent)'); } catch (PDOException $e) { error_log($e->getMessage()); } }
        if (!indexExists($pdo,'pages','idx_pages_active')) { try { $pdo->exec('ALTER TABLE pages ADD KEY idx_pages_active (is_active)'); } catch (PDOException $e) { error_log($e->getMessage()); } }
        if (!fkExists($pdo,'pages','fk_pages_navparent')) { try { $pdo->exec('ALTER TABLE pages ADD CONSTRAINT fk_pages_navparent FOREIGN KEY (nav_parent) REFERENCES pages(id) ON DELETE SET NULL'); } catch (PDOException $e) { error_log($e->getMessage()); } }
        // seed defaults if missing
        try { $chk=$pdo->query("SELECT COUNT(*) FROM pages WHERE page_name IN ('contact','terms')"); if ($chk && (int)$chk->fetchColumn()<2) { $ins=$pdo->prepare('INSERT IGNORE INTO pages (last_date, page_name, page_title, page_content, location, nav_parent, sort_order, is_active) VALUES (:d1,\'contact\',\'Contact\',:c1,\'footer\',NULL,0,1),(:d2,\'terms\',\'Terms of Service\',:c2,\'footer\',NULL,1,1)'); $ins->execute([':d1'=>$date, ':d2'=>$date, ':c1'=>'<h1>Contact Us</h1><p>Email: <a href="mailto:admin@example.com">admin@example.com</a></p>', ':c2'=>'<h1>Terms of Service</h1><p>Replace this with your actual terms.</p>']); $output[]='Default Contact/Terms pages ensured.'; } } catch (Throwable $e) {}
    }

    if (!tableExists($pdo,'page_view')) {
        $pdo->exec("CREATE TABLE page_view (
            id INT NOT NULL AUTO_INCREMENT,
            date DATE NOT NULL,
            tpage INT UNSIGNED NOT NULL DEFAULT 0,
            tvisit INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[]='page_view table created.';
    } else {
        ensureEngineAndCharset($pdo,'page_view',$output,$errors); // legacy MyISAM→InnoDB
        ensureColumn($pdo,'page_view','id','INT NOT NULL AUTO_INCREMENT',$output,$errors);
        ensureDateType($pdo,'page_view','date','DATE',$output,$errors);
        ensureColumn($pdo,'page_view','tpage','INT UNSIGNED NOT NULL DEFAULT 0',$output,$errors);
        ensureColumn($pdo,'page_view','tvisit','INT UNSIGNED NOT NULL DEFAULT 0',$output,$errors);
    }

    if (!tableExists($pdo,'ads')) {
        $pdo->exec("CREATE TABLE ads (
            id INT NOT NULL AUTO_INCREMENT,
            text_ads TEXT,
            ads_1 TEXT,
            ads_2 TEXT,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT INTO ads (text_ads, ads_1, ads_2) VALUES ('','','')");
        $output[]='ads table created & seeded.';
    } else {
        ensureEngineAndCharset($pdo,'ads',$output,$errors);
        ensureColumn($pdo,'ads','id','INT NOT NULL AUTO_INCREMENT',$output,$errors);
        ensureColumn($pdo,'ads','text_ads','TEXT',$output,$errors);
        ensureColumn($pdo,'ads','ads_1','TEXT',$output,$errors);
        ensureColumn($pdo,'ads','ads_2','TEXT',$output,$errors);
    }

    if (!tableExists($pdo,'sitemap_options')) {
        $pdo->exec("CREATE TABLE sitemap_options (
            id INT NOT NULL AUTO_INCREMENT,
            priority  VARCHAR(10) NOT NULL DEFAULT '0.9',
            changefreq VARCHAR(20) NOT NULL DEFAULT 'daily',
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT INTO sitemap_options (priority, changefreq) VALUES ('0.9','daily')");
        $output[]='sitemap_options table created & seeded.';
    } else {
        ensureEngineAndCharset($pdo,'sitemap_options',$output,$errors);
        ensureColumn($pdo,'sitemap_options','id','INT NOT NULL AUTO_INCREMENT',$output,$errors);
        ensureColumn($pdo,'sitemap_options','priority',"VARCHAR(10) NOT NULL DEFAULT '0.9'",$output,$errors);
        ensureColumn($pdo,'sitemap_options','changefreq',"VARCHAR(20) NOT NULL DEFAULT 'daily'",$output,$errors);
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    // ---------- Optional: re-key legacy encrypted pastes ----------
    $old_sec_key_input = isset($_POST['old_sec_key']) ? (string)$_POST['old_sec_key'] : '';
    try {
        if ($old_sec_key_input !== '') {
            $mig = migrate_encrypted_pastes($pdo, $old_sec_key_input, $sec_key);
            $output[] = sprintf('Re-key summary — checked: %d, converted: %d, skipped: %d, failed: %d', $mig['checked'], $mig['converted'], $mig['skipped'], $mig['failed']);
            if (!empty($mig['errors'])) { $errors = array_merge($errors, $mig['errors']); }
        } else {
            $output[] = 'Re-key step skipped (no old $sec_key provided).';
        }
    } catch (Throwable $e) {
        error_log('install.php re-key error: ' . $e->getMessage());
        $errors[] = 'Re-key failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }

    // ---------- finish ----------
    $post = 'Installation and schema update completed successfully.<br>';
    if ($needsOAuth) {
        if (!empty($enablegoog) && $enablegoog === 'yes') {
            $post .= "<br>Configure Google OAuth in the config - Authorized Redirect URI: {$baseurl}oauth/google.php <br>install dependencies: <small>cd oauth<br>composer require google/apiclient:^2.12 league/oauth2-client:^2.7</small><br>";
        }
        if (!empty($enablefb) && $enablefb === 'yes') {
            $post .= "<br>Configure Facebook OAuth (redirect: {$baseurl}oauth/facebook.php). ";
        }
    }
    if (!empty($enablesmtp) && $enablesmtp === 'yes') {
        $post .= "<br>Configure Gmail SMTP OAuth from the admin panel (Authorized Redirect URI redirect: {$baseurl}oauth/google_smtp.php) <br>install dependencies: </small> cd mail<br>composer require phpmailer/phpmailer:^6.9</small><br>";
    }
    if ($warnings) { $post .= '<br>Notes: ' . implode('<br>', $warnings); }
    if ($errors)   { $post .= '<br>Warnings: ' . implode('<br>', $errors); }
    $post .= ' Remove /install and chmod 600 config.php. Go to <a href="../" class="btn btn-primary">main site</a> or <a href="../admin" class="btn btn-primary">dashboard</a>.';

    ob_end_clean();
    echo json_encode(['status'=>'success','message'=>implode('<br>', $output) . '<br>' . $post]);
} catch (Throwable $e) {
    ob_end_clean();
    error_log('install.php: unexpected error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Unexpected error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]);
} finally {
    try { if (isset($pdo)) { $pdo = null; } } catch (Throwable $e) {}
}
