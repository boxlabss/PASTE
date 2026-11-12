<?php
/*
 * Paste Admin https://github.com/boxlabss/PASTE
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License in LICENCE for more details.
 */
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../includes/admin_session.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['admin_login']) || !isset($_SESSION['admin_id'])) {
    ob_end_clean();
    header("Location: index.php");
    exit();
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');
$date = date('Y-m-d H:i:s');
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
require_once '../config.php';
require_once __DIR__ . '/../includes/admin_access.php';
admin_require('configuration');
// Dependency guards if vendor trees missing
$OAUTH_VENDOR = __DIR__ . '/../oauth/vendor/autoload.php';
$OAUTH_READY = is_file($OAUTH_VENDOR) && is_file(__DIR__ . '/../oauth/vendor/composer/autoload_real.php');
$OAUTH_WARNING_HTML = $OAUTH_READY ? '' :
    'OAuth libraries not installed. Install: <code>cd oauth && composer require google/apiclient:^2.17 league/oauth2-client:^2.7 league/oauth2-google:^4.0</code>';
$MAIL_VENDOR = __DIR__ . '/../mail/vendor/autoload.php';
$MAIL_READY = is_file($MAIL_VENDOR) && is_file(__DIR__ . '/../mail/vendor/composer/autoload_real.php');
$MAIL_WARNING_HTML = $MAIL_READY ? '' :
    'Mail libraries not installed. Install: <code>cd mail && composer require phpmailer/phpmailer:^6.9</code>';
function is_ajax(): bool {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// Require OAuth vendor only when actually needed.
function require_oauth_vendor_or_json_error(): void {
    global $OAUTH_READY, $OAUTH_VENDOR;
    if ($OAUTH_READY) { require_once $OAUTH_VENDOR; return; }
    if (is_ajax()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error',
            'message' => 'OAuth libraries not installed. Run: cd oauth && composer require google/apiclient:^2.17 league/oauth2-client:^2.7 league/oauth2-google:^4.0'
        ]);
        exit;
    }
    // UI will show a warning.
}

// Require Mail vendor + mail.php only when actually needed.
function require_mail_vendor_or_json_error(): void {
    global $MAIL_READY, $MAIL_VENDOR;
    if ($MAIL_READY) {
        require_once $MAIL_VENDOR;
        require_once __DIR__ . '/../mail/mail.php';
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'error',
        'message' => 'Mail libraries not installed. Run: cd mail && composer require phpmailer/phpmailer:^6.9'
    ]);
    exit;
}

// --- Active tab persistence (server-side default) ---
$activeTab = $_POST['active_tab'] ?? $_GET['tab'] ?? 'siteinfo';
$validTabs = ['siteinfo','permissions','urlsettings','api','captcha','mail'];
if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = 'siteinfo';
}

