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
ob_start();
require_once __DIR__ . '/../includes/admin_session.php';

$ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$date = date('Y-m-d H:i:s');
require_once('../config.php');
require_once __DIR__ . '/../includes/admin_access.php';

if (!isset($_SESSION['admin_login']) || !isset($_SESSION['admin_id'])) {
    ob_end_clean();
    header("Location: index.php");
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf   = (string)$_SESSION['csrf_token'];
$csrfOk = hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''));
$h      = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

try {
    $pdo = new PDO("mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpassword, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $access = admin_require(null, $pdo);
    $isFull = admin_full();

    // Tabs this admin can open
    $validTabs = $isFull ? ['settings', 'manage_admins', 'logs'] : ['settings'];
    $activeTab = (string)($_POST['active_tab'] ?? $_GET['tab'] ?? 'settings');
    if (!in_array($activeTab, $validTabs, true)) {
        $activeTab = 'settings';
    }

    $st = $pdo->prepare("SELECT id,user,pass,email FROM admin WHERE id=?");
    $st->execute([$access['id']]);
    $me = $st->fetch();
    $current_admin_id = (int)$me['id'];
    $adminid          = (string)$me['user'];
    $password_hash    = (string)$me['pass'];
    $my_email         = (string)$me['email'];

    // Logout
    if (isset($_GET['logout'])) {
        $_SESSION = [];
        session_destroy();
        ob_end_clean();
        header("Location: index.php");
        exit();
    }

    // Messages
    $msg = '';
    $msg_type = 'info';
    if (isset($_GET['msg'])) {
        $msg = htmlspecialchars((string)$_GET['msg'], ENT_QUOTES, 'UTF-8');
        $msg_type = 'success';
    } elseif (isset($_GET['error'])) {
        $msg = htmlspecialchars((string)$_GET['error'], ENT_QUOTES, 'UTF-8');
        $msg_type = 'danger';
    }

    /** Redirect back to the current tab with a message. */
    $back = function (string $text, bool $ok) use (&$activeTab): never {
        ob_end_clean();
        header('Location: admin.php?tab=' . urlencode($activeTab) . '&' . ($ok ? 'msg' : 'error') . '=' . urlencode($text));
        exit();
    };

    $isAction = $_SERVER['REQUEST_METHOD'] === 'POST'
        && (isset($_POST['update_admin']) || isset($_POST['add_admin']) || isset($_POST['set_access']) || isset($_POST['delete_admin']));
    if ($isAction && !$csrfOk) {
        $back('Your session token was missing or expired. Reload the page and try again.', false);
    }
    if ($isAction && !isset($_POST['update_admin']) && !$isFull) {
        $back('Only admins with full access can manage admins.', false);
    }

    // Per-admin access needs the permissions column; full admins add it when it is missing
    $permReady = $isFull ? admin_permissions_ensure($pdo) : admin_permissions_ready($pdo);

    /** Section keys chosen in a form, or null for full access. */
    $postedAccess = static function (): ?array {
        if (($_POST['access'] ?? 'full') !== 'limited') return null;
        return admin_parse_permissions(implode(',', array_map('strval', (array)($_POST['sections'] ?? []))));
    };

    // Update my account
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_admin'])) {
        $new_user = trim((string)($_POST['adminid'] ?? ''));
        $new_email = trim((string)($_POST['email'] ?? ''));
        $new_pass = (string)($_POST['password'] ?? '');

        if ($new_user === '' || strlen($new_user) < 3 || strlen($new_user) > 50 || !preg_match('/^[a-zA-Z0-9]+$/', $new_user)) {
            $back('Error: Username must be 3–50 alphanumeric characters.', false);
        }
        if ($new_email === '' || !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $back('Error: Valid email is required.', false);
        }
        if ($new_pass !== '' && strlen($new_pass) < 8) {
            $back('Error: Password must be at least 8 characters.', false);
        }
        $st = $pdo->prepare("SELECT COUNT(*) c FROM admin WHERE user=? AND id<>?");
        $st->execute([$new_user, $current_admin_id]);
        if ((int)$st->fetch()['c'] > 0) {
            $back('Error: Username already exists.', false);
        }
        if ($new_email !== $my_email) {
            $st = $pdo->prepare("SELECT COUNT(*) c FROM admin WHERE email=? AND id<>?");
            $st->execute([$new_email, $current_admin_id]);
            if ((int)$st->fetch()['c'] > 0) {
                $back('Error: Email already exists.', false);
            }
        }
        $hash = $new_pass !== '' ? password_hash($new_pass, PASSWORD_DEFAULT) : $password_hash;
        $st = $pdo->prepare("UPDATE admin SET user=?, email=?, pass=? WHERE id=?");
        $st->execute([$new_user, $new_email, $hash, $current_admin_id]);
        $_SESSION['admin_login'] = $new_user;
        $back('Account details updated.', true);
    }

    // Add admin
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_admin'])) {
        $new_username = trim((string)($_POST['new_username'] ?? ''));
        $new_email = trim((string)($_POST['new_email'] ?? ''));
        $new_password = (string)($_POST['new_password'] ?? '');
        $new_access = $postedAccess();

        if ($new_username === '' || $new_email === '' || $new_password === '') {
            $back('Error: Username, email, and password are required.', false);
        }
        if (strlen($new_username) < 3 || strlen($new_username) > 50 || !preg_match('/^[a-zA-Z0-9]+$/', $new_username)) {
            $back('Error: Username must be 3–50 alphanumeric characters.', false);
        }
        if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $back('Error: Valid email is required.', false);
        }
        if (strlen($new_password) < 8) {
            $back('Error: Password must be at least 8 characters.', false);
        }
        if ($new_access !== null && !$permReady) {
            $back('Error: Limited access needs the permissions column. Run the upgrader first.', false);
        }
        $st = $pdo->prepare("SELECT COUNT(*) c FROM admin WHERE user=?");
        $st->execute([$new_username]);
        if ((int)$st->fetch()['c'] > 0) {
            $back('Error: Username already exists.', false);
        }
        $st = $pdo->prepare("SELECT COUNT(*) c FROM admin WHERE email=?");
        $st->execute([$new_email]);
        if ((int)$st->fetch()['c'] > 0) {
            $back('Error: Email already exists.', false);
        }
        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        if ($permReady) {
            $st = $pdo->prepare("INSERT INTO admin (user, email, pass, permissions) VALUES (?, ?, ?, ?)");
            $st->execute([$new_username, $new_email, $hash, admin_store_permissions($new_access)]);
        } else {
            $st = $pdo->prepare("INSERT INTO admin (user, email, pass) VALUES (?, ?, ?)");
            $st->execute([$new_username, $new_email, $hash]);
        }
        $back('Admin ' . $new_username . ' added. Access: ' . admin_access_summary($new_access) . '.', true);
    }

    // Change another admin's access
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['set_access'])) {
        $aid = (int)($_POST['admin_id'] ?? 0);
        $new_access = $postedAccess();
        if (!$permReady) {
            $back('Error: Limited access needs the permissions column. Run the upgrader first.', false);
        }
        if ($aid === 1) {
            $back('Error: The primary admin (ID 1) always has full access.', false);
        }
        if ($aid === $current_admin_id) {
            $back('Error: You cannot change your own access.', false);
        }
        $st = $pdo->prepare("SELECT user FROM admin WHERE id=?");
        $st->execute([$aid]);
        $target = $st->fetchColumn();
        if ($target === false) {
            $back('Error: That admin no longer exists.', false);
        }
        $pdo->prepare("UPDATE admin SET permissions=? WHERE id=?")->execute([admin_store_permissions($new_access), $aid]);
        $back('Access for ' . $target . ': ' . admin_access_summary($new_access) . '.', true);
    }

    // Delete admin (cannot delete id 1 or yourself)
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_admin'])) {
        $del_id = (int)$_POST['delete_admin'];
        if ($del_id === 1) {
            $back('Error: You cannot delete the primary admin (ID 1).', false);
        }
        if ($del_id === $current_admin_id) {
            $back('Error: You cannot delete your own account while logged in.', false);
        }
        $pdo->prepare("DELETE FROM admin WHERE id=?")->execute([$del_id]);
        $back('Admin deleted successfully.', true);
    }

    $admins = [];
    $history_rows = [];
    $rec_count = 0;
    $page = 1;
    $total_pages = 1;
    if ($isFull) {
        $admins = $pdo->query("SELECT id, user, email, " . ($permReady ? 'permissions' : 'NULL AS permissions') . " FROM admin ORDER BY id")->fetchAll();

        // History pagination
        $rec_limit = 10;
        $page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $offset = ($page - 1) * $rec_limit;

        $rec_count = (int)$pdo->query("SELECT COUNT(*) FROM admin_history")->fetchColumn();
        $total_pages = max(1, (int)ceil($rec_count / $rec_limit));

        $st = $pdo->prepare("SELECT ah.last_date, ah.ip, ah.user_agent, a.user AS admin_user
                             FROM admin_history ah
                             LEFT JOIN admin a ON ah.admin_id = a.id
                             ORDER BY ah.id DESC LIMIT :lim OFFSET :off");
        $st->bindValue(':lim', $rec_limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();
        $history_rows = $st->fetchAll();
    }

} catch (PDOException $e) {
    ob_end_clean();
    die("Unable to connect to database: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

/** Access radios and section checkboxes for a form; $selected null means full access. */
$accessFields = function (string $prefix, ?array $selected) use ($h): string {
    $limited = $selected !== null;
    $out  = '<div class="access-choice">';
    $out .= '<div class="form-check"><input class="form-check-input" type="radio" name="access" value="full" id="' . $prefix . '_full"' . ($limited ? '' : ' checked') . '>'
          . '<label class="form-check-label" for="' . $prefix . '_full">Full access: every section, and managing admins</label></div>';
    $out .= '<div class="form-check"><input class="form-check-input" type="radio" name="access" value="limited" id="' . $prefix . '_limited"' . ($limited ? ' checked' : '') . '>'
          . '<label class="form-check-label" for="' . $prefix . '_limited">Only the sections ticked below</label></div>';
    $out .= '<fieldset class="access-sections row g-2 mt-1 ms-3"' . ($limited ? '' : ' disabled') . '>';
    foreach (admin_sections() as $key => [$label, $about]) {
        $id = $prefix . '_' . $key;
        $out .= '<div class="col-sm-6 col-xl-4"><div class="form-check">'
              . '<input class="form-check-input" type="checkbox" name="sections[]" value="' . $h($key) . '" id="' . $h($id) . '"' . ($limited && in_array($key, $selected, true) ? ' checked' : '') . '>'
              . '<label class="form-check-label" for="' . $h($id) . '">' . $h($label) . '<div class="small text-secondary">' . $h($about) . '</div></label>'
              . '</div></div>';
    }
    $out .= '<div class="col-12 small text-secondary">Every admin can open the dashboard and change their own account.</div>';
    $out .= '</fieldset></div>';
    return $out;
};
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title>Paste - Admin Account</title>
<link rel="shortcut icon" href="favicon.ico">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  :root{
    --bg:#0f1115; --card:#141821; --muted:#7f8da3; --border:#1f2633; --accent:#0d6efd;
  }
  body{background:var(--bg);color:#fff;}
  .navbar{background:#121826!important;position:sticky;top:0;z-index:1030}
  .btn-soft{background:#101521;border:1px solid var(--border);color:#dbe5f5}
  .btn-soft:hover{background:#0e1422;color:#fff}
  .sidebar-desktop{position:sticky; top:1rem;background:#121826;border:1px solid var(--border);border-radius:12px;padding:12px}
  .sidebar-desktop .list-group-item{background:transparent;color:#dbe5f5;border:0;border-radius:10px;padding:.65rem .8rem}
  .sidebar-desktop .list-group-item:hover{background:#0e1422}
  .sidebar-desktop .list-group-item.active{background:#0d6efd;color:#fff}
  .card{background:var(--card);border:1px solid var(--border);border-radius:12px}
  .form-control,.form-select{background:#0e1422;border-color:var(--border);color:#e6edf3}
  .form-control:focus,.form-select:focus{border-color:var(--accent);box-shadow:0 0 0 .25rem rgba(13,110,253,.25)}
  .table{color:#e6edf3}
  .table thead th{background:#101521;color:#c6d4f0;border-color:var(--border)}
  .table td,.table th{border-color:var(--border)}
  .pagination .page-link{color:#c6d4f0;background:#101521;border-color:var(--border)}
  .pagination .page-item.active .page-link{background:#0d6efd;border-color:#0d6efd}
  .offcanvas-nav{width:280px;background:#0f1523;color:#dbe5f5}
  .offcanvas-nav .list-group-item{background:transparent;border:0;color:#dbe5f5}
  .offcanvas-nav .list-group-item:hover{background:#0e1422}
</style>
<script>
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.access-choice').forEach(box => {
    const set = box.querySelector('.access-sections');
    box.querySelectorAll('input[name="access"]').forEach(r => r.addEventListener('change', () => {
      set.disabled = box.querySelector('input[name="access"]:checked').value !== 'limited';
    }));
  });
  const tabs = document.getElementById('adminTabs');
  const setHiddenInputs = (tabId) => {
    document.querySelectorAll('form input[name="active_tab"]').forEach(i => i.value = tabId);
  };
  const initial = '<?php echo htmlspecialchars($activeTab, ENT_QUOTES, "UTF-8"); ?>';
  setHiddenInputs(initial);
  tabs?.addEventListener('shown.bs.tab', (e) => {
    const id = e.target?.getAttribute('data-bs-target')?.replace('#','') || 'settings';
    setHiddenInputs(id);
    history.replaceState(null, '', '?tab=' + id);
    try { localStorage.setItem('admin.activeTab', id); } catch(e){}
  });
});
</script>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container-fluid">
    <div class="d-flex align-items-center gap-2">
      <button class="btn btn-soft d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#navOffcanvas" aria-controls="navOffcanvas">
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
            <?php echo htmlspecialchars($_SESSION['admin_login']); ?>
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
    <button class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body">
    <div class="list-group">
<?php admin_nav('admin.php'); ?>
    </div>
  </div>
</div>

<div class="container-fluid my-2">
  <div class="row g-2">
    <!-- Desktop sidebar -->
    <div class="col-lg-2 d-none d-lg-block">
      <div class="sidebar-desktop">
        <div class="list-group">
<?php admin_nav('admin.php'); ?>
        </div>
      </div>
    </div>

    <div class="col-lg-10">
      <?php if (!empty($msg)): ?>
        <div class="alert alert-<?php echo htmlspecialchars($msg_type); ?> alert-dismissible fade show" role="alert">
          <?php echo $msg; ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-body">
          <ul class="nav nav-tabs mb-3" id="adminTabs" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link <?php echo $activeTab==='settings'?'active':''; ?>" id="settings-tab" data-bs-toggle="tab" data-bs-target="#settings" type="button" role="tab" aria-controls="settings" aria-selected="<?php echo $activeTab==='settings'?'true':'false'; ?>">Settings</button>
            </li>
            <?php if ($isFull): ?>
            <li class="nav-item" role="presentation">
              <button class="nav-link <?php echo $activeTab==='manage_admins'?'active':''; ?>" id="manage_admins-tab" data-bs-toggle="tab" data-bs-target="#manage_admins" type="button" role="tab" aria-controls="manage_admins" aria-selected="<?php echo $activeTab==='manage_admins'?'true':'false'; ?>">Manage Admins</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link <?php echo $activeTab==='logs'?'active':''; ?>" id="logs-tab" data-bs-toggle="tab" data-bs-target="#logs" type="button" role="tab" aria-controls="logs" aria-selected="<?php echo $activeTab==='logs'?'true':'false'; ?>">Login History</button>
            </li>
            <?php endif; ?>
          </ul>

          <div class="tab-content">
            <!-- My Settings -->
            <div class="tab-pane fade <?php echo $activeTab==='settings'?'show active':''; ?>" id="settings" role="tabpanel" aria-labelledby="settings-tab">
              <h4 class="card-title">My Settings</h4>
              <form action="admin.php" method="POST" id="admin-form" class="row g-2">
                <input type="hidden" name="update_admin" value="1">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                <input type="hidden" name="active_tab" value="<?php echo htmlspecialchars($activeTab); ?>">
                <div class="col-md-4">
                  <label for="adminid" class="form-label">Username</label>
                  <input type="text" class="form-control" id="adminid" name="adminid"
                         value="<?php echo htmlspecialchars($adminid); ?>"
                         placeholder="3–50 alphanumeric" required>
                </div>
                <div class="col-md-4">
                  <label for="email" class="form-label">Email</label>
                  <input type="email" class="form-control" id="email" name="email"
                         value="<?php echo htmlspecialchars($my_email); ?>"
                         placeholder="admin@example.com" required>
                </div>
                <div class="col-md-4">
                  <label for="password" class="form-label">Password</label>
                  <input type="password" class="form-control" id="password" name="password"
                         placeholder="Leave blank to keep current">
                </div>
                <div class="col-12">
                  <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save</button>
                </div>
              </form>
              <p class="small text-secondary mt-3 mb-0"><i class="bi bi-shield-lock me-1"></i>Your access: <?php echo $h(admin_access_summary($access['sections'])); ?></p>
            </div>

            <!-- Manage Admins -->
            <?php if ($isFull): ?>
            <div class="tab-pane fade <?php echo $activeTab==='manage_admins'?'show active':''; ?>" id="manage_admins" role="tabpanel" aria-labelledby="manage_admins-tab">
              <h4 class="card-title">Manage Admins</h4>
              <?php if (!$permReady): ?>
                <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-2"></i>Limiting an admin to some sections needs a new
                  <code>permissions</code> column in the <code>admin</code> table, and the database user cannot add it. Run the upgrader
                  (<code>php install/upgrade.php</code>) or give the database user ALTER rights. Until then every admin has full access.</div>
              <?php endif; ?>

              <h5 class="mt-2">Add New Admin</h5>
              <form action="admin.php" method="POST" id="add-admin-form" class="row g-2">
                <input type="hidden" name="add_admin" value="1">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                <input type="hidden" name="active_tab" value="<?php echo $h($activeTab); ?>">
                <div class="col-md-4">
                  <label for="new_username" class="form-label">Username</label>
                  <input type="text" class="form-control" id="new_username" name="new_username" placeholder="3–50 alphanumeric" required>
                </div>
                <div class="col-md-4">
                  <label for="new_email" class="form-label">Email</label>
                  <input type="email" class="form-control" id="new_email" name="new_email" placeholder="admin@example.com" required>
                </div>
                <div class="col-md-4">
                  <label for="new_password" class="form-label">Password</label>
                  <input type="password" class="form-control" id="new_password" name="new_password" placeholder="Min 8 characters" required>
                </div>
                <?php if ($permReady): ?>
                <div class="col-12">
                  <label class="form-label mt-2">Access</label>
                  <?php echo $accessFields('new', null); ?>
                </div>
                <?php endif; ?>
                <div class="col-12">
                  <button type="submit" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Add Admin</button>
                </div>
              </form>

              <h5 class="mt-4">Existing Admins</h5>
              <div class="table-responsive">
                <table class="table table-hover table-bordered align-middle" id="admins-table">
                  <thead>
                    <tr>
                      <th>ID</th>
                      <th>Username</th>
                      <th>Email</th>
                      <th>Access</th>
                      <th style="width:220px;">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($admins)): ?>
                      <tr><td colspan="5" class="text-center">No admins found</td></tr>
                    <?php else: ?>
                      <?php foreach ($admins as $a):
                        $aid = (int)$a['id'];
                        $aSections = $aid === 1 ? null : admin_parse_permissions($a['permissions']);
                      ?>
                        <tr data-admin="<?php echo $h($a['user']); ?>">
                          <td><?php echo $aid; ?></td>
                          <td><?php echo $h($a['user']); ?></td>
                          <td><?php echo $h($a['email']); ?></td>
                          <td class="small access-summary"><?php echo $h(admin_access_summary($aSections)); ?></td>
                          <td class="text-nowrap">
                            <?php if ($aid === 1): ?>
                              <span class="badge bg-secondary">Primary Admin</span>
                            <?php elseif ($aid === $current_admin_id): ?>
                              <span class="badge bg-info text-dark">Current Admin</span>
                            <?php else: ?>
                              <?php if ($permReady): ?>
                              <button type="button" class="btn btn-soft btn-sm" data-bs-toggle="collapse" data-bs-target="#access-<?php echo $aid; ?>" aria-expanded="false">
                                <i class="bi bi-shield-lock"></i> Access</button>
                              <?php endif; ?>
                              <form method="POST" action="admin.php" class="d-inline" onsubmit="return confirm('Delete admin <?php echo $h(addslashes($a['user'])); ?>? This cannot be undone.')">
                                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                                <input type="hidden" name="active_tab" value="manage_admins">
                                <button class="btn btn-danger btn-sm" name="delete_admin" value="<?php echo $aid; ?>"><i class="bi bi-trash"></i> Delete</button>
                              </form>
                            <?php endif; ?>
                          </td>
                        </tr>
                        <?php if ($permReady && $aid !== 1 && $aid !== $current_admin_id): ?>
                        <tr class="collapse" id="access-<?php echo $aid; ?>">
                          <td colspan="5">
                            <form method="POST" action="admin.php" class="p-2">
                              <input type="hidden" name="set_access" value="1">
                              <input type="hidden" name="admin_id" value="<?php echo $aid; ?>">
                              <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                              <input type="hidden" name="active_tab" value="manage_admins">
                              <div class="mb-2 fw-semibold">Access for <?php echo $h($a['user']); ?></div>
                              <?php echo $accessFields('a' . $aid, $aSections); ?>
                              <button class="btn btn-primary btn-sm mt-2"><i class="bi bi-save"></i> Save access</button>
                            </form>
                          </td>
                        </tr>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
              <div class="small text-secondary">The primary admin (ID 1) always has full access. Only admins with full access see this tab and the login history.</div>
            </div>

            <!-- Login History -->
            <div class="tab-pane fade <?php echo $activeTab==='logs'?'show active':''; ?>" id="logs" role="tabpanel" aria-labelledby="logs-tab">
              <h4 class="card-title">Login History</h4>
              <?php if ($rec_count === 0): ?>
                <p class="text-muted">No login history available.</p>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-hover table-bordered align-middle">
                    <thead><tr><th>Date</th><th>Admin</th><th>IP</th><th>User Agent</th></tr></thead>
                    <tbody>
                      <?php foreach ($history_rows as $r): ?>
                        <tr>
                          <td><?php echo htmlspecialchars($r['last_date']); ?></td>
                          <td><?php echo htmlspecialchars($r['admin_user'] ?? 'Legacy'); ?></td>
                          <td><?php echo htmlspecialchars($r['ip']); ?></td>
                          <td><?php echo htmlspecialchars($r['user_agent']); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <?php if ($total_pages > 1): ?>
                  <nav aria-label="Login history">
                    <ul class="pagination justify-content-center">
                      <?php if ($page > 1): ?>
                        <li class="page-item"><a class="page-link" href="?tab=logs&page=<?php echo $page-1; ?>">&laquo;</a></li>
                      <?php else: ?>
                        <li class="page-item disabled"><span class="page-link">&laquo;</span></li>
                      <?php endif; ?>
                      <?php
                        $start=max(1,$page-3); $end=min($total_pages,$page+3);
                        for($i=$start;$i<=$end;$i++){
                          $active=$i===$page?' active':'';
                          echo "<li class='page-item$active'><a class='page-link' href='?tab=logs&page=$i'>$i</a></li>";
                        }
                      ?>
                      <?php if ($page < $total_pages): ?>
                        <li class="page-item"><a class="page-link" href="?tab=logs&page=<?php echo $page+1; ?>">&raquo;</a></li>
                      <?php else: ?>
                        <li class="page-item disabled"><span class="page-link">&raquo;</span></li>
                      <?php endif; ?>
                    </ul>
                  </nav>
                <?php endif; ?>
              <?php endif; ?>
            </div>
            <?php endif; ?>

          </div>
        </div>
      </div>

      <div class="text-muted small mt-3">
        Powered by <a class="text-decoration-none" href="https://phpaste.sourceforge.io" target="_blank">Paste</a>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<?php
$pdo = null;
ob_end_flush();
?>
</body>
</html>
