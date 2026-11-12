<?php
/*
 * Paste https://github.com/boxlabss/PASTE
 *
 * Which sections of the admin panel each admin can open. The admin table's
 * `permissions` column holds a comma-separated list of section keys; NULL
 * means full access, and admin 1 always has full access. Only admins with
 * full access can manage admins and see the admin login history.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

/** Sections an admin can be given: key => [label, description]. */
function admin_sections(): array
{
    return [
        'configuration' => ['Configuration', 'Site settings, mail, captcha, sign-in and API keys. Its additional scripts run on every page, so treat it as full access'],
        'interface'     => ['Interface', 'Theme and language'],
        'pastes'        => ['Pastes', 'Pastes and their comments'],
        'files'         => ['Website uploads', 'Files uploaded at /img, their settings and reports'],
        'filehost'      => ['IRC uploads', 'Files uploaded through FILEHOST'],
        'users'         => ['Users and personal data', 'The Users page, and the email and IP addresses shown in other sections'],
        'ipbans'        => ['IP bans', 'Banning addresses, including "Ban IP" in Pastes'],
        'spamfilter'    => ['Spam filter', 'Rules, the moderation queue and the log'],
        'stats'         => ['Statistics', 'Page view statistics'],
        'ads'           => ['Ads', 'Ad code, which runs on site pages, so treat it as full access'],
        'pages'         => ['Pages', 'Custom pages, which can hold HTML and scripts, so treat it as full access'],
        'sitemap'       => ['Sitemap', 'Sitemap generation'],
        'tasks'         => ['Tasks', 'Maintenance tasks'],
    ];
}

/** Sidebar entries: [file, icon, label, sections that show it, or null for every admin]. */
function admin_nav_items(): array
{
    return [
        ['dashboard.php',     'bi-house',              'Dashboard',     null],
        ['configuration.php', 'bi-gear',               'Configuration', ['configuration']],
        ['interface.php',     'bi-eye',                'Interface',     ['interface']],
        ['admin.php',         'bi-person',             'Admin Account', null],
        ['pastes.php',        'bi-clipboard',          'Pastes',        ['pastes']],
        ['uploads.php',       'bi-images',             'Uploads',       ['files', 'filehost']],
        ['users.php',         'bi-people',             'Users',         ['users']],
        ['ipbans.php',        'bi-ban',                'IP Bans',       ['ipbans']],
        ['spamfilter.php',    'bi-shield-exclamation', 'Spam Filter',   ['spamfilter']],
        ['stats.php',         'bi-graph-up',           'Statistics',    ['stats']],
        ['ads.php',           'bi-currency-pound',     'Ads',           ['ads']],
        ['pages.php',         'bi-file-earmark',       'Pages',         ['pages']],
        ['sitemap.php',       'bi-map',                'Sitemap',       ['sitemap']],
        ['tasks.php',         'bi-list-task',          'Tasks',         ['tasks']],
    ];
}

/** Section keys from a stored permissions value, or null for full access. */
function admin_parse_permissions(?string $stored): ?array
{
    if ($stored === null) return null;
    $given = array_map('trim', explode(',', $stored));
    return array_values(array_intersect(array_keys(admin_sections()), $given));
}

/** Stored permissions value for a list of section keys, or null for full access. */
function admin_store_permissions(?array $sections): ?string
{
    if ($sections === null) return null;
    return implode(',', array_values(array_intersect(array_keys(admin_sections()), $sections)));
}

/** True when the admin table has the permissions column. $set records that it was just added. */
function admin_permissions_ready(PDO $pdo, ?bool $set = null): bool
{
    static $ready = null;
    if ($set !== null) $ready = $set;
    if ($ready === null) {
        $ready = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns
                                   WHERE table_schema = DATABASE() AND table_name = 'admin' AND column_name = 'permissions'")
                          ->fetchColumn() > 0;
    }
    return $ready;
}

/** Add the permissions column when it is missing. Returns false if the database user cannot. */
function admin_permissions_ensure(PDO $pdo): bool
{
    if (admin_permissions_ready($pdo)) return true;
    try {
        $pdo->exec("ALTER TABLE admin ADD COLUMN permissions VARCHAR(255) NULL DEFAULT NULL");
    } catch (PDOException $e) {
        error_log('admin_permissions_ensure: ' . $e->getMessage());
        return false;
    }
    return admin_permissions_ready($pdo, true);
}

/** Remember or return the signed-in admin: ['id', 'user', 'sections' (null: full access)]. */
function admin_state(?array $set = null): ?array
{
    static $admin = null;
    if ($set !== null) $admin = $set;
    return $admin;
}

