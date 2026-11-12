<?php
/*
 * Paste Admin: Spam Filter
 *
 * Tabs:
 *   Settings  thresholds, heuristic weights, learned filter, link and IP blocklists, form traps
 *   Rules     keyword / regex / domain rules with weights
 *   Exempt    registered users who bypass the filter
 *   Queue     held pastes: approve (publish, learn as normal) or reject (discard, learn as spam)
 *   Learn     teach the learned filter examples or this site's pastes
 *   Log       recent block/hold (and optionally allow) events
 *   Test      paste some text and see how it would score
 *
 * Every mutating action requires the session CSRF token.
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
admin_require('spamfilter');
require_once(__DIR__ . '/../includes/spam.php');

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

$h   = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$msg = '';
$tab = (string)($_GET['tab'] ?? 'settings');
$allowedTabs = ['settings','rules','exempt','queue','learn','log','test'];
if (!in_array($tab, $allowedTabs, true)) $tab = 'settings';

/* Are the tables present? If not, point the admin at the installer. */
$installed = true;
try { $pdo->query("SELECT 1 FROM spam_settings LIMIT 1"); }
catch (Throwable $e) { $installed = false; }

$exemptReady = $installed && spam_ensure_exempt_column($pdo);
$learnReady  = $installed && spam_learn_ready($pdo);

/* Slug helpers for approving held pastes. */
function spamadmin_url_mode(PDO $pdo): string {
    try { $v = $pdo->query("SELECT option_value FROM paste_options WHERE option_name='url_mode'")->fetchColumn(); return (string)($v ?: 'slug'); }
    catch (Throwable $e) { return 'slug'; }
}
function spamadmin_slug_len(PDO $pdo): int {
    try { $v = (int)$pdo->query("SELECT option_value FROM paste_options WHERE option_name='slug_length'")->fetchColumn(); }
    catch (Throwable $e) { $v = 8; }
    return max(4, min(32, $v ?: 8));
}
function spamadmin_slug_taken(PDO $pdo, string $slug): bool {
    $q = $pdo->prepare("SELECT 1 FROM pastes WHERE slug = ? LIMIT 1");
    $q->execute([$slug]);
    return (bool)$q->fetchColumn();
}
function spamadmin_make_slug(PDO $pdo, int $len): ?string {
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    for ($try = 0; $try < 12; $try++) {
        $s = '';
        for ($i = 0; $i < $len; $i++) $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        if (!spamadmin_slug_taken($pdo, $s)) return $s;
    }
    return null;
}
function spamadmin_set_exempt(PDO $pdo, string $username, bool $on): bool {
    $st = $pdo->prepare("UPDATE users SET spam_exempt = ? WHERE username = ?");
    $st->execute([$on ? 1 : 0, $username]);
    return $st->rowCount() > 0;
}

/* Builds a CSRF-protected action link for this page. */
$actionUrl = function(array $params) use ($csrf, $h): string {
    return $h('?' . http_build_query($params + ['csrf_token' => $csrf]));
};

$isMutation = $_SERVER['REQUEST_METHOD'] === 'POST'
    || array_intersect(array_keys($_GET), ['toggle_rule','del_rule','approve','reject','clear_log','unexempt']);
$csrfOk = hash_equals($csrf, (string)($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? ''));

if ($installed && $isMutation && !$csrfOk) {
    $msg = '<div class="alert alert-danger">Your session token was missing or expired. Reload the page and try again.</div>';
    $canAct = false;
} else {
    $canAct = $installed;
}

/** Learn a held paste's payload as 'spam' or 'ham'. */
function spamadmin_learn_queued(PDO $pdo, string $payload, string $label): void {
    $fields = json_decode($payload, true);
    if (is_array($fields)) spam_learn_row($pdo, $fields, $label);
}

/* ── Actions ─────────────────────────────────────────────────────────── */
if ($canAct && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $intf = function(string $k, int $def): int { return isset($_POST[$k]) ? max(0, (int)$_POST[$k]) : $def; };
    $pct  = fn(string $k, int $def): int => min(100, $intf($k, $def));
    $chk  = fn(string $k) => isset($_POST[$k]) ? 1 : 0;
    try {
        $pdo->prepare(
            "UPDATE spam_settings SET
                enabled=?, block_threshold=?, review_threshold=?,
                max_links=?, link_weight=?, link_ratio_pct=?, link_ratio_weight=?,
                min_length=?, short_weight=?, caps_weight=?, guest_weight=?,
                dnsbl_enabled=?, dnsbl_zones=?, dnsbl_weight=?,
                sfs_enabled=?, sfs_weight=?, sfs_confidence=?,
                ip_cache_ttl=?, log_allowed=?
             WHERE id=1"
        )->execute([
            $chk('enabled'), $intf('block_threshold',100), $intf('review_threshold',50),
            $intf('max_links',4), $intf('link_weight',12), $intf('link_ratio_pct',35), $intf('link_ratio_weight',30),
            $intf('min_length',24), $intf('short_weight',15), $intf('caps_weight',10), $intf('guest_weight',10),
            $chk('dnsbl_enabled'), trim((string)($_POST['dnsbl_zones'] ?? '')), $intf('dnsbl_weight',40),
            $chk('sfs_enabled'), $intf('sfs_weight',50), $intf('sfs_confidence',30),
            $intf('ip_cache_ttl',3600), $chk('log_allowed'),
        ]);
        $pdo->prepare(
            "UPDATE spam_settings SET
                uribl_enabled=?, uribl_zones=?, uribl_weight=?,
                bayes_enabled=?, bayes_min_docs=?, bayes_spam_pct=?, bayes_spam_weight=?, bayes_ham_pct=?, bayes_ham_weight=?,
                trap_enabled=?, trap_weight=?, trap_min_seconds=?, trap_fast_weight=?
             WHERE id=1"
        )->execute([
            $chk('uribl_enabled'), trim((string)($_POST['uribl_zones'] ?? '')), $intf('uribl_weight',50),
            $chk('bayes_enabled'), max(1, $intf('bayes_min_docs',5)), $pct('bayes_spam_pct',90), $intf('bayes_spam_weight',60),
            $pct('bayes_ham_pct',10), $intf('bayes_ham_weight',30),
            $chk('trap_enabled'), $intf('trap_weight',150), $intf('trap_min_seconds',3), $intf('trap_fast_weight',30),
        ]);
        $msg = '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Settings saved.</div>';
    } catch (Throwable $e) {
        $msg = '<div class="alert alert-danger">Could not save: ' . $h($e->getMessage()) . '</div>';
    }
    $tab = 'settings';
}

