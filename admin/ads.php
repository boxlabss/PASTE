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
    header("Location: ../index.php");
    exit();
}

$date = date('Y-m-d H:i:s');
$ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

require_once('../config.php'); // expects $dbhost,$dbuser,$dbpassword,$dbname
require_once __DIR__ . '/../includes/admin_access.php';
admin_require('ads');
require_once('../includes/functions.php');

$msg = '';
$msg_type = 'info';

try {
    // PDO
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

    // Fetch baseurl for sidebar links
    $row = $pdo->query("SELECT baseurl FROM site_info WHERE id = 1")->fetch();
    $baseurl = rtrim((string)($row['baseurl'] ?? ''), '/');

    // Validate admin id ↔ username
    $st = $pdo->prepare("SELECT id, user FROM admin WHERE id = ?");
    $st->execute([$_SESSION['admin_id']]);
    $adm = $st->fetch();
    if (!$adm || $adm['user'] !== $_SESSION['admin_login']) {
        unset($_SESSION['admin_login'], $_SESSION['admin_id']);
        header("Location: " . htmlspecialchars($baseurl . '/admin/index.php', ENT_QUOTES, 'UTF-8'));
        exit();
    }

    // Fetch current ad settings (ensure row exists)
    $st = $pdo->query("SELECT text_ads, ads_1, ads_2 FROM ads WHERE id = 1");
    $adsRow = $st->fetch();
    if (!$adsRow) {
        $pdo->prepare("INSERT INTO ads (id, text_ads, ads_1, ads_2) VALUES (1, '', '', '')")->execute();
        $adsRow = ['text_ads' => '', 'ads_1' => '', 'ads_2' => ''];
    }

    $text_ads = (string)$adsRow['text_ads'];
    $ads_1    = (string)$adsRow['ads_1'];
    $ads_2    = (string)$adsRow['ads_2'];

    // Save
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !admin_csrf_ok()) {
        $msg = 'Your form token was missing or expired. Please try again.';
        $msg_type = 'danger';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Text Ads from WYSIWYG (hidden field)
        $text_ads = isset($_POST['text_ads_html']) ? (string)$_POST['text_ads_html'] : '';

        // Raw HTML/JS for ad slots (from CodeMirror’ed textareas)
        $ads_1 = isset($_POST['ads_1']) ? (string)$_POST['ads_1'] : '';
        $ads_2 = isset($_POST['ads_2']) ? (string)$_POST['ads_2'] : '';

        $st = $pdo->prepare("UPDATE ads SET text_ads = ?, ads_1 = ?, ads_2 = ? WHERE id = 1");
        $st->execute([$text_ads, $ads_1, $ads_2]);

        $msg = 'Ads saved successfully.';
        $msg_type = 'success';
    }

} catch (PDOException $e) {
    die("Unable to connect to database: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title>Paste - Ads</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

<!-- Quill 2 (BSD-3) -->
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js" integrity="sha384-hcxmSutM10NL6iGBAA0LStIhy+kWJxfrhqWVMRuABZH5Vqztexq2nBz/Xnfllly9" crossorigin="anonymous"></script>

<!-- CodeMirror 5 -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/lib/codemirror.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/theme/material-darker.css">
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/lib/codemirror.js" integrity="sha384-/WlgLpnUCi+5eS6SnLPKTwRY0l8X4TxBIroMKS0KpFjlFoST+ZbSMKqTpgS24m8i" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/xml/xml.js" integrity="sha384-KX/+LdYWr3JcKfT7HK55DC3oPVJwnJSympb1qoO14sxVDtDIg+xHPVLltqJEbitI" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/javascript/javascript.js" integrity="sha384-UEBcG5Bts/NmkO8UcF+Jq5qLb7Z1SVsz66bvds73oF8/I6p33YGu3l9bgAs27nNj" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/css/css.js" integrity="sha384-ZD4C1ohrucZOfP7+jQSuBELICO7Z73CFD5stbjic1D3DbZk88mqj3KsRjSml/NCK" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/htmlmixed/htmlmixed.js" integrity="sha384-0MH/N0DfWPIbCXDe9I7tmLw0dsJ4gKQUijwCcpaTgGxrTTrypsJwEOOqi5yhkQiK" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/edit/closetag.js" integrity="sha384-i4ai3UXE5wIk3ILN77PB9DhNmku+sefNKDTHXRvsrYX2bxWzm+EDmoBui5wsNU2v" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/edit/closebrackets.js" integrity="sha384-pF/JiVjqZ1pMXmw6/3YCvu7PgYNcVJTxusZ7AuvTvSFR9iw74b85dWF5WYXeQ5t5" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/edit/matchbrackets.js" integrity="sha384-VTfea43jk3cts7Fd5DbskOZKCo1P+X4qCWSv0+oIhMKaSymJL4+KZ9eC6VzkZbab" crossorigin="anonymous"></script>

<style>
  :root{
    --bg: #0f1115;
    --card:#141821;
    --muted:#7f8da3;
    --border:#1f2633;
    --accent:#0d6efd;
    --content:#0f1115;
    --content-text:#e6edf3;
    --toolbar:#101521;
  }
  body{background:var(--bg);color:#fff;}
  .navbar{background:#121826!important;position:sticky;top:0;z-index:1030}
  .navbar .navbar-brand{font-weight:600}

  /* Desktop sidebar */
  .sidebar-desktop{
    position:sticky; top:1rem;
    background:#121826;border:1px solid var(--border);
    border-radius:12px;padding:12px;
  }
  .sidebar-desktop .list-group-item{
    background:transparent;color:#dbe5f5;border:0;border-radius:10px;padding:.65rem .8rem;
  }
  .sidebar-desktop .list-group-item:hover{background:#0e1422}
  .sidebar-desktop .list-group-item.active{background:#0d6efd;color:#fff}

  .card{background:var(--card);border:1px solid var(--border);border-radius:12px}
  .form-control,.form-select{background:#0e1422;border-color:var(--border);color:#e6edf3}
  .form-control:focus,.form-select:focus{border-color:var(--accent);box-shadow:0 0 0 .25rem rgba(13,110,253,.25)}
  .btn-soft{background:#101521;border:1px solid var(--border);color:#dbe5f5}
  .btn-soft:hover{background:#0e1422;color:#fff}

  .footer { margin-top:24px; padding:12px; color:#9fb1d1 }

  /* Quill */
  .ql-container.ql-snow{
    border:1px solid var(--border);
    border-radius:8px;
    font-size:16px;
    background:var(--content);
    color:var(--content-text);
    min-height:360px;
  }
  .ql-editor.ql-blank::before {
    color: var(--muted) !important;
  }
  .ql-editor {
    min-height:360px;
    color:var(--content-text);
  }
  .ql-toolbar.ql-snow{
    background:var(--toolbar);
    border:1px solid var(--border);
    border-radius:8px;
  }
  .ql-snow .ql-picker, .ql-snow .ql-stroke{ color:#dbe5f5; stroke:#dbe5f5; }
  .ql-snow .ql-fill{ fill:#dbe5f5; }
  .ql-snow .ql-picker-options{ background:#0e1422; border-color:var(--border); }
  .ql-snow .ql-tooltip{ background:#0e1422; border:1px solid var(--border); color:#e6edf3; }
  .ql-snow .ql-tooltip input[type=text]{ background:#0c1220; color:#e6edf3; border-color:#23304a; }
  .ql-snow .ql-picker-label:hover, .ql-snow .ql-picker-item:hover{ color:#fff; }
  .ql-snow .ql-toolbar button:hover .ql-stroke,
  .ql-snow .ql-toolbar button:hover .ql-fill { color:#fff; stroke:#fff; fill:#fff; }
  .editor-footer {
    display:flex;justify-content:space-between;gap:12px;align-items:center;margin-top:8px;
    color:#9fb1d1;font-size:12px;
  }
  .editor-footer .status-dot{width:8px;height:8px;background:#2bd576;border-radius:50%;display:inline-block;margin-right:6px}
  .editor-footer .stats{opacity:.9}

  /* CodeMirror */
  .CodeMirror {
    height: 260px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: #0e1422;
    color: #e6edf3;
    font-size: 14px;
  }
  .cm-s-material-darker .CodeMirror-gutters {
    background: #0b101a;
    border-right: 1px solid #1c2535;
  }

  .offcanvas-nav{width:280px;background:#0f1523;color:#dbe5f5}
  .offcanvas-nav .list-group-item{background:transparent;border:0;color:#dbe5f5}
  .offcanvas-nav .list-group-item:hover{background:#0e1422}
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
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body">
    <div class="list-group">
<?php admin_nav('ads.php'); ?>
    </div>
  </div>
</div>

<div class="container-fluid my-2">
  <div class="row g-2">
    <!-- Desktop sidebar -->
    <div class="col-lg-2 d-none d-lg-block">
      <div class="sidebar-desktop">
        <div class="list-group">
<?php admin_nav('ads.php'); ?>
        </div>
      </div>
    </div>

    <div class="col-lg-10">
      <?php if (!empty($msg)): ?>
        <div class="alert alert-<?php echo htmlspecialchars($msg_type); ?> alert-dismissible fade show" role="alert">
          <?php echo htmlspecialchars($msg); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <div class="card mb-3">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <h4 class="card-title mb-0">Manage Ads</h4>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-soft" id="preview-textads"><i class="bi bi-eye"></i> Preview Text Ads</button>
              <button type="button" class="btn btn-soft" id="preview-ads1"><i class="bi bi-eye"></i> Preview Sidebar Ad</button>
              <button type="button" class="btn btn-soft" id="preview-ads2"><i class="bi bi-eye"></i> Preview Footer Ad</button>
            </div>
          </div>

          <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="ads-form">
            <?= admin_csrf_field() ?>
            <!-- Text Ads (WYSIWYG) -->
            <div class="mb-3">
              <label class="form-label">Text Ads (WYSIWYG)</label>
              <div id="toolbar">
                <span class="ql-formats">
                  <select class="ql-header">
                    <option selected></option>
                    <option value="2">Heading</option>
                    <option value="3">Subheading</option>
                  </select>
                  <select class="ql-font"></select>
                  <select class="ql-size"></select>
                </span>
                <span class="ql-formats">
                  <button class="ql-bold"></button>
                  <button class="ql-italic"></button>
                  <button class="ql-underline"></button>
                  <button class="ql-strike"></button>
                </span>
                <span class="ql-formats">
                  <button class="ql-blockquote"></button>
                  <button class="ql-code-block"></button>
                </span>
                <span class="ql-formats">
                  <button class="ql-list" value="ordered"></button>
                  <button class="ql-list" value="bullet"></button>
                  <button class="ql-indent" value="-1"></button>
                  <button class="ql-indent" value="1"></button>
                </span>
                <span class="ql-formats">
                  <select class="ql-align"></select>
                </span>
                <span class="ql-formats">
                  <button class="ql-link"></button>
                  <button class="ql-image"></button>
                </span>
              </div>
              <div id="textAdsEditor"><?php echo $text_ads; ?></div>
              <div class="text-muted small mt-1">For third-party ad tags that use <code>&lt;script&gt;</code>, use the raw fields below.</div>
              <textarea class="d-none" name="text_ads_html" id="text_ads_html"></textarea>
            </div>

            <!-- Raw ad slots (CodeMirror) -->
            <div class="mb-3">
              <label for="ads_1" class="form-label">Image/HTML Ad (Sidebar)</label>
              <textarea class="form-control d-none" id="ads_1" name="ads_1"><?php echo htmlspecialchars($ads_1); ?></textarea>
              <div id="ads_1_cm"></div>
              <div class="text-muted small mt-1">Appears in the sidebar (e.g., 300×250 / 300×600). Scripts allowed.</div>
            </div>

            <div class="mb-3">
              <label for="ads_2" class="form-label">Image/HTML Ad (Footer)</label>
              <textarea class="form-control d-none" id="ads_2" name="ads_2"><?php echo htmlspecialchars($ads_2); ?></textarea>
              <div id="ads_2_cm"></div>
              <div class="text-muted small mt-1">Appears in the footer. Scripts allowed.</div>
            </div>

            <div class="d-flex gap-2">
              <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save</button>
              <a href="../" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-up-right"></i> View Site</a>
            </div>
          </form>
        </div>
      </div>

      <div class="text-muted small mt-3">
        Powered by <a class="text-decoration-none" href="https://phpaste.sourceforge.io" target="_blank">Paste</a>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script>
(function(){
  // --- Quill (Text Ads)
  const q = new Quill('#textAdsEditor', {
    theme: 'snow',
    modules: {
      toolbar: '#toolbar',
      history: { delay: 800, maxStack: 200 },
      clipboard: true
    },
    placeholder: 'Write your text ad content here...'
  });

  // --- CodeMirror (raw ad slots)
  function cmFromTextArea(textareaId, mountId){
    const ta = document.getElementById(textareaId);
    const mount = document.getElementById(mountId);
    const cm = CodeMirror(mount, {
      value: ta.value,
      mode: 'htmlmixed',
      theme: 'material-darker',
      lineNumbers: true,
      lineWrapping: true,
      tabSize: 2,
      autoCloseTags: true,
      autoCloseBrackets: true,
      matchBrackets: true,
    });
    // Keep textarea in sync so PHP receives latest content
    cm.on('change', () => { ta.value = cm.getValue(); });
    // Initial sync in case the value is unchanged
    ta.value = cm.getValue();
    return cm;
  }

  const cmAds1 = cmFromTextArea('ads_1', 'ads_1_cm');
  const cmAds2 = cmFromTextArea('ads_2', 'ads_2_cm');

  // Submit: ensure Quill HTML is posted
  document.getElementById('ads-form').addEventListener('submit', function(){
    document.getElementById('text_ads_html').value = q.root.innerHTML;
    // CodeMirror textareas are already synced by 'change' handler
  });

  // Simple preview windows
  function previewHtml(html){
    const w = window.open('', '_blank', 'width=900,height=700');
    if (!w) return;
    const doc =
      '<!doctype html><html><head><meta charset="utf-8"><title>Preview</title>' +
      '<meta name="viewport" content="width=device-width,initial-scale=1">' +
      '<style>body{background:#0f1115;color:#e6edf3;font:15px/1.6 system-ui,Segoe UI,Roboto,sans-serif;padding:24px}</style>' +
      '</head><body>' + html + '</body></html>';
    w.document.open(); w.document.write(doc); w.document.close();
  }

  document.getElementById('preview-textads')?.addEventListener('click', () => {
    previewHtml(q.root.innerHTML);
  });
  document.getElementById('preview-ads1')?.addEventListener('click', () => {
    previewHtml(cmAds1.getValue());
  });
  document.getElementById('preview-ads2')?.addEventListener('click', () => {
    previewHtml(cmAds2.getValue());
  });
})();
</script>
</body>
</html>
<?php
// Optional: logout
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit();
}
$pdo = null;
?>
