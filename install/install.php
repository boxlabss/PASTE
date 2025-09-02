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

// Start output buffering
ob_start();

// Ensure JSON content type
header('Content-Type: application/json; charset=utf-8');

// Convert fatals to JSON so the UI shows a useful message
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

// Disable display errors (log instead)
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Check required config
$config_file = '../config.php';
if (!file_exists($config_file)) {
    ob_end_clean();
    error_log("install.php: config.php not found");
    echo json_encode(['status' => 'error', 'message' => 'config.php not found. Run configure.php first.']);
    exit;
}

try {
    require_once $config_file; // provides: $dbhost,$dbname,$dbuser,$dbpassword,$enablegoog,$enablefb,$enablesmtp,$sec_key
} catch (Exception $e) {
    ob_end_clean();
    error_log("install.php: Error including config.php: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Failed to include config.php: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]);
    exit;
}

/**
 * Optional/conditional file checks (do NOT hard fail unless truly critical)
 * theme/default/login.php is considered critical; OAuth/SMTP are conditional
 */
$critical_errors = [];
$warnings = [];

if (!file_exists('../theme/default/login.php')) {
    $critical_errors[] = "Missing required file: ../theme/default/login.php";
}

$needsOAuth = (isset($enablegoog) && $enablegoog === 'yes') || (isset($enablefb) && $enablefb === 'yes');
if ($needsOAuth) {
    if (!file_exists('../oauth/google.php')) {
        $warnings[] = "OAuth enabled but missing ../oauth/google.php";
    }
    if (!file_exists('../oauth/vendor/autoload.php')) {
        $warnings[] = "OAuth enabled: Composer autoload missing in /oauth. Run: <code>cd oauth && composer require google/apiclient:^2.12 league/oauth2-client</code>";
    }
}

if (isset($enablesmtp) && $enablesmtp === 'yes') {
    if (!file_exists('../mail/mail.php')) {
        $warnings[] = "SMTP enabled but missing ../mail/mail.php";
    }
    if (!file_exists('../mail/vendor/autoload.php')) {
        $warnings[] = "SMTP enabled: Composer autoload missing in /mail. Run: <code>cd mail && composer require phpmailer/phpmailer</code>";
    }
}

if (!empty($critical_errors)) {
    ob_end_clean();
    error_log("install.php: " . implode(' | ', $critical_errors));
    echo json_encode(['status' => 'error', 'message' => implode('<br>', $critical_errors)]);
    exit;
}

// Sanitize input (avoid deprecated FILTER_SANITIZE_STRING)
$admin_user = isset($_POST['admin_user']) ? trim($_POST['admin_user']) : '';
$admin_pass = isset($_POST['admin_pass']) ? password_hash($_POST['admin_pass'], PASSWORD_DEFAULT) : '';
$date       = date('Y-m-d H:i:s');

// Basic username policy (optional)
if ($admin_user !== '' && !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $admin_user)) {
    ob_end_clean();
    error_log("install.php: Invalid admin username");
    echo json_encode(['status' => 'error', 'message' => 'Username must be 3–50 chars: letters, digits, dot, underscore, dash.']);
    exit;
}

// Validate admin credentials
if (empty($admin_user) || empty($_POST['admin_pass'])) {
    ob_end_clean();
    error_log("install.php: Missing admin user or password");
    echo json_encode(['status' => 'error', 'message' => 'Please provide both admin username and password.']);
    exit;
}

// Connect to database using PDO
try {
    $pdo = new PDO("mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpassword);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->exec("SET time_zone = '+00:00'");
} catch (PDOException $e) {
    ob_end_clean();
    error_log("install.php: Database connection failed: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]);
    exit;
}

// Calculate base URL with trailing slash
$base_path = rtrim(dirname($_SERVER['PHP_SELF'], 2), '/') . '/';
$baseurl   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['SERVER_NAME'] . $base_path;

// ---------- Helpers ----------
function tableExists($pdo, $table) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
        return $stmt && $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("install.php: Error checking table $table: " . $e->getMessage());
        return false;
    }
}
function getColumnDefinition($pdo, $table, $column) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($column));
        return $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    } catch (PDOException $e) {
        error_log("install.php: Error checking column $column in $table: " . $e->getMessage());
        return false;
    }
}
/**
 * Very lightweight column ensure:
 * - Adds column if missing.
 * - If present, tries MODIFY to expected definition (best-effort).
 */
function ensureColumn($pdo, $table, $column, $expected_def, &$output, &$errors) {
    $current = getColumnDefinition($pdo, $table, $column);
    if (!$current) {
        try {
            $pdo->exec("ALTER TABLE `$table` ADD `$column` $expected_def");
            $output[] = "Added column $table.$column.";
        } catch (PDOException $e) {
            $errors[] = "Failed to add $table.$column: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            error_log("install.php: Failed to add $table.$column: " . $e->getMessage());
        }
        return;
    }
    try {
        $pdo->exec("ALTER TABLE `$table` MODIFY `$column` $expected_def");
        $output[] = "Aligned column $table.$column.";
    } catch (PDOException $e) {
        error_log("install.php: Skipped modify for $table.$column: " . $e->getMessage());
    }
}
function indexExists(PDO $pdo, string $table, string $index): bool {
    try {
        $q = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = :k");
        $q->execute([':k' => $index]);
        return (bool)$q->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("install.php: indexExists($table,$index): " . $e->getMessage());
        return false;
    }
}
function fkExists(PDO $pdo, string $table, string $fk): bool {
    try {
        $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
        $sql = "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = :db AND CONSTRAINT_NAME = :fk AND TABLE_NAME = :tbl";
        $q = $pdo->prepare($sql);
        $q->execute([':db'=>$db, ':fk'=>$fk, ':tbl'=>$table]);
        return (bool)$q->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("install.php: fkExists($table,$fk): " . $e->getMessage());
        return false;
    }
}