if ($canAct && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_rule'])) {
    $type   = in_array($_POST['rule_type'] ?? '', ['keyword','regex','domain'], true) ? $_POST['rule_type'] : 'keyword';
    $target = in_array($_POST['target'] ?? '', ['content','title','both'], true) ? $_POST['target'] : 'both';
    $pat    = trim((string)($_POST['pattern'] ?? ''));
    $weight = max(1, (int)($_POST['weight'] ?? 50));
    $note   = trim((string)($_POST['note'] ?? ''));
    if ($pat === '') {
        $msg = '<div class="alert alert-danger">Pattern cannot be empty.</div>';
    } elseif ($type === 'regex' && @preg_match('~' . str_replace('~','\~',$pat) . '~u', '') === false) {
        $msg = '<div class="alert alert-danger">That regular expression is invalid.</div>';
    } else {
        $pdo->prepare("INSERT INTO spam_rules (rule_type, pattern, weight, target, enabled, note) VALUES (?,?,?,?,1,?)")
            ->execute([$type, $pat, $weight, $target, $note !== '' ? $note : null]);
        $msg = '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Rule added.</div>';
    }
    $tab = 'rules';
}

if ($canAct && isset($_GET['toggle_rule'])) {
    $pdo->prepare("UPDATE spam_rules SET enabled = 1 - enabled WHERE id = ?")->execute([(int)$_GET['toggle_rule']]);
    $msg = '<div class="alert alert-success">Rule updated.</div>'; $tab = 'rules';
}
if ($canAct && isset($_GET['del_rule'])) {
    $pdo->prepare("DELETE FROM spam_rules WHERE id = ?")->execute([(int)$_GET['del_rule']]);
    $msg = '<div class="alert alert-success">Rule deleted.</div>'; $tab = 'rules';
}

if ($canAct && $exemptReady && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_exempt'])) {
    $who = trim((string)($_POST['exempt_username'] ?? ''));
    if ($who === '') {
        $msg = '<div class="alert alert-danger">Enter a username.</div>';
    } elseif (spamadmin_set_exempt($pdo, $who, true)) {
        $msg = '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>' . $h($who) . ' is now exempt from the spam filter.</div>';
    } else {
        $chk = $pdo->prepare("SELECT spam_exempt FROM users WHERE username = ?");
        $chk->execute([$who]);
        $state = $chk->fetchColumn();
        $msg = $state === false
            ? '<div class="alert alert-warning">No user named ' . $h($who) . '.</div>'
            : '<div class="alert alert-info">' . $h($who) . ' is already exempt.</div>';
    }
    $tab = 'exempt';
}
if ($canAct && $exemptReady && isset($_GET['unexempt'])) {
    $who = is_string($_GET['unexempt']) ? $_GET['unexempt'] : '';
    spamadmin_set_exempt($pdo, $who, false);
    $msg = '<div class="alert alert-success">' . $h($who) . ' is no longer exempt.</div>'; $tab = 'exempt';
}

