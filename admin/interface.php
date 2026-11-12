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

/* Early logout (before any output) */
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit();
}

/* Guard: admin session */
if (!isset($_SESSION['admin_login']) || !isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit();
}

$date = date('Y-m-d H:i:s');
$ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

require_once('../config.php');
require_once __DIR__ . '/../includes/admin_access.php';
admin_require('interface');
require_once __DIR__ . '/../includes/list_languages.php'; // unified language helpers

// The admin pages do not load the detector, which defines this for the site
if (!function_exists('paste_friendly_label')) {
    function paste_friendly_label(string $id): string {
        $t = str_replace(['-', '_'], ' ', strtolower($id));
        $t = ucwords($t);
        $t = preg_replace('/\bSql\b/i','SQL',$t);
        $t = preg_replace('/\bJson\b/i','JSON',$t);
        $t = preg_replace('/\bYaml\b/i','YAML',$t);
        $t = preg_replace('/\bXml\b/i','XML',$t);
        return $t;
    }
}

$engine       = $highlighter ?? 'geshi';
$isHighlight  = ($engine === 'highlight');
$isGeSHi      = ($engine === 'geshi');

try {
    $pdo = new PDO(
        "mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4",
        $dbuser,
        $dbpassword,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    // baseurl for sidebar links
    $row     = $pdo->query("SELECT baseurl FROM site_info WHERE id=1")->fetch();
    $baseurl = rtrim((string)($row['baseurl'] ?? ''), '/');

    // validate admin username
    $st = $pdo->prepare("SELECT id,user FROM admin WHERE id=?");
    $st->execute([$_SESSION['admin_id']]);
    $adm = $st->fetch();
    if (!$adm || $adm['user'] !== ($_SESSION['admin_login'] ?? '')) {
        unset($_SESSION['admin_login'], $_SESSION['admin_id']);
        header("Location: " . $baseurl . '/admin/index.php');
        exit();
    }

    // read current interface settings
    $st = $pdo->prepare("SELECT theme, lang FROM interface WHERE id=1");
    $st->execute();
    $iface   = $st->fetch() ?: ['theme'=>'default','lang'=>'en.php'];
    $d_theme = trim((string)$iface['theme']);
    $d_lang  = trim((string)$iface['lang']);

    $msg = '';
    // save updates
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !admin_csrf_ok()) {
        $msg = '<div class="alert alert-danger">Your form token was missing or expired. Please try again.</div>';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $new_lang  = trim((string)($_POST['lang']  ?? $d_lang));
        $new_theme = trim((string)($_POST['theme'] ?? $d_theme));

        // Only a language file from /langs and a theme folder from /theme
        if (!preg_match('/^[A-Za-z0-9_-]+\.php$/', $new_lang) || $new_lang === 'index.php' || !is_file(__DIR__ . '/../langs/' . $new_lang)
            || !preg_match('/^[A-Za-z0-9_-]+$/', $new_theme) || !is_file(__DIR__ . '/../theme/' . $new_theme . '/index.php')) {
            $msg = '<div class="alert alert-danger">Unknown language or theme.</div>';
        } else {
            $d_lang = $new_lang;
            $d_theme = $new_theme;
            $st = $pdo->prepare("UPDATE interface SET lang=?, theme=? WHERE id=1");
            $st->execute([$d_lang, $d_theme]);
            $msg = '<div class="alert alert-success alert-dismissible fade show" role="alert">
                    Interface settings saved.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>';
        }
    }

    // Build language choices from /langs
    $langs = [];
    $langDir = __DIR__ . '/../langs';
    if (is_dir($langDir)) {
        foreach (scandir($langDir) ?: [] as $f) {
            if ($f === '.' || $f === '..' || $f === 'index.php') continue;
            if (is_file("$langDir/$f")) $langs[] = $f;
        }
        sort($langs, SORT_NATURAL|SORT_FLAG_CASE);
    }

    // Build themes (directories with index.php)
    $themes = [];
    $themeDir = __DIR__ . '/../theme';
    if (is_dir($themeDir)) {
        foreach (scandir($themeDir) ?: [] as $t) {
            if ($t === '.' || $t === '..') continue;
            $path = "$themeDir/$t";
            if (is_dir($path) && file_exists("$path/index.php")) $themes[] = $t;
        }
        sort($themes, SORT_NATURAL|SORT_FLAG_CASE);
    }

    // Check currently enabled theme has css/paste.css
    $themeCssAbs    = __DIR__ . "/../theme/{$d_theme}/css/paste.css";
    $themeCssExists = is_file($themeCssAbs);

    // -------- highlight.php languages (via helper) ----------
    $hl_dir_abs  = highlight_lang_dir();
    $hl_dir_disp = '';
    if ($hl_dir_abs) {
        $projectRoot = realpath(__DIR__ . '/..');
        $hl_dir_disp = ($projectRoot && str_starts_with($hl_dir_abs, $projectRoot))
            ? '..' . substr($hl_dir_abs, strlen($projectRoot))
            : $hl_dir_abs;
    }
    $hl_langs = highlight_supported_languages(); // [['id','name','filename','aliases'=>[]], ...]
    $hl_count = count($hl_langs);

    // -------- GeSHi languages (via helper) ----------
    $geshi_map     = geshi_language_map(); // id => label
    $geshi_langs   = [];
    foreach ($geshi_map as $id => $label) {
        $geshi_langs[] = [
            'id'       => (string)$id,
            'name'     => (string)$label,
            'filename' => $id . '.php', // display hint; we do not load it
        ];
    }
    usort($geshi_langs, static function($a,$b){
        return strnatcasecmp($a['name'], $b['name']);
    });
    $geshi_count   = count($geshi_langs);
    $geshi_dir_abs = realpath(__DIR__ . '/../includes/geshi') ?: (__DIR__ . '/../includes/geshi');
    $projectRoot   = realpath(__DIR__ . '/..');
    $geshi_dir_disp = (is_string($geshi_dir_abs) && $projectRoot && str_starts_with($geshi_dir_abs, $projectRoot))
        ? '..' . substr($geshi_dir_abs, strlen($projectRoot))
        : $geshi_dir_abs;

} catch (PDOException $e) {
    die("Unable to connect to database: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paste - Interface</title>
    <link rel="shortcut icon" href="favicon.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
      :root{ --bg:#0f1115; --card:#141821; --muted:#7f8da3; --border:#1f2633; --accent:#0d6efd; }
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
      .offcanvas-nav{width:280px;background:#0f1523;color:#dbe5f5}
      .offcanvas-nav .list-group-item{background:transparent;border:0;color:#dbe5f5}
      .offcanvas-nav .list-group-item:hover{background:#0e1422}
      .lang-list{max-height:380px;overflow:auto;border:1px solid var(--border);border-radius:10px}
      .sticky-top-sm{position:sticky;top:0;background:var(--card);z-index:1}
      .code{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;}
    </style>
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
    <button class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body">
    <div class="list-group">
<?php admin_nav('interface.php'); ?>
    </div>
  </div>
</div>

<div class="container-fluid my-2">
  <div class="row g-2">
    <!-- Desktop sidebar -->
    <div class="col-lg-2 d-none d-lg-block">
      <div class="sidebar-desktop">
        <div class="list-group">
<?php admin_nav('interface.php'); ?>
        </div>
      </div>
    </div>

    <div class="col-lg-10">
      <!-- any save message -->
      <?php if (!empty($msg)) echo $msg; ?>

      <!-- THEME CSS WARNING -->
      <?php if (!$themeCssExists): ?>
        <div class="alert alert-warning d-flex align-items-center" role="alert">
          <i class="bi bi-exclamation-triangle me-2"></i>
          <div>
            The selected theme <strong><?php echo htmlspecialchars($d_theme, ENT_QUOTES, 'UTF-8'); ?></strong> is missing
            <code>../theme/<?php echo htmlspecialchars($d_theme, ENT_QUOTES, 'UTF-8'); ?>/css/paste.css</code>.
            Please add it or choose a different theme.
          </div>
        </div>
      <?php endif; ?>

      <div class="card mb-2">
        <div class="card-body">
          <h4 class="card-title mb-3">Interface Settings</h4>
          <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" method="post" class="row g-2">
            <?= admin_csrf_field() ?>
            <div class="col-md-6">
              <label for="lang" class="form-label">Language</label>
              <select class="form-select" name="lang" id="lang">
                <?php
                  if (empty($langs)) {
                      echo '<option value="en.php">en</option>';
                  } else {
                      foreach ($langs as $f) {
                          $sel   = ($d_lang === $f) ? 'selected' : '';
                          $label = htmlspecialchars(pathinfo($f, PATHINFO_FILENAME), ENT_QUOTES, 'UTF-8');
                          echo '<option value="'.htmlspecialchars($f, ENT_QUOTES, 'UTF-8').'" '.$sel.'>'.$label.'</option>';
                      }
                  }
                ?>
              </select>
            </div>
            <div class="col-md-6">
              <label for="theme" class="form-label">Theme</label>
              <select class="form-select" name="theme" id="theme">
                <?php
                  if (empty($themes)) {
                      echo '<option value="default">default</option>';
                  } else {
                      foreach ($themes as $t) {
                          $sel = ($d_theme === $t) ? 'selected' : '';
                          echo '<option value="'.htmlspecialchars($t, ENT_QUOTES, 'UTF-8').'" '.$sel.'>'.htmlspecialchars($t, ENT_QUOTES, 'UTF-8').'</option>';
                      }
                  }
                ?>
              </select>
              <div class="form-text">
                Theme stylesheet must exist at <code>../theme/{themename}/css/paste.css</code>.
              </div>
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Highlight.php languages card -->
      <div class="card mb-2">
        <div class="card-body">
          <h4 class="card-title mb-3">
            Code Highlighting (highlight.php)
            <?php if ($isHighlight): ?>
              <span class="badge text-bg-primary ms-2">Active</span>
            <?php else: ?>
              <span class="badge text-bg-secondary ms-2">Available</span>
            <?php endif; ?>
          </h4>

          <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <div>
              <span class="text-muted">Languages folder:</span>
              <code class="code"><?php echo htmlspecialchars($hl_dir_abs ? $hl_dir_disp : 'not found', ENT_QUOTES, 'UTF-8'); ?></code>
            </div>
            <span class="badge text-bg-secondary"><?php echo (int)$hl_count; ?> languages</span>
            <button type="button" class="btn btn-soft btn-sm" onclick="location.reload()">
              <i class="bi bi-arrow-clockwise"></i> Rescan
            </button>
          </div>

          <div class="mb-2">
            <input type="search" id="hl-search" class="form-control" placeholder="Filter highlight.php languages… (e.g. php, c++, json)">
          </div>

          <div class="lang-list">
            <table class="table table-sm align-middle mb-0">
              <thead class="sticky-top-sm">
                <tr>
                  <th style="width: 38%">Name</th>
                  <th style="width: 32%">ID</th>
                  <th>File</th>
                </tr>
              </thead>
              <tbody id="hl-rows">
                <?php foreach ($hl_langs as $L): ?>
                <tr>
                  <td><?php echo htmlspecialchars($L['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                  <td><code class="code"><?php echo htmlspecialchars($L['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?></code></td>
                  <td class="text-muted"><span class="code"><?php echo htmlspecialchars($L['filename'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($hl_langs)): ?>
                <tr><td colspan="3" class="text-warning">No highlight.php languages found.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="form-text mt-2">
            Listed via <code>includes/list_languages.php</code> from <code><?php echo htmlspecialchars($hl_dir_disp ?: 'N/A', ENT_QUOTES, 'UTF-8'); ?></code>.
          </div>
        </div>
      </div>

      <!-- GeSHi languages card -->
      <div class="card">
        <div class="card-body">
          <h4 class="card-title mb-3">
            Code Highlighting (GeSHi)
            <?php if ($isGeSHi): ?>
              <span class="badge text-bg-primary ms-2">Active</span>
            <?php else: ?>
              <span class="badge text-bg-secondary ms-2">Available</span>
            <?php endif; ?>
          </h4>

          <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <div>
              <span class="text-muted">Languages folder (expected):</span>
              <code class="code"><?php echo htmlspecialchars($geshi_dir_disp, ENT_QUOTES, 'UTF-8'); ?></code>
            </div>
            <span class="badge text-bg-secondary"><?php echo (int)$geshi_count; ?> languages</span>
            <button type="button" class="btn btn-soft btn-sm" onclick="location.reload()">
              <i class="bi bi-arrow-clockwise"></i> Rescan
            </button>
          </div>

          <div class="mb-2">
            <input type="search" id="geshi-search" class="form-control" placeholder="Filter GeSHi languages… (e.g. php, c, yaml)">
          </div>

          <div class="lang-list">
            <table class="table table-sm align-middle mb-0">
              <thead class="sticky-top-sm">
                <tr>
                  <th style="width: 38%">Name</th>
                  <th style="width: 32%">ID</th>
                  <th>File</th>
                </tr>
              </thead>
              <tbody id="geshi-rows">
                <?php foreach ($geshi_langs as $G): ?>
                <tr>
                  <td><?php echo htmlspecialchars($G['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                  <td><code class="code"><?php echo htmlspecialchars($G['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?></code></td>
                  <td class="text-muted"><span class="code"><?php echo htmlspecialchars($G['filename'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($geshi_langs)): ?>
                <tr><td colspan="3" class="text-warning">No GeSHi languages listed by helper.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="form-text mt-2">
            Listed via <code>includes/list_languages.php</code> (static map). Files typically live in <code>includes/geshi/</code>.
          </div>
        </div>
      </div>

      <div class="text-muted small mt-3">
        Powered by <a class="text-decoration-none" href="https://phpaste.sourceforge.io" target="_blank">Paste</a>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const hookFilter = (inputId, rowsSelector) => {
    const q = document.getElementById(inputId);
    if (!q) return;
    const rows = document.querySelectorAll(rowsSelector);
    q.addEventListener('input', () => {
      const needle = q.value.trim().toLowerCase();
      rows.forEach(tr => {
        tr.style.display = tr.innerText.toLowerCase().includes(needle) ? '' : 'none';
      });
    });
  };
  hookFilter('hl-search', '#hl-rows tr');
  hookFilter('geshi-search', '#geshi-rows tr');
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
<?php $pdo = null; ?>
