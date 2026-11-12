<?php
/*
 * Paste Admin — IP Bans
 * Supports exact IPs and wildcard patterns: 49.37.117.* / 49.37.* / 49.*
 */
require_once __DIR__ . '/../includes/admin_session.php';

if (!isset($_SESSION['admin_login']) || !isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit();
}

$date = date('Y-m-d H:i:s');
require_once('../config.php');
require_once __DIR__ . '/../includes/admin_access.php';
admin_require('ipbans');

try {
    $pdo = new PDO(
        "mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4",
        $dbuser, $dbpassword,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    $baseurl = rtrim((string)($pdo->query("SELECT baseurl FROM site_info WHERE id=1")->fetch()['baseurl'] ?? ''), '/') . '/';
} catch (Throwable $e) {
    die("DB error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

function validate_ban_entry(string $v): ?string {
    $v = trim($v);
    if ($v === '') return null;
    if (filter_var($v, FILTER_VALIDATE_IP)) return $v;
    // Trailing-wildcard patterns: a.b.c.* | a.b.* | a.*
    if (preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.\*$/', $v, $m) && $m[1]<=255 && $m[2]<=255 && $m[3]<=255) return $v;
    if (preg_match('/^(\d{1,3})\.(\d{1,3})\.\*$/',             $v, $m) && $m[1]<=255 && $m[2]<=255)               return $v;
    if (preg_match('/^(\d{1,3})\.\*$/',                         $v, $m) && $m[1]<=255)                            return $v;
    return null;
}

function is_wildcard(string $ip): bool { return str_contains($ip, '*'); }

/* ── Actions ─────────────────────────────────────────────────────────── */
$msg = '';

// Changes need the form token
if (($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['delete'])) && !admin_csrf_ok()) {
    $msg = '<div class="alert alert-danger">Your form token was missing or expired. Please try again.</div>';
    unset($_GET['delete']);
    $_POST = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ban_ip'])) {
    $ban_ip = validate_ban_entry((string)$_POST['ban_ip']);
    if ($ban_ip === null) {
        $msg = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Invalid entry. Use a valid IP like <code>1.2.3.4</code> or wildcard like <code>49.37.117.*</code></div>';
    } else {
        $exists = $pdo->prepare("SELECT id FROM ban_user WHERE ip = ? LIMIT 1");
        $exists->execute([$ban_ip]);
        if ($row = $exists->fetch()) {
            $pdo->prepare("UPDATE ban_user SET last_date=? WHERE id=?")->execute([$date, (int)$row['id']]);
            $msg = '<div class="alert alert-warning">'.$h($ban_ip).' is already banned — date updated.</div>';
        } else {
            $pdo->prepare("INSERT INTO ban_user (last_date, ip) VALUES (?, ?)")->execute([$date, $ban_ip]);
            $type = is_wildcard($ban_ip) ? 'Wildcard pattern' : 'IP';
            $msg = '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>'.$type.' <strong>'.$h($ban_ip).'</strong> added to the banlist.</div>';
        }
    }
}

if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM ban_user WHERE id = ?")->execute([(int)$_GET['delete']]);
    $msg = '<div class="alert alert-success">Entry removed.</div>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['selected_ids']) && isset($_POST['bulk_delete'])) {
    $ids = array_map('intval', (array)$_POST['selected_ids']);
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM ban_user WHERE id IN ($ph)")->execute($ids);
        $msg = '<div class="alert alert-success">'.count($ids).' entr'.(count($ids)===1?'y':'ies').' removed.</div>';
    }
}

/* ── List query ──────────────────────────────────────────────────────── */
$per_page = 30;
$page     = max(1, (int)($_GET['page'] ?? 1));
$q        = trim((string)($_GET['q'] ?? ''));
$filter   = in_array($_GET['filter'] ?? '', ['exact','wildcard']) ? $_GET['filter'] : 'all';

$whereParts = [];
$params     = [];
if ($q !== '') { $whereParts[] = "ip LIKE ?"; $params[] = "%$q%"; }
if ($filter === 'wildcard') $whereParts[] = "ip LIKE '%*%'";
if ($filter === 'exact')    $whereParts[] = "ip NOT LIKE '%*%'";
$where = $whereParts ? 'WHERE '.implode(' AND ', $whereParts) : '';

$cst = $pdo->prepare("SELECT COUNT(*) FROM ban_user $where");
$cst->execute($params);
$total       = (int)$cst->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));
$offset      = ($page - 1) * $per_page;