if ($canAct && isset($_GET['approve'])) {
    $id = (int)$_GET['approve'];
    try {
        $row = $pdo->prepare("SELECT payload FROM spam_queue WHERE id = ? AND status = 'pending'");
        $row->execute([$id]);
        $payload = $row->fetchColumn();
        if ($payload === false) {
            $msg = '<div class="alert alert-warning">That item is no longer pending.</div>';
        } else {
            $fields = json_decode((string)$payload, true) ?: [];
            $needSlug = spamadmin_url_mode($pdo) === 'slug' && empty($fields['slug']);
            if (!empty($fields['slug']) && spamadmin_slug_taken($pdo, (string)$fields['slug'])) {
                $needSlug = true;
            }
            if ($needSlug) {
                $slug = spamadmin_make_slug($pdo, spamadmin_slug_len($pdo));
                if ($slug !== null) $fields['slug'] = $slug; else unset($fields['slug']);
            }
            $newId = spam_insert_paste_row($pdo, $fields);
            $pdo->prepare("UPDATE spam_queue SET status = 'approved', excerpt = NULL WHERE id = ?")->execute([$id]);
            spamadmin_learn_queued($pdo, (string)$payload, 'ham');
            $note = '';
            $member = (string)($fields['member'] ?? 'Guest');
            if (!empty($_GET['exempt']) && $exemptReady && $member !== 'Guest' && spamadmin_set_exempt($pdo, $member, true)) {
                $note = ' ' . $h($member) . ' is now exempt.';
            }
            $msg = '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Paste #' . (int)$newId . ' approved and published.' . $note . '</div>';
        }
    } catch (Throwable $e) {
        $msg = '<div class="alert alert-danger">Approve failed: ' . $h($e->getMessage()) . '</div>';
    }
    $tab = 'queue';
}
if ($canAct && isset($_GET['reject'])) {
    $id = (int)$_GET['reject'];
    $row = $pdo->prepare("SELECT payload FROM spam_queue WHERE id = ? AND status = 'pending'");
    $row->execute([$id]);
    $payload = $row->fetchColumn();
    $pdo->prepare("UPDATE spam_queue SET status = 'rejected', excerpt = NULL WHERE id = ?")->execute([$id]);
    if ($payload !== false) spamadmin_learn_queued($pdo, (string)$payload, 'spam');
    $msg = '<div class="alert alert-success">Paste rejected and discarded.</div>'; $tab = 'queue';
}

if ($canAct && $learnReady && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['learn_examples'])) {
    $label  = ($_POST['learn_label'] ?? '') === 'ham' ? 'ham' : 'spam';
    $chunks = preg_split('/^\s*-{3,}\s*$/m', (string)($_POST['examples'] ?? '')) ?: [];
    $done   = ['learned' => 0, 'relearned' => 0, 'known' => 0, 'empty' => 0];
    foreach (array_slice(array_filter(array_map('trim', $chunks), 'strlen'), 0, 200) as $chunk) {
        $done[spam_learn($pdo, '', $chunk, $label)]++;
    }
    $what = $label === 'spam' ? 'spam' : 'normal';
    $msg  = '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Learned ' . ($done['learned'] + $done['relearned']) . ' example(s) as ' . $what . '.'
          . ($done['relearned'] ? ' ' . $done['relearned'] . ' of them had been learned the other way.' : '')
          . ($done['known'] ? ' ' . $done['known'] . ' were already known.' : '')
          . ($done['empty'] ? ' ' . $done['empty'] . ' had too few words to learn.' : '') . '</div>';
    $tab = 'learn';
}

if ($canAct && $learnReady && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['learn_site'])) {
    @set_time_limit(300);
    $limit = max(1, min(2000, (int)($_POST['learn_count'] ?? 300)));
    $where = "encrypt <> '2' AND visible IN ('0', '1') AND password = 'NONE'" . (isset($_POST['learn_guests']) ? '' : " AND member <> 'Guest'");
    $st = $pdo->prepare("SELECT title, content, encrypt FROM pastes WHERE $where ORDER BY id DESC LIMIT ?");
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    $done = ['learned' => 0, 'relearned' => 0, 'known' => 0, 'empty' => 0];
    foreach ($st->fetchAll() as $p) {
        $done[spam_learn_row($pdo, $p, 'ham')]++;
    }
    $msg = '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Learned ' . $done['learned'] . ' paste(s) as normal.'
         . ($done['known'] + $done['relearned'] ? ' ' . ($done['known'] + $done['relearned']) . ' were already known and left as they were.' : '') . '</div>';
    $tab = 'learn';
}

if ($canAct && $learnReady && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forget_all'])) {
    $pdo->exec("DELETE FROM spam_learned");
    $pdo->exec("DELETE FROM spam_tokens");
    $msg = '<div class="alert alert-success">The learned filter has forgotten everything.</div>'; $tab = 'learn';
}
if ($canAct && isset($_GET['clear_log'])) {
    $pdo->exec("DELETE FROM spam_log");
    $msg = '<div class="alert alert-success">Log cleared.</div>'; $tab = 'log';
}

/* ── Data for the active tab ─────────────────────────────────────────── */
$settings = $installed ? spam_get_settings($pdo) : spam_default_settings();

$rules = [];
if ($installed) {
    try { $rules = $pdo->query("SELECT * FROM spam_rules ORDER BY enabled DESC, id DESC")->fetchAll(); }
    catch (Throwable $e) {}
}

$queue = []; $pendingCount = 0;
if ($installed) {
    try {
        $queue = $pdo->query("SELECT * FROM spam_queue WHERE status = 'pending' ORDER BY id DESC LIMIT 200")->fetchAll();
        $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM spam_queue WHERE status='pending'")->fetchColumn();
    } catch (Throwable $e) {}
}

$log = [];
if ($installed) {
    try { $log = $pdo->query("SELECT * FROM spam_log ORDER BY id DESC LIMIT 200")->fetchAll(); }
    catch (Throwable $e) {}
}

$learned = ['spam' => 0, 'ham' => 0]; $tokenCount = 0; $topTokens = [];
if ($learnReady && $tab === 'learn') {
    $learned    = spam_learned_counts($pdo);
    $tokenCount = (int)$pdo->query("SELECT COUNT(*) FROM spam_tokens")->fetchColumn();
    $topTokens  = $pdo->query("SELECT token, spam, ham FROM spam_tokens WHERE spam > ham ORDER BY spam - ham DESC, spam DESC LIMIT 20")->fetchAll();
}