/** Load the signed-in admin from the session and database; null if the session is not a valid admin. */
function admin_load(PDO $pdo): ?array
{
    $id   = (int)($_SESSION['admin_id'] ?? 0);
    $user = (string)($_SESSION['admin_login'] ?? '');
    if ($id <= 0 || $user === '') return null;
    $cols = admin_permissions_ready($pdo) ? 'id, user, permissions' : 'id, user, NULL AS permissions';
    $st = $pdo->prepare("SELECT $cols FROM admin WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['user'] !== $user) return null;
    return admin_state([
        'id'       => (int)$row['id'],
        'user'     => (string)$row['user'],
        'sections' => (int)$row['id'] === 1 ? null : admin_parse_permissions($row['permissions']),
    ]);
}

/** Database connection from config.php's settings. */
function admin_access_pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        global $dbhost, $dbname, $dbuser, $dbpassword;
        try {
            $pdo = new PDO("mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpassword, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            error_log('admin_access_pdo: ' . $e->getMessage());
            http_response_code(500);
            exit('Unable to connect to the database.');
        }
    }
    return $pdo;
}

/** Path from the running script to a file in the admin folder. */
function admin_url(string $file): string
{
    $script = realpath(dirname((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) ?: '';
    return $script === (realpath(dirname(__DIR__) . '/admin') ?: '') ? $file : '../admin/' . $file;
}

/**
 * Stop unless a valid admin is signed in and, when $sections is given, can
 * open at least one of them. Sends signed-out sessions to the login page.
 */
function admin_require(string|array|null $sections = null, ?PDO $pdo = null): array
{
    $admin = admin_load($pdo ?? admin_access_pdo());
    if ($admin === null) {
        unset($_SESSION['admin_login'], $_SESSION['admin_id']);
        header('Location: ' . admin_url('index.php'));
        exit;
    }
    if ($sections !== null && !admin_can_any((array)$sections)) {
        admin_denied();
    }
    return $admin;
}

/** True when the signed-in admin can open $section. */
function admin_can(string $section): bool
{
    $admin = admin_state();
    return $admin !== null && ($admin['sections'] === null || in_array($section, $admin['sections'], true));
}

/** True when the signed-in admin can open any of $sections. */
function admin_can_any(array $sections): bool
{
    foreach ($sections as $s) {
        if (admin_can($s)) return true;
    }
    return false;
}

/** True when the signed-in admin has full access. */
function admin_full(): bool
{
    $admin = admin_state();
    return $admin !== null && $admin['sections'] === null;
}

/** An email or IP address for display, or "hidden" without the users permission. */
function admin_personal(?string $value): string
{
    return admin_can('users') ? (string)$value : 'hidden';
}

/** Sidebar links the signed-in admin can open, with $active highlighted. */
function admin_nav(string $active): void
{
    foreach (admin_nav_items() as [$file, $icon, $label, $needs]) {
        if ($needs !== null && !admin_can_any($needs)) continue;
        echo '<a class="list-group-item' . ($file === $active ? ' active' : '') . '" href="'
            . htmlspecialchars(admin_url($file), ENT_QUOTES, 'UTF-8') . '"><i class="bi ' . $icon . ' me-2"></i>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>' . "\n";
    }
}

/** Describe an admin's access for lists: "Full access" or the section labels. */
function admin_access_summary(?array $sections): string
{
    if ($sections === null) return 'Full access';
    if (!$sections) return 'Dashboard only';
    $names = admin_sections();
    return implode(', ', array_map(static fn(string $k): string => $names[$k][0], $sections));
}

/** 403 page listing the sections the admin can open. */
function admin_denied(): never
{
    http_response_code(403);
    header('Cache-Control: no-store');
    $links = '';
    foreach (admin_nav_items() as [$file, $icon, $label, $needs]) {
        if ($needs !== null && !admin_can_any($needs)) continue;
        $links .= '<a class="list-group-item" href="' . htmlspecialchars(admin_url($file), ENT_QUOTES, 'UTF-8') . '"><i class="bi ' . $icon . ' me-2"></i>'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }
    echo '<!DOCTYPE html><html lang="en" data-bs-theme="dark"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Paste - No access</title>'
       . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">'
       . '<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">'
       . '<style>body{background:#0f1115;color:#fff}.card{background:#141821;border:1px solid #1f2633;border-radius:12px}'
       . '.list-group-item{background:transparent;color:#dbe5f5;border-color:#1f2633}.list-group-item:hover{background:#0e1422}</style></head>'
       . '<body><div class="container my-5" style="max-width:520px"><div class="card p-4">'
       . '<h4 class="mb-2"><i class="bi bi-lock me-2"></i>No access</h4>'
       . '<p class="text-secondary">Your admin account cannot open this section. An admin with full access can change that in Admin Account &gt; Manage Admins.</p>'
       . '<div class="list-group">' . $links . '</div>'
       . '<a class="btn btn-sm btn-outline-light mt-3" href="' . htmlspecialchars(admin_url('dashboard.php'), ENT_QUOTES, 'UTF-8') . '?logout">Log out</a>'
       . '</div></div></body></html>';
    exit;
}