$st = $pdo->prepare("SELECT id, last_date, ip FROM ban_user $where ORDER BY id DESC LIMIT $per_page OFFSET $offset");
$st->execute($params);
$ips = $st->fetchAll();

$all_count = (int)$pdo->query("SELECT COUNT(*) FROM ban_user")->fetchColumn();
$wc_count  = (int)$pdo->query("SELECT COUNT(*) FROM ban_user WHERE ip LIKE '%*%'")->fetchColumn();
$ex_count  = $all_count - $wc_count;
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>IP Bans — Paste Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  :root { --bg:#0f1115; --card:#141821; --border:#1f2633; --accent:#0d6efd; }
  body { background:var(--bg); color:#fff; }
  .navbar { background:#121826!important; position:sticky; top:0; z-index:1030; }
  .navbar .navbar-brand { font-weight:600; }
  .sidebar-desktop { position:sticky; top:1rem; background:#121826; border:1px solid var(--border); border-radius:12px; padding:12px; }
  .sidebar-desktop .list-group-item { background:transparent; color:#dbe5f5; border:0; border-radius:10px; padding:.65rem .8rem; }
  .sidebar-desktop .list-group-item:hover { background:#0e1422; }
  .sidebar-desktop .list-group-item.active { background:#0d6efd; color:#fff; }
  .card { background:var(--card); border:1px solid var(--border); border-radius:12px; }
  .form-control, .form-select { background:#0e1422; border-color:var(--border); color:#e6edf3; }
  .form-control:focus, .form-select:focus { border-color:var(--accent); box-shadow:0 0 0 .25rem rgba(13,110,253,.25); }
  .table { color:#e6edf3; }
  .table thead th { background:#101521; color:#c6d4f0; border-color:var(--border); }
  .table td, .table th { border-color:var(--border); vertical-align:middle; }
  .pagination .page-link { color:#c6d4f0; background:#101521; border-color:var(--border); }
  .pagination .page-item.active .page-link { background:#0d6efd; border-color:#0d6efd; }
  .btn-soft { background:#101521; border:1px solid var(--border); color:#dbe5f5; }
  .btn-soft:hover { background:#0e1422; color:#fff; }
  .offcanvas-nav { width:280px; background:#0f1523; color:#dbe5f5; }
  .offcanvas-nav .list-group-item { background:transparent; border:0; color:#dbe5f5; }
  .offcanvas-nav .list-group-item:hover { background:#0e1422; }
  .stat-pill { background:#101521; border:1px solid var(--border); border-radius:10px; padding:.6rem 1rem; text-align:center; }
  .ip-mono { font-family:ui-monospace, monospace; font-size:.875rem; }
  .filter-btn { border-radius:999px; padding:.3rem .9rem; font-size:.85rem; }
  .wildcard-row { background:rgba(255,193,7,.04)!important; }
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container-fluid">
    <div class="d-flex align-items-center gap-2">
      <button class="btn btn-soft d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#navOffcanvas"><i class="bi bi-list"></i></button>
      <a class="navbar-brand" href="../">Paste</a>
    </div>
    <div class="collapse navbar-collapse justify-content-end">
      <ul class="navbar-nav">
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown"><?= $h($_SESSION['admin_login']) ?></a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="admin.php">Settings</a></li>
            <li><a class="dropdown-item" href="?logout">Logout</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>

<div class="offcanvas offcanvas-start offcanvas-nav" tabindex="-1" id="navOffcanvas">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title">Admin Menu</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body">
    <div class="list-group">
<?php admin_nav('ipbans.php'); ?>
    </div>
  </div>
</div>

<div class="container-fluid my-2">
  <div class="row g-2">

    <!-- Sidebar -->
    <div class="col-lg-2 d-none d-lg-block">
      <div class="sidebar-desktop">
        <div class="list-group">
<?php admin_nav('ipbans.php'); ?>
        </div>
      </div>
    </div>

    <!-- Main content -->
    <div class="col-lg-10">
      <?php if ($msg) echo $msg; ?>

      <!-- Stats -->
      <div class="row g-2 mb-3">
        <div class="col-4"><div class="stat-pill"><div class="text-muted small">Total</div><div class="fw-bold fs-4"><?= number_format($all_count) ?></div></div></div>
        <div class="col-4"><div class="stat-pill"><div class="text-muted small">Exact IPs</div><div class="fw-bold fs-4"><?= number_format($ex_count) ?></div></div></div>
        <div class="col-4"><div class="stat-pill"><div class="text-muted small">Wildcards</div><div class="fw-bold fs-4 text-warning"><?= number_format($wc_count) ?></div></div></div>
      </div>

      <!-- Add form -->
      <div class="card mb-3">
        <div class="card-body">
          <h5 class="card-title mb-1"><i class="bi bi-shield-plus me-2"></i>Add Ban</h5>
          <p class="text-muted small mb-3">
            Exact IP: <code>192.168.1.1</code> &nbsp;|&nbsp;
            Wildcard (class&nbsp;C): <code>49.37.117.*</code> &nbsp;|&nbsp;
            Wildcard (class&nbsp;B): <code>49.37.*</code> &nbsp;|&nbsp;
            Wildcard (class&nbsp;A): <code>49.*</code>
          </p>
          <form method="POST" class="row g-2 align-items-end">
            <?= admin_csrf_field() ?>
            <div class="col-sm-8 col-lg-9">
              <input type="text" class="form-control ip-mono" name="ban_ip"
                     placeholder="e.g. 203.0.113.45  or  49.37.117.*"
                     autocomplete="off" spellcheck="false">
            </div>
            <div class="col-sm-4 col-lg-3 d-grid">
              <button class="btn btn-danger" type="submit"><i class="bi bi-ban me-1"></i>Ban</button>
            </div>
          </form>
        </div>
      </div>

      <!-- List -->
      <div class="card mb-3">
        <div class="card-body">

          <!-- Controls row -->
          <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <!-- Filter chips -->
            <div class="d-flex gap-1">
              <?php
              foreach (['all' => "All ($all_count)", 'exact' => "Exact ($ex_count)", 'wildcard' => "Wildcard ($wc_count)"] as $val => $label):
                $act = ($filter === $val) ? 'btn-primary' : 'btn-soft';
                $href = '?filter='.$val.($q!==''?'&q='.$h($q):'');
              ?>
              <a href="<?= $h($href) ?>" class="btn <?= $act ?> filter-btn"><?= $label ?></a>
              <?php endforeach; ?>
            </div>

            <!-- Search -->
            <form method="GET" class="ms-auto d-flex gap-1">
              <input type="hidden" name="filter" value="<?= $h($filter) ?>">
              <input type="text" class="form-control form-control-sm ip-mono" name="q"
                     placeholder="Search…" value="<?= $h($q) ?>" style="width:180px">
              <button class="btn btn-soft btn-sm" type="submit"><i class="bi bi-search"></i></button>
              <?php if ($q !== ''): ?>
              <a href="?filter=<?= $h($filter) ?>" class="btn btn-soft btn-sm" title="Clear"><i class="bi bi-x-lg"></i></a>
              <?php endif; ?>
            </form>
          </div>

          <form method="POST" id="bulk-form">
            <?= admin_csrf_field() ?>
            <!-- Bulk action bar -->
            <div class="d-flex align-items-center gap-2 mb-2">
              <button type="submit" name="bulk_delete" value="1" class="btn btn-danger btn-sm" id="bulk-btn" disabled>
                <i class="bi bi-trash me-1"></i>Delete selected
              </button>
              <span class="text-muted small" id="sel-count"></span>
            </div>

            <div class="table-responsive">
              <table class="table table-hover table-bordered mb-0">
                <thead>
                  <tr>
                    <th style="width:40px"><input type="checkbox" id="select-all" title="Select all"></th>
                    <th style="width:170px">Date Added</th>
                    <th>IP / Pattern</th>
                    <th style="width:120px">Type</th>
                    <th style="width:80px">Remove</th>
                  </tr>
                </thead>
                <tbody>
                <?php if ($ips): ?>
                  <?php foreach ($ips as $r):
                    $wc = is_wildcard($r['ip']);
                    $rowClass = $wc ? ' class="wildcard-row"' : '';
                  ?>
                  <tr<?= $rowClass ?>>
                    <td><input type="checkbox" class="row-select" name="selected_ids[]" value="<?= (int)$r['id'] ?>"></td>
                    <td class="text-muted small"><?= $h($r['last_date']) ?></td>
                    <td>
                      <code class="ip-mono text-light"><?= $h($r['ip']) ?></code>
                      <?php if ($wc):
                        $parts = substr_count(rtrim($r['ip'],'.*'), '.') + 1;
                        $range = match($parts) { 3 => '/24', 2 => '/16', 1 => '/8', default => '' };
                      ?>
                        <span class="badge bg-warning text-dark ms-2" title="Matches all IPs in this range">
                          <i class="bi bi-asterisk me-1"></i><?= $range ?> range
                        </span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($wc): ?>
                        <span class="badge bg-warning text-dark">Wildcard</span>
                      <?php else: ?>
                        <span class="badge bg-secondary">Exact</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <a href="?delete=<?= (int)$r['id'] ?>&amp;page=<?= (int)$page ?>&amp;filter=<?= $h(urlencode($filter)) ?><?= $q!==''?'&amp;q='.$h(urlencode($q)):'' ?>&amp;csrf_token=<?= $h(urlencode(admin_csrf_token())) ?>"
                         class="btn btn-danger btn-sm del-btn" title="Remove ban">
                        <i class="bi bi-trash"></i>
                      </a>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr><td colspan="5" class="text-center py-4 text-muted">
                    <?= $q !== '' ? 'No results for "' . $h($q) . '"' : 'The banlist is empty.' ?>
                  </td></tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          </form>

          <?php if ($total_pages > 1): ?>
          <nav class="mt-3">
            <ul class="pagination justify-content-center mb-0">
              <?php
              $pb = '?filter='.$h($filter).($q!==''?'&q='.urlencode($q):'');
              echo $page > 1
                ? '<li class="page-item"><a class="page-link" href="'.$pb.'&page='.($page-1).'">&laquo;</a></li>'
                : '<li class="page-item disabled"><span class="page-link">&laquo;</span></li>';
              for ($i = max(1,$page-3); $i <= min($total_pages,$page+3); $i++)
                echo '<li class="page-item'.($i===$page?' active':'').'"><a class="page-link" href="'.$pb.'&page='.$i.'">'.$i.'</a></li>';
              echo $page < $total_pages
                ? '<li class="page-item"><a class="page-link" href="'.$pb.'&page='.($page+1).'">&raquo;</a></li>'
                : '<li class="page-item disabled"><span class="page-link">&raquo;</span></li>';
              ?>
            </ul>
          </nav>
          <?php endif; ?>

        </div>
      </div><!-- /card -->
    </div><!-- /col -->
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.del-btn').forEach(a => {
    a.addEventListener('click', e => {
      e.preventDefault();
      if (confirm('Remove this entry from the banlist?')) location.href = a.href;
    });
  });

  const all = document.getElementById('select-all');
  const btn = document.getElementById('bulk-btn');
  const cnt = document.getElementById('sel-count');

  function sync() {
    const n = [...document.querySelectorAll('.row-select:checked')].length;
    btn.disabled = n === 0;
    cnt.textContent = n > 0 ? n + ' selected' : '';
  }
  all?.addEventListener('change', () => {
    document.querySelectorAll('.row-select').forEach(c => c.checked = all.checked);
    sync();
  });
  document.querySelectorAll('.row-select').forEach(c => c.addEventListener('change', sync));

  document.getElementById('bulk-form')?.addEventListener('submit', e => {
    const n = [...document.querySelectorAll('.row-select:checked')].length;
    if (n === 0) { e.preventDefault(); return; }
    if (!confirm('Remove ' + n + ' entr' + (n===1?'y':'ies') + ' from the banlist?')) e.preventDefault();
  });
});
</script>
<?php
if (isset($_GET['logout'])) { $_SESSION=[]; session_destroy(); header('Location: index.php'); exit(); }
$pdo = null;
?>
</body>
</html>