try {
    $pdo = new PDO("mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $stmt = $pdo->prepare("SELECT id FROM admin WHERE user = ?");
    $stmt->execute([$_SESSION['admin_login']]);
    $admin = $stmt->fetch();
    if (!$admin || $admin['id'] != $_SESSION['admin_id']) {
        error_log("configuration.php: Invalid admin session for admin_login: {$_SESSION['admin_login']}, admin_id: {$_SESSION['admin_id']}");
        $_SESSION = [];
        session_destroy();
        ob_end_clean();
        header('Location: index.php');
        exit;
    }
    $stmt = $pdo->query("SELECT * FROM site_info WHERE id = 1");
    $row = $stmt->fetch() ?: [];
    $title = trim($row['title'] ?? '');
    $des = trim($row['des'] ?? '');
    $baseurl = trim($row['baseurl'] ?? '');
    $keyword = trim($row['keyword'] ?? '');
    $site_name = trim($row['site_name'] ?? '');
    $email = trim($row['email'] ?? '');
    $twit = trim($row['twit'] ?? '');
    $face = trim($row['face'] ?? '');
    $gplus = trim($row['gplus'] ?? '');
    $ga = trim($row['ga'] ?? '');
    $additional_scripts = trim($row['additional_scripts'] ?? '');
    $stmt = $pdo->query("SELECT * FROM captcha WHERE id = 1");
    $row = $stmt->fetch() ?: [];
    $cap_e = $row['cap_e'] ?? '';
    $mode = $row['mode'] ?? '';
    $recaptcha_version = $row['recaptcha_version'] ?? 'v2';
    $mul = $row['mul'] ?? '';
    $allowed = $row['allowed'] ?? '';
    $color = $row['color'] ?? '';
    $recaptcha_sitekey = $row['recaptcha_sitekey'] ?? '';
    $recaptcha_secretkey = $row['recaptcha_secretkey'] ?? '';
    $turnstile_sitekey = $row['turnstile_sitekey'] ?? '';
    $turnstile_secretkey = $row['turnstile_secretkey'] ?? '';
    $stmt = $pdo->query("SELECT * FROM site_permissions WHERE id = 1");
    $row = $stmt->fetch() ?: [];
    $disableguest = trim($row['disableguest'] ?? '');
    $siteprivate = trim($row['siteprivate'] ?? '');
    $hiderecentpastes = trim($row['hiderecentpastes'] ?? '');
    $stmt = $pdo->query("SELECT * FROM mail WHERE id = 1");
    $row = $stmt->fetch() ?: [];
    $required_fields = ['verification', 'smtp_host', 'smtp_username', 'smtp_password', 'smtp_port', 'protocol', 'auth', 'socket', 'oauth_client_id', 'oauth_client_secret', 'oauth_refresh_token'];
    foreach ($required_fields as $field) {
        if (!array_key_exists($field, $row)) {
            $row[$field] = '';
        }
    }
    $verification = trim($row['verification'] ?? '');
    $smtp_host = trim($row['smtp_host'] ?? '');
    $smtp_username = trim($row['smtp_username'] ?? '');
    $smtp_password = trim($row['smtp_password'] ?? '');
    $smtp_port = trim($row['smtp_port'] ?? '');
    $protocol = trim($row['protocol'] ?? '');
    $auth = trim($row['auth'] ?? '');
    $socket = trim($row['socket'] ?? '');
    $oauth_client_id = trim($row['oauth_client_id'] ?? '');
    $oauth_client_secret = trim($row['oauth_client_secret'] ?? '');
    $oauth_refresh_token = trim($row['oauth_refresh_token'] ?? '');
    $oauth_status = $oauth_refresh_token ? 'OAuth refresh token is set.' : 'OAuth refresh token not set. Configure Gmail OAuth if using smtp.gmail.com.';
    $redirect_uri = $baseurl ? rtrim($baseurl, '/') . '/oauth/google_smtp.php' : '';

    // URL settings (paste_options table)
    $current_url_mode = 'slug';
    $current_slug_length = 8;
    $current_block_numeric = false;
    $current_api_enabled = true;
    $current_api_keys_per_user = 5;
    $current_api_rate_limit = 60;
    $current_api_guest_pastes = false;
    $current_api_guest_uploads = false;
    $current_api_guest_rate_limit = 10;
    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'paste_options'");
        if ($tableCheck->rowCount() > 0) {
            $stmt = $pdo->query("SELECT option_name, option_value FROM paste_options WHERE option_name IN ('url_mode', 'slug_length', 'block_numeric_urls', 'api_enabled', 'api_keys_per_user', 'api_rate_limit', 'api_guest_pastes', 'api_guest_uploads', 'api_guest_rate_limit')");
            while ($row = $stmt->fetch()) {
                if ($row['option_name'] === 'url_mode') {
                    $current_url_mode = $row['option_value'] ?: 'slug';
                } elseif ($row['option_name'] === 'slug_length') {
                    $current_slug_length = (int)($row['option_value'] ?: 8);
                } elseif ($row['option_name'] === 'block_numeric_urls') {
                    $current_block_numeric = ($row['option_value'] === '1');
                } elseif ($row['option_name'] === 'api_enabled') {
                    $current_api_enabled = ($row['option_value'] !== '0');
                } elseif ($row['option_name'] === 'api_keys_per_user') {
                    $current_api_keys_per_user = (int)($row['option_value'] ?: 5);
                } elseif ($row['option_name'] === 'api_rate_limit') {
                    $current_api_rate_limit = max(0, (int)$row['option_value']);
                } elseif ($row['option_name'] === 'api_guest_pastes') {
                    $current_api_guest_pastes = $row['option_value'] === '1';
                } elseif ($row['option_name'] === 'api_guest_uploads') {
                    $current_api_guest_uploads = $row['option_value'] === '1';
                } elseif ($row['option_name'] === 'api_guest_rate_limit') {
                    $current_api_guest_rate_limit = max(0, (int)$row['option_value']);
                }
            }
        }
    } catch (PDOException $e) {
        // Table might not exist yet - use defaults
    }

    $msg = '';
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        if (!admin_csrf_ok()) {
            $msg_plain = 'CSRF validation failed. Please try again.';
            $msg_type = 'error';
        } else {
            if (isset($_POST['test_recaptcha'])) {
                $recaptcha_sitekey = trim($_POST['recaptcha_sitekey'] ?? '');
                $recaptcha_secretkey = trim($_POST['recaptcha_secretkey'] ?? '');
                $recaptcha_version = trim($_POST['recaptcha_version'] ?? 'v2');
                if (empty($recaptcha_sitekey) || empty($recaptcha_secretkey)) {
                    $msg = '<div class="alert alert-danger text-center">reCAPTCHA Site Key and Secret Key are required for testing.</div>';
                    error_log("configuration.php: Missing reCAPTCHA keys for test");
                } else {
                    $verify_url = "https://www.google.com/recaptcha/api/siteverify?secret=" . urlencode($recaptcha_secretkey) . "&response=test";
                    $ch = curl_init($verify_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    $response = curl_exec($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curl_error = curl_error($ch);
                    curl_close($ch);
                    if ($response === false || $http_code != 200) {
                        $msg = '<div class="alert alert-danger text-center">Failed to verify reCAPTCHA keys: ' . htmlspecialchars($curl_error ?: 'No response', ENT_QUOTES, 'UTF-8') . '</div>';
                        error_log("configuration.php: reCAPTCHA test failed: HTTP Code: $http_code, Error: " . ($curl_error ?: 'No response'));
                    } else {
                        $response = json_decode($response, true);
                        if (($response['success'] ?? null) === false && isset($response['error-codes']) && in_array('invalid-input-secret', $response['error-codes'])) {
                            $msg = '<div class="alert alert-danger text-center">Invalid reCAPTCHA Secret Key. Please verify your keys.</div>';
                            error_log("configuration.php: reCAPTCHA test failed: Invalid secret key");
                        } else {
                            if ($recaptcha_version === 'v3' && isset($response['score']) && $response['score'] < 0.5) {
                                $msg = '<div class="alert alert-danger text-center">reCAPTCHA v3 test failed: Score ' . htmlspecialchars((string)$response['score'], ENT_QUOTES, 'UTF-8') . ' is below threshold (0.5).</div>';
                                error_log("configuration.php: reCAPTCHA v3 test failed: Score " . $response['score']);
                            } else {
                                $msg = '<div class="alert alert-success text-center">reCAPTCHA keys are valid' . ($recaptcha_version === 'v3' ? ' (Score: ' . htmlspecialchars((string)($response['score'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') . ')' : '') . '.</div>';
                            }
                        }
                    }
                }
                if (is_ajax()) {
                    ob_end_clean();
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['message' => $msg]);
                    exit;
                }
            } elseif (isset($_POST['test_turnstile'])) {
                $turnstile_sitekey = trim($_POST['turnstile_sitekey'] ?? '');
                $turnstile_secretkey = trim($_POST['turnstile_secretkey'] ?? '');
                if (empty($turnstile_sitekey) || empty($turnstile_secretkey)) {
                    $msg = '<div class="alert alert-danger text-center">Turnstile Site Key and Secret Key are required for testing.</div>';
                    error_log("configuration.php: Missing Turnstile keys for test");
                } else {
                    // Use an empty token to trigger 'missing-input-response' if secret is valid
                    $token = '';
                    $data = [
                        'secret' => $turnstile_secretkey,
                        'response' => $token,
                    ];
                    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    $response = curl_exec($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curl_error = curl_error($ch);
                    curl_close($ch);
                    if ($response === false || $http_code != 200) {
                        $msg = '<div class="alert alert-danger text-center">Failed to verify Turnstile keys: ' . htmlspecialchars($curl_error ?: 'No response', ENT_QUOTES, 'UTF-8') . '</div>';
                        error_log("configuration.php: Turnstile test failed: HTTP Code: $http_code, Error: " . ($curl_error ?: 'No response'));
                    } else {
                        $result = json_decode($response, true);
                        if (!$result || !is_array($result)) {
                            $msg = '<div class="alert alert-danger text-center">Invalid response from Turnstile API.</div>';
                            error_log("configuration.php: Turnstile test invalid JSON: " . $response);
                        } elseif ($result['success']) {
                            // Unexpected with empty token, but valid
                            $msg = '<div class="alert alert-success text-center">Turnstile keys are valid.</div>';
                        } else {
                            $errors = $result['error-codes'] ?? [];
                            if (in_array('invalid-input-secret', $errors)) {
                                $msg = '<div class="alert alert-danger text-center">Invalid Turnstile Secret Key.</div>';
                                error_log("configuration.php: Turnstile test failed: Invalid secret key");
                            } elseif (in_array('missing-input-response', $errors)) {
                                $msg = '<div class="alert alert-success text-center">Turnstile keys are valid.</div>';
                            } else {
                                $msg = '<div class="alert alert-danger text-center">Turnstile verification failed. Error codes: ' . htmlspecialchars(implode(', ', $errors), ENT_QUOTES, 'UTF-8') . '</div>';
                                error_log("configuration.php: Turnstile test failed: " . implode(', ', $errors));
                            }
                        }
                    }
                }
                if (is_ajax()) {
                    ob_end_clean();
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['message' => $msg]);
                    exit;
                }
            } elseif (isset($_POST['test_smtp'])) {
                require_mail_vendor_or_json_error();
                header('Content-Type: application/json; charset=utf-8');
                if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    error_log("configuration.php: Invalid or missing admin email: $email");
                    ob_end_clean();
                    echo json_encode(['status' => 'error', 'message' => '<div class="alert alert-danger text-center">Invalid or missing Admin Email in Site Info. Please set a valid email address.</div>']);
                    exit;
                } elseif ($protocol === '2' && $smtp_host === 'smtp.gmail.com' && (empty($oauth_client_id) || empty($oauth_client_secret) || empty($oauth_refresh_token))) {
                    error_log("configuration.php: Missing OAuth credentials for Gmail SMTP");
                    ob_end_clean();
                    echo json_encode(['status' => 'error', 'message' => '<div class="alert alert-danger text-center">OAuth credentials missing for Gmail SMTP. Please configure Client ID, Client Secret, and authorize Gmail SMTP.</div>']);
                    exit;
                } elseif ($protocol === '2' && $smtp_host !== 'smtp.gmail.com' && $auth === 'true' && (empty($smtp_username) || empty($smtp_password))) {
                    error_log("configuration.php: Missing SMTP username or password for $smtp_host");
                    ob_end_clean();
                    echo json_encode(['status' => 'error', 'message' => '<div class="alert alert-danger text-center">SMTP Username and Password are required for non-Gmail SMTP servers with authentication.</div>']);
                    exit;
                } elseif ($protocol === '1' && !ini_get('sendmail_path')) {
                    error_log("configuration.php: sendmail_path not configured in php.ini");
                    ob_end_clean();
                    echo json_encode(['status' => 'error', 'message' => '<div class="alert alert-danger text-center">PHP Mail selected, but sendmail_path is not configured in php.ini.</div>']);
                    exit;
                } else {
                    $test_message = "
                        <html>
                        <head><style>body { font-family: Arial, sans-serif; color: #333; }</style></head>
                        <body>
                            <div style='text-align: center;'>
                                <img src='$baseurl/images/logo.png' alt='$site_name Logo'>
                                <h2>Test Email from $site_name</h2>
                            </div>
                            <p>This is a test email sent from your Pastebin installation to verify mail settings.</p>
                        </body>
                        </html>";
                    $mail_result = send_mail($email, "Test Email from $site_name", $test_message, $site_name, $_SESSION['csrf_token']);
                    ob_end_clean();
                    if (($mail_result['status'] ?? 'error') === 'success') {
                        echo json_encode(['status' => 'success', 'message' => '<div class="alert alert-success text-center">Test email sent successfully to ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '.</div>']);
                    } else {
                        echo json_encode(['status' => 'error', 'message' => '<div class="alert alert-danger text-center">Failed to send test email: ' . htmlspecialchars($mail_result['message'] ?? 'Unknown error', ENT_QUOTES, 'UTF-8') . '</div>']);
                    }
                    exit;
                }
            } elseif (isset($_POST['save_oauth_credentials'])) {
                require_oauth_vendor_or_json_error();
                $client_id = trim($_POST['client_id'] ?? '');
                $client_secret = trim($_POST['client_secret'] ?? '');
                if (empty($client_id) || empty($client_secret)) {
                    $msg_plain = 'Please fill in both Client ID and Client Secret.';
                    $msg_type = 'error';
                    error_log("configuration.php: Missing OAuth Client ID or Secret");
                } elseif (!preg_match('/^[0-9a-zA-Z\-]+\.apps\.googleusercontent\.com$/', $client_id)) {
                    $msg_plain = 'Invalid Client ID format. It should look like \'1234567890-abcdef.apps.googleusercontent.com\'.';
                    $msg_type = 'error';
                    error_log("configuration.php: Invalid OAuth Client ID format: $client_id");
                } elseif (!preg_match('/^[0-9a-zA-Z\-_]+$/', $client_secret)) {
                    $msg_plain = 'Invalid Client Secret format. It should contain only letters, numbers, hyphens, and underscores.';
                    $msg_type = 'error';
                } else {
                    try {
                        $stmt = $pdo->prepare("UPDATE mail SET oauth_client_id = ?, oauth_client_secret = ? WHERE id = 1");
                        $stmt->execute([$client_id, $client_secret]);
                        if ($stmt->rowCount() === 0 && (int)$pdo->query("SELECT COUNT(*) FROM mail WHERE id = 1")->fetchColumn() === 0) {
                            $msg_plain = 'Failed to update OAuth credentials in database. No rows affected.';
                            $msg_type = 'error';
                        } else {
                            $oauth_client_id = $client_id;
                            $oauth_client_secret = $client_secret;
                            $msg_plain = 'OAuth credentials saved successfully.';
                            $msg_type = 'success';
                        }
                    } catch (PDOException $e) {
                        error_log("configuration.php: OAuth credentials update error: " . $e->getMessage());
                        $msg_plain = $e->getMessage();
                        $msg_type = 'error';
                    }
                }
            } elseif (isset($_POST['reset_oauth_token'])) {
                try {
                    $stmt = $pdo->prepare("UPDATE mail SET oauth_refresh_token = '' WHERE id = 1");
                    $stmt->execute();
                    $oauth_refresh_token = '';
                    $oauth_status = 'OAuth refresh token not set. Configure Gmail OAuth if using smtp.gmail.com.';
                    $msg_plain = 'OAuth refresh token reset successfully.';
                    $msg_type = 'success';
                } catch (PDOException $e) {
                    error_log("configuration.php: OAuth refresh token reset error: " . $e->getMessage());
                    $msg_plain = $e->getMessage();
                    $msg_type = 'error';
                }
            } elseif (isset($_POST['cap'])) {
                $cap_e = trim($_POST['cap_e'] ?? '');
                $mode = trim($_POST['mode'] ?? '');
                $recaptcha_version = trim($_POST['recaptcha_version'] ?? 'v2');
                $mul = trim($_POST['mul'] ?? '');
                $allowed = trim($_POST['allowed'] ?? '');
                $color = trim($_POST['color'] ?? '');
                $recaptcha_sitekey = trim($_POST['recaptcha_sitekey'] ?? '');
                $recaptcha_secretkey = trim($_POST['recaptcha_secretkey'] ?? '');
                $turnstile_sitekey = trim($_POST['turnstile_sitekey'] ?? '');
                $turnstile_secretkey = trim($_POST['turnstile_secretkey'] ?? '');
                if ($cap_e == 'on' && $mode == 'reCAPTCHA' && (empty($recaptcha_sitekey) || empty($recaptcha_secretkey))) {
                    $msg_plain = 'reCAPTCHA Site Key and Secret Key are required when reCAPTCHA is enabled.';
                    $msg_type = 'error';
                    error_log("configuration.php: Missing reCAPTCHA keys for mode: $mode");
                } elseif ($cap_e == 'on' && $mode == 'turnstile' && (empty($turnstile_sitekey) || empty($turnstile_secretkey))) {
                    $msg_plain = 'Turnstile Site Key and Secret Key are required when Turnstile is enabled.';
                    $msg_type = 'error';
                    error_log("configuration.php: Missing Turnstile keys for mode: $mode");
                } else {
                    try {
                        $stmt = $pdo->prepare("UPDATE captcha SET cap_e = ?, mode = ?, recaptcha_version = ?, mul = ?, allowed = ?, color = ?, recaptcha_sitekey = ?, recaptcha_secretkey = ?, turnstile_sitekey = ?, turnstile_secretkey = ? WHERE id = 1");
                        $stmt->execute([$cap_e, $mode, $recaptcha_version, $mul, $allowed, $color, $recaptcha_sitekey, $recaptcha_secretkey, $turnstile_sitekey, $turnstile_secretkey]);
                        $msg_plain = 'Captcha settings saved';
                        $msg_type = 'success';
                    } catch (PDOException $e) {
                        error_log("configuration.php: Captcha update error: " . $e->getMessage());
                        $msg_plain = $e->getMessage();
                        $msg_type = 'error';
                    }
                }
            } elseif (isset($_POST['manage'])) {
                $site_name = filter_var(trim($_POST['site_name'] ?? ''), FILTER_SANITIZE_SPECIAL_CHARS);
                $title = filter_var(trim($_POST['title'] ?? ''), FILTER_SANITIZE_SPECIAL_CHARS);
                $baseurl = filter_var(trim($_POST['baseurl'] ?? ''), FILTER_SANITIZE_URL);
                $des = filter_var(trim($_POST['des'] ?? ''), FILTER_SANITIZE_SPECIAL_CHARS);
                $keyword = htmlspecialchars(trim($_POST['keyword'] ?? ''), ENT_QUOTES, 'UTF-8');
                $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
                $twit = htmlspecialchars(trim($_POST['twit'] ?? ''), ENT_QUOTES, 'UTF-8');
                $face = htmlspecialchars(trim($_POST['face'] ?? ''), ENT_QUOTES, 'UTF-8');
                $gplus = htmlspecialchars(trim($_POST['gplus'] ?? ''), ENT_QUOTES, 'UTF-8');
                $ga = htmlspecialchars(trim($_POST['ga'] ?? ''), ENT_QUOTES, 'UTF-8');
                $additional_scripts = filter_var(trim($_POST['additional_scripts'] ?? ''), FILTER_SANITIZE_SPECIAL_CHARS);
                try {
                    $stmt = $pdo->prepare("UPDATE site_info SET title = ?, des = ?, baseurl = ?, keyword = ?, site_name = ?, email = ?, twit = ?, face = ?, gplus = ?, ga = ?, additional_scripts = ? WHERE id = 1");
                    $stmt->execute([$title, $des, $baseurl, $keyword, $site_name, $email, $twit, $face, $gplus, $ga, $additional_scripts]);
                    $msg_plain = 'Configuration saved';
                    $msg_type = 'success';
                } catch (PDOException $e) {
                    error_log("configuration.php: Site info update error: " . $e->getMessage());
                    $msg_plain = $e->getMessage();
                    $msg_type = 'error';
                }
            } elseif (isset($_POST['permissions'])) {
                $disableguest = trim($_POST['disableguest'] ?? '');
                $siteprivate = trim($_POST['siteprivate'] ?? '');
                $hiderecentpastes = trim($_POST['hiderecentpastes'] ?? '');
                try {
                    $stmt = $pdo->prepare("UPDATE site_permissions SET disableguest = ?, siteprivate = ?, hiderecentpastes = ? WHERE id = 1");
                    $stmt->execute([$disableguest, $siteprivate, $hiderecentpastes]);
                    $msg_plain = 'Site permissions saved';
                    $msg_type = 'success';
                } catch (PDOException $e) {
                    error_log("configuration.php: Permissions update error: " . $e->getMessage());
                    $msg_plain = $e->getMessage();
                    $msg_type = 'error';
                }
            } elseif (isset($_POST['url_settings'])) {
                $url_mode = trim($_POST['url_mode'] ?? 'slug');
                $slug_length = (int)($_POST['slug_length'] ?? 8);
                $block_numeric_urls = isset($_POST['block_numeric_urls']) ? '1' : '0';

                // Validate
                if (!in_array($url_mode, ['slug', 'numeric'], true)) {
                    $url_mode = 'slug';
                }
                if ($slug_length < 4 || $slug_length > 32) {
                    $slug_length = 8;
                }

                try {
                    // Check if paste_options table exists
                    $tableCheck = $pdo->query("SHOW TABLES LIKE 'paste_options'");
                    if ($tableCheck->rowCount() === 0) {
                        // Create the table
                        $pdo->exec("CREATE TABLE paste_options (
                            id INT NOT NULL AUTO_INCREMENT,
                            option_name VARCHAR(100) NOT NULL,
                            option_value TEXT,
                            PRIMARY KEY(id),
                            UNIQUE KEY idx_option_name (option_name)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                    }

                    // Helper function to upsert options
                    $upsertOption = function($name, $value) use ($pdo) {
                        $stmt = $pdo->prepare("SELECT id FROM paste_options WHERE option_name = ?");
                        $stmt->execute([$name]);
                        if ($stmt->fetch()) {
                            $stmt = $pdo->prepare("UPDATE paste_options SET option_value = ? WHERE option_name = ?");
                            $stmt->execute([$value, $name]);
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO paste_options (option_name, option_value) VALUES (?, ?)");
                            $stmt->execute([$name, $value]);
                        }
                    };

                    $upsertOption('url_mode', $url_mode);
                    $upsertOption('slug_length', (string)$slug_length);
                    $upsertOption('block_numeric_urls', $block_numeric_urls);

                    // If enabling block_numeric_urls, generate slugs for pastes that don't have them
                    $slugsGenerated = 0;
                    if ($block_numeric_urls === '1') {
                        $stmt = $pdo->query("SELECT COUNT(*) FROM pastes WHERE slug IS NULL OR slug = ''");
                        $pastesWithoutSlugs = (int)$stmt->fetchColumn();

                        if ($pastesWithoutSlugs > 0) {
                            // Generate slugs for pastes without them
                            $charset = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
                            $charsetLen = strlen($charset);

                            $stmt = $pdo->query("SELECT id FROM pastes WHERE slug IS NULL OR slug = ''");
                            $pastes = $stmt->fetchAll(PDO::FETCH_COLUMN);

                            foreach ($pastes as $pasteId) {
                                $attempts = 0;
                                $currentLength = $slug_length;

                                do {
                                    $slug = '';
                                    for ($i = 0; $i < $currentLength; $i++) {
                                        $slug .= $charset[random_int(0, $charsetLen - 1)];
                                    }

                                    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM pastes WHERE slug = ?");
                                    $checkStmt->execute([$slug]);
                                    $exists = (int)$checkStmt->fetchColumn() > 0;

                                    if (!$exists) {
                                        $updateStmt = $pdo->prepare("UPDATE pastes SET slug = ? WHERE id = ?");
                                        $updateStmt->execute([$slug, $pasteId]);
                                        $slugsGenerated++;
                                        break;
                                    }

                                    $attempts++;
                                    if ($attempts >= 5) {
                                        $currentLength++;
                                        $attempts = 0;
                                    }
                                } while ($attempts < 15);
                            }
                        }
                    }

                    $msg_plain = 'URL settings saved successfully';
                    if ($slugsGenerated > 0) {
                        $msg_plain .= ". Generated slugs for $slugsGenerated existing pastes.";

                        // Regenerate sitemap with new slug URLs
                        require_once(__DIR__ . '/../includes/functions.php');
                        $sitemapResult = regenerateSitemap($pdo);
                        if ($sitemapResult['success']) {
                            $msg_plain .= " Sitemap updated with {$sitemapResult['count']} URLs.";
                        }
                    }
                    $msg_type = 'success';
                } catch (PDOException $e) {
                    error_log("configuration.php: URL settings update error: " . $e->getMessage());
                    $msg_plain = 'Error saving URL settings: ' . $e->getMessage();
                    $msg_type = 'error';
                }
            } elseif (isset($_POST['api_settings'])) {
                $api_enabled = isset($_POST['api_enabled']) ? '1' : '0';
                $api_keys_per_user = max(1, min(20, (int)($_POST['api_keys_per_user'] ?? 5)));
                $api_rate_limit = max(0, min(10000, (int)($_POST['api_rate_limit'] ?? 60)));
                $api_guest_pastes = isset($_POST['api_guest_pastes']) ? '1' : '0';
                $api_guest_uploads = isset($_POST['api_guest_uploads']) ? '1' : '0';
                $api_guest_rate_limit = max(0, min(10000, (int)($_POST['api_guest_rate_limit'] ?? 10)));

                try {
                    // Ensure paste_options table exists
                    $tableCheck = $pdo->query("SHOW TABLES LIKE 'paste_options'");
                    if ($tableCheck->rowCount() === 0) {
                        $pdo->exec("CREATE TABLE paste_options (
                            id INT NOT NULL AUTO_INCREMENT,
                            option_name VARCHAR(100) NOT NULL,
                            option_value TEXT,
                            PRIMARY KEY(id),
                            UNIQUE KEY idx_option_name (option_name)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                    }

                    $pdo->exec("INSERT INTO paste_options (option_name, option_value) VALUES ('api_enabled', '$api_enabled') ON DUPLICATE KEY UPDATE option_value = '$api_enabled'");
                    $pdo->exec("INSERT INTO paste_options (option_name, option_value) VALUES ('api_keys_per_user', '$api_keys_per_user') ON DUPLICATE KEY UPDATE option_value = '$api_keys_per_user'");
                    $pdo->exec("INSERT INTO paste_options (option_name, option_value) VALUES ('api_rate_limit', '$api_rate_limit') ON DUPLICATE KEY UPDATE option_value = '$api_rate_limit'");
                    $saveOption = $pdo->prepare("INSERT INTO paste_options (option_name, option_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)");
                    $saveOption->execute(['api_guest_pastes', $api_guest_pastes]);
                    $saveOption->execute(['api_guest_uploads', $api_guest_uploads]);
                    $saveOption->execute(['api_guest_rate_limit', (string)$api_guest_rate_limit]);

                    $msg_plain = 'API settings saved successfully';
                    $msg_type = 'success';

                    // Update local vars for display
                    $current_api_enabled = ($api_enabled === '1');
                    $current_api_keys_per_user = $api_keys_per_user;
                    $current_api_rate_limit = $api_rate_limit;
                    $current_api_guest_pastes = $api_guest_pastes === '1';
                    $current_api_guest_uploads = $api_guest_uploads === '1';
                    $current_api_guest_rate_limit = $api_guest_rate_limit;
                } catch (PDOException $e) {
                    error_log("configuration.php: API settings update error: " . $e->getMessage());
                    $msg_plain = 'Error saving API settings: ' . $e->getMessage();
                    $msg_type = 'error';
                }
            } elseif (isset($_POST['smtp_code'])) {
                $verification = trim($_POST['verification'] ?? '');
                $smtp_host = trim($_POST['smtp_host'] ?? '');
                $smtp_port = trim($_POST['smtp_port'] ?? '');
                $smtp_username = trim($_POST['smtp_user'] ?? '');
                $smtp_password = trim($_POST['smtp_pass'] ?? '');
                $socket = trim($_POST['socket'] ?? '');
                $auth = trim($_POST['auth'] ?? '');
                $protocol = trim($_POST['protocol'] ?? '');
                if ($protocol === '2' && $smtp_host !== 'smtp.gmail.com' && $auth === 'true' && (empty($smtp_username) || empty($smtp_password))) {
                    $msg_plain = 'SMTP Username and Password are required for non-Gmail SMTP servers with authentication.';
                    $msg_type = 'error';
                    error_log("configuration.php: Missing SMTP username or password for $smtp_host");
                } elseif ($protocol === '1' && !ini_get('sendmail_path')) {
                    $msg_plain = 'PHP Mail selected, but sendmail_path is not configured in php.ini.';
                    $msg_type = 'error';
                    error_log("configuration.php: sendmail_path not configured in php.ini");
                } elseif ($protocol === '2' && empty($smtp_host)) {
                    $msg_plain = 'SMTP Host is required for SMTP protocol.';
                    $msg_type = 'error';
                    error_log("configuration.php: Missing SMTP host");
                } elseif ($protocol === '2' && empty($smtp_port)) {
                    $msg_plain = 'SMTP Port is required for SMTP protocol.';
                    $msg_type = 'error';
                    error_log("configuration.php: Missing SMTP port");
                } else {
                    try {
                        $stmt = $pdo->prepare("UPDATE mail SET verification = ?, smtp_host = ?, smtp_port = ?, smtp_username = ?, smtp_password = ?, socket = ?, protocol = ?, auth = ? WHERE id = 1");
                        $stmt->execute([$verification, $smtp_host, $smtp_port, $smtp_username, $smtp_password, $socket, $protocol, $auth]);
                        $msg_plain = 'Mail settings updated';
                        $msg_type = 'success';
                    } catch (PDOException $e) {
                        error_log("configuration.php: Mail settings update error: " . $e->getMessage());
                        $msg_plain = $e->getMessage();
                        $msg_type = 'error';
                    }
                }
            }
            if (isset($msg_plain)) {
                if (!is_ajax()) {
                    $query_param = ($msg_type === 'success') ? 'msg' : 'error';
                    ob_end_clean();
                    header("Location: {$_SERVER['PHP_SELF']}?tab=" . urlencode($activeTab) . "&$query_param=" . urlencode($msg_plain));
                    exit;
                }
            }
        }
        // For non-AJAX requests, fall through to render
    }
    if (isset($_GET['msg'])) {
        $msg = '<div class="alert alert-success text-center">' . htmlspecialchars(urldecode($_GET['msg'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>';
    } elseif (isset($_GET['error'])) {
        $msg = '<div class="alert alert-danger text-center">' . htmlspecialchars(urldecode($_GET['error'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>';
    }
} catch (PDOException $e) {
    error_log("configuration.php: Database error: " . $e->getMessage());
    ob_end_clean();
    die("Unable to connect to database: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
} finally {
    // Keep variables like $baseurl/$site_name in scope for HTML; we only close PDO connection here.
    $pdo = null;
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paste - Configuration</title>
    <link rel="shortcut icon" href="favicon.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js" integrity="sha384-1H217gwSVyLSIfaLxHbE7dRb3v4mYCKbpQvzx0cegeju1MVsGrX5xXxAvs/HgeFs" crossorigin="anonymous"></script>
    <style>
      :root{
        --bg:#0f1115; --card:#141821; --muted:#7f8da3; --border:#1f2633; --accent:#0d6efd;
      }
      body{background:var(--bg);color:#e6edf3;}
      .navbar{background:#121826!important;position:sticky;top:0;z-index:1030}
      .navbar .navbar-brand{font-weight:600}
      .offcanvas-nav{width:280px;background:#0f1523;color:#dbe5f5}
      .offcanvas-nav .list-group-item{background:transparent;border:0;color:#dbe5f5}
      .offcanvas-nav .list-group-item:hover{background:#0e1422}
      .sidebar-desktop{position:sticky; top:1rem; background:#121826;border:1px solid var(--border);border-radius:12px;padding:12px}
      .sidebar-desktop .list-group-item{background:transparent;color:#dbe5f5;border:0;border-radius:10px;padding:.65rem .8rem}
      .sidebar-desktop .list-group-item:hover{background:#0e1422}
      .sidebar-desktop .list-group-item.active{background:#0d6efd;color:#fff}
      .main-content{padding:16px}
      .card{background:var(--card);border:1px solid var(--border);border-radius:12px}
      .form-control,.form-select{background:#0e1422;border-color:var(--border);color:#e6edf3}
      .form-control:focus,.form-select:focus{border-color:var(--accent);box-shadow:0 0 0 .25rem rgba(13,110,253,.25)}
      .btn-outline-primary{border-color:#0d6efd;color:#0d6efd}
      .btn-outline-primary:hover{background:#0d6efd;color:#fff}
      .nav-tabs .nav-link{color:#c6d4f0}
      .nav-tabs .nav-link.active{color:#fff;background:#101521;border-color:var(--border) var(--border) transparent}
      .table{color:#e6edf3}
      .table thead th{background:#101521;color:#c6d4f0;border-color:var(--border)}
      .table td,.table th{border-color:var(--border)}
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container-fluid">
    <div class="d-flex align-items-center gap-2">
      <button class="btn btn-outline-primary d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#navOffcanvas" aria-controls="navOffcanvas">
        <i class="bi bi-list"></i>
      </button>
      <a class="navbar-brand" href="../">Paste</a>
    </div>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
      <ul class="navbar-nav">
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
            <?php echo htmlspecialchars($_SESSION['admin_login'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="admin.php">Settings</a></li>
            <li><a class="dropdown-item" href="?logout">Logout</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>
<!-- Mobile offcanvas nav -->
<div class="offcanvas offcanvas-start offcanvas-nav" tabindex="-1" id="navOffcanvas">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title">Admin Menu</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body">
    <div class="list-group">
<?php admin_nav('configuration.php'); ?>
    </div>
  </div>
</div>
<div class="container-fluid my-2">
  <div class="row g-2">
    <!-- Desktop sidebar -->
    <div class="col-lg-2 d-none d-lg-block">
      <div class="sidebar-desktop">
        <div class="list-group">
<?php admin_nav('configuration.php'); ?>
        </div>
      </div>
    </div>
    <div class="col-lg-10">
      <div class="card">
        <div class="card-body">
          <div id="message-container"><?php if (isset($msg)) echo $msg; ?></div>
          <?php if (!empty($OAUTH_WARNING_HTML)): ?>
            <div class="alert alert-warning text-center mb-3"><?php echo $OAUTH_WARNING_HTML; ?></div>
          <?php endif; ?>
          <?php if (!empty($MAIL_WARNING_HTML)): ?>
            <div class="alert alert-warning text-center mb-3"><?php echo $MAIL_WARNING_HTML; ?></div>
          <?php endif; ?>
          <ul class="nav nav-tabs mb-3" id="configTabs" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link <?php echo $activeTab==='siteinfo'?'active':''; ?>" id="siteinfo-tab" data-bs-toggle="tab" data-bs-target="#siteinfo" type="button" role="tab" aria-controls="siteinfo" aria-selected="<?php echo $activeTab==='siteinfo'?'true':'false'; ?>">Site Info</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link <?php echo $activeTab==='permissions'?'active':''; ?>" id="permissions-tab" data-bs-toggle="tab" data-bs-target="#permissions" type="button" role="tab" aria-controls="permissions" aria-selected="<?php echo $activeTab==='permissions'?'true':'false'; ?>">Permissions</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link <?php echo $activeTab==='urlsettings'?'active':''; ?>" id="urlsettings-tab" data-bs-toggle="tab" data-bs-target="#urlsettings" type="button" role="tab" aria-controls="urlsettings" aria-selected="<?php echo $activeTab==='urlsettings'?'true':'false'; ?>">URL Settings</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link <?php echo $activeTab==='api'?'active':''; ?>" id="api-tab" data-bs-toggle="tab" data-bs-target="#api" type="button" role="tab" aria-controls="api" aria-selected="<?php echo $activeTab==='api'?'true':'false'; ?>">API</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link <?php echo $activeTab==='captcha'?'active':''; ?>" id="captcha-tab" data-bs-toggle="tab" data-bs-target="#captcha" type="button" role="tab" aria-controls="captcha" aria-selected="<?php echo $activeTab==='captcha'?'true':'false'; ?>">Captcha Settings</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link <?php echo $activeTab==='mail'?'active':''; ?>" id="mail-tab" data-bs-toggle="tab" data-bs-target="#mail" type="button" role="tab" aria-controls="mail" aria-selected="<?php echo $activeTab==='mail'?'true':'false'; ?>">Mail Settings</button>
            </li>
          </ul>
          <div class="tab-content">
            <!-- Site Info -->
            <div class="tab-pane fade <?php echo $activeTab==='siteinfo'?'show active':''; ?>" id="siteinfo" role="tabpanel" aria-labelledby="siteinfo-tab">
              <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="active_tab" value="<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="mb-3">
                  <label for="site_name" class="form-label">Site Name</label>
                  <input type="text" class="form-control" id="site_name" name="site_name" placeholder="The name of your site" value="<?php echo htmlspecialchars(isset($_POST['site_name']) ? $_POST['site_name'] : $site_name, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="mb-3">
                  <label for="title" class="form-label">Site Title</label>
                  <input type="text" class="form-control" id="title" name="title" placeholder="Site title tag" value="<?php echo htmlspecialchars(isset($_POST['title']) ? $_POST['title'] : $title, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="mb-3">
                  <label for="baseurl" class="form-label">Domain name</label>
                  <input type="text" class="form-control" id="baseurl" name="baseurl" placeholder="eg: pastethis.in (no trailing slash)" value="<?php echo htmlspecialchars(isset($_POST['baseurl']) ? $_POST['baseurl'] : $baseurl, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="mb-3">
                  <label for="des" class="form-label">Site Description</label>
                  <input type="text" class="form-control" id="des" name="des" placeholder="Site description" value="<?php echo htmlspecialchars(isset($_POST['des']) ? $_POST['des'] : $des, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="mb-3">
                  <label for="keyword" class="form-label">Site Keywords</label>
                  <input type="text" class="form-control" id="keyword" name="keyword" placeholder="Keywords (separated by a comma)" value="<?php echo htmlspecialchars($keyword ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="mb-3">
                  <label for="ga" class="form-label">Google Analytics</label>
                  <input type="text" class="form-control" id="ga" name="ga" placeholder="Google Analytics ID" value="<?php echo htmlspecialchars($ga ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="mb-3">
                  <label for="email" class="form-label">Admin Email</label>
                  <input type="text" class="form-control" id="email" name="email" placeholder="Email" value="<?php echo htmlspecialchars(isset($_POST['email']) ? $_POST['email'] : $email, ENT_QUOTES, 'UTF-8'); ?>">
                  <div class="form-text">Used as the From address for emails and for receiving test emails.</div>
                </div>
                <div class="mb-3">
                  <label for="face" class="form-label">Facebook URL</label>
                  <input type="text" class="form-control" id="face" name="face" placeholder="Facebook URL" value="<?php echo htmlspecialchars($face ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="mb-3">
                  <label for="twit" class="form-label">X URL</label>
                  <input type="text" class="form-control" id="twit" name="twit" placeholder="X URL" value="<?php echo htmlspecialchars($twit ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="mb-3">
                  <label for="gplus" class="form-label">Other URL</label>
                  <input type="text" class="form-control" id="gplus" name="gplus" placeholder="URL" value="<?php echo htmlspecialchars($gplus ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="mb-3">
                  <label for="additional_scripts" class="form-label">Additional Site Scripts</label>
                  <textarea class="form-control" id="additional_scripts" name="additional_scripts" rows="8"><?php echo htmlspecialchars(isset($_POST['additional_scripts']) ? $_POST['additional_scripts'] : $additional_scripts, ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <input type="hidden" name="manage" value="manage" />
                <button type="submit" class="btn btn-primary">Save</button>
              </form>
            </div>
            <!-- Permissions -->
            <div class="tab-pane fade <?php echo $activeTab==='permissions'?'show active':''; ?>" id="permissions" role="tabpanel" aria-labelledby="permissions-tab">
              <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="active_tab" value="<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-check mb-3">
                  <input class="form-check-input" type="checkbox" name="disableguest" id="disableguest" <?php if ($disableguest == 'on') echo 'checked'; ?>>
                  <label class="form-check-label" for="disableguest">Only allow registered users to paste</label>
                </div>
                <div class="form-check mb-3">
                  <input class="form-check-input" type="checkbox" name="hiderecentpastes" id="hiderecentpastes" <?php if ($hiderecentpastes == 'on') echo 'checked'; ?>>
                  <label class="form-check-label" for="hiderecentpastes">Hide Recent Pastes from sidebar</label>
                  <small class="form-text text-muted d-block">Hides the recent pastes list without making the whole site private.</small>
                </div>
                <div class="form-check mb-3">
                  <input class="form-check-input" type="checkbox" name="siteprivate" id="siteprivate" <?php if ($siteprivate == 'on') echo 'checked'; ?>>
                  <label class="form-check-label" for="siteprivate">Make site private (no Recent Pastes for non-members)</label>
                </div>
                <input type="hidden" name="permissions" value="permissions" />
                <button type="submit" class="btn btn-primary">Save</button>
              </form>
            </div>
            <!-- URL Settings -->
            <div class="tab-pane fade <?php echo $activeTab==='urlsettings'?'show active':''; ?>" id="urlsettings" role="tabpanel" aria-labelledby="urlsettings-tab">
              <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="active_tab" value="urlsettings">
                <div class="mb-4">
                  <h5>Paste URL Format</h5>
                  <p class="text-muted">Choose how paste URLs are generated.</p>
                </div>
                <div class="mb-3">
                  <label for="url_mode" class="form-label">URL Mode</label>
                  <select class="form-select" id="url_mode" name="url_mode">
                    <option value="slug" <?php echo $current_url_mode === 'slug' ? 'selected' : ''; ?>>Random Slug (e.g., /jbFzprF6)</option>
                    <option value="numeric" <?php echo $current_url_mode === 'numeric' ? 'selected' : ''; ?>>Numeric ID (e.g., /12345)</option>
                  </select>
                  <div class="form-text">
                    <strong>Random Slug:</strong> Generates URLs like <code>yoursite.com/jbFzprF6</code> - harder to enumerate, looks cleaner<br>
                    <strong>Numeric ID:</strong> Generates URLs like <code>yoursite.com/12345</code> - sequential, easy to guess
                  </div>
                </div>
                <div class="mb-3">
                  <label for="slug_length" class="form-label">Slug Length</label>
                  <input type="number" class="form-control" id="slug_length" name="slug_length" value="<?php echo $current_slug_length; ?>" min="4" max="32" style="max-width: 150px;">
                  <div class="form-text">
                    Length of random slugs (4-32 characters). Longer = more unique combinations.<br>
                    8 characters = ~53 trillion combinations. 6 characters = ~19 billion combinations.
                  </div>
                </div>
                <div class="mb-3">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="block_numeric_urls" name="block_numeric_urls" <?php echo $current_block_numeric ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="block_numeric_urls">
                      <strong>Block numeric URL access</strong> - Return 404 when accessing pastes by numeric ID
                    </label>
                  </div>
                </div>
                <div class="alert alert-info">
                  <strong>Note:</strong> Changing URL mode only affects new pastes. Run the installer to generate slugs for existing pastes.
                </div>
                <input type="hidden" name="url_settings" value="1">
                <button type="submit" class="btn btn-primary">Save URL Settings</button>
              </form>
            </div>
            <!-- API -->
            <div class="tab-pane fade <?php echo $activeTab==='api'?'show active':''; ?>" id="api" role="tabpanel" aria-labelledby="api-tab">
              <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="active_tab" value="api">
                <div class="mb-4">
                  <h5>Paste API</h5>
                  <p class="text-muted">Allow users to create and manage pastes programmatically via the API.</p>
                </div>
                <div class="mb-3">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="api_enabled" name="api_enabled" <?php echo $current_api_enabled ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="api_enabled">
                      <strong>Enable API</strong>
                    </label>
                  </div>
                  <div class="form-text">When disabled, all API requests will be rejected.</div>
                </div>
                <div class="mb-3">
                  <label for="api_keys_per_user" class="form-label">API Keys Per User</label>
                  <input type="number" class="form-control" id="api_keys_per_user" name="api_keys_per_user" value="<?php echo $current_api_keys_per_user; ?>" min="1" max="20" style="max-width: 150px;">
                  <div class="form-text">Maximum number of API keys each user can create (1-20).</div>
                </div>
                <div class="mb-3">
                  <label for="api_rate_limit" class="form-label">Requests Per Minute Per Key</label>
                  <input type="number" class="form-control" id="api_rate_limit" name="api_rate_limit" value="<?php echo (int)$current_api_rate_limit; ?>" min="0" max="10000" style="max-width: 150px;">
                  <div class="form-text">Requests over this answer 429 until the next minute. 0 turns the limit off.</div>
                </div>
                <div class="mb-4">
                  <h6 class="mb-2">Guest Access</h6>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="api_guest_pastes" name="api_guest_pastes" <?php echo $current_api_guest_pastes ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="api_guest_pastes">Guests can create pastes through the API without a key</label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="api_guest_uploads" name="api_guest_uploads" <?php echo $current_api_guest_uploads ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="api_guest_uploads">Guests can upload images and videos through the API without a key</label>
                  </div>
                  <label for="api_guest_rate_limit" class="form-label mt-2">Guest Requests Per Minute Per IP</label>
                  <input type="number" class="form-control" id="api_guest_rate_limit" name="api_guest_rate_limit" value="<?php echo (int)$current_api_guest_rate_limit; ?>" min="0" max="10000" style="max-width: 150px;">
                  <div class="form-text">Guests post as "Guest" and cannot solve the captcha through the API, so keep this low. Their pastes still go through the spam filter and cannot be private;
                    "Only allow registered users to paste" (Permissions tab) also turns guest API pastes off. Guest uploads count against the per-IP limits in Admin &gt; Uploads,
                    and need uploads turned on there. Guests cannot read, change or delete anything through the API.</div>
                </div>
                <div class="alert alert-info">
                  <strong>API Endpoints:</strong><br>
                  <code>POST /api.php?action=paste</code> - Create a paste<br>
                  <code>GET /api.php?action=get&id=X</code> - Get paste content<br>
                  <code>GET /api.php?action=list</code> - List user's pastes<br>
                  <code>DELETE /api.php?action=delete&id=X</code> - Delete a paste<br>
                  <code>GET /api.php?action=search&q=X</code> - Search pastes<br>
                  <code>POST /api.php?action=update&id=X</code> - Update a paste<br>
                  <code>GET /api.php?action=user</code> - Key owner's details<br>
                  <code>POST /api.php?action=upload</code> - Upload an image or video<br>
                  <code>DELETE /api.php?action=upload_delete&key=X</code> - Delete an upload<br>
                  <code>GET /api.php?action=languages</code> - List available languages<br><br>
                  Users can create API keys in their profile page. See <a href="../api-docs.php">api-docs.php</a>
                </div>
                <input type="hidden" name="api_settings" value="1">
                <button type="submit" class="btn btn-primary">Save API Settings</button>
              </form>
            </div>
            <!-- Captcha -->
            <div class="tab-pane fade <?php echo $activeTab==='captcha'?'show active':''; ?>" id="captcha" role="tabpanel" aria-labelledby="captcha-tab">
              <form id="captcha-form" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="active_tab" value="<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-check mb-3">
                  <input class="form-check-input" type="checkbox" name="cap_e" id="cap_e" <?php if ($cap_e == 'on') echo 'checked'; ?>>
                  <label class="form-check-label" for="cap_e">Enable Captcha</label>
                </div>
                <div class="mb-3">
                  <label for="mode" class="form-label">Captcha Type</label>
                  <select class="form-select" id="mode" name="mode">
                    <option value="Easy" <?php if ($mode == 'Easy') echo 'selected'; ?>>Easy</option>
                    <option value="Normal" <?php if ($mode == 'Normal') echo 'selected'; ?>>Normal</option>
                    <option value="Tough" <?php if ($mode == 'Tough') echo 'selected'; ?>>Tough</option>
                    <option value="reCAPTCHA" <?php if ($mode == 'reCAPTCHA') echo 'selected'; ?>>reCAPTCHA</option>
                    <option value="turnstile" <?php if ($mode == 'turnstile') echo 'selected'; ?>>Cloudflare Turnstile</option>
                  </select>
                </div>
                <div id="internal-settings" style="<?php echo in_array($mode, ['Easy', 'Normal', 'Tough']) ? '' : 'display: none;'; ?>">
                  <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="mul" id="mul" <?php if ($mul == 'on') echo 'checked'; ?>>
                    <label class="form-check-label" for="mul">Multiplication Captcha</label>
                  </div>
                  <div class="mb-3">
                    <label for="allowed" class="form-label">Allowed Characters</label>
                    <input type="text" class="form-control" id="allowed" name="allowed" value="<?php echo htmlspecialchars($allowed ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="form-text">Characters to use for non-reCAPTCHA captchas</div>
                  </div>
                  <div class="mb-3">
                    <label for="color" class="form-label">Captcha Color</label>
                    <input type="color" class="form-control form-control-color" id="color" name="color" value="<?php echo htmlspecialchars($color ?? '#000000', ENT_QUOTES, 'UTF-8'); ?>">
                  </div>
                </div>
                <div id="recaptcha-settings" style="<?php echo $mode == 'reCAPTCHA' ? '' : 'display: none;'; ?>">
                  <div class="mb-3">
                    <label for="recaptcha_version" class="form-label">reCAPTCHA Version</label>
                    <select class="form-select" id="recaptcha_version" name="recaptcha_version">
                      <option value="v2" <?php if ($recaptcha_version == 'v2') echo 'selected'; ?>>reCAPTCHA v2</option>
                      <option value="v3" <?php if ($recaptcha_version == 'v3') echo 'selected'; ?>>reCAPTCHA v3</option>
                    </select>
                  </div>
                  <div class="mb-3">
                    <label for="recaptcha_sitekey" class="form-label">reCAPTCHA Site Key</label>
                    <input type="text" class="form-control" id="recaptcha_sitekey" name="recaptcha_sitekey" value="<?php echo htmlspecialchars($recaptcha_sitekey ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="form-text">Obtain from <a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener">Google reCAPTCHA Admin</a></div>
                  </div>
                  <div class="mb-3">
                    <label for="recaptcha_secretkey" class="form-label">reCAPTCHA Secret Key</label>
                    <input type="text" class="form-control" id="recaptcha_secretkey" name="recaptcha_secretkey" value="<?php echo htmlspecialchars($recaptcha_secretkey ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                  </div>
                </div>
                <div id="turnstile-settings" style="<?php echo $mode == 'turnstile' ? '' : 'display: none;'; ?>">
                  <div class="mb-3">
                    <label for="turnstile_sitekey" class="form-label">Turnstile Site Key</label>
                    <input type="text" class="form-control" id="turnstile_sitekey" name="turnstile_sitekey" value="<?php echo htmlspecialchars($turnstile_sitekey ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="form-text">Obtain from <a href="https://dash.cloudflare.com/" target="_blank" rel="noopener">Cloudflare Dashboard</a></div>
                  </div>
                  <div class="mb-3">
                    <label for="turnstile_secretkey" class="form-label">Turnstile Secret Key</label>
                    <input type="text" class="form-control" id="turnstile_secretkey" name="turnstile_secretkey" value="<?php echo htmlspecialchars($turnstile_secretkey ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                  </div>
                </div>
                <input type="hidden" name="cap" value="cap" />
                <button type="submit" class="btn btn-primary">Save</button>
                <button type="button" id="test-recaptcha" class="btn btn-outline-primary ms-2" style="<?php echo $mode == 'reCAPTCHA' ? '' : 'display: none;'; ?>">Test reCAPTCHA</button>
                <button type="button" id="test-turnstile" class="btn btn-outline-primary ms-2" style="<?php echo $mode == 'turnstile' ? '' : 'display: none;'; ?>">Test Turnstile</button>
              </form>
            </div>
            <!-- Mail -->
            <div class="tab-pane fade <?php echo $activeTab==='mail'?'show active':''; ?>" id="mail" role="tabpanel" aria-labelledby="mail-tab">
              <form id="mail-form" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="active_tab" value="<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="mb-3">
                  <label for="verification" class="form-label">Email Verification</label>
                  <select class="form-select" id="verification" name="verification">
                    <option value="enabled" <?php if ($verification == 'enabled') echo 'selected'; ?>>Enabled</option>
                    <option value="disabled" <?php if ($verification == 'disabled') echo 'selected'; ?>>Disabled</option>
                  </select>
                  <div class="form-text">Send verification email when users register</div>
                </div>
                <div class="mb-3">
                  <label for="protocol" class="form-label">Mail Protocol</label>
                  <select class="form-select" id="protocol" name="protocol">
                    <option value="1" <?php if ($protocol == '1') echo 'selected'; ?>>PHP Mail</option>
                    <option value="2" <?php if ($protocol == '2') echo 'selected'; ?>>SMTP</option>
                  </select>
                </div>
                <div class="mb-3">
                  <label for="smtp_host" class="form-label">SMTP Host</label>
                  <input type="text" class="form-control" id="smtp_host" name="smtp_host" placeholder="smtp.gmail.com" value="<?php echo htmlspecialchars($smtp_host ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="mb-3">
                  <label for="smtp_port" class="form-label">SMTP Port</label>
                  <input type="text" class="form-control" id="smtp_port" name="smtp_port" placeholder="587" value="<?php echo htmlspecialchars($smtp_port ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="mb-3">
                  <label for="smtp_user" class="form-label">SMTP Username</label>
                  <input type="text" class="form-control" id="smtp_user" name="smtp_user" placeholder="username@domain.com" value="<?php echo htmlspecialchars($smtp_username ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                  <div class="form-text">Leave blank if using Gmail SMTP with OAuth</div>
                </div>
                <div class="mb-3">
                  <label for="smtp_pass" class="form-label">SMTP Password</label>
                  <input type="password" class="form-control" id="smtp_pass" name="smtp_pass" placeholder="SMTP Password" value="<?php echo htmlspecialchars($smtp_password ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                  <div class="form-text">Leave blank if using Gmail SMTP with OAuth</div>
                </div>
                <div class="mb-3">
                  <label for="socket" class="form-label">SMTP Security</label>
                  <select class="form-select" id="socket" name="socket">
                    <option value="tls" <?php if ($socket == 'tls') echo 'selected'; ?>>TLS</option>
                    <option value="ssl" <?php if ($socket == 'ssl') echo 'selected'; ?>>SSL</option>
                  </select>
                </div>
                <div class="mb-3">
                  <label for="auth" class="form-label">SMTP Auth</label>
                  <select class="form-select" id="auth" name="auth">
                    <option value="true" <?php if ($auth == 'true') echo 'selected'; ?>>True</option>
                    <option value="false" <?php if ($auth == 'false') echo 'selected'; ?>>False</option>
                  </select>
                </div>
                <input type="hidden" name="smtp_code" value="smtp_code" />
                <button type="submit" class="btn btn-primary">Save</button>
                <button type="button" id="test-smtp" class="btn btn-outline-primary ms-2">Test SMTP</button>
              </form>
              <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" class="mt-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="active_tab" value="<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="mb-3">
                  <label for="client_id" class="form-label">Client ID</label>
                  <input type="text" class="form-control" id="client_id" name="client_id" placeholder="Client ID" value="<?php echo htmlspecialchars($oauth_client_id ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                  <div class="form-text">Obtain from <a href="https://console.developers.google.com" target="_blank" rel="noopener">Google Cloud Console</a></div>
                </div>
                <div class="mb-3">
                  <label for="client_secret" class="form-label">Client Secret</label>
                  <input type="text" class="form-control" id="client_secret" name="client_secret" placeholder="Client Secret" value="<?php echo htmlspecialchars($oauth_client_secret ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="mb-3">
                  <label for="redirect_uri" class="form-label">Redirect URI</label>
                  <input type="text" class="form-control" id="redirect_uri" readonly value="<?php echo htmlspecialchars($redirect_uri ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                  <div class="form-text">Use this URI in Google Cloud Console for OAuth configuration</div>
                </div>
                <div class="mb-3">
                  <label class="form-label">OAuth Status</label>
                  <div class="form-text"><?php echo htmlspecialchars($oauth_status ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                  <?php if (empty($oauth_refresh_token)): ?>
                    <p class="mt-2"><a href="../oauth/google_smtp.php" class="btn btn-primary">Authorize Gmail SMTP</a></p>
                  <?php else: ?>
                    <p class="mt-2"><button type="submit" name="reset_oauth_token" value="reset" class="btn btn-danger">Reset OAuth Token</button></p>
                  <?php endif; ?>
                </div>
                <button type="submit" name="save_oauth_credentials" value="save" class="btn btn-primary">Save OAuth Credentials</button>
              </form>
            </div>
          </div><!-- /.tab-content -->
        </div>
      </div>
      <div class="text-muted small mt-3">
        Powered by <a class="text-decoration-none" href="https://phpaste.sourceforge.io" target="_blank">Paste</a>
      </div>
    </div><!-- /.col-lg-10 -->
  </div><!-- /.row -->
</div><!-- /.container -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script>
  // reCAPTCHA TEST
  $('#test-recaptcha').on('click', function(e) {
    e.preventDefault();
    var $button = $(this);
    $button.prop('disabled', true).text('Testing...');
    var formData = $('#captcha-form').serialize() + '&test_recaptcha=1&csrf_token=' + encodeURIComponent($('input[name="csrf_token"]').val());
    $.ajax({
      url: '<?php echo htmlspecialchars($_SERVER['PHP_SELF'] ?? '', ENT_QUOTES, 'UTF-8'); ?>',
      type: 'POST',
      data: formData,
      dataType: 'json',
      success: function(response) {
        $('#message-container').html(response.message);
        setTimeout(function() { $('#message-container').empty(); }, 6000);
      },
      error: function(xhr, status, error) {
        $('#message-container').html('<div class="alert alert-danger text-center">Failed to test reCAPTCHA: ' + error + '</div>');
        setTimeout(function() { $('#message-container').empty(); }, 6000);
      },
      complete: function() {
        $button.prop('disabled', false).text('Test reCAPTCHA');
      }
    });
  });
  // Turnstile TEST
  $('#test-turnstile').on('click', function(e) {
    e.preventDefault();
    var $button = $(this);
    $button.prop('disabled', true).text('Testing...');
    var formData = $('#captcha-form').serialize() + '&test_turnstile=1&csrf_token=' + encodeURIComponent($('input[name="csrf_token"]').val());
    $.ajax({
      url: '<?php echo htmlspecialchars($_SERVER['PHP_SELF'] ?? '', ENT_QUOTES, 'UTF-8'); ?>',
      type: 'POST',
      data: formData,
      dataType: 'json',
      success: function(response) {
        $('#message-container').html(response.message);
        setTimeout(function() { $('#message-container').empty(); }, 6000);
      },
      error: function(xhr, status, error) {
        $('#message-container').html('<div class="alert alert-danger text-center">Failed to test Turnstile: ' + error + '</div>');
        setTimeout(function() { $('#message-container').empty(); }, 6000);
      },
      complete: function() {
        $button.prop('disabled', false).text('Test Turnstile');
      }
    });
  });
  // SMTP TEST
  $('#test-smtp').on('click', function(e) {
    e.preventDefault();
    var $button = $(this);
    $button.prop('disabled', true).text('Testing...');
    var formData = $('#mail-form').serialize() + '&test_smtp=1&csrf_token=' + encodeURIComponent($('input[name="csrf_token"]').val());
    $.ajax({
      url: '<?php echo htmlspecialchars($_SERVER['PHP_SELF'] ?? '', ENT_QUOTES, 'UTF-8'); ?>',
      type: 'POST',
      data: formData,
      dataType: 'json',
      success: function(response) {
        $('#message-container').html(response.message);
        setTimeout(function() { $('#message-container').empty(); }, 6000);
      },
      error: function(xhr, status, error) {
        $('#message-container').html('<div class="alert alert-danger text-center">Failed to test SMTP: ' + error + '</div>');
        setTimeout(function() { $('#message-container').empty(); }, 6000);
      },
      complete: function() {
        $button.prop('disabled', false).text('Test SMTP');
      }
    });
  });
  // Toggle captcha settings based on mode
  function toggleCaptchaSettings() {
    const mode = $('#mode').val();
    $('#internal-settings').toggle(['Easy', 'Normal', 'Tough'].includes(mode));
    $('#recaptcha-settings').toggle(mode === 'reCAPTCHA');
    $('#turnstile-settings').toggle(mode === 'turnstile');
    $('#test-recaptcha').toggle(mode === 'reCAPTCHA');
    $('#test-turnstile').toggle(mode === 'turnstile');
  }
  $('#mode').on('change', toggleCaptchaSettings);
  toggleCaptchaSettings(); // Initial toggle on load
  // Keep active active tab after Save + support URL hashes
  document.addEventListener('DOMContentLoaded', () => {
    const tabs = document.getElementById('configTabs');
    const setHiddenInputs = (tabId) => {
      document.querySelectorAll('form input[name="active_tab"]').forEach(i => i.value = tabId);
    };
    const initial = '<?php echo htmlspecialchars($activeTab, ENT_QUOTES, "UTF-8"); ?>';
    setHiddenInputs(initial);
    tabs?.addEventListener('shown.bs.tab', (e) => {
      const id = e.target?.getAttribute('data-bs-target')?.replace('#','') || 'siteinfo';
      setHiddenInputs(id);
      history.replaceState(null, '', '#' + id);
      try { localStorage.setItem('config.activeTab', id); } catch(e){}
    });
    // If URL has hash on load, Bootstrap will handle via markup; localStorage fallback not required here.
  });
</script>
</body>
</html>
<?php
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    ob_end_clean();
    header('Location: index.php');
    exit();
}
ob_end_flush();
