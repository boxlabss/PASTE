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
require_once __DIR__ . '/../includes/admin_session.php';

// Guard: admin session
if (!isset($_SESSION['admin_login']) || !isset($_SESSION['admin_id'])) {
    error_log("users.php: admin session missing");
    header("Location: ../index.php");
    exit();
}

/* Logout */
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['csrf_token'];

$date = date('Y-m-d H:i:s');
$ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

require_once('../config.php');
require_once __DIR__ . '/../includes/admin_access.php';
admin_require('users');
require_once(__DIR__ . '/../includes/spam.php');
require_once(__DIR__ . '/../includes/account.php');

try {
    $pdo = new PDO("mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpassword, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    die("Unable to connect to database: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

/* Base URL for sidebar links */
try {
    $st = $pdo->query("SELECT baseurl FROM site_info WHERE id = 1");
    $baseurl = rtrim((string)($st->fetch()['baseurl'] ?? ''), '/').'/';
} catch (PDOException $e) {
    $baseurl = '../';
}

/* Messages */
$msg = '';

$hasExempt = spam_ensure_exempt_column($pdo);

/* Actions (GET, CSRF-protected) */
$actionKeys = ['delete', 'ban', 'unban', 'verify', 'exempt', 'unexempt'];
if (array_intersect(array_keys($_GET), $actionKeys)
    && !hash_equals($csrf, (string)($_GET['csrf_token'] ?? ''))) {
    $msg = '<div class="alert alert-danger text-center">Your session token was missing or expired. Reload the page and try again.</div>';
    foreach ($actionKeys as $k) unset($_GET[$k]);
}

/* Builds a CSRF-protected action link that keeps the current page and filter. */
$actionHref = function(string $action, int $id) use ($csrf, &$page, &$status_filter): string {
    return '?' . http_build_query([
        $action      => $id,
        'page'       => $page,
        'status'     => $status_filter,
        'csrf_token' => $csrf,
    ]);
};

if ($hasExempt && (isset($_GET['exempt']) || isset($_GET['unexempt']))) {
    $on  = isset($_GET['exempt']);
    $uid = (int)($on ? $_GET['exempt'] : $_GET['unexempt']);
    try {
        $stmt = $pdo->prepare("UPDATE users SET spam_exempt = ? WHERE id = ?");
        $stmt->execute([$on ? 1 : 0, $uid]);
        $msg = $on
            ? '<div class="alert alert-success text-center">User is now exempt from the spam filter</div>'
            : '<div class="alert alert-success text-center">Spam filter exemption removed</div>';
    } catch (PDOException $e) {
        $msg = '<div class="alert alert-danger text-center">Error updating exemption: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    }
}

if (isset($_GET['delete'])) {
    $delid = (int)filter_var($_GET['delete'], FILTER_SANITIZE_NUMBER_INT);
    try {
        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$delid]);
        $delname = $stmt->fetchColumn();
        if ($delname !== false) {
            paste_delete_account($pdo, $delid, (string)$delname);
        }
        $msg = $delname !== false && paste_username_reserved((string)$delname)
            ? '<div class="alert alert-success text-center">Account deleted. Content under its reserved name was kept</div>'
            : '<div class="alert alert-success text-center">User and their pastes, comments, uploads and albums deleted</div>';
    } catch (Throwable $e) {
        $msg = '<div class="alert alert-danger text-center">Error deleting user: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    }
}

if (isset($_GET['ban'])) {
    $ban_id = (int)filter_var($_GET['ban'], FILTER_SANITIZE_NUMBER_INT);
    try {
        // Mark banned and kill any live session (token) so it can't keep working.
        $stmt = $pdo->prepare("UPDATE users SET verified = '2', token = NULL WHERE id = ?");
        $stmt->execute([$ban_id]);
        // Also disable their API keys so the ban covers the API, not just the web UI.
        try { $pdo->prepare("UPDATE api_keys SET is_active = 0 WHERE user_id = ?")->execute([$ban_id]); }
        catch (PDOException $e) { /* api_keys table optional; ignore if absent */ }
        $msg = '<div class="alert alert-success text-center">User banned successfully</div>';
    } catch (PDOException $e) {
        $msg = '<div class="alert alert-danger text-center">Error banning user: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    }
}

if (isset($_GET['unban'])) {
    $unban_id = (int)filter_var($_GET['unban'], FILTER_SANITIZE_NUMBER_INT);
    try {
        $stmt = $pdo->prepare("UPDATE users SET verified = '1' WHERE id = ?");
        $stmt->execute([$unban_id]);
        $msg = '<div class="alert alert-success text-center">User unbanned successfully</div>';
    } catch (PDOException $e) {
        $msg = '<div class="alert alert-danger text-center">Error unbanning user: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    }
}

/* verify action (for unverified users) */
if (isset($_GET['verify'])) {
    $verify_id = (int)filter_var($_GET['verify'], FILTER_SANITIZE_NUMBER_INT);
    try {
        $stmt = $pdo->prepare("UPDATE users SET verified = '1' WHERE id = ?");
        $stmt->execute([$verify_id]);
        $msg = '<div class="alert alert-success text-center">User verified successfully</div>';
    } catch (PDOException $e) {
        $msg = '<div class="alert alert-danger text-center">Error verifying user: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    }
}

/* Filters / pagination */
$per_page = 20;
$page     = isset($_GET['page']) ? max(1, (int)filter_var($_GET['page'], FILTER_SANITIZE_NUMBER_INT)) : 1;
$offset   = ($page - 1) * $per_page;

$status_filter = isset($_GET['status']) ? (string)$_GET['status'] : 'all';

$where  = '';
$params = [];
if ($status_filter === 'exempt') {
    $where = $hasExempt ? " WHERE spam_exempt = 1" : " WHERE 1 = 0";
} elseif ($status_filter !== 'all') {
    $where = " WHERE verified = ?";
    $params[] = $status_filter;
}

/* Count */
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM users".$where);
    $stmt->execute($params);
    $total_users = (int)($stmt->fetch()['total'] ?? 0);
} catch (PDOException $e) {
    $total_users = 0;
}
$total_pages = max(1, (int)ceil($total_users / $per_page));

/* Page data */
$per_page_safe = (int)$per_page;
$offset_safe   = (int)$offset;
$exemptCol = $hasExempt ? ', spam_exempt' : ', 0 AS spam_exempt';
$query = "
    SELECT id, username, email_id, full_name, platform, verified, date, ip, oauth_uid{$exemptCol}
      FROM users
      $where
  ORDER BY id DESC
     LIMIT $per_page_safe OFFSET $offset_safe
";
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    $msg = '<div class="alert alert-danger text-center">Error fetching users: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    $users = [];
}

/* Details */
$detailRow = null;
if (isset($_GET['details'])) {
    $detail_id = (int)filter_var($_GET['details'], FILTER_SANITIZE_NUMBER_INT);
    $st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$detail_id]);
    $detailRow = $st->fetch() ?: null;
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title>Paste - Users</title>
<link rel="shortcut icon" href="favicon.ico">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

<style>
  :root{
    --bg:#0f1115;
    --card:#141821;
    --muted:#7f8da3;
    --border:#1f2633;
    --accent:#0d6efd;
  }
  body{background:var(--bg);color:#e6edf3;}
  .navbar{background:#121826!important;position:sticky;top:0;z-index:1030}
  .navbar .navbar-brand{font-weight:600}
  .btn-soft{background:#101521;border:1px solid var(--border);color:#dbe5f5}
  .btn-soft:hover{background:#0e1422;color:#fff}

  /* Desktop sidebar (matches pages.php) */
  .sidebar-desktop{
    position:sticky; top:1rem;
    background:#121826;border:1px solid var(--border);
    border-radius:12px; padding:12px;
  }
  .sidebar-desktop .list-group-item{
    background:transparent;color:#dbe5f5;border:0;border-radius:10px;padding:.65rem .8rem;
  }
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

  /* Offcanvas for mobile */
  .offcanvas-nav{width:280px;background:#0f1523;color:#dbe5f5}
  .offcanvas-nav .list-group-item{background:transparent;border:0;color:#dbe5f5}
  .offcanvas-nav .list-group-item:hover{background:#0e1422}

  /* Action buttons row */
  .btn-group-xs > .btn { padding: .25rem .5rem; font-size:.8rem; }
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container-fluid">
    <div class="d-flex align-items-center gap-2">
      <!-- Mobile: open offcanvas -->
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
<?php admin_nav('users.php'); ?>
    </div>
  </div>
</div>

<div class="container-fluid my-2">
  <div class="row g-2">
    <!-- Desktop sidebar -->
    <div class="col-lg-2 d-none d-lg-block">
      <div class="sidebar-desktop">
        <div class="list-group">
<?php admin_nav('users.php'); ?>
        </div>
      </div>
    </div>

    <div class="col-lg-10">
      <?php if ($msg) echo $msg; ?>

      <?php if ($detailRow): ?>
        <div class="card mb-3">
          <div class="card-body">
            <h4 class="card-title mb-3"><?php echo htmlspecialchars($detailRow['username']); ?> — Details</h4>
            <div class="table-responsive">
              <table class="table table-striped">
                <tbody>
                  <tr><td>Username</td><td><?php echo htmlspecialchars($detailRow['username']); ?></td></tr>
                  <tr><td>Email</td><td><?php echo htmlspecialchars($detailRow['email_id']); ?></td></tr>
                  <tr><td>Full name</td><td><?php echo htmlspecialchars($detailRow['full_name']); ?></td></tr>
                  <tr><td>Platform</td><td><?php echo htmlspecialchars(trim((string)$detailRow['platform'])); ?></td></tr>
                  <tr><td>OAUTH ID</td><td><?php echo $detailRow['oauth_uid']=='0'?'None':htmlspecialchars($detailRow['oauth_uid']); ?></td></tr>
                  <tr><td>Status</td><td>
                    <?php
                      echo match ((string)$detailRow['verified']) {
                        '0' => '<span class="badge bg-secondary">Unverified</span>',
                        '1' => '<span class="badge bg-success">Verified</span>',
                        '2' => '<span class="badge bg-danger">Banned</span>',
                        default => '<span class="badge bg-dark">Unknown</span>'
                      };
                    ?>
                  </td></tr>
                  <?php if ($hasExempt): $dExempt = (int)($detailRow['spam_exempt'] ?? 0) === 1; ?>
                  <tr><td>Spam filter</td><td>
                    <?php echo $dExempt ? '<span class="badge bg-info text-dark">Exempt</span>' : '<span class="badge bg-secondary">Checked</span>'; ?>
                    <a class="btn btn-soft btn-sm ms-2" href="<?php echo htmlspecialchars('?' . http_build_query([($dExempt ? 'unexempt' : 'exempt') => (int)$detailRow['id'], 'details' => (int)$detailRow['id'], 'csrf_token' => $csrf]), ENT_QUOTES, 'UTF-8'); ?>">
                      <?php echo $dExempt ? 'Remove exemption' : 'Exempt from spam filter'; ?>
                    </a>
                  </td></tr>
                  <?php endif; ?>
                  <tr><td>Registered</td><td><?php echo htmlspecialchars($detailRow['date']); ?></td></tr>
                  <tr><td>IP</td><td><?php echo htmlspecialchars($detailRow['ip']); ?></td></tr>
                </tbody>
              </table>
            </div>
            <a class="btn btn-soft btn-sm" href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>"><i class="bi bi-arrow-left"></i> Back</a>
          </div>
        </div>

      <?php else: ?>
        <div class="card mb-3">
          <div class="card-body">
            <h4 class="card-title">Manage Users</h4>
            <form class="row g-2 align-items-end" method="get" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
              <div class="col-md-4">
                <label class="form-label">Status</label>
                <select class="form-select" name="status" onchange="this.form.submit()">
                  <option value="all" <?php echo $status_filter==='all'?'selected':''; ?>>All</option>
                  <option value="0" <?php echo $status_filter==='0'?'selected':''; ?>>Unverified</option>
                  <option value="1" <?php echo $status_filter==='1'?'selected':''; ?>>Verified</option>
                  <option value="2" <?php echo $status_filter==='2'?'selected':''; ?>>Banned</option>
                  <?php if ($hasExempt): ?>
                  <option value="exempt" <?php echo $status_filter==='exempt'?'selected':''; ?>>Spam filter exempt</option>
                  <?php endif; ?>
                </select>
              </div>
              <div class="col-md-2">
                <a class="btn btn-soft w-100" href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>"><i class="bi bi-x-circle"></i> Reset</a>
              </div>
            </form>
          </div>
        </div>

        <div class="card">
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-hover table-bordered align-middle">
                <thead>
                  <tr>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Registered</th>
                    <th>Platform</th>
                    <th>OAUTH</th>
                    <th>Status</th>
                    <th class="text-center">Actions</th>
                  </tr>
                </thead>
                <tbody>
                <?php if ($users): foreach ($users as $u):
                  $statusBadge = match ((string)$u['verified']) {
                    '0' => '<span class="badge bg-secondary">Unverified</span>',
                    '1' => '<span class="badge bg-success">Verified</span>',
                    '2' => '<span class="badge bg-danger">Banned</span>',
                    default => '<span class="badge bg-dark">Unknown</span>'
                  };
                  $oauth = $u['oauth_uid']=='0'?'None':htmlspecialchars((string)$u['oauth_uid']);
                  $id    = (int)$u['id'];
                  $isBanned = ((string)$u['verified'] === '2');
                  $isUnverified = ((string)$u['verified'] === '0');
                  $isExempt = ((int)($u['spam_exempt'] ?? 0) === 1);

                  $banHref  = $actionHref($isBanned ? 'unban' : 'ban', $id);
                  $banLabel = $isBanned ? 'Unban' : 'Ban';

                  $verifyHref = $actionHref('verify', $id);
                  $exemptHref = $actionHref($isExempt ? 'unexempt' : 'exempt', $id);
                  $deleteHref = $actionHref('delete', $id);
                ?>
                  <tr>
                    <td><?php echo htmlspecialchars($u['username']); ?></td>
                    <td><?php echo htmlspecialchars($u['email_id']); ?></td>
                    <td><?php echo htmlspecialchars($u['date']); ?></td>
                    <td><?php echo htmlspecialchars(trim((string)$u['platform'])); ?></td>
                    <td><?php echo $oauth; ?></td>
                    <td>
                      <?php echo $statusBadge; ?>
                      <?php if ($isExempt): ?><span class="badge bg-info text-dark ms-1" title="Exempt from the spam filter">Exempt</span><?php endif; ?>
                    </td>
                    <td class="text-center">
                      <div class="btn-group btn-group-xs" role="group" aria-label="User actions">
                        <a class="btn btn-soft btn-sm" href="?details=<?php echo $id; ?>" title="Details"><i class="bi bi-person-badge"></i></a>

                        <?php if ($hasExempt): ?>
                          <a class="btn btn-sm <?php echo $isExempt ? 'btn-info' : 'btn-soft'; ?> exempt-user" href="<?php echo htmlspecialchars($exemptHref, ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo $isExempt ? 'Remove spam filter exemption' : 'Exempt from spam filter'; ?>">
                            <i class="bi <?php echo $isExempt ? 'bi-shield-fill-check' : 'bi-shield'; ?>"></i>
                          </a>
                        <?php endif; ?>

                        <?php if ($isUnverified): ?>
                          <a class="btn btn-success btn-sm verify-user" href="<?php echo htmlspecialchars($verifyHref, ENT_QUOTES, 'UTF-8'); ?>" title="Verify user">
                            <i class="bi bi-check2-circle"></i>
                          </a>
                        <?php endif; ?>

                        <a class="btn btn-secondary btn-sm ban-user" href="<?php echo htmlspecialchars($banHref, ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo $banLabel; ?>">
                          <i class="bi <?php echo $isBanned ? 'bi-unlock' : 'bi-slash-circle'; ?>"></i>
                        </a>

                        <a class="btn btn-danger btn-sm delete-user" href="<?php echo htmlspecialchars($deleteHref, ENT_QUOTES, 'UTF-8'); ?>" title="Delete">
                          <i class="bi bi-trash"></i>
                        </a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; else: ?>
                  <tr><td colspan="7" class="text-center">No users found</td></tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>

            <nav aria-label="Page navigation">
              <ul class="pagination justify-content-center">
                <?php
                  $params = [];
                  if ($status_filter !== 'all') $params['status'] = $status_filter;
                  $qs = function($arr){ return $arr ? ('?'.http_build_query($arr)) : '?'; };

                  $prev = $params; $prev['page'] = max(1, $page-1);
                  $next = $params; $next['page'] = min($total_pages, $page+1);

                  echo '<li class="page-item'.($page<=1?' disabled':'').'"><a class="page-link" href="'.($page<=1?'#':htmlspecialchars($qs($prev))).'">&laquo;</a></li>';

                  $start = max(1, $page-3); $end = min($total_pages, $page+3);
                  for ($i=$start; $i<=$end; $i++) {
                    $p = $params; $p['page']=$i;
                    echo '<li class="page-item'.($i==$page?' active':'').'"><a class="page-link" href="'.htmlspecialchars($qs($p)).'">'.$i.'</a></li>';
                  }

                  echo '<li class="page-item'.($page>=$total_pages?' disabled':'').'"><a class="page-link" href="'.($page>= $total_pages?'#':htmlspecialchars($qs($next))).'">&raquo;</a></li>';
                ?>
              </ul>
            </nav>
          </div>
        </div>
      <?php endif; ?>

      <div class="text-muted small mt-3">
        Powered by <a class="text-decoration-none" href="https://phpaste.sourceforge.io" target="_blank" rel="noopener">Paste</a>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script>
  // Confirm handlers (Delete / Ban-Unban / Verify)
  document.addEventListener('click', function(e){
    const del = e.target.closest('a.delete-user');
    if (del) {
      e.preventDefault();
      if (confirm('Delete this user and all their pastes, comments, uploads and albums? This cannot be undone.')) window.location.href = del.href;
      return;
    }
    const ban = e.target.closest('a.ban-user');
    if (ban) {
      e.preventDefault();
      const action = ban.href.includes('unban=') ? 'unban' : 'ban';
      if (confirm('Are you sure you want to ' + action + ' this user?')) window.location.href = ban.href;
      return;
    }
    const verify = e.target.closest('a.verify-user');
    if (verify) {
      e.preventDefault();
      if (confirm('Verify this user?')) window.location.href = verify.href;
      return;
    }
    const exempt = e.target.closest('a.exempt-user');
    if (exempt) {
      e.preventDefault();
      const msg = exempt.href.includes('unexempt=')
        ? 'Remove this user\'s spam filter exemption?'
        : 'Exempt this user from the spam filter? Their pastes, edits, API calls and comments will skip all spam checks.';
      if (confirm(msg)) window.location.href = exempt.href;
      return;
    }
  });
</script>
</body>
</html>
<?php $pdo = null; ?>