// ===== Rekey helpers (legacy v2.2 -> new v3.1) =====
function hex_or_raw_key(string $input): string {
    $t = trim($input);
    if ($t === '') return '';
    if (ctype_xdigit($t) && (strlen($t) % 2 === 0)) {
        $bin = @hex2bin($t);
        if ($bin !== false) return $bin;   // treat hex as bytes if valid
    }
    return $t; // otherwise use as raw passphrase
}

/**
 * v2.2 decryption attempts strictly matching the old code:
 * - Primary: openssl_decrypt($base64, AES-256-CBC, $key)  (PHP auto-base64, empty IV)
 * - Fallbacks:
 *    - base64_decode + RAW_DATA + zero IV (some PHP/OS combos)
 *    - key = md5($key, true)  (if someone mistakenly used SECRET as the key)
 *    - key = md5($key)        (hex string variant)
 */
function decrypt_v22_strict(string $value, string $oldKeyRaw): ?string {
    $cipher = 'AES-256-CBC';
    $ivlen  = openssl_cipher_iv_length($cipher);
    $zeroIv = str_repeat("\0", $ivlen);

    // 1) Exact legacy style (PHP auto-decoding base64, empty IV)
    $pt = @openssl_decrypt($value, $cipher, $oldKeyRaw);
    if ($pt !== false && $pt !== '') return $pt;

    // 2) base64 -> raw + zero IV
    $decoded = base64_decode($value, true);
    if ($decoded !== false) {
        $pt = @openssl_decrypt($decoded, $cipher, $oldKeyRaw, OPENSSL_RAW_DATA, $zeroIv);
        if ($pt !== false && $pt !== '') return $pt;
    }

    // 3) Try md5(key) as *binary* (SECRET behavior)
    $md5bin = md5($oldKeyRaw, true);
    $pt = @openssl_decrypt($value, $cipher, $md5bin);
    if ($pt !== false && $pt !== '') return $pt;
    if ($decoded !== false) {
        $pt = @openssl_decrypt($decoded, $cipher, $md5bin, OPENSSL_RAW_DATA, $zeroIv);
        if ($pt !== false && $pt !== '') return $pt;
    }

    // 4) Try md5(key) as *hex string* (just in case)
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

/**
 * Re-key all rows with encrypt='1':
 * - Skip if already decryptable with the NEW key (means already v3.1/new-key)
 * - Else decrypt with *old* key (v2.2 strict), then re-encrypt with NEW key (v3.1)
 */
function migrate_encrypted_pastes(PDO $pdo, string $oldKeyInput, string $newKeyHex): array {
    $res = ['checked'=>0,'converted'=>0,'skipped'=>0,'failed'=>0,'errors'=>[]];

    if (!extension_loaded('openssl')) {
        $res['errors'][] = 'OpenSSL extension not available.';
        return $res;
    }
    $oldKeyRaw = hex_or_raw_key($oldKeyInput);
    if ($oldKeyRaw === '') {
        $res['errors'][] = 'Old $sec_key not provided.';
        return $res;
    }
    $newKeyBin = @hex2bin(trim($newKeyHex));
    if ($newKeyBin === false) {
        $res['errors'][] = 'New $sec_key is not valid hex.';
        return $res;
    }

    // Any encrypted rows?
    $total = (int)$pdo->query("SELECT COUNT(*) FROM pastes WHERE encrypt='1'")->fetchColumn();
    if ($total === 0) return $res;

    $batch = 500;
    for ($offset=0; $offset < $total; $offset += $batch) {
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

            // Already new-format under new key? skip.
            if (decrypt_v31_with_key($enc, $newKeyBin) !== null) {
                $res['skipped']++;
                continue;
            }

            // Legacy decrypt (strict to v2.2 behavior)
            $plain = decrypt_v22_strict($enc, $oldKeyRaw);
            if ($plain === null) {
                $res['failed']++;
                $res['errors'][] = "ID {$id}: could not decrypt with old key.";
                continue;
            }

            // Re-encrypt with new key
            $reb = encrypt_v31_with_key($plain, $newKeyBin);
            $u = $pdo->prepare("UPDATE pastes SET content=:c WHERE id=:id");
            $u->execute([':c'=>$reb, ':id'=>$id]);
            $res['converted']++;
        }
        $pdo->commit();
    }

    return $res;
}

// Initialize output array
$output = [];
$errors = [];