$exemptUsers = [];
if ($exemptReady) {
    try {
        $exemptUsers = $pdo->query("SELECT id, username, email_id, verified, date FROM users WHERE spam_exempt = 1 ORDER BY username ASC")->fetchAll();
    } catch (Throwable $e) {}
}

/* Test tab: run the scorer on submitted text (no DB writes). */
$testResult = null;
if ($canAct && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_test'])) {
    $tTitle = (string)($_POST['test_title'] ?? '');
    $tBody  = (string)($_POST['test_body'] ?? '');
    $tGuest = isset($_POST['test_guest']);
    [$score, $reasons] = spam_score_content($settings, spam_get_rules($pdo), $tTitle, $tBody, $tGuest);
    [$lScore, $lReasons] = spam_score_links($settings, $tBody);
    [$bScore, $bReasons] = spam_score_learned($pdo, $settings, $tTitle, $tBody);
    $score  += $lScore + $bScore;
    $reasons = array_merge($reasons, $lReasons, $bReasons);
    $verdict = 'allow';
    if ((int)$settings['block_threshold'] > 0 && $score >= (int)$settings['block_threshold']) $verdict = 'block';
    elseif ((int)$settings['review_threshold'] > 0 && $score >= (int)$settings['review_threshold']) $verdict = 'hold';
    $testResult = ['score' => $score, 'reasons' => $reasons, 'verdict' => $verdict];
    $tab = 'test';
}

