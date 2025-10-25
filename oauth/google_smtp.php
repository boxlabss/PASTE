<?php
/*
 * Paste $v3.3 2025/10/24 https://github.com/boxlabss/PASTE
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License in LICENCE for more details.
 */
declare(strict_types=1);
session_start([
    'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'cookie_httponly' => true,
    'use_strict_mode' => true,
]);

ob_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/vendor/autoload.php';

use Google\Client as Google_Client;

try {
    // Restrict to admins
    if (!isset($_SESSION['admin_login']) || !isset($_SESSION['admin_id'])) {
        error_log("oauth/google_smtp.php: Unauthorized access attempt from {$_SERVER['REMOTE_ADDR']}");
        header('Content-Type: application/json; charset=utf-8');
        ob_end_clean();
        echo json_encode([
            'status' => 'error',
            'message' => 'Admin authentication required.',
            'redirect' => '../admin/configuration.php'
        ]);
        exit;
    }

    // CSRF token generation
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    // Ensure config.php exists
    if (!file_exists(__DIR__ . '/../config.php')) {
        throw new Exception("Missing config.php at ../config.php");
    }
    require_once __DIR__ . '/../config.php';

    // Connect to DB
    if (!isset($dbhost, $dbuser, $dbpassword, $dbname)) {
        throw new Exception("Database configuration missing in config.php.");
    }
    $pdo = new PDO("mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Fetch baseurl for redirect URI
    $stmt = $pdo->query("SELECT baseurl FROM site_info WHERE id = 1");
    $site_info = $stmt->fetch();
    if (!$site_info || empty($site_info['baseurl'])) {
        throw new Exception("Base URL not found in site_info. Go to /admin/configuration.php");
    }
    $baseurl = rtrim($site_info['baseurl'], '/') . '/';
    $redirect_uri = $baseurl . 'oauth/google_smtp.php';

    // Fetch existing mail settings
    $stmt = $pdo->query("SELECT oauth_client_id, oauth_client_secret, oauth_refresh_token FROM mail WHERE id = 1");
    $mail_settings = $stmt->fetch();
    $client_id = trim($mail_settings['oauth_client_id'] ?? '');
    $client_secret = trim($mail_settings['oauth_client_secret'] ?? '');
    $refresh_token = trim($mail_settings['oauth_refresh_token'] ?? '');

    // Handle saving client credentials via AJAX POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_credentials'])) {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("CSRF validation failed for POST request.");
        }
        $client_id = trim($_POST['client_id'] ?? '');
        $client_secret = trim($_POST['client_secret'] ?? '');
        if (empty($client_id) || empty($client_secret)) {
            throw new Exception("Please fill in both Client ID and Client Secret.");
        }
        $stmt = $pdo->prepare("UPDATE mail SET oauth_client_id = ?, oauth_client_secret = ? WHERE id = 1");
        $stmt->execute([$client_id, $client_secret]);
        error_log("oauth/google_smtp.php: OAuth credentials saved for client_id={$client_id}");
        header('Content-Type: application/json; charset=utf-8');
        ob_end_clean();
        echo json_encode(['status' => 'success', 'message' => 'OAuth credentials saved. Click "Authorize Gmail SMTP" to proceed.', 'reload' => true]);
        exit;
    }

    // Initialize Google client when needed
    if ((isset($_GET['start']) && !empty($client_id) && !empty($client_secret)) || isset($_GET['code'])) {
        $gclient = new Google_Client();
        $gclient->setClientId($client_id);
        $gclient->setClientSecret($client_secret);
        $gclient->setRedirectUri($redirect_uri);

        // IMPORTANT: use full Gmail scope for SMTP access
        $gclient->setScopes(['https://mail.google.com/']);
        $gclient->setAccessType('offline'); // request refresh token
        $gclient->setPrompt('consent');     // ensure refresh token is returned
        $gclient->setState($_SESSION['csrf_token']);
    }

    // Start OAuth flow: redirect to Google consent screen
    if (isset($_GET['start'])) {
        if (empty($client_id) || empty($client_secret)) {
            throw new Exception("Please save OAuth Client ID and Secret first.");
        }
        $authUrl = $gclient->createAuthUrl();
        error_log("oauth/google_smtp.php: Redirecting to Google OAuth: $authUrl");
        ob_end_clean();
        header('Location: ' . $authUrl);
        exit;
    }

    // OAuth callback: exchange code for tokens
    if (isset($_GET['code'])) {
        if (!isset($_GET['state']) || $_GET['state'] !== $_SESSION['csrf_token']) {
            throw new Exception("CSRF validation failed for OAuth callback.");
        }
        if (empty($client_id) || empty($client_secret)) {
            throw new Exception("OAuth Client ID or Secret not set in mail settings.");
        }

        $token = $gclient->fetchAccessTokenWithAuthCode($_GET['code']);
        if (isset($token['error'])) {
            // Provide a safe message for admin and log details
            error_log("oauth/google_smtp.php: Token error: " . json_encode($token));
            throw new Exception("Failed to obtain access token: " . htmlspecialchars($token['error_description'] ?? $token['error']));
        }

        $new_refresh = $token['refresh_token'] ?? null;
        if (!$new_refresh) {
            // If Google didn't return a refresh token, likely user previously authorized without 'prompt=consent'
            throw new Exception("No refresh token received. Ensure you've used the provided 'Authorize Gmail SMTP' button which forces a fresh consent screen.");
        }

        // Save refresh token to DB
        $stmt = $pdo->prepare("UPDATE mail SET oauth_refresh_token = ? WHERE id = 1");
        $stmt->execute([$new_refresh]);
        error_log("oauth/google_smtp.php: OAuth refresh token saved to DB.");
        ob_end_clean();
        header('Location: ../admin/configuration.php');
        exit;
    }

    // Render HTML
    header('Content-Type: text/html; charset=UTF-8');
    ob_end_flush();

} catch (Exception $e) {
    error_log("oauth/google_smtp.php: Error: " . $e->getMessage());
    header('Content-Type: application/json; charset=utf-8');
    ob_end_clean();
    echo json_encode([
        'status' => 'error',
        'message' => 'OAuth error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'),
        'reload' => true
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Google OAuth Setup for Gmail SMTP - Paste</title>
    <link rel="shortcut icon" href="../theme/default/img/favicon.ico">
    <link href="//cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="../theme/default/css/paste.css" rel="stylesheet" type="text/css">
</head>
<body>
    <nav class="navbar navbar-expand-lg bg-dark">
        <div class="container-xxl d-flex align-items-center">
            <a class="navbar-brand" href="<?php echo htmlspecialchars($baseurl ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <i class="bi bi-clipboard"></i> Paste
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <?php echo htmlspecialchars($_SESSION['admin_login'] ?? 'Admin', ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="../admin/configuration.php"><i class="bi bi-gear"></i> Configuration</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-xxl my-4">
        <div class="row">
            <div class="col-lg-10">
                <div class="card">
                    <div class="card-header">
                        <h1>Google OAuth 2.0 Setup for Gmail SMTP</h1>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <div class="mb-3">
                                <label class="form-label">Client ID</label>
                                <input type="text" class="form-control" name="client_id" placeholder="Google OAuth Client ID" value="<?php echo htmlspecialchars($client_id); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Client Secret</label>
                                <input type="text" class="form-control" name="client_secret" placeholder="Google OAuth Client Secret" value="<?php echo htmlspecialchars($client_secret); ?>">
                            </div>
                            <div class="mb-3">
                                <button type="submit" name="save_credentials" class="btn btn-primary">Save Credentials</button>
                                <?php if (!empty($client_id) && !empty($client_secret)): ?>
                                    <a href="?start=1" class="btn btn-success">Authorize Gmail SMTP</a>
                                <?php else: ?>
                                    <button type="button" class="btn btn-info" disabled>Authorize Gmail SMTP (save creds first)</button>
                                <?php endif; ?>
                            </div>
                        </form>

                        <p><a href="https://console.developers.google.com" target="_blank" rel="noreferrer">Create or manage your Google OAuth credentials</a></p>
                        <p>Redirect URI for Google Cloud Console: <code><?php echo htmlspecialchars($redirect_uri); ?></code></p>

                        <?php if (!empty($refresh_token)): ?>
                            <p><strong>Refresh Token Status:</strong> A refresh token is saved in the database.</p>
                        <?php else: ?>
                            <p><strong>Refresh Token Status:</strong> No refresh token saved. Click "Authorize Gmail SMTP" to obtain one (you'll be redirected to Google).</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelector('form').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = this.querySelector('button[name="save_credentials"]');
            btn.disabled = true;
            const formData = new FormData(this);
            fetch(this.action, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    if (data.status === 'success') {
                        alert(data.message);
                        if (data.reload) window.location.reload();
                    } else {
                        alert('Error: ' + data.message);
                        if (data.reload) window.location.reload();
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    alert('Request failed: ' + err.message);
                });
        });
    });
    </script>
</body>
</html>
<?php $pdo = null; ?>