try {
    // --- admin ---
    if (!tableExists($pdo, 'admin')) {
        $pdo->exec("CREATE TABLE admin (
            id INT NOT NULL AUTO_INCREMENT,
            user VARCHAR(250) NOT NULL UNIQUE,
            pass VARCHAR(250) NOT NULL,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = "admin table created.";
    } else {
        ensureColumn($pdo, 'admin', 'id',   "INT NOT NULL AUTO_INCREMENT", $output, $errors);
        ensureColumn($pdo, 'admin', 'user', "VARCHAR(250) NOT NULL",       $output, $errors);
        ensureColumn($pdo, 'admin', 'pass', "VARCHAR(250) NOT NULL",       $output, $errors);
        if (!indexExists($pdo, 'admin', 'user')) {
            try { $pdo->exec("ALTER TABLE admin ADD UNIQUE KEY `user` (user)"); } catch (PDOException $e) { error_log($e->getMessage()); }
        }
    }
    // Admin user
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin WHERE user = :u");
        $stmt->execute([':u' => $admin_user]);
        if ((int)$stmt->fetchColumn() === 0) {
            $ins = $pdo->prepare("INSERT INTO admin (user, pass) VALUES (:u,:p)");
            $ins->execute([':u'=>$admin_user, ':p'=>$admin_pass]);
            $output[] = "Admin user inserted.";
        } else {
            $output[] = "Admin user already exists, skipping insertion.";
        }
    } catch (PDOException $e) {
        $errors[] = "Failed to insert admin user: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        error_log("install.php: Admin user insertion failed: " . $e->getMessage());
    }

    // --- admin_history ---
    if (!tableExists($pdo, 'admin_history')) {
        $pdo->exec("CREATE TABLE admin_history (
            id INT NOT NULL AUTO_INCREMENT,
            last_date DATETIME NOT NULL,
            ip VARCHAR(45) NOT NULL,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = "admin_history table created.";
    } else {
        ensureColumn($pdo, 'admin_history', 'id',        "INT NOT NULL AUTO_INCREMENT", $output, $errors);
        ensureColumn($pdo, 'admin_history', 'last_date', "DATETIME NOT NULL",           $output, $errors);
        ensureColumn($pdo, 'admin_history', 'ip',        "VARCHAR(45) NOT NULL",        $output, $errors);
    }

    // --- site_info ---
    if (!tableExists($pdo, 'site_info')) {
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
        $output[] = "site_info table created.";
        $stmt = $pdo->prepare("INSERT INTO site_info
            (title, des, keyword, site_name, email, twit, face, gplus, ga, additional_scripts, baseurl)
            VALUES (:title,:des,:keyword,:site_name,:email,:twit,:face,:gplus,:ga,:scripts,:baseurl)");
        $stmt->execute([
            ':title'   => 'Paste',
            ':des'     => 'Paste can store text, source code, or sensitive data for a set period of time.',
            ':keyword' => 'paste,pastebin.com,pastebin,text,paste,online paste',
            ':site_name'=> 'Paste',
            ':email'   => 'admin@yourdomain.com',
            ':twit'    => 'https://x.com/',
            ':face'    => 'https://www.facebook.com/',
            ':gplus'   => '',
            ':ga'      => '',
            ':scripts' => '',
            ':baseurl' => $baseurl
        ]);
        $output[] = "Site info inserted.";
    } else {
        ensureColumn($pdo, 'site_info', 'id',                 "INT NOT NULL AUTO_INCREMENT", $output, $errors);
        ensureColumn($pdo, 'site_info', 'title',              "VARCHAR(255) NOT NULL",       $output, $errors);
        ensureColumn($pdo, 'site_info', 'des',                "MEDIUMTEXT",                  $output, $errors);
        ensureColumn($pdo, 'site_info', 'keyword',            "MEDIUMTEXT",                  $output, $errors);
        ensureColumn($pdo, 'site_info', 'site_name',          "VARCHAR(255) NOT NULL",       $output, $errors);
        ensureColumn($pdo, 'site_info', 'email',              "VARCHAR(255)",                $output, $errors);
        ensureColumn($pdo, 'site_info', 'twit',               "VARCHAR(255)",                $output, $errors);
        ensureColumn($pdo, 'site_info', 'face',               "VARCHAR(255)",                $output, $errors);
        ensureColumn($pdo, 'site_info', 'gplus',              "VARCHAR(255)",                $output, $errors);
        ensureColumn($pdo, 'site_info', 'ga',                 "VARCHAR(255)",                $output, $errors);
        ensureColumn($pdo, 'site_info', 'additional_scripts', "TEXT",                        $output, $errors);
        ensureColumn($pdo, 'site_info', 'baseurl',            "TEXT NOT NULL",               $output, $errors);
        try {
            $stmt = $pdo->prepare("UPDATE site_info SET baseurl=:b WHERE id=1");
            $stmt->execute([':b'=>$baseurl]);
            $output[] = "Updated baseurl in site_info.";
        } catch (PDOException $e) {
            $errors[] = "Failed to update baseurl in site_info: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            error_log("install.php: Failed to update baseurl in site_info: " . $e->getMessage());
        }
    }

    // --- site_permissions ---
    if (!tableExists($pdo, 'site_permissions')) {
        $pdo->exec("CREATE TABLE site_permissions (
            id INT NOT NULL AUTO_INCREMENT,
            disableguest VARCHAR(10) NOT NULL DEFAULT 'off',
            siteprivate  VARCHAR(10) NOT NULL DEFAULT 'off',
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT INTO site_permissions (disableguest, siteprivate) VALUES ('off','off')");
        $output[] = "site_permissions table created and seeded.";
    } else {
        ensureColumn($pdo, 'site_permissions', 'id',           "INT NOT NULL AUTO_INCREMENT",         $output, $errors);
        ensureColumn($pdo, 'site_permissions', 'disableguest', "VARCHAR(10) NOT NULL DEFAULT 'off'",  $output, $errors);
        ensureColumn($pdo, 'site_permissions', 'siteprivate',  "VARCHAR(10) NOT NULL DEFAULT 'off'",  $output, $errors);
    }

    // --- interface ---
    if (!tableExists($pdo, 'interface')) {
        $pdo->exec("CREATE TABLE interface (
            id INT NOT NULL AUTO_INCREMENT,
            theme VARCHAR(50) NOT NULL DEFAULT 'default',
            lang  VARCHAR(50) NOT NULL DEFAULT 'en.php',
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT INTO interface (theme, lang) VALUES ('default','en.php')");
        $output[] = "interface table created and seeded.";
    } else {
        ensureColumn($pdo, 'interface', 'id',    "INT NOT NULL AUTO_INCREMENT",     $output, $errors);
        ensureColumn($pdo, 'interface', 'theme', "VARCHAR(50) NOT NULL DEFAULT 'default'", $output, $errors);
        ensureColumn($pdo, 'interface', 'lang',  "VARCHAR(50) NOT NULL DEFAULT 'en.php'",  $output, $errors);
    }

    // --- pastes ---
    if (!tableExists($pdo, 'pastes')) {
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
        $output[] = "pastes table created.";
    } else {
        ensureColumn($pdo, 'pastes', 'id',       "INT NOT NULL AUTO_INCREMENT",                 $output, $errors);
        ensureColumn($pdo, 'pastes', 'title',    "VARCHAR(255) NOT NULL DEFAULT 'Untitled'",    $output, $errors);
        ensureColumn($pdo, 'pastes', 'content',  "LONGTEXT NOT NULL",                           $output, $errors);
        ensureColumn($pdo, 'pastes', 'visible',  "VARCHAR(10) NOT NULL DEFAULT '0'",            $output, $errors);
        ensureColumn($pdo, 'pastes', 'code',     "VARCHAR(50) NOT NULL DEFAULT 'text'",         $output, $errors);
        ensureColumn($pdo, 'pastes', 'expiry',   "VARCHAR(50)",                                 $output, $errors);
        ensureColumn($pdo, 'pastes', 'password', "VARCHAR(255) NOT NULL DEFAULT 'NONE'",        $output, $errors);
        ensureColumn($pdo, 'pastes', 'encrypt',  "VARCHAR(1) NOT NULL DEFAULT '0'",             $output, $errors);
        ensureColumn($pdo, 'pastes', 'member',   "VARCHAR(255) NOT NULL DEFAULT 'Guest'",       $output, $errors);
        ensureColumn($pdo, 'pastes', 'date',     "DATETIME NOT NULL",                           $output, $errors);
        ensureColumn($pdo, 'pastes', 'ip',       "VARCHAR(45) NOT NULL",                        $output, $errors);
        ensureColumn($pdo, 'pastes', 'now_time', "VARCHAR(50)",                                 $output, $errors);
        ensureColumn($pdo, 'pastes', 's_date',   "DATE",                                        $output, $errors);
        if (getColumnDefinition($pdo, 'pastes', 'views')) {
            $output[] = "Note: 'views' column in pastes is deprecated. Using paste_views table.";
        }
    }

    // --- paste_views ---
    if (!tableExists($pdo, 'paste_views')) {
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
        $output[] = "paste_views table created.";
    } else {
        ensureColumn($pdo, 'paste_views', 'id',        "INT NOT NULL AUTO_INCREMENT", $output, $errors);
        ensureColumn($pdo, 'paste_views', 'paste_id',  "INT NOT NULL",                $output, $errors);
        ensureColumn($pdo, 'paste_views', 'ip',        "VARCHAR(45) NOT NULL",        $output, $errors);
        ensureColumn($pdo, 'paste_views', 'view_date', "DATE NOT NULL",               $output, $errors);
        if (!indexExists($pdo, 'paste_views', 'unique_paste_ip_date')) {
            try { $pdo->exec("ALTER TABLE paste_views ADD UNIQUE KEY unique_paste_ip_date (paste_id, ip, view_date)"); } catch (PDOException $e) { error_log($e->getMessage()); }
        }
        if (!indexExists($pdo, 'paste_views', 'idx_paste_id')) {
            try { $pdo->exec("ALTER TABLE paste_views ADD KEY idx_paste_id (paste_id)"); } catch (PDOException $e) { error_log($e->getMessage()); }
        }
        if (!indexExists($pdo, 'paste_views', 'idx_view_date')) {
            try { $pdo->exec("ALTER TABLE paste_views ADD KEY idx_view_date (view_date)"); } catch (PDOException $e) { error_log($e->getMessage()); }
        }
        if (!fkExists($pdo, 'paste_views', 'paste_views_ibfk_1')) {
            try { $pdo->exec("ALTER TABLE paste_views ADD CONSTRAINT paste_views_ibfk_1 FOREIGN KEY (paste_id) REFERENCES pastes(id) ON DELETE CASCADE"); } catch (PDOException $e) { error_log($e->getMessage()); }
        }
    }

    // --- paste_comments ---
    if (!tableExists($pdo, 'paste_comments')) {
        $pdo->exec("CREATE TABLE paste_comments (
            id INT NOT NULL AUTO_INCREMENT,
            paste_id INT NOT NULL,
            user_id INT DEFAULT NULL,
            username VARCHAR(50) NOT NULL,
            body TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            ip VARCHAR(45) NOT NULL,
            PRIMARY KEY(id),
            KEY idx_paste_time (paste_id, created_at),
            CONSTRAINT fk_comments_paste FOREIGN KEY (paste_id) REFERENCES pastes(id) ON DELETE CASCADE,
            CONSTRAINT fk_comments_user  FOREIGN KEY (user_id) REFERENCES users(id)  ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = "paste_comments table created.";
    } else {
        ensureColumn($pdo, 'paste_comments', 'id',         "INT NOT NULL AUTO_INCREMENT", $output, $errors);
        ensureColumn($pdo, 'paste_comments', 'paste_id',   "INT NOT NULL",                $output, $errors);
        ensureColumn($pdo, 'paste_comments', 'user_id',    "INT DEFAULT NULL",            $output, $errors);
        ensureColumn($pdo, 'paste_comments', 'username',   "VARCHAR(50) NOT NULL",        $output, $errors);
        ensureColumn($pdo, 'paste_comments', 'body',       "TEXT NOT NULL",               $output, $errors);
        ensureColumn($pdo, 'paste_comments', 'created_at', "DATETIME NOT NULL",           $output, $errors);
        ensureColumn($pdo, 'paste_comments', 'ip',         "VARCHAR(45) NOT NULL",        $output, $errors);
        if (!indexExists($pdo, 'paste_comments', 'idx_paste_time')) {
            try { $pdo->exec("ALTER TABLE paste_comments ADD KEY idx_paste_time (paste_id, created_at)"); } catch (PDOException $e) { error_log($e->getMessage()); }
        }
        if (!fkExists($pdo, 'paste_comments', 'fk_comments_paste')) {
            try { $pdo->exec("ALTER TABLE paste_comments ADD CONSTRAINT fk_comments_paste FOREIGN KEY (paste_id) REFERENCES pastes(id) ON DELETE CASCADE"); } catch (PDOException $e) { error_log($e->getMessage()); }
        }
        if (!fkExists($pdo, 'paste_comments', 'fk_comments_user')) {
            try { $pdo->exec("ALTER TABLE paste_comments ADD CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL"); } catch (PDOException $e) { error_log($e->getMessage()); }
        }
    }

    // --- visitor_ips ---
    if (!tableExists($pdo, 'visitor_ips')) {
        $pdo->exec("CREATE TABLE visitor_ips (
            id INT NOT NULL AUTO_INCREMENT,
            ip VARCHAR(45) NOT NULL,
            visit_date DATE NOT NULL,
            PRIMARY KEY(id),
            UNIQUE KEY idx_ip_date (ip, visit_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = "visitor_ips table created.";
    } else {
        ensureColumn($pdo, 'visitor_ips', 'id',         "INT NOT NULL AUTO_INCREMENT", $output, $errors);
        ensureColumn($pdo, 'visitor_ips', 'ip',         "VARCHAR(45) NOT NULL",        $output, $errors);
        ensureColumn($pdo, 'visitor_ips', 'visit_date', "DATE NOT NULL",               $output, $errors);
        if (!indexExists($pdo, 'visitor_ips', 'idx_ip_date')) {
            try { $pdo->exec("ALTER TABLE visitor_ips ADD UNIQUE KEY idx_ip_date (ip, visit_date)"); } catch (PDOException $e) { error_log($e->getMessage()); }
        }
    }

    // --- users ---
    if (!tableExists($pdo, 'users')) {
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
        $output[] = "users table created.";
    } else {
        ensureColumn($pdo, 'users', 'id',               "INT NOT NULL AUTO_INCREMENT",                 $output, $errors);
        ensureColumn($pdo, 'users', 'oauth_uid',        "VARCHAR(255) DEFAULT NULL",                   $output, $errors);
        ensureColumn($pdo, 'users', 'username',         "VARCHAR(50) NOT NULL",                        $output, $errors);
        ensureColumn($pdo, 'users', 'username_locked',  "TINYINT(1) NOT NULL DEFAULT '1'",             $output, $errors);
        ensureColumn($pdo, 'users', 'email_id',         "VARCHAR(255) NOT NULL",                       $output, $errors);
        ensureColumn($pdo, 'users', 'full_name',        "VARCHAR(255) NOT NULL",                       $output, $errors);
        ensureColumn($pdo, 'users', 'platform',         "VARCHAR(50) NOT NULL",                        $output, $errors);
        ensureColumn($pdo, 'users', 'password',         "VARCHAR(255) DEFAULT ''",                     $output, $errors);
        ensureColumn($pdo, 'users', 'verified',         "ENUM('0','1','2') NOT NULL DEFAULT '0'",      $output, $errors);
        ensureColumn($pdo, 'users', 'picture',          "VARCHAR(255) DEFAULT 'NONE'",                 $output, $errors);
        ensureColumn($pdo, 'users', 'date',             "DATETIME NOT NULL",                           $output, $errors);
        ensureColumn($pdo, 'users', 'ip',               "VARCHAR(45) NOT NULL",                        $output, $errors);
        ensureColumn($pdo, 'users', 'last_ip',          "VARCHAR(45) DEFAULT NULL",                    $output, $errors);
        ensureColumn($pdo, 'users', 'refresh_token',    "VARCHAR(255) DEFAULT NULL",                   $output, $errors);
        ensureColumn($pdo, 'users', 'token',            "VARCHAR(512) DEFAULT NULL",                   $output, $errors);
        ensureColumn($pdo, 'users', 'verification_code',"VARCHAR(32) DEFAULT NULL",                    $output, $errors);
        ensureColumn($pdo, 'users', 'reset_code',       "VARCHAR(32) DEFAULT NULL",                    $output, $errors);
        ensureColumn($pdo, 'users', 'reset_expiry',     "DATETIME DEFAULT NULL",                       $output, $errors);
        ensureColumn($pdo, 'users', 'remember_token',   "VARCHAR(64) DEFAULT NULL",                    $output, $errors);
        if (!indexExists($pdo, 'users', 'username')) {
            try { $pdo->exec("ALTER TABLE users ADD UNIQUE KEY `username` (username)"); } catch (PDOException $e) { error_log($e->getMessage()); }
        }
    }

    // --- ban_user ---
    if (!tableExists($pdo, 'ban_user')) {
        $pdo->exec("CREATE TABLE ban_user (
            id INT NOT NULL AUTO_INCREMENT,
            ip VARCHAR(45) NOT NULL,
            last_date DATETIME NOT NULL,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = "ban_user table created.";
    } else {
        ensureColumn($pdo, 'ban_user', 'id',        "INT NOT NULL AUTO_INCREMENT", $output, $errors);
        ensureColumn($pdo, 'ban_user', 'ip',        "VARCHAR(45) NOT NULL",        $output, $errors);
        ensureColumn($pdo, 'ban_user', 'last_date', "DATETIME NOT NULL",           $output, $errors);
    }

    // --- mail ---
    if (!tableExists($pdo, 'mail')) {
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
        $output[] = "mail table created and seeded.";
    } else {
        ensureColumn($pdo, 'mail', 'id',                 "INT NOT NULL AUTO_INCREMENT",         $output, $errors);
        ensureColumn($pdo, 'mail', 'verification',       "VARCHAR(20) NOT NULL DEFAULT 'enabled'", $output, $errors);
        ensureColumn($pdo, 'mail', 'smtp_host',          "VARCHAR(255) DEFAULT ''",             $output, $errors);
        ensureColumn($pdo, 'mail', 'smtp_username',      "VARCHAR(255) DEFAULT ''",             $output, $errors);
        ensureColumn($pdo, 'mail', 'smtp_password',      "VARCHAR(255) DEFAULT ''",             $output, $errors);
        ensureColumn($pdo, 'mail', 'smtp_port',          "VARCHAR(10) DEFAULT ''",              $output, $errors);
        ensureColumn($pdo, 'mail', 'protocol',           "VARCHAR(20) NOT NULL DEFAULT '2'",    $output, $errors);
        ensureColumn($pdo, 'mail', 'auth',               "VARCHAR(20) NOT NULL DEFAULT 'true'", $output, $errors);
        ensureColumn($pdo, 'mail', 'socket',             "VARCHAR(20) NOT NULL DEFAULT 'tls'",  $output, $errors);
        ensureColumn($pdo, 'mail', 'oauth_client_id',    "VARCHAR(255) DEFAULT NULL",           $output, $errors);
        ensureColumn($pdo, 'mail', 'oauth_client_secret',"VARCHAR(255) DEFAULT NULL",           $output, $errors);
        ensureColumn($pdo, 'mail', 'oauth_refresh_token',"VARCHAR(255) DEFAULT NULL",           $output, $errors);
    }

    // --- mail_log ---
    if (!tableExists($pdo, 'mail_log')) {
        $pdo->exec("CREATE TABLE mail_log (
            id INT NOT NULL AUTO_INCREMENT,
            email VARCHAR(255) NOT NULL,
            sent_at DATETIME NOT NULL,
            type ENUM('verification','reset','test') NOT NULL,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = "mail_log table created.";
    } else {
        ensureColumn($pdo, 'mail_log', 'id',      "INT NOT NULL AUTO_INCREMENT",      $output, $errors);
        ensureColumn($pdo, 'mail_log', 'email',   "VARCHAR(255) NOT NULL",            $output, $errors);
        ensureColumn($pdo, 'mail_log', 'sent_at', "DATETIME NOT NULL",                $output, $errors);
        ensureColumn($pdo, 'mail_log', 'type',    "ENUM('verification','reset','test') NOT NULL", $output, $errors);
    }

    // --- pages (new schema: location/nav_parent/sort_order/is_active) ---
    if (!tableExists($pdo, 'pages')) {
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
        $output[] = "pages table created.";

        // Seed default pages (footer)
        $ins = $pdo->prepare("INSERT INTO pages (last_date, page_name, page_title, page_content, location, nav_parent, sort_order, is_active)
            VALUES 
            (:d1, 'contact', 'Contact', :c1, 'footer', NULL, 0, 1),
            (:d2, 'terms',   'Terms of Service', :c2, 'footer', NULL, 1, 1)");
        $ins->execute([
            ':d1' => $date,
            ':d2' => $date,
            ':c1' => '<h1>Contact Us</h1><p>Email: <a href="mailto:admin@example.com">admin@example.com</a></p>',
            ':c2' => '<h1>Terms of Service</h1><p>Replace this with your actual terms.</p>'
        ]);
        $output[] = "Default Contact and Terms of Service pages inserted.";
    } else {
        ensureColumn($pdo, 'pages', 'id',           "INT NOT NULL AUTO_INCREMENT",                                $output, $errors);
        ensureColumn($pdo, 'pages', 'last_date',    "DATETIME NOT NULL",                                           $output, $errors);
        ensureColumn($pdo, 'pages', 'page_name',    "VARCHAR(255) NOT NULL",                                       $output, $errors);
        ensureColumn($pdo, 'pages', 'page_title',   "MEDIUMTEXT NOT NULL",                                         $output, $errors);
        ensureColumn($pdo, 'pages', 'page_content', "LONGTEXT",                                                    $output, $errors);
        ensureColumn($pdo, 'pages', 'location',     "ENUM('','header','footer','both') NOT NULL DEFAULT ''",       $output, $errors);
        ensureColumn($pdo, 'pages', 'nav_parent',   "INT DEFAULT NULL",                                            $output, $errors);
        ensureColumn($pdo, 'pages', 'sort_order',   "INT NOT NULL DEFAULT 0",                                      $output, $errors);
        ensureColumn($pdo, 'pages', 'is_active',    "TINYINT(1) NOT NULL DEFAULT 1",                               $output, $errors);

        if (!indexExists($pdo, 'pages', 'idx_pages_location')) {
            try { $pdo->exec("ALTER TABLE pages ADD KEY idx_pages_location (location)"); } catch (PDOException $e) { error_log($e->getMessage()); }
        }
        if (!indexExists($pdo, 'pages', 'idx_pages_navparent')) {
            try { $pdo->exec("ALTER TABLE pages ADD KEY idx_pages_navparent (nav_parent)"); } catch (PDOException $e) { error_log($e->getMessage()); }
        }
        if (!indexExists($pdo, 'pages', 'idx_pages_active')) {
            try { $pdo->exec("ALTER TABLE pages ADD KEY idx_pages_active (is_active)"); } catch (PDOException $e) { error_log($e->getMessage()); }
        }
        if (!fkExists($pdo, 'pages', 'fk_pages_navparent')) {
            try { $pdo->exec("ALTER TABLE pages ADD CONSTRAINT fk_pages_navparent FOREIGN KEY (nav_parent) REFERENCES pages(id) ON DELETE SET NULL"); } catch (PDOException $e) { error_log($e->getMessage()); }
        }

        // Seed defaults if missing
        $needSeed = false;
        $chk = $pdo->query("SELECT COUNT(*) FROM pages WHERE page_name IN ('contact','terms')");
        if ($chk && (int)$chk->fetchColumn() < 2) $needSeed = true;
        if ($needSeed) {
            $ins = $pdo->prepare("INSERT IGNORE INTO pages (last_date, page_name, page_title, page_content, location, nav_parent, sort_order, is_active)
                VALUES 
                (:d1, 'contact', 'Contact', :c1, 'footer', NULL, 0, 1),
                (:d2, 'terms',   'Terms of Service', :c2, 'footer', NULL, 1, 1)");
            $ins->execute([
                ':d1' => $date,
                ':d2' => $date,
                ':c1' => '<h1>Contact Us</h1><p>Email: <a href="mailto:admin@example.com">admin@example.com</a></p>',
                ':c2' => '<h1>Terms of Service</h1><p>Replace this with your actual terms.</p>'
            ]);
            $output[] = "Default Contact/Terms pages ensured.";
        }
    }

    // --- page_view ---
    if (!tableExists($pdo, 'page_view')) {
        $pdo->exec("CREATE TABLE page_view (
            id INT NOT NULL AUTO_INCREMENT,
            date DATE NOT NULL,
            tpage INT UNSIGNED NOT NULL DEFAULT 0,
            tvisit INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $output[] = "page_view table created.";
    } else {
        ensureColumn($pdo, 'page_view', 'id',     "INT NOT NULL AUTO_INCREMENT", $output, $errors);
        ensureColumn($pdo, 'page_view', 'date',   "DATE NOT NULL",               $output, $errors);
        ensureColumn($pdo, 'page_view', 'tpage',  "INT UNSIGNED NOT NULL DEFAULT 0", $output, $errors);
        ensureColumn($pdo, 'page_view', 'tvisit', "INT UNSIGNED NOT NULL DEFAULT 0", $output, $errors);
    }

    // --- ads ---
    if (!tableExists($pdo, 'ads')) {
        $pdo->exec("CREATE TABLE ads (
            id INT NOT NULL AUTO_INCREMENT,
            text_ads TEXT,
            ads_1 TEXT,
            ads_2 TEXT,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT INTO ads (text_ads, ads_1, ads_2) VALUES ('','','')");
        $output[] = "ads table created and seeded.";
    } else {
        ensureColumn($pdo, 'ads', 'id',       "INT NOT NULL AUTO_INCREMENT", $output, $errors);
        ensureColumn($pdo, 'ads', 'text_ads', "TEXT",                        $output, $errors);
        ensureColumn($pdo, 'ads', 'ads_1',    "TEXT",                        $output, $errors);
        ensureColumn($pdo, 'ads', 'ads_2',    "TEXT",                        $output, $errors);
    }

    // --- sitemap_options ---
    if (!tableExists($pdo, 'sitemap_options')) {
        $pdo->exec("CREATE TABLE sitemap_options (
            id INT NOT NULL AUTO_INCREMENT,
            priority  VARCHAR(10) NOT NULL DEFAULT '0.9',
            changefreq VARCHAR(20) NOT NULL DEFAULT 'daily',
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT INTO sitemap_options (priority, changefreq) VALUES ('0.9','daily')");
        $output[] = "sitemap_options table created and seeded.";
    } else {
        ensureColumn($pdo, 'sitemap_options', 'id',        "INT NOT NULL AUTO_INCREMENT",     $output, $errors);
        ensureColumn($pdo, 'sitemap_options', 'priority',  "VARCHAR(10) NOT NULL DEFAULT '0.9'", $output, $errors);
        ensureColumn($pdo, 'sitemap_options', 'changefreq',"VARCHAR(20) NOT NULL DEFAULT 'daily'", $output, $errors);
    }

    // captcha
    if (!tableExists($pdo, 'captcha')) {
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
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT INTO captcha (cap_e, mode, recaptcha_version, mul, allowed, color, recaptcha_sitekey, recaptcha_secretkey)
            VALUES ('off','Normal','v2','off','ABCDEFGHIJKLMNOPQRSTUVYXYZabcdefghijklmnopqrstuvwxyz0123456789','#000000','','')");
        $output[] = "captcha table created and seeded.";
    } else {
        ensureColumn($pdo, 'captcha', 'id',                 "INT NOT NULL AUTO_INCREMENT",          $output, $errors);
        ensureColumn($pdo, 'captcha', 'cap_e',              "VARCHAR(10) NOT NULL DEFAULT 'off'",   $output, $errors);
        ensureColumn($pdo, 'captcha', 'mode',               "VARCHAR(50) NOT NULL DEFAULT 'Normal'",$output, $errors);
        ensureColumn($pdo, 'captcha', 'recaptcha_version',  "ENUM('v2','v3') DEFAULT 'v2'",         $output, $errors);
        ensureColumn($pdo, 'captcha', 'mul',                "VARCHAR(10) NOT NULL DEFAULT 'off'",   $output, $errors);
        ensureColumn($pdo, 'captcha', 'allowed',            "TEXT NOT NULL",                         $output, $errors);
        ensureColumn($pdo, 'captcha', 'color',              "VARCHAR(7) NOT NULL DEFAULT '#000000'", $output, $errors);
        ensureColumn($pdo, 'captcha', 'recaptcha_sitekey',  "TEXT",                                   $output, $errors);
        ensureColumn($pdo, 'captcha', 'recaptcha_secretkey',"TEXT",                                   $output, $errors);
    }

    // decrypt legacy (v2.x) and re-encrypt with NEW key
    $old_sec_key_input = isset($_POST['old_sec_key']) ? (string)$_POST['old_sec_key'] : '';
    try {
        if ($old_sec_key_input !== '') {
            $mig = migrate_encrypted_pastes($pdo, $old_sec_key_input, $sec_key);
            $output[] = sprintf(
                "Re-key summary — checked: %d, converted: %d, skipped: %d, failed: %d",
                $mig['checked'], $mig['converted'], $mig['skipped'], $mig['failed']
            );
            if (!empty($mig['errors'])) {
                $errors = array_merge($errors, $mig['errors']);
            }
        } else {
            $output[] = "Re-key step skipped (no old \$sec_key provided).";
        }
    } catch (Throwable $e) {
        error_log("install.php re-key error: ".$e->getMessage());
        $errors[] = "Re-key failed: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }

    // Post-install message
    $post_install_message = 'Installation and schema update completed successfully. ';
    if (isset($enablegoog) && $enablegoog === 'yes') {
        $post_install_message .= "Configure Google OAuth at <a href=\"https://console.developers.google.com\" target=\"_blank\">Google Cloud Console</a> with redirect URI: {$baseurl}oauth/google.php and scopes: openid, userinfo.profile, userinfo.email. Update G_CLIENT_ID and G_CLIENT_SECRET in config.php. ";
    }
    if (isset($enablefb) && $enablefb === 'yes') {
        $post_install_message .= "Configure Facebook OAuth at <a href=\"https://developers.facebook.com\" target=\"_blank\">Facebook Developer Portal</a> with redirect URI: {$baseurl}oauth/facebook.php. Update FB_APP_ID and FB_APP_SECRET in config.php. ";
    }
    if (isset($enablesmtp) && $enablesmtp === 'yes') {
        $post_install_message .= "Configure Gmail SMTP OAuth at <a href=\"https://console.developers.google.com\" target=\"_blank\">Google Cloud Console</a> with redirect URI: {$baseurl}oauth/google_smtp.php and scope: gmail.send. Set credentials in admin/configuration.php. ";
    }
    if (!empty($warnings)) {
        $post_install_message .= '<br>Notes: ' . implode('<br>', $warnings);
    }
    $post_install_message .= 'Remove the /install directory and set secure permissions on config.php (chmod 600). Proceed to the <a href="../" class="btn btn-primary">main site</a> or your <a href="../admin" class="btn btn-primary">dashboard</a>.';

    if (!empty($errors)) {
        $post_install_message .= '<br>Warnings: ' . implode('<br>', $errors);
    }

    ob_end_clean();
    echo json_encode([
        'status'  => 'success',
        'message' => implode('<br>', $output) . '<br>' . $post_install_message
    ]);
} catch (PDOException $e) {
    ob_end_clean();
    error_log("install.php: Installation error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Installation failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]);
} catch (Exception $e) {
    ob_end_clean();
    error_log("install.php: Unexpected error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Unexpected error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]);
} finally {
    if (isset($pdo)) $pdo = null;
}
?>