$verdictBadge = function(string $a): string {
    return match ($a) {
        'block' => '<span class="badge bg-danger">Block</span>',
        'hold'  => '<span class="badge bg-warning text-dark">Hold</span>',
        default => '<span class="badge bg-secondary">Allow</span>',
    };
};
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Spam Filter - Paste Admin</title>
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
  .nav-tabs .nav-link { color:#c6d4f0; }
  .nav-tabs .nav-link.active { background:var(--card); border-color:var(--border) var(--border) var(--card); color:#fff; }
  .mono { font-family:ui-monospace, monospace; font-size:.85rem; }
  .reason { font-family:ui-monospace,monospace; font-size:.78rem; color:#9fb3d1; }
  .excerpt { white-space:pre-wrap; max-height:9rem; overflow:auto; background:#0e1422; border:1px solid var(--border); border-radius:8px; padding:.5rem; }
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
$renderNav = fn() => admin_nav('spamfilter.php');
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
      <h4 class="mb-3"><i class="bi bi-shield-exclamation me-2"></i>Spam Filter</h4>
      <?php if ($msg) echo $msg; ?>

      <?php if (!$installed): ?>
        <div class="alert alert-warning">
          <i class="bi bi-exclamation-triangle me-2"></i>
          The spam-filter tables don't exist yet. Re-run <code><?= $h($baseurl) ?>install/</code> (it only adds the
          new tables; existing data is untouched), then come back here.
        </div>
      <?php else: ?>

      <ul class="nav nav-tabs mb-3">
        <?php
          $tabs = ['settings'=>'Settings','rules'=>'Rules','exempt'=>'Exempt','queue'=>'Queue','learn'=>'Learn','log'=>'Log','test'=>'Test'];
          foreach ($tabs as $k=>$label):
            $extra = ($k==='queue' && $pendingCount>0) ? ' <span class="badge bg-warning text-dark">'.$pendingCount.'</span>' : '';
            if ($k==='exempt' && $exemptUsers) $extra = ' <span class="badge bg-secondary">'.count($exemptUsers).'</span>';
        ?>
          <li class="nav-item">
            <a class="nav-link <?= $tab===$k?'active':'' ?>" href="?tab=<?= $k ?>"><?= $label . $extra ?></a>
          </li>
        <?php endforeach; ?>
      </ul>

      <?php if ($tab === 'settings'): ?>
      <form method="post" action="?tab=settings">
        <input type="hidden" name="save_settings" value="1">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
        <div class="card p-3 mb-3">
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="enabled" name="enabled" <?= (int)$settings['enabled']?'checked':'' ?>>
            <label class="form-check-label" for="enabled"><strong>Enable spam filtering</strong> (off = every paste is allowed)</label>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Block at score ≥</label>
              <input type="number" min="0" class="form-control" name="block_threshold" value="<?= $h($settings['block_threshold']) ?>">
              <div class="form-text">Paste is rejected outright.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Hold for review at score ≥</label>
              <input type="number" min="0" class="form-control" name="review_threshold" value="<?= $h($settings['review_threshold']) ?>">
              <div class="form-text">Paste is queued for moderation (must be below block).</div>
            </div>
          </div>
        </div>

        <div class="card p-3 mb-3">
          <h6 class="mb-3">Content heuristics</h6>
          <div class="row g-3">
            <div class="col-md-3"><label class="form-label">Links allowed</label><input type="number" min="0" class="form-control" name="max_links" value="<?= $h($settings['max_links']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Weight per extra link</label><input type="number" min="0" class="form-control" name="link_weight" value="<?= $h($settings['link_weight']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Link/line ratio %</label><input type="number" min="0" class="form-control" name="link_ratio_pct" value="<?= $h($settings['link_ratio_pct']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Ratio weight</label><input type="number" min="0" class="form-control" name="link_ratio_weight" value="<?= $h($settings['link_ratio_weight']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Min length (chars)</label><input type="number" min="0" class="form-control" name="min_length" value="<?= $h($settings['min_length']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Short+links weight</label><input type="number" min="0" class="form-control" name="short_weight" value="<?= $h($settings['short_weight']) ?>"></div>
            <div class="col-md-3"><label class="form-label">All-caps weight</label><input type="number" min="0" class="form-control" name="caps_weight" value="<?= $h($settings['caps_weight']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Guest weight</label><input type="number" min="0" class="form-control" name="guest_weight" value="<?= $h($settings['guest_weight']) ?>"></div>
          </div>
        </div>

        <div class="card p-3 mb-3">
          <h6 class="mb-1">Learned filter</h6>
          <p class="text-secondary small mb-3">Judges a paste by its words and shape, using what it has learned in the <a href="?tab=learn">Learn</a> tab,
            from pastes you reject or approve in the queue, and from pastes you mark as spam in Pastes.</p>
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="bayes_enabled" name="bayes_enabled" <?= (int)$settings['bayes_enabled']?'checked':'' ?>>
            <label class="form-check-label" for="bayes_enabled">Use the learned filter</label>
          </div>
          <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Looks like spam at ≥ %</label><input type="number" min="50" max="100" class="form-control" name="bayes_spam_pct" value="<?= $h($settings['bayes_spam_pct']) ?>"></div>
            <div class="col-md-4"><label class="form-label">Points added</label><input type="number" min="0" class="form-control" name="bayes_spam_weight" value="<?= $h($settings['bayes_spam_weight']) ?>"></div>
            <div class="col-md-4"><label class="form-label">Examples needed of each kind</label><input type="number" min="1" class="form-control" name="bayes_min_docs" value="<?= $h($settings['bayes_min_docs']) ?>"></div>
            <div class="col-md-4"><label class="form-label">Looks normal at ≤ %</label><input type="number" min="0" max="50" class="form-control" name="bayes_ham_pct" value="<?= $h($settings['bayes_ham_pct']) ?>"></div>
            <div class="col-md-4"><label class="form-label">Points taken off</label><input type="number" min="0" class="form-control" name="bayes_ham_weight" value="<?= $h($settings['bayes_ham_weight']) ?>"></div>
          </div>
          <div class="form-text mt-2">Points taken off let a normal paste with a link through when a rule or the guest weight would hold it.
            Set the points added to the block score once you trust it.</div>
        </div>

        <div class="card p-3 mb-3">
          <h6 class="mb-1">Link domains</h6>
          <p class="text-secondary small mb-3">Looks up the domains a paste links to on URI blocklists, so one link to a known spam site counts.</p>
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="uribl_enabled" name="uribl_enabled" <?= (int)$settings['uribl_enabled']?'checked':'' ?>>
            <label class="form-check-label" for="uribl_enabled">Check link domains</label>
          </div>
          <div class="row g-3">
            <div class="col-md-9">
              <label class="form-label">URI blocklist zones (one per line)</label>
              <textarea class="form-control mono" name="uribl_zones" rows="2"><?= $h($settings['uribl_zones']) ?></textarea>
            </div>
            <div class="col-md-3"><label class="form-label">Weight per listed domain</label><input type="number" min="0" class="form-control" name="uribl_weight" value="<?= $h($settings['uribl_weight']) ?>"></div>
          </div>
          <div class="form-text mt-2">Spamhaus and SURBL refuse lookups made through public DNS such as 8.8.8.8 or 1.1.1.1. On a server that uses one,
            these lookups and the DNSBLs below find nothing, so use your host's resolver or a local one.</div>
        </div>

        <div class="card p-3 mb-3">
          <h6 class="mb-1">Form traps</h6>
          <p class="text-secondary small mb-3">The paste form carries a hidden field that people never see but bots fill in, and the time it was shown.</p>
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="trap_enabled" name="trap_enabled" <?= (int)$settings['trap_enabled']?'checked':'' ?>>
            <label class="form-check-label" for="trap_enabled">Use form traps</label>
          </div>
          <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Hidden field filled in</label><input type="number" min="0" class="form-control" name="trap_weight" value="<?= $h($settings['trap_weight']) ?>"></div>
            <div class="col-md-4"><label class="form-label">Sent within (seconds)</label><input type="number" min="0" class="form-control" name="trap_min_seconds" value="<?= $h($settings['trap_min_seconds']) ?>"></div>
            <div class="col-md-4"><label class="form-label">Points for sending that fast</label><input type="number" min="0" class="form-control" name="trap_fast_weight" value="<?= $h($settings['trap_fast_weight']) ?>"></div>
          </div>
          <div class="form-text mt-2">Applies to the website's paste, edit and fork forms, not the API.</div>
        </div>

        <div class="card p-3 mb-3">
          <h6 class="mb-3">IP reputation</h6>
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="dnsbl_enabled" name="dnsbl_enabled" <?= (int)$settings['dnsbl_enabled']?'checked':'' ?>>
            <label class="form-check-label" for="dnsbl_enabled">Check DNSBLs (email-style DNS blocklists)</label>
          </div>
          <div class="row g-3 mb-2">
            <div class="col-md-9">
              <label class="form-label">DNSBL zones (one per line)</label>
              <textarea class="form-control mono" name="dnsbl_zones" rows="3"><?= $h($settings['dnsbl_zones']) ?></textarea>
            </div>
            <div class="col-md-3"><label class="form-label">Weight per listing</label><input type="number" min="0" class="form-control" name="dnsbl_weight" value="<?= $h($settings['dnsbl_weight']) ?>"></div>
          </div>
          <hr class="border-secondary">
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="sfs_enabled" name="sfs_enabled" <?= (int)$settings['sfs_enabled']?'checked':'' ?>>
            <label class="form-check-label" for="sfs_enabled">Check StopForumSpam (api.stopforumspam.org)</label>
          </div>
          <div class="row g-3">
            <div class="col-md-4"><label class="form-label">SFS weight</label><input type="number" min="0" class="form-control" name="sfs_weight" value="<?= $h($settings['sfs_weight']) ?>"></div>
            <div class="col-md-4"><label class="form-label">Min confidence %</label><input type="number" min="0" max="100" class="form-control" name="sfs_confidence" value="<?= $h($settings['sfs_confidence']) ?>"></div>
            <div class="col-md-4"><label class="form-label">IP cache TTL (sec)</label><input type="number" min="60" class="form-control" name="ip_cache_ttl" value="<?= $h($settings['ip_cache_ttl']) ?>"></div>
          </div>
          <div class="form-text mt-2">Lookups are cached per IP, a network problem never blocks a paste.</div>
        </div>

        <div class="card p-3 mb-3">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="log_allowed" name="log_allowed" <?= (int)$settings['log_allowed']?'checked':'' ?>>
            <label class="form-check-label" for="log_allowed">Also log pastes that passed (noisier, useful for tuning)</label>
          </div>
        </div>

        <button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save settings</button>
      </form>

      <?php elseif ($tab === 'rules'): ?>
      <div class="card p-3 mb-3">
        <h6 class="mb-3">Add a rule</h6>
        <form method="post" action="?tab=rules" class="row g-2 align-items-end">
          <input type="hidden" name="add_rule" value="1">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <div class="col-md-2">
            <label class="form-label">Type</label>
            <select class="form-select" name="rule_type">
              <option value="keyword">Keyword</option>
              <option value="regex">Regex</option>
              <option value="domain">Domain</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Pattern</label>
            <input class="form-control mono" name="pattern" placeholder="cheap-loans / (viagra|casino) / spam.example">
          </div>
          <div class="col-md-2">
            <label class="form-label">Target</label>
            <select class="form-select" name="target">
              <option value="both">Title + body</option>
              <option value="content">Body</option>
              <option value="title">Title</option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Weight</label>
            <input type="number" min="1" class="form-control" name="weight" value="50">
          </div>
          <div class="col-md-2">
            <button class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i> Add</button>
          </div>
          <div class="col-12">
            <input class="form-control" name="note" placeholder="Optional note (why this rule exists)">
          </div>
        </form>
      </div>

      <div class="card p-3">
        <h6 class="mb-3">Rules (<?= count($rules) ?>)</h6>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead><tr><th>Type</th><th>Pattern</th><th>Target</th><th>Weight</th><th>Status</th><th>Note</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php if (!$rules): ?>
              <tr><td colspan="7" class="text-center text-secondary py-3">No rules yet.</td></tr>
            <?php else: foreach ($rules as $r): ?>
              <tr>
                <td><span class="badge bg-secondary"><?= $h($r['rule_type']) ?></span></td>
                <td class="mono"><?= $h($r['pattern']) ?></td>
                <td><?= $h($r['target']) ?></td>
                <td><?= (int)$r['weight'] ?></td>
                <td><?= ((int)$r['enabled']) ? '<span class="badge bg-success">on</span>' : '<span class="badge bg-secondary">off</span>' ?></td>
                <td class="text-secondary small"><?= $h($r['note'] ?? '') ?></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-soft" href="<?= $actionUrl(['tab' => 'rules', 'toggle_rule' => (int)$r['id']]) ?>" title="Toggle"><i class="bi bi-power"></i></a>
                  <a class="btn btn-sm btn-danger" href="<?= $actionUrl(['tab' => 'rules', 'del_rule' => (int)$r['id']]) ?>" onclick="return confirm('Delete this rule?')" title="Delete"><i class="bi bi-trash"></i></a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php elseif ($tab === 'exempt'): ?>
      <?php if (!$exemptReady): ?>
        <div class="alert alert-warning">
          <i class="bi bi-exclamation-triangle me-2"></i>
          The <code>users.spam_exempt</code> column could not be created. Run
          <code>ALTER TABLE users ADD COLUMN spam_exempt TINYINT(1) NOT NULL DEFAULT 0;</code> and reload.
        </div>
      <?php else: ?>
      <div class="card p-3 mb-3">
        <h6 class="mb-1">Exempt a user</h6>
        <p class="text-secondary small mb-3">Exempt users skip every spam check on pastes, edits, forks, API calls and comments. Guests can never be exempt.</p>
        <form method="post" action="?tab=exempt" class="row g-2 align-items-end">
          <input type="hidden" name="add_exempt" value="1">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <div class="col-md-6">
            <label class="form-label" for="exempt_username">Username</label>
            <input class="form-control" id="exempt_username" name="exempt_username" autocomplete="off" required>
          </div>
          <div class="col-md-2">
            <button class="btn btn-primary w-100"><i class="bi bi-shield-check"></i> Exempt</button>
          </div>
        </form>
      </div>

      <div class="card p-3">
        <h6 class="mb-3">Exempt users (<?= count($exemptUsers) ?>)</h6>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead><tr><th>Username</th><th>Email</th><th>Status</th><th>Registered</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php if (!$exemptUsers): ?>
              <tr><td colspan="5" class="text-center text-secondary py-3">No exempt users. You can also toggle this per user in <a href="users.php">Users</a>.</td></tr>
            <?php else: foreach ($exemptUsers as $u): ?>
              <tr>
                <td><?= $h($u['username']) ?></td>
                <td class="small"><?= $h($u['email_id']) ?></td>
                <td><?= match ((string)$u['verified']) {
                      '1' => '<span class="badge bg-success">Verified</span>',
                      '2' => '<span class="badge bg-danger">Banned</span>',
                      default => '<span class="badge bg-secondary">Unverified</span>',
                    } ?></td>
                <td class="small text-nowrap"><?= $h($u['date']) ?></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-soft" href="<?= $actionUrl(['tab' => 'exempt', 'unexempt' => (string)$u['username']]) ?>" onclick="return confirm('Remove the exemption for this user?')" title="Remove exemption"><i class="bi bi-shield-x"></i> Remove</a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <?php elseif ($tab === 'queue'): ?>
      <div class="card p-3">
        <h6 class="mb-1">Held for moderation (<?= $pendingCount ?>)</h6>
        <p class="text-secondary small mb-3">Approving teaches the learned filter that the paste is normal; rejecting teaches it spam.</p>
        <?php if (!$queue): ?>
          <p class="text-secondary mb-0">Nothing waiting. Pastes that score between the review and block thresholds appear here.</p>
        <?php else: foreach ($queue as $q): ?>
          <div class="border rounded p-2 mb-2" style="border-color:var(--border)!important;">
            <div class="d-flex justify-content-between flex-wrap gap-2">
              <div>
                <strong><?= $h($q['title'] ?: '(untitled)') ?></strong>
                <span class="badge bg-warning text-dark ms-1">score <?= (int)$q['score'] ?></span>
                <div class="small text-secondary">by <?= $h($q['member']) ?> · <span class="mono"><?= $h(admin_personal($q['ip'])) ?></span> · <?= $h($q['created']) ?></div>
              </div>
              <div class="text-end">
                <a class="btn btn-sm btn-success" href="<?= $actionUrl(['tab' => 'queue', 'approve' => (int)$q['id']]) ?>" onclick="return confirm('Publish this paste?')"><i class="bi bi-check-lg"></i> Approve</a>
                <?php if ($exemptReady && (string)$q['member'] !== 'Guest'): ?>
                <a class="btn btn-sm btn-outline-success" href="<?= $actionUrl(['tab' => 'queue', 'approve' => (int)$q['id'], 'exempt' => 1]) ?>" onclick="return confirm('Publish this paste and exempt this user from future checks?')" title="Approve and exempt this user"><i class="bi bi-shield-check"></i> Approve + exempt</a>
                <?php endif; ?>
                <a class="btn btn-sm btn-danger" href="<?= $actionUrl(['tab' => 'queue', 'reject' => (int)$q['id']]) ?>" onclick="return confirm('Discard this paste?')"><i class="bi bi-x-lg"></i> Reject</a>
              </div>
            </div>
            <?php if (($q['excerpt'] ?? '') !== ''): ?><div class="excerpt mt-2"><?= $h($q['excerpt']) ?></div><?php endif; ?>
            <div class="reason mt-1"><?= $h($q['reasons']) ?></div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <?php elseif ($tab === 'learn'): ?>
      <?php if (!$learnReady): ?>
        <div class="alert alert-warning">
          <i class="bi bi-exclamation-triangle me-2"></i>
          The learned filter's tables don't exist yet. Run the upgrader (<code><?= $h($baseurl) ?>install/</code>) to add them.
        </div>
      <?php else:
        $minDocs = max(1, (int)$settings['bayes_min_docs']);
        $active  = $learned['spam'] >= $minDocs && $learned['ham'] >= $minDocs;
      ?>
      <div class="card p-3 mb-3">
        <div class="d-flex flex-wrap gap-4 align-items-center">
          <div><div class="fs-4 fw-semibold"><?= number_format($learned['spam']) ?></div><div class="small text-secondary">spam examples</div></div>
          <div><div class="fs-4 fw-semibold"><?= number_format($learned['ham']) ?></div><div class="small text-secondary">normal examples</div></div>
          <div><div class="fs-4 fw-semibold"><?= number_format($tokenCount) ?></div><div class="small text-secondary">words known</div></div>
          <div class="ms-auto">
            <?php if (!(int)$settings['bayes_enabled']): ?>
              <span class="badge bg-secondary">Off in Settings</span>
            <?php elseif ($active): ?>
              <span class="badge bg-success">Scoring pastes</span>
            <?php else: ?>
              <span class="badge bg-warning text-dark">Needs <?= $minDocs ?> of each kind to start</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="card p-3 mb-3">
        <h6 class="mb-1">Teach it examples</h6>
        <p class="text-secondary small mb-3">Paste one or more examples. Put a line of dashes (<code>-----</code>) between them.</p>
        <form method="post" action="?tab=learn">
          <input type="hidden" name="learn_examples" value="1">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <textarea class="form-control mono mb-2" name="examples" rows="8" required></textarea>
          <button class="btn btn-danger" name="learn_label" value="spam"><i class="bi bi-hand-thumbs-down me-1"></i>These are spam</button>
          <button class="btn btn-success" name="learn_label" value="ham"><i class="bi bi-hand-thumbs-up me-1"></i>These are normal</button>
        </form>
      </div>

      <div class="card p-3 mb-3">
        <h6 class="mb-1">Learn this site's pastes as normal</h6>
        <p class="text-secondary small mb-3">Learns the most recent public and unlisted pastes, newest first, skipping private, password-protected, client-encrypted and banned ones.
          Mark any spam among them first (Pastes &gt; Spam) so it isn't learned as normal. Pastes it already knows are left as they are.</p>
        <form method="post" action="?tab=learn" class="row g-2 align-items-end">
          <input type="hidden" name="learn_site" value="1">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <div class="col-md-3">
            <label class="form-label" for="learn_count">How many</label>
            <input type="number" min="1" max="2000" class="form-control" id="learn_count" name="learn_count" value="300">
          </div>
          <div class="col-md-5">
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" id="learn_guests" name="learn_guests">
              <label class="form-check-label" for="learn_guests">Include guest pastes</label>
            </div>
          </div>
          <div class="col-md-4"><button class="btn btn-primary w-100"><i class="bi bi-mortarboard me-1"></i>Learn</button></div>
        </form>
      </div>

      <?php if ($topTokens): ?>
      <div class="card p-3 mb-3">
        <h6 class="mb-3">What it sees most in spam</h6>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($topTokens as $t): ?>
            <span class="badge bg-secondary mono fw-normal" title="spam <?= (int)$t['spam'] ?>, normal <?= (int)$t['ham'] ?>"><?= $h($t['token']) ?> <span class="text-warning"><?= (int)$t['spam'] ?></span>/<?= (int)$t['ham'] ?></span>
          <?php endforeach; ?>
        </div>
        <div class="form-text mt-2">Each word shows how many spam and normal examples it appeared in.</div>
      </div>
      <?php endif; ?>

      <form method="post" action="?tab=learn" onsubmit="return confirm('Forget every learned example?')">
        <input type="hidden" name="forget_all" value="1">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
        <button class="btn btn-sm btn-soft"><i class="bi bi-eraser me-1"></i>Forget everything</button>
      </form>
      <?php endif; ?>

      <?php elseif ($tab === 'log'): ?>
      <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h6 class="mb-0">Recent events (<?= count($log) ?>)</h6>
          <?php if ($log): ?><a class="btn btn-sm btn-soft" href="<?= $actionUrl(['tab' => 'log', 'clear_log' => 1]) ?>" onclick="return confirm('Clear the whole log?')"><i class="bi bi-trash"></i> Clear</a><?php endif; ?>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead><tr><th>When</th><th>Action</th><th>Score</th><th>Member</th><th>IP</th><th>Title</th><th>Reasons</th></tr></thead>
            <tbody>
            <?php if (!$log): ?>
              <tr><td colspan="7" class="text-center text-secondary py-3">Nothing logged yet.</td></tr>
            <?php else: foreach ($log as $l): ?>
              <tr>
                <td class="small text-nowrap"><?= $h($l['created']) ?></td>
                <td><?= $verdictBadge((string)$l['action']) ?></td>
                <td><?= (int)$l['score'] ?></td>
                <td class="small"><?= $h($l['member']) ?></td>
                <td class="mono small"><?= $h(admin_personal($l['ip'])) ?></td>
                <td class="small"><?= $h(mb_strimwidth((string)$l['title'], 0, 40, '…')) ?></td>
                <td class="reason"><?= $h(mb_strimwidth((string)$l['reasons'], 0, 80, '…')) ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php elseif ($tab === 'test'): ?>
      <div class="card p-3 mb-3">
        <h6 class="mb-3">Test a sample (rules, content heuristics, link domains and the learned filter; no IP lookups, nothing saved)</h6>
        <form method="post" action="?tab=test">
          <input type="hidden" name="run_test" value="1">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <div class="mb-2"><label class="form-label">Title</label><input class="form-control" name="test_title" value="<?= $h($_POST['test_title'] ?? '') ?>"></div>
          <div class="mb-2"><label class="form-label">Body</label><textarea class="form-control mono" name="test_body" rows="6"><?= $h($_POST['test_body'] ?? '') ?></textarea></div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="test_guest" name="test_guest" <?= isset($_POST['test_guest'])?'checked':'' ?>>
            <label class="form-check-label" for="test_guest">Treat as guest</label>
          </div>
          <button class="btn btn-primary"><i class="bi bi-play me-1"></i>Run test</button>
        </form>
      </div>
      <?php if ($testResult !== null): ?>
        <div class="card p-3">
          <div class="mb-2">Verdict: <?= $verdictBadge($testResult['verdict']) ?> &nbsp; Score: <strong><?= (int)$testResult['score'] ?></strong>
            <span class="text-secondary small">(hold ≥ <?= (int)$settings['review_threshold'] ?>, block ≥ <?= (int)$settings['block_threshold'] ?>)</span>
          </div>
          <?php if ($testResult['reasons']): ?>
            <ul class="reason mb-0"><?php foreach ($testResult['reasons'] as $why): ?><li><?= $h($why) ?></li><?php endforeach; ?></ul>
          <?php else: ?>
            <div class="text-secondary">No signals matched.</div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php endif; /* tab */ ?>
      <?php endif; /* installed */ ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
