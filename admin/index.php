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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License in LICENCE for more details.
 */
require_once('../includes/password.php'); 
session_start();
require_once('../config.php');
require_once('../mail/mail.php');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Fetch site info for baseurl and site_name
try {
    $pdo = new PDO("mysql:host=$dbhost;dbname=$dbname", $dbuser, $dbpassword);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->query("SELECT baseurl, site_name FROM site_info WHERE id = 1");
    $site = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log("index.php: Database connection failed for site_info: " . $e->getMessage());
    $site = [];
}
$baseurl = trim($site['baseurl'] ?? '');
$site_name = trim($site['site_name'] ?? 'Paste');

// Check if admin is already logged in
if (isset($_SESSION['admin_login']) && isset($_SESSION['admin_id'])) {
    try {
        $stmt = $pdo->prepare('SELECT id, user FROM admin WHERE id = ?');
        $stmt->execute([$_SESSION['admin_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['user'] === $_SESSION['admin_login']) {
            error_log("index.php: Admin already logged in - user: {$_SESSION['admin_login']}, redirecting to dashboard.php");
            header("Location: dashboard.php");
            exit();
        } else {
            error_log("index.php: Session validation failed - id: {$_SESSION['admin_id']}, user: {$_SESSION['admin_login']}, found: " . ($row ? json_encode($row) : 'null'));
            unset($_SESSION['admin_login'], $_SESSION['admin_id']);
        }
    } catch (PDOException $e) {
        error_log("index.php: Database connection failed during session validation: " . $e->getMessage());
        unset($_SESSION['admin_login'], $_SESSION['admin_id']);
    }
}

$action = $_GET['action'] ?? '';
$msg = '';

// Reset password
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $msg = '<div class="alert alert-danger text-center mb-3">Invalid CSRF token</div>';
    } else {
        if ($action === 'forgot') {
            $username = trim($_POST['username'] ?? '');
            if ($username === '') {
                $msg = '<div class="alert alert-warning text-center mb-3">Username is required</div>';
            } else {
                try {
                    $stmt = $pdo->prepare('SELECT id, email FROM admin WHERE user = ?');
                    $stmt->execute([$username]);
                    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $code = bin2hex(random_bytes(16));
                        $exp = date('Y-m-d H:i:s', strtotime('+1 hour'));
                        $stmt = $pdo->prepare('UPDATE admin SET reset_code = ?, reset_expiry = ? WHERE user = ?');
                        $stmt->execute([$code, $exp, $username]);
                        $reset_url = rtrim($baseurl, '/') . '/admin/index.php?action=reset&user=' . urlencode($username) . '&code=' . urlencode($code);
                        $subject = "$site_name Admin Password Reset";
                        $body = "To reset your admin password, click the link below:<br><br><a href='$reset_url' target='_self'>$reset_url</a><br><br>This link expires in 1 hour.";
                        send_mail($row['email'], $subject, $body, $site_name, $csrf_token);
                        $msg = '<div class="alert alert-success text-center mb-3">If that username exists, the password reset link was successfully sent to your email.</div>';
                    } else {
                        error_log("index.php: Forgot password failed - invalid username: '$username'");
                        $msg = '<div class="alert alert-success text-center mb-3">If that username exists, the password reset link was successfully sent to your email.</div>';
                    }
                } catch (PDOException $e) {
                    error_log("index.php: Database error during forgot password: " . $e->getMessage());
                    $msg = '<div class="alert alert-danger text-center mb-3">An error occurred. Please try again.</div>';
                }
            }
        } elseif ($action === 'reset') {
            $username = trim($_GET['user'] ?? '');
            $code = trim($_GET['code'] ?? '');
            $password = trim($_POST['password'] ?? '');
            if ($password === '') {
                $msg = '<div class="alert alert-warning text-center mb-3">Password is required</div>';
            } else {
                try {
                    $stmt = $pdo->prepare('SELECT id FROM admin WHERE user = ? AND reset_code = ? AND reset_expiry > NOW()');
                    $stmt->execute([$username, $code]);
                    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare('UPDATE admin SET pass = ?, reset_code = NULL, reset_expiry = NULL WHERE user = ?');
                        $stmt->execute([$hash, $username]);
                        error_log("index.php: Password reset successful for user: '$username'");
                        $msg = '<div class="alert alert-success text-center mb-3">Password reset successful. You can now log in.</div>';
                    } else {
                        error_log("index.php: Password reset failed - invalid/expired code for user: '$username'");
                        $msg = '<div class="alert alert-danger text-center mb-3">Invalid or expired reset link</div>';
                    }
                } catch (PDOException $e) {
                    error_log("index.php: Database error during password reset: " . $e->getMessage());
                    $msg = '<div class="alert alert-danger text-center mb-3">An error occurred. Please try again.</div>';
                }
            }
        } else {
            // Login
            $username = trim($_POST['username'] ?? '');
            $password = trim($_POST['password'] ?? '');
            if ($username === '' || $password === '') {
                error_log("index.php: Login failed - username or password empty. Username: '$username'");
                $msg = '<div class="alert alert-warning text-center mb-3">Username and password are required</div>';
            } else {
                try {
                    $stmt = $pdo->prepare('SELECT id, user, pass FROM admin WHERE user = :user LIMIT 1');
                    $stmt->execute(['user' => $username]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($row && password_verify($password, $row['pass'])) {
                        $_SESSION['admin_login'] = $row['user'];
                        $_SESSION['admin_id'] = $row['id'];
                        error_log("index.php: Login successful for user: '$username', redirecting to dashboard.php");
                        // Login Admin History
                        $date = date('Y-m-d H:i:s');
                        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                        $log_stmt = $pdo->prepare("INSERT INTO admin_history (admin_id, last_date, ip, user_agent) VALUES (?, ?, ?, ?)");
                        $log_stmt->execute([$row['id'], $date, $ip, $ua]);
                        header("Location: dashboard.php");
                        exit();
                    } else {
                        error_log("index.php: Login failed - invalid username or password. Username: '$username', Row: " . ($row ? json_encode($row) : 'null'));
                        $msg = '<div class="alert alert-danger text-center mb-3">Invalid login details.</div>';
                    }
                } catch (PDOException $e) {
                    error_log("index.php: Database error during login: " . $e->getMessage());
                    $msg = '<div class="alert alert-danger text-center mb-3">An error occurred. Please try again.</div>';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Paste - Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  :root{
    --bg:#0f1115; --card:#141821; --muted:#7f8da3; --border:#1f2633; --accent:#0d6efd;
  }
  body{background:var(--bg);color:#e6edf3;}
  .navbar{background:#121826!important}
  .card{background:var(--card);border:1px solid var(--border);border-radius:12px}
  .form-control{background:#0e1422;border-color:var(--border);color:#e6edf3}
  .form-control:focus{border-color:var(--accent);box-shadow:0 0 0 .25rem rgba(13,110,253,.25)}
  .btn-soft{background:#101521;border:1px solid var(--border);color:#dbe5f5}
  .btn-soft:hover{background:#0e1422;color:#fff}
  .login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
  .brand{font-weight:600}
</style>
</head>
<body>
<nav class="navbar navbar-dark">
  <div class="container-fluid">
    <a class="navbar-brand brand" href="../">Paste</a>
  </div>
</nav>

<div class="login-wrap">
  <div class="card shadow-sm" style="max-width:460px;width:100%;">
    <div class="card-body p-4">
      <?php if ($action === 'forgot') { ?>
        <div class="d-flex align-items-center justify-content-between mb-3">
          <h4 class="mb-0">Forgot Password</h4>
          <i class="bi bi-shield-lock"></i>
        </div>
        <?php if ($msg) echo $msg; ?>
        <form action="?action=forgot" method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
          <div class="mb-3">
            <label for="username" class="form-label">Username</label>
            <input type="text" class="form-control" id="username" name="username" autocomplete="username" required>
          </div>
          <div class="d-grid gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-envelope me-1"></i>Send Reset Link</button>
            <a class="btn btn-soft" href="index.php">Back to Login</a>
          </div>
        </form>
      <?php } elseif ($action === 'reset' && isset($_GET['user']) && isset($_GET['code'])) { ?>
        <div class="d-flex align-items-center justify-content-between mb-3">
          <h4 class="mb-0">Reset Password</h4>
          <i class="bi bi-shield-lock"></i>
        </div>
        <?php if ($msg) echo $msg; ?>
        <form action="?action=reset&user=<?php echo urlencode($_GET['user']); ?>&code=<?php echo urlencode($_GET['code']); ?>" method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
          <div class="mb-3">
            <label for="password" class="form-label">New Password</label>
            <input type="password" class="form-control" id="password" name="password" autocomplete="new-password" required>
          </div>
          <div class="d-grid gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-key me-1"></i>Reset Password</button>
            <a class="btn btn-soft" href="index.php">Back to Login</a>
          </div>
        </form>
      <?php } else { ?>
        <div class="d-flex align-items-center justify-content-between mb-3">
          <h4 class="mb-0">Admin Login</h4>
          <i class="bi bi-shield-lock"></i>
        </div>
        <?php if ($msg) echo $msg; ?>
        <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
          <div class="mb-3">
            <label for="username" class="form-label">Username</label>
            <input type="text" class="form-control" id="username" name="username" autocomplete="username" required>
          </div>
          <div class="mb-3">
            <label for="password" class="form-label d-flex justify-content-between">
              <span>Password</span>
              <a class="link-secondary small text-decoration-none" href="?action=forgot">Forgot?</a>
            </label>
            <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
          </div>
          <div class="d-grid gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-box-arrow-in-right me-1"></i>Login</button>
            <a class="btn btn-soft" href="<?php echo htmlspecialchars('../', ENT_QUOTES, 'UTF-8'); ?>">Back to site</a>
          </div>
        </form>
      <?php } ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
<?php $pdo = null; ?>