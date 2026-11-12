<?php
/*
 * Paste Admin https://github.com/boxlabss/PASTE
 *
 * Settings for /img uploads, reports from visitors, finding an upload by its
 * link, and a list of every stored file (web and FILEHOST) with delete. Files
 * are stored encrypted with their link as the key, so the list shows no
 * previews or links. Every mutating action requires the session CSRF token.
 * Admins see website uploads with the "files" permission, IRC uploads with
 * "filehost", and IP addresses with "users".
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */
require_once __DIR__ . '/../includes/admin_session.php';

if (!isset($_SESSION['admin_login']) || !isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit();
}

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

require_once('../config.php');
require_once __DIR__ . '/../includes/admin_access.php';
admin_require(['files', 'filehost']);
require_once(__DIR__ . '/../includes/img_store.php');

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

$h       = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$msg     = '';
$rewrite = ((string)($mod_rewrite ?? '0') === '1');
$tableOk = img_ensure_table($pdo);
$csrfOk  = hash_equals($csrf, (string)($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? ''));
$canWeb  = admin_can('files');
$canIrc  = admin_can('filehost');
$showIps = admin_can('users');
$scope   = $canWeb && $canIrc ? null : ($canWeb ? 'web' : 'irc');
$scopeSql = filecrypt_source_sql($scope);
$badToken = '<div class="alert alert-danger">Your session token was missing or expired. Reload the page and try again.</div>';
$noAccess = '<div class="alert alert-danger">Your admin account cannot manage those uploads.</div>';

/* ── Actions ─────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (!$csrfOk) {
        $msg = $badToken;
    } elseif (!$canWeb) {
        $msg = $noAccess;
    } else {
        try {
            img_save_settings($pdo, [
                'img_enabled'       => isset($_POST['img_enabled']) ? 1 : 0,
                'img_guests'        => isset($_POST['img_guests']) ? 1 : 0,
                'img_strip_default'   => isset($_POST['img_strip_default']) ? 1 : 0,
                'img_encrypt_default' => isset($_POST['img_encrypt_default']) ? 1 : 0,
                'img_max_image_mb'    => max(1, min(1024, (int)($_POST['img_max_image_mb'] ?? 10))),
                'img_max_video_mb'    => max(1, min(4096, (int)($_POST['img_max_video_mb'] ?? 25))),
                'img_per_hour'        => max(0, (int)($_POST['img_per_hour'] ?? 20)),
                'img_quota_mb'        => max(0, (int)($_POST['img_quota_mb'] ?? 250)),
                'img_album_guest'     => max(0, min(500, (int)($_POST['img_album_guest'] ?? 5))),
                'img_album_member'    => max(0, min(500, (int)($_POST['img_album_member'] ?? 50))),
            ]);
            $msg = '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Settings saved.</div>';
        } catch (Throwable $e) {
            $msg = '<div class="alert alert-danger">Could not save: ' . $h($e->getMessage()) . '</div>';
        }
    }
}

if (isset($_GET['delete'])) {
    $delNet = false;
    if ($tableOk) {
        $st = $pdo->prepare("SELECT network FROM filehost_files WHERE slug = ? AND paste_id IS NULL LIMIT 1");
        $st->execute([(string)$_GET['delete']]);
        $delNet = $st->fetchColumn();
    }
    if (!$csrfOk) {
        $msg = $badToken;
    } elseif ($delNet !== false && !($delNet === IMG_NETWORK ? $canWeb : $canIrc)) {
        $msg = $noAccess;
    } elseif ($tableOk && img_delete($pdo, (string)$_GET['delete'])) {
        $msg = '<div class="alert alert-success">File deleted.</div>';
    } else {
        $msg = '<div class="alert alert-warning">That file no longer exists.</div>';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_missing'])) {
    if (!$csrfOk) {
        $msg = $badToken;
    } elseif ($tableOk) {
        $p = filecrypt_prune_missing($pdo, filehost_storage_dir(), true, $scope);
        $msg = '<div class="alert alert-success">Removed ' . (int)$p['removed'] . ' entries whose files no longer exist.</div>';
    }
}

/* Find an upload or album from a reported link or key (POST, so the key stays out of access logs) */
$found = null;
$foundAlbum = null;
$findInput = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['find'])) {
    $findInput = trim((string)$_POST['find']);
    $name = filecrypt_name_from_link($findInput);
    if (!$csrfOk) {
        $msg = $badToken;
    } elseif ($tableOk && $name !== null) {
        $st = $pdo->prepare("SELECT * FROM filehost_files WHERE paste_id IS NULL$scopeSql
                             AND ((slug = ? AND encrypted = ?) OR (slug = ? AND encrypted = 1)) LIMIT 1");
        $st->execute([filecrypt_id($name), FILECRYPT_MODE, $name]);
        $found = $st->fetch() ?: null;
        if ($found) {
            $found['_name'] = $name;
            $found['_pass'] = preg_match('/#pass=([^&\s]+)/', $findInput, $pm) ? rawurldecode($pm[1]) : null;
        } elseif ($canWeb) {
            $foundAlbum = img_album_find($pdo, $name);
        }
    }
    if ($found === null && $foundAlbum === null && $msg === '') {
        $msg = '<div class="alert alert-warning">No upload or album matches that link or key. It may have been deleted or expired.</div>';
    }
}

/* Delete an album found by its link (POST, the link is its key) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_album'])) {
    $album = $tableOk ? img_album_find($pdo, (string)$_POST['delete_album']) : null;
    if (!$csrfOk) {
        $msg = $badToken;
    } elseif (!$canWeb) {
        $msg = $noAccess;
    } elseif ($album === null) {
        $msg = '<div class="alert alert-warning">That album no longer exists.</div>';
    } else {
        $withUploads = ($_POST['with_uploads'] ?? '') === '1';
        img_album_delete($pdo, $album, $withUploads);
        $msg = '<div class="alert alert-success">' . ($withUploads ? 'Album and its uploads deleted.' : 'Album deleted.') . '</div>';
    }
}

if (isset($_GET['dismiss'])) {
    if (!$csrfOk) {
        $msg = $badToken;
    } elseif (!$canWeb) {
        $msg = $noAccess;
    } elseif ($tableOk) {
        img_dismiss_report($pdo, (int)$_GET['dismiss']);
        $msg = '<div class="alert alert-success">Report dismissed.</div>';
    }
}

/* ── Data ────────────────────────────────────────────────────────────── */
$settings = img_settings($pdo, true);
$limits   = img_limits($settings);
$dir      = filehost_storage_dir();
$dirReal  = realpath($dir);

$missing    = $tableOk ? filecrypt_missing($pdo, $dir, $scope) : [];
$missingIds = array_flip(array_map('intval', array_column($missing, 'id')));

$warnings = [];
if ($canWeb && $limits['php'] > 0 && $limits['php'] < max((int)$settings['img_max_image_mb'], (int)$settings['img_max_video_mb']) * 1048576) {
    $warnings[] = 'PHP only accepts uploads up to ' . img_format_bytes($limits['php'])
        . ' (upload_max_filesize ' . $h(ini_get('upload_max_filesize')) . ', post_max_size ' . $h(ini_get('post_max_size')) . ' here in admin). '
        . 'img/.user.ini raises this for php-fpm; with mod_php set it in php.ini. The upload page uses the lower value.';
}
if ($dirReal !== false && !is_writable($dirReal)) {
    $warnings[] = 'The storage folder ' . $h($dirReal) . ' is not writable by PHP.';
}
if ($dirReal === false) {
    $parent = dirname($dir);
    while (!is_dir($parent) && dirname($parent) !== $parent) {
        $parent = dirname($parent);
    }
    if (!is_writable($parent)) {
        $warnings[] = 'The storage folder ' . $h($dir) . ' does not exist and PHP cannot create it. '
            . 'Create it and give the web server user write access.';
    }
}

$source = $scope ?? (in_array($_GET['source'] ?? '', ['web', 'irc'], true) ? $_GET['source'] : 'all');
$kind   = in_array($_GET['kind'] ?? '', ['image', 'video', 'browser'], true) ? $_GET['kind'] : 'all';
$who    = trim((string)($_GET['who'] ?? ''));
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 50;

$where = ["paste_id IS NULL"];
$args  = [];
if ($source === 'web') { $where[] = "network = ?"; $args[] = IMG_NETWORK; }
if ($source === 'irc') { $where[] = "network <> ?"; $args[] = IMG_NETWORK; }
if ($kind === 'browser') { $where[] = "encrypted = 1"; }
elseif ($kind !== 'all')   { $where[] = "mime LIKE ?"; $args[] = $kind . '/%'; }
if ($who !== '' && $showIps) { $where[] = "(account = ? OR ip = ?)"; $args[] = $who; $args[] = $who; }
elseif ($who !== '')   { $where[] = "account = ?"; $args[] = $who; }
$whereSql = implode(' AND ', $where);

$files = []; $total = 0; $reports = [];
$stats = ['count' => 0, 'bytes' => 0, 'web' => 0, 'irc' => 0];
if ($tableOk) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM filehost_files WHERE $whereSql");
    $st->execute($args);
    $total = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT * FROM filehost_files WHERE $whereSql ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($args);
    $files = $st->fetchAll();
    $row = $pdo->query("SELECT COUNT(*) AS c, COALESCE(SUM(size + COALESCE(thumb_size, 0)),0) AS b,
                               COALESCE(SUM(network = '" . IMG_NETWORK . "'),0) AS w
                        FROM filehost_files WHERE paste_id IS NULL$scopeSql")->fetch();
    $stats = ['count' => (int)$row['c'], 'bytes' => (int)$row['b'], 'web' => (int)$row['w'],
              'irc' => (int)$row['c'] - (int)$row['w']];
    $reports = $canWeb ? img_reports($pdo) : [];
}
$pages = max(1, (int)ceil($total / $per));

/** Link that opens an upload, when the link name (or browser key) is known; null otherwise. */
$openUrl = function (array $f, ?string $name, ?string $pass) use ($baseurl, $rewrite): ?string {
    $mode = (int)($f['encrypted'] ?? 0);
    $n = $mode === FILECRYPT_MODE ? $name : (string)$f['slug'];
    if ($n === null || $n === '') return null;
    if (($f['network'] ?? IMG_NETWORK) !== IMG_NETWORK) {
        return $rewrite ? $baseurl . 'filehost/' . $n . '.' . $f['ext'] : $baseurl . 'filehost.php?f=' . $n . '.' . $f['ext'];
    }
    $url = img_page_url($n, $baseurl, $rewrite);
    if ($mode === 1) {
        return $pass !== null && $pass !== '' ? $url . '#pass=' . rawurlencode($pass) : null;
    }
    return $url;
};
$modeLabel = static fn(array $f): string => match ((int)($f['encrypted'] ?? 0)) {
    FILECRYPT_MODE => 'Encrypted with its link',
    1              => 'Encrypted in the browser',
    default        => 'Not encrypted (left from development)',
};
$listUrl = function (array $extra = []) use ($source, $kind, $who, $page, $scope): string {
    $q = array_filter(['source' => $scope === null ? $source : 'all', 'kind' => $kind, 'who' => $who, 'page' => $page], fn($v) => $v !== 'all' && $v !== '' && $v !== 1);
    return '?' . http_build_query(array_merge($q, $extra));
};
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Paste - Uploads</title>
<link rel="shortcut icon" href="favicon.ico">
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
  .btn-soft { background:#101521; border:1px solid var(--border); color:#dbe5f5; }
  .btn-soft:hover { background:#0e1422; color:#fff; }
  .offcanvas-nav { width:280px; background:#0f1523; color:#dbe5f5; }
  .offcanvas-nav .list-group-item { background:transparent; border:0; color:#dbe5f5; }
  .pagination .page-link { color:#c6d4f0; background:#101521; border-color:var(--border); }
  .pagination .page-item.active .page-link { background:#0d6efd; border-color:#0d6efd; }
  .thumb { width:56px; height:42px; object-fit:cover; border-radius:6px; background:#0b0d12; }
  .thumb-icon { width:56px; height:42px; display:flex; align-items:center; justify-content:center; border-radius:6px; background:#0b0d12; color:#7f8da3; }
  .stat { font-size:1.4rem; font-weight:600; }
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

<?php
$renderNav = fn() => admin_nav('uploads.php');
?>

<div class="offcanvas offcanvas-start offcanvas-nav" tabindex="-1" id="navOffcanvas">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title">Admin Menu</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body"><div class="list-group"><?php $renderNav(); ?></div></div>
</div>

<div class="container-fluid my-2">
  <div class="row g-2">
    <div class="col-lg-2 d-none d-lg-block">
      <div class="sidebar-desktop"><div class="list-group"><?php $renderNav(); ?></div></div>
    </div>

    <div class="col-lg-10">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h4 class="mb-0"><i class="bi bi-images me-2"></i><?= $scope === 'web' ? 'Website uploads' : ($scope === 'irc' ? 'IRC uploads' : 'Uploads') ?></h4>
        <?php if ($canWeb): ?><a class="btn btn-sm btn-soft" href="<?= $h(img_home_url($baseurl)) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Open /img</a><?php endif; ?>
      </div>
      <?php if ($msg) echo $msg; ?>
      <?php if (!$tableOk): ?>
        <div class="alert alert-danger">The filehost_files table could not be created. Check that the database user can create tables, or run php install/upgrade.php, then reload.</div>
      <?php endif; ?>
      <?php foreach ($warnings as $w): ?>
        <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i><?= $w ?></div>
      <?php endforeach; ?>

      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><div class="card p-3"><div class="text-secondary small">Files</div><div class="stat"><?= (int)$stats['count'] ?></div></div></div>
        <div class="col-6 col-md-3"><div class="card p-3"><div class="text-secondary small">Storage used</div><div class="stat"><?= $h(img_format_bytes($stats['bytes'])) ?></div></div></div>
        <?php if ($canWeb): ?><div class="col-6 col-md-3"><div class="card p-3"><div class="text-secondary small">From the website</div><div class="stat"><?= (int)$stats['web'] ?></div></div></div><?php endif; ?>
        <?php if ($canIrc): ?><div class="col-6 col-md-3"><div class="card p-3"><div class="text-secondary small">From IRC (FILEHOST)</div><div class="stat"><?= (int)$stats['irc'] ?></div></div></div><?php endif; ?>
      </div>

      <?php if ($canWeb): ?>
      <form method="post" class="card p-3 mb-3">
        <input type="hidden" name="save_settings" value="1">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
        <h6 class="mb-3">Settings for /img</h6>
        <div class="row g-3">
          <div class="col-md-4">
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" id="img_enabled" name="img_enabled" <?= (int)$settings['img_enabled'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="img_enabled">Enable uploads</label>
            </div>
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" id="img_guests" name="img_guests" <?= (int)$settings['img_guests'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="img_guests">Allow guests (captcha applies)</label>
            </div>
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" id="img_strip_default" name="img_strip_default" <?= (int)$settings['img_strip_default'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="img_strip_default">"Strip EXIF &amp; metadata" ticked by default</label>
            </div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="img_encrypt_default" name="img_encrypt_default" <?= (int)$settings['img_encrypt_default'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="img_encrypt_default">"Encrypt in my browser" ticked by default</label>
            </div>
          </div>
          <div class="col-md-8">
            <div class="row g-3">
              <div class="col-sm-4">
                <label class="form-label" for="img_max_image_mb">Max image size (MB)</label>
                <input type="number" min="1" class="form-control" id="img_max_image_mb" name="img_max_image_mb" value="<?= (int)$settings['img_max_image_mb'] ?>">
              </div>
              <div class="col-sm-4">
                <label class="form-label" for="img_max_video_mb">Max video size (MB)</label>
                <input type="number" min="1" class="form-control" id="img_max_video_mb" name="img_max_video_mb" value="<?= (int)$settings['img_max_video_mb'] ?>">
              </div>
              <div class="col-sm-4">
                <label class="form-label" for="img_per_hour">Uploads per hour</label>
                <input type="number" min="0" class="form-control" id="img_per_hour" name="img_per_hour" value="<?= (int)$settings['img_per_hour'] ?>">
              </div>
              <div class="col-sm-4">
                <label class="form-label" for="img_quota_mb">Daily allowance (MB)</label>
                <input type="number" min="0" class="form-control" id="img_quota_mb" name="img_quota_mb" value="<?= (int)$settings['img_quota_mb'] ?>">
              </div>
              <div class="col-sm-8 d-flex align-items-end">
                <div class="form-text">Both limits are per account, or per IP for guests. 0 = no limit. Admins and spam-exempt users skip them.</div>
              </div>
              <div class="col-sm-4">
                <label class="form-label" for="img_album_guest">Uploads per album, guests</label>
                <input type="number" min="0" max="500" class="form-control" id="img_album_guest" name="img_album_guest" value="<?= (int)$settings['img_album_guest'] ?>">
              </div>
              <div class="col-sm-4">
                <label class="form-label" for="img_album_member">Uploads per album, members</label>
                <input type="number" min="0" max="500" class="form-control" id="img_album_member" name="img_album_member" value="<?= (int)$settings['img_album_member'] ?>">
              </div>
              <div class="col-sm-4 d-flex align-items-end">
                <div class="form-text">0 turns albums off for that group.</div>
              </div>
            </div>
          </div>
        </div>
        <div class="mt-3"><button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save settings</button></div>
      </form>
      <?php endif; ?>

      <?php if ($missing): ?>
      <form method="post" class="alert alert-secondary d-flex align-items-center justify-content-between gap-3 flex-wrap"
            onsubmit="return confirm('Remove the entries of <?= count($missing) ?> uploads whose files are missing? Their links already do not work.')">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
        <span><i class="bi bi-file-earmark-x me-2"></i><?= count($missing) ?> uploads are listed but their files are no longer in
          <code><?= $h($dir) ?></code>, so they cannot be opened.</span>
        <button class="btn btn-sm btn-outline-light" name="remove_missing" value="1"><i class="bi bi-trash me-1"></i>Remove them</button>
      </form>
      <?php endif; ?>

      <div class="card p-3 mb-3">
        <h6 class="mb-2"><i class="bi bi-search me-2"></i>Find an upload or album by its link</h6>
        <p class="small text-secondary mb-2">Files are stored encrypted and their links are the keys, so this panel cannot show them.
          Paste a reported link or key<?= $scope === null ? ' (from the website or IRC)' : '' ?> to look the file up and delete it.</p>
        <form method="post" class="row g-2">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <div class="col"><input class="form-control form-control-sm font-monospace" name="find" value="<?= $h($findInput) ?>" placeholder="https://.../filehost/AbC...xyz.jpg or the key" autocomplete="off" required></div>
          <div class="col-auto"><button class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Find</button></div>
        </form>
        <?php if ($found): $fo = $openUrl($found, $found['_name'], $found['_pass']);
            $foMeta = (int)$found['encrypted'] === FILECRYPT_MODE ? filecrypt_open_meta($found['meta'] ?? null, $found['_name']) : [];
            $foName = (int)$found['encrypted'] === FILECRYPT_MODE ? ($foMeta['n'] ?? null) : $found['filename'];
            $foTitle = $foMeta['title'] ?? null; ?>
          <div class="border rounded p-3 mt-3" id="foundUpload">
            <div class="row small g-2">
              <div class="col-sm-4"><span class="text-secondary">Source</span><br><?= $found['network'] === IMG_NETWORK ? 'Website' : 'IRC (' . $h($found['network']) . ')' ?></div>
              <div class="col-sm-4"><span class="text-secondary">By</span><br><?= $h($found['account']) ?></div>
              <div class="col-sm-4"><span class="text-secondary">IP</span><br><span class="font-monospace"><?= $h(admin_personal($found['ip'])) ?></span></div>
              <div class="col-sm-4"><span class="text-secondary">Type</span><br><?= $h($found['mime']) ?></div>
              <div class="col-sm-4"><span class="text-secondary">Size</span><br><?= $h(img_format_bytes((int)$found['size'])) ?></div>
              <div class="col-sm-4"><span class="text-secondary">Uploaded</span><br><?= $h($found['created']) ?></div>
              <?php if ($foTitle): ?><div class="col-12"><span class="text-secondary">Title</span><br><?= $h($foTitle) ?></div><?php endif; ?>
              <?php if ($foName): ?><div class="col-12"><span class="text-secondary">File name</span><br><?= $h($foName) ?></div><?php endif; ?>
            </div>
            <div class="d-flex gap-2 mt-3">
              <?php if ($fo): ?><a class="btn btn-sm btn-soft" href="<?= $h($fo) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right me-1"></i>Open</a><?php endif; ?>
              <a class="btn btn-sm btn-danger" href="<?= $h($listUrl(['delete' => $found['slug'], 'csrf_token' => $csrf])) ?>" onclick="return confirm('Delete this upload? This cannot be undone.')"><i class="bi bi-trash me-1"></i>Delete</a>
            </div>
          </div>
        <?php endif; ?>
        <?php if ($foundAlbum): ?>
          <div class="border rounded p-3 mt-3" id="foundAlbum">
            <div class="row small g-2">
              <div class="col-sm-4"><span class="text-secondary">Album</span><br><?= $h(img_album_title($foundAlbum)) ?></div>
              <div class="col-sm-4"><span class="text-secondary">By</span><br><?= $h($foundAlbum['account']) ?></div>
              <div class="col-sm-4"><span class="text-secondary">IP</span><br><span class="font-monospace"><?= $h(admin_personal($foundAlbum['ip'])) ?></span></div>
              <div class="col-sm-4"><span class="text-secondary">Uploads</span><br><?= count($foundAlbum['_meta']['items']) ?></div>
              <div class="col-sm-4"><span class="text-secondary">Made</span><br><?= $h($foundAlbum['created']) ?></div>
              <div class="col-sm-4"><span class="text-secondary">Expires</span><br><?= $h($foundAlbum['expires'] ?? 'Never') ?></div>
            </div>
            <form method="post" class="d-flex gap-2 mt-3 flex-wrap">
              <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
              <input type="hidden" name="delete_album" value="<?= $h($foundAlbum['_name']) ?>">
              <a class="btn btn-sm btn-soft" href="<?= $h(img_page_url($foundAlbum['_name'], $baseurl, $rewrite)) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right me-1"></i>Open</a>
              <button class="btn btn-sm btn-outline-danger" name="with_uploads" value="0" onclick="return confirm('Delete this album? Its uploads stay at their own links.')"><i class="bi bi-trash me-1"></i>Delete album</button>
              <button class="btn btn-sm btn-danger" name="with_uploads" value="1" onclick="return confirm('Delete this album and all its uploads? This cannot be undone.')"><i class="bi bi-trash me-1"></i>Delete album and uploads</button>
            </form>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($reports): ?>
      <div class="card p-3 mb-3">
        <h6 class="mb-3"><i class="bi bi-flag me-2 text-danger"></i>Reports <span class="badge bg-danger"><?= count($reports) ?></span></h6>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Upload</th><th>Reason</th><th>Reported</th><th>Reporter IP</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($reports as $r): $rOpen = $openUrl($r, $r['pass'], $r['pass']); ?>
              <tr>
                <td class="small text-nowrap">
                  <span class="font-monospace"><?= $h(substr((string)$r['slug'], 0, 12)) ?></span>
                  <div><?= $rOpen !== null ? '<span class="badge bg-warning text-dark"><i class="bi bi-key me-1"></i>Link included</span>'
                                           : '<span class="badge bg-secondary"><i class="bi bi-lock me-1"></i>No key</span>' ?></div>
                  <div class="text-secondary">by <?= $h($r['account']) ?></div>
                </td>
                <td class="small" style="white-space:pre-wrap;max-width:420px"><?= $h($r['reason']) ?></td>
                <td class="small text-nowrap"><?= $h($r['created']) ?></td>
                <td class="small"><?php if ($showIps): ?><a class="font-monospace" href="<?= $h($listUrl(['who' => $r['ip'], 'page' => 1])) ?>"><?= $h($r['ip']) ?></a><?php else: ?><span class="text-secondary">hidden</span><?php endif; ?></td>
                <td class="text-end text-nowrap">
                  <?php if ($rOpen !== null): ?><a class="btn btn-sm btn-soft" href="<?= $h($rOpen) ?>" target="_blank" rel="noopener noreferrer" title="Open with the reported link"><i class="bi bi-box-arrow-up-right"></i></a><?php endif; ?>
                  <a class="btn btn-sm btn-soft" href="<?= $h($listUrl(['dismiss' => (int)$r['id'], 'csrf_token' => $csrf])) ?>" title="Dismiss"><i class="bi bi-check2"></i></a>
                  <a class="btn btn-sm btn-danger" href="<?= $h($listUrl(['delete' => $r['slug'], 'csrf_token' => $csrf])) ?>" onclick="return confirm('Delete this upload? This cannot be undone.')" title="Delete upload"><i class="bi bi-trash"></i></a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="form-text mt-2">The server cannot see inside uploads. A report includes the link or key the reporter used, so you can open it.</div>
      </div>
      <?php endif; ?>

      <div class="card p-3">
        <form class="row g-2 align-items-end mb-3" method="get">
          <?php if ($scope === null): ?>
          <div class="col-sm-3">
            <label class="form-label small">Source</label>
            <select class="form-select form-select-sm" name="source" onchange="this.form.submit()">
              <option value="all" <?= $source === 'all' ? 'selected' : '' ?>>All</option>
              <option value="web" <?= $source === 'web' ? 'selected' : '' ?>>Website (/img)</option>
              <option value="irc" <?= $source === 'irc' ? 'selected' : '' ?>>IRC (FILEHOST)</option>
            </select>
          </div>
          <?php endif; ?>
          <div class="col-sm-3">
            <label class="form-label small">Type</label>
            <select class="form-select form-select-sm" name="kind" onchange="this.form.submit()">
              <option value="all" <?= $kind === 'all' ? 'selected' : '' ?>>All</option>
              <option value="image" <?= $kind === 'image' ? 'selected' : '' ?>>Images</option>
              <option value="video" <?= $kind === 'video' ? 'selected' : '' ?>>Videos</option>
              <option value="browser" <?= $kind === 'browser' ? 'selected' : '' ?>>Encrypted in the browser</option>
            </select>
          </div>
          <div class="col-sm-4">
            <label class="form-label small"><?= $showIps ? 'User or IP' : 'User' ?></label>
            <input class="form-control form-control-sm" name="who" value="<?= $h($who) ?>" placeholder="<?= $showIps ? 'username, IRC account or IP' : 'username or IRC account' ?>">
          </div>
          <div class="col-sm-2">
            <button class="btn btn-sm btn-soft w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
          </div>
        </form>

        <div class="table-responsive">
          <table class="table table-sm align-middle mb-2">
            <thead><tr><th></th><th>File</th><th>By</th><th>Source</th><th>Size</th><th>Uploaded</th><th>Expires</th><th>IP</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php if (!$files): ?>
              <tr><td colspan="9" class="text-center text-secondary py-3">No files.</td></tr>
            <?php else: foreach ($files as $f):
              $isPlain = (int)($f['encrypted'] ?? 0) === 0;
              $isVideo = str_starts_with((string)$f['mime'], 'video/');
            ?>
              <tr>
                <td>
                  <span class="thumb-icon" title="<?= $h($modeLabel($f)) ?>"><i class="bi <?= $isPlain ? 'bi-unlock text-warning' : 'bi-lock-fill' ?>"></i></span>
                </td>
                <td class="small">
                  <i class="bi <?= $isVideo ? 'bi-film' : 'bi-image' ?> me-1"></i><?= $h($f['mime']) ?>
                  <div class="text-secondary"><?= $h($modeLabel($f)) ?><?php if (isset($missingIds[(int)$f['id']])): ?> <span class="badge bg-secondary">File missing</span><?php endif; ?></div>
                </td>
                <td class="small"><a href="<?= $h($listUrl(['who' => $f['account'], 'page' => 1])) ?>"><?= $h($f['account']) ?></a></td>
                <td class="small"><?= $f['network'] === IMG_NETWORK ? '<span class="badge bg-primary">Website</span>' : '<span class="badge bg-secondary">' . $h($f['network']) . '</span>' ?></td>
                <td class="small text-nowrap"><?= $h(img_format_bytes((int)$f['size'] + (int)($f['thumb_size'] ?? 0))) ?></td>
                <td class="small text-nowrap"><?= $h($f['created']) ?></td>
                <td class="small text-nowrap"><?= $f['expires'] ? $h($f['expires']) : '<span class="text-secondary">Never</span>' ?></td>
                <td class="small"><?php if ($showIps): ?><a class="font-monospace" href="<?= $h($listUrl(['who' => $f['ip'], 'page' => 1])) ?>"><?= $h($f['ip']) ?></a><?php else: ?><span class="text-secondary">hidden</span><?php endif; ?></td>
                <td class="text-end text-nowrap">
                  <a class="btn btn-sm btn-danger" href="<?= $h($listUrl(['delete' => $f['slug'], 'csrf_token' => $csrf])) ?>" onclick="return confirm('Delete this file? This cannot be undone.')" title="Delete"><i class="bi bi-trash"></i></a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <?php if ($pages > 1): ?>
        <nav><ul class="pagination pagination-sm justify-content-center mb-0">
          <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?>
            <li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="<?= $h($listUrl(['page' => $i])) ?>"><?= $i ?></a></li>
          <?php endfor; ?>
        </ul></nav>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
