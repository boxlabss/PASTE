<?php
/*
 * Paste https://github.com/boxlabss/PASTE
 *
 * Image and video uploads.
 *   /img/                 upload form, your uploads and your albums
 *   /img/?album=<name>    upload form adding to one of your albums
 *   /img/<name>           view page of an upload or an album
 *   /img/<name>.<ext>     the file, decrypted with its link name
 *   /img/<slug>.bin       ciphertext of an upload encrypted in the browser
 *   /img/<slug>.thumb     its encrypted preview (the view page decrypts both)
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */
declare(strict_types=1);

chdir(dirname(__DIR__));
ini_set('display_errors', '0');

/* ---- Stored file: no session, cacheable ---- */
if (isset($_GET['f'])) {
    require_once 'config.php';
    require_once 'includes/functions.php';
    $f = is_string($_GET['f']) ? $_GET['f'] : '';
    $row = preg_match('/^([A-Za-z0-9]{6,32})\.([a-z0-9]{1,8})$/', $f, $m) ? img_find($pdo, $m[1]) : null;
    $want = $m[2] ?? '';
    if ($row && $row['encrypted'] === FILECRYPT_MODE) {
        if ($row['ext'] !== $want) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            exit("Not found\n");
        }
        filecrypt_send(filehost_storage_dir() . '/' . $row['slug'] . '.enc', (string)$row['_name'], (string)$row['mime'],
            img_download_name($row), ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD');
    }
    // Browser-encrypted upload: the ciphertext, or its encrypted preview
    $thumb = $row && $want === 'thumb' && (int)($row['thumb_size'] ?? 0) > 0;
    $path = $row ? filehost_storage_dir() . '/' . $row['slug'] . '.' . ($thumb ? 'thumb' : $row['ext']) : '';
    if (!$row || (!$thumb && $row['ext'] !== $want) || !is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Not found\n");
    }
    filehost_send_file($path, 'application/octet-stream', $thumb ? (int)filesize($path) : (int)$row['size'], null,
        ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD');
}

require_once 'includes/session.php';
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/admin_access.php';
paste_enable_themed_errors();

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

/* ---- Site settings for the theme ---- */
$si = $pdo->query("SELECT * FROM site_info WHERE id = 1")->fetch() ?: [];
$title              = trim((string)($si['title'] ?? 'Paste'));
$des                = trim((string)($si['des'] ?? ''));
$keyword            = trim((string)($si['keyword'] ?? ''));
$site_name          = trim((string)($si['site_name'] ?? 'Paste'));
$baseurl            = paste_request_baseurl(rtrim(trim((string)($si['baseurl'] ?? '')), '/') . '/');
$ga                 = trim((string)($si['ga'] ?? ''));
$additional_scripts = trim((string)($si['additional_scripts'] ?? ''));

$iface         = $pdo->query("SELECT * FROM interface WHERE id = 1")->fetch() ?: [];
$default_lang  = basename(trim((string)($iface['lang'] ?? 'en.php')));
$default_theme = basename(trim((string)($iface['theme'] ?? 'default')));
require_once "langs/$default_lang";

$perm         = $pdo->query("SELECT * FROM site_permissions WHERE id = 1")->fetch() ?: [];
$disableguest = trim((string)($perm['disableguest'] ?? 'off'));
$privatesite  = trim((string)($perm['siteprivate'] ?? 'off')) === 'on' ? 'on' : 'off';

$ads      = $pdo->query("SELECT * FROM ads WHERE id = 1")->fetch() ?: [];
$text_ads = trim((string)($ads['text_ads'] ?? ''));
$ads_1    = trim((string)($ads['ads_1'] ?? ''));
$ads_2    = trim((string)($ads['ads_2'] ?? ''));

$rewrite   = ((string)($mod_rewrite ?? '0') === '1');
img_ensure_table($pdo);
$settings  = img_settings($pdo);
$limits    = img_limits($settings);
$username  = isset($_SESSION['username']) ? (string)$_SESSION['username'] : null;
$isAdmin   = isset($_SESSION['admin_login']) && admin_load($pdo) !== null && admin_can('files');
$account   = $username ?? 'Guest';
$canUpload = $username !== null || (int)$settings['img_guests'] === 1;
$csrf      = (string)($_SESSION['csrf_token'] ?? '');
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$wantsJson = ($_POST['ajax'] ?? '') === '1'
    || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

/** Render a page through the site theme and exit. */
$render = function (string $template, array $vars = [], int $status = 200) {
    extract($GLOBALS, EXTR_SKIP);
    extract($vars, EXTR_OVERWRITE);
    http_response_code($status);
    // Pages carry this visitor's form token and delete buttons, so no shared cache may keep them
    header('Cache-Control: private, no-store, max-age=0');
    $theme = 'theme/' . $default_theme;
    require $theme . '/header.php';
    require $theme . '/' . $template;
    require $theme . '/footer.php';
    exit;
};

/** JSON reply for the upload script, then exit. */
$json = function (array $data, int $status = 200): never {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

/** No ads, analytics or extra scripts on the upload form, where keys are made. */
$quietPage = function () use (&$ga, &$additional_scripts, &$ads_1, &$ads_2, &$text_ads) {
    $ga = $additional_scripts = $ads_1 = $ads_2 = $text_ads = '';
    header('Referrer-Policy: no-referrer');
};

/** Error for the upload form: JSON for scripts, themed page otherwise. */
$fail = function (string $message, int $status = 400) use (&$json, &$render, &$wantsJson, &$quietPage) {
    if ($wantsJson) {
        $json(['success' => false, 'error' => $message], $status);
    }
    $quietPage();
    $render('img_upload.php', ['img_error' => $message], $status);
};

/** Guest captcha check; passing it covers the next ten minutes of uploads. */
function img_verify_human(): ?string
{
    global $error, $lang;
    if ((int)($_SESSION['img_human_until'] ?? 0) > time()) return null;
    if (($_SESSION['cap_e'] ?? 'off') !== 'on') return null;

    $error = '';
    $mode = (string)($_SESSION['mode'] ?? 'normal');
    if ($mode === 'reCAPTCHA') {
        require_once 'includes/recaptcha.php';
        require_human('upload');
        if (!empty($error)) return (string)$error;
    } elseif ($mode === 'turnstile') {
        require_once 'includes/turnstile.php';
        if (!require_human_turnstile('upload')) {
            return (string)($error ?: ($lang['turnstile_failed'] ?? 'Turnstile verification failed. Please try again.'));
        }
    } else {
        $posted = strtolower(preg_replace('/\s+/', '', (string)($_POST['scode'] ?? '')) ?? '');
        $cfg = isset($_SESSION['_CAPTCHA']['config'])
            ? @unserialize((string)$_SESSION['_CAPTCHA']['config'], ['allowed_classes' => false])
            : null;
        $code = strtolower((string)(is_array($cfg) ? ($cfg['code'] ?? '') : ''));
        if ($code === '' || !hash_equals($code, $posted)) {
            return $lang['image_wrong'] ?? 'Incorrect CAPTCHA code.';
        }
        unset($_SESSION['_CAPTCHA'], $_SESSION['captcha']);
    }
    $_SESSION['img_human_until'] = time() + 600;
    return null;
}

/** Is the current visitor the uploader? Only they can delete from here; admins use Admin > Uploads. */
$canDelete = function (array $row) use ($username): bool {
    if ($username !== null && $row['account'] === $username) return true;
    return $row['account'] === 'Guest' && in_array($row['slug'], (array)($_SESSION['img_uploads'] ?? []), true);
};

/** Is the current visitor the album's maker? Guests keep the albums they made in their session. */
$canManageAlbum = function (array $album) use ($username): bool {
    if ($username !== null && $album['account'] === $username) return true;
    return $album['account'] === 'Guest' && in_array($album['_name'], (array)($_SESSION['img_albums'] ?? []), true);
};

/** Album page URL. */
$albumUrl = fn(string $name): string => img_page_url($name, $baseurl, $rewrite);

/**
 * Link names from the request that name uploads this visitor made and that can go in an album,
 * in the order given. Fails the request when one does not.
 */
$ownUploadNames = function () use (&$fail, $pdo, $canDelete): array {
    $names = $_POST['names'] ?? [];
    if (!is_array($names) || !$names) $fail('Choose the uploads to put in the album.', 422);
    $out = [];
    foreach ($names as $name) {
        $row = is_string($name) ? img_find($pdo, $name) : null;
        if ($row === null || $row['encrypted'] !== FILECRYPT_MODE || !$canDelete($row)) {
            $fail('Only your own uploads, and not ones encrypted in the browser, can go in an album.', 403);
        }
        $out[] = $name;
    }
    return array_values(array_unique($out));
};

/** Keep third-party scripts and ads off pages that hold decryption keys. */
$privacyMode = function () use (&$ga, &$additional_scripts, &$ads_1, &$ads_2, &$text_ads) {
    $GLOBALS['no_third_party'] = true;
    $ga = $additional_scripts = $ads_1 = $ads_2 = $text_ads = '';
    paste_privacy_headers();
};

if (is_banned($pdo, $ip)) {
    if ($wantsJson) $json(['success' => false, 'error' => $lang['banned'] ?? 'You are banned from this site.'], 403);
    paste_error($lang['banned'] ?? 'You are banned from this site.', 403);
}

if ((int)$settings['img_enabled'] !== 1 && !$isAdmin) {
    if ($wantsJson) $json(['success' => false, 'error' => 'Uploads are disabled.'], 404);
    paste_error('Uploads are disabled on this site.', 404);
}

/* ---- POST: delete (by stored slug, or by link name) ---- */
if ($method === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $slug = is_string($_POST['slug'] ?? null) ? $_POST['slug'] : '';
    $row = img_row($pdo, $slug) ?? img_find($pdo, $slug);
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $fail($lang['invalidtoken'] ?? 'Your session expired. Reload the page and try again.', 403);
    }
    if (!$row || !$canDelete($row)) {
        $fail('You cannot delete that upload.', 403);
    }
    img_delete($pdo, $row['slug']);
    $_SESSION['img_flash'] = 'Upload deleted.';
    header('Location: ' . img_home_url($baseurl), true, 303);
    exit;
}

/* ---- POST: keep the links this browser remembers in the account ---- */
if ($method === 'POST' && ($_POST['action'] ?? '') === 'keep') {
    $wantsJson = true;
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $fail($lang['invalidtoken'] ?? 'Your session expired. Reload the page and try again.', 403);
    }
    if ($username === null) $fail('Log in to keep links in your account.', 403);
    $kept = 0;
    foreach (array_slice((array)($_POST['names'] ?? []), 0, 200) as $name) {
        $row = is_string($name) ? img_find($pdo, $name) : null;
        if ($row !== null && $row['account'] === $username && img_keep_link($pdo, $name, true)) $kept++;
    }
    $json(['success' => true, 'kept' => $kept]);
}

/* ---- POST: make an album, or add uploads to one ---- */
if ($method === 'POST' && in_array($_POST['action'] ?? '', ['album_create', 'album_add'], true)) {
    $wantsJson = true;
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $fail($lang['invalidtoken'] ?? 'Your session expired. Reload the page and try again.', 403);
    }
    if (!$canUpload) $fail('Log in to make albums.', 403);
    $names = $ownUploadNames();

    if ($_POST['action'] === 'album_add') {
        $album = img_album_find($pdo, is_string($_POST['album'] ?? null) ? $_POST['album'] : '');
        if ($album === null || !$canManageAlbum($album)) $fail('That album does not exist or is not yours.', 404);
        $max = img_album_limit($settings, $album['account'] !== 'Guest');
        $all = array_values(array_unique(array_merge($album['_meta']['items'], $names)));
        if ($max === 0 || count($all) > $max) {
            $fail('An album can hold up to ' . $max . ' uploads.', 422);
        }
        img_album_save($pdo, $album, $album['_meta']['title'], $album['_meta']['desc'], $all);
        $json(['success' => true, 'url' => $albumUrl($album['_name']), 'count' => count($all)]);
    }

    $max = img_album_limit($settings, $username !== null);
    if ($max === 0) $fail('Albums are turned off' . ($username === null ? ' for guests. Log in to make one.' : '.'), 403);
    if (count($names) > $max) {
        $fail('An album can hold up to ' . $max . ' uploads' . ($username === null ? ' for guests. Log in for more.' : '.'), 422);
    }
    $expiry = is_string($_POST['expiry'] ?? null) && isset(IMG_EXPIRY[$_POST['expiry']]) ? $_POST['expiry'] : 'N';
    $keep = $username !== null && ($_POST['keep'] ?? '') === '1';
    try {
        $albumName = img_album_create($pdo, $names, img_clean_title($_POST['title'] ?? null), img_clean_desc($_POST['desc'] ?? null),
            $account, $ip, $expiry, $keep);
    } catch (Throwable $e) {
        error_log('img album: ' . $e->getMessage());
        $fail('The album could not be saved. Please try again.', 500);
    }
    if ($username === null) {
        $_SESSION['img_albums'] = array_slice(array_merge((array)($_SESSION['img_albums'] ?? []), [$albumName]), -20);
    }
    $json(['success' => true, 'url' => $albumUrl($albumName), 'key' => $albumName, 'count' => count($names)], 201);
}

/* ---- POST: change, reorder, trim or delete an album ---- */
if ($method === 'POST' && in_array($_POST['action'] ?? '', ['album_edit', 'album_move', 'album_remove', 'album_delete'], true)) {
    $album = img_album_find($pdo, is_string($_POST['album'] ?? null) ? $_POST['album'] : '');
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $fail($lang['invalidtoken'] ?? 'Your session expired. Reload the page and try again.', 403);
    }
    if ($album === null || !$canManageAlbum($album)) $fail('That album does not exist or is not yours.', 404);

    if ($_POST['action'] === 'album_delete') {
        $withUploads = ($_POST['uploads'] ?? '') === '1';
        img_album_delete($pdo, $album, $withUploads, $canDelete);
        $_SESSION['img_flash'] = $withUploads ? 'Album and its uploads deleted.' : 'Album deleted. Its uploads are still at their own links.';
        header('Location: ' . img_home_url($baseurl), true, 303);
        exit;
    }
    $meta = $album['_meta'];
    if ($_POST['action'] === 'album_edit') {
        $meta['title'] = img_clean_title($_POST['title'] ?? null);
        $meta['desc'] = img_clean_desc($_POST['desc'] ?? null);
        $_SESSION['img_flash'] = 'Album saved.';
    } elseif ($_POST['action'] === 'album_move') {
        // One place up or down
        $item = is_string($_POST['item'] ?? null) ? $_POST['item'] : '';
        $at = array_search($item, $meta['items'], true);
        $to = $at === false ? false : $at + (($_POST['dir'] ?? '') === 'up' ? -1 : 1);
        if ($to !== false && isset($meta['items'][$to])) {
            [$meta['items'][$at], $meta['items'][$to]] = [$meta['items'][$to], $meta['items'][$at]];
        }
    } else {
        $item = is_string($_POST['item'] ?? null) ? $_POST['item'] : '';
        $meta['items'] = array_values(array_filter($meta['items'], static fn($n) => $n !== $item));
        $_SESSION['img_flash'] = 'Removed from the album. The upload is still at its own link.';
    }
    img_album_save($pdo, $album, $meta['title'], $meta['desc'], $meta['items']);
    $anchor = $_POST['action'] === 'album_move' ? '#item-' . (is_string($_POST['item'] ?? null) ? $_POST['item'] : '') : '';
    header('Location: ' . $albumUrl($album['_name']) . $anchor, true, 303);
    exit;
}

/* ---- POST: report ---- */
if ($method === 'POST' && ($_POST['action'] ?? '') === 'report') {
    $wantsJson = true;
    $slug = is_string($_POST['slug'] ?? null) ? $_POST['slug'] : '';
    $reason = trim(is_string($_POST['reason'] ?? null) ? $_POST['reason'] : '');
    $pass = is_string($_POST['pass'] ?? null) ? $_POST['pass'] : '';
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $fail($lang['invalidtoken'] ?? 'Your session expired. Reload the page and try again.', 403);
    }
    $row = img_find($pdo, $slug);
    if (!$row) {
        $fail('That upload no longer exists.', 404);
    }
    if ($reason === '') {
        $fail('Say what is wrong with this upload.', 422);
    }
    // A link-encrypted upload was reported from its link, so the report carries the key
    if ($row['encrypted'] === FILECRYPT_MODE) {
        $pass = (string)$row['_name'];
    }
    if (!img_add_report($pdo, $row['slug'], $reason, $pass, $ip)) {
        $fail('You have sent a lot of reports. Please try again later.', 429);
    }
    $json(['success' => true]);
}

/* ---- POST: upload ---- */
if ($method === 'POST') {
    $declared = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (empty($_POST) && empty($_FILES) && $limits['php'] > 0 && $declared > $limits['php']) {
        $fail('That file is larger than the server accepts (' . img_format_bytes($limits['php']) . ').', 413);
    }
    if (!$canUpload) {
        $fail('Log in to upload files.', 403);
    }
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $fail($lang['invalidtoken'] ?? 'Your session expired. Reload the page and try again.', 403);
    }

    $encrypted = ($_POST['encrypted'] ?? '') === '1';
    if (!$encrypted && ($_POST['encrypt_requested'] ?? '') === '1') {
        $fail('Encryption happens in your browser and needs JavaScript. Turn on JavaScript, or untick "Encrypt" to upload publicly.', 422);
    }
    if ($username === null && ($human = img_verify_human()) !== null) {
        $fail($human, 403);
    }

    $exempt = $isAdmin || ($username !== null && spam_member_is_exempt($pdo, $username));
    if (!$exempt && img_ensure_table($pdo)) {
        $perHour = (int)$settings['img_per_hour'];
        if ($perHour > 0 && img_recent_count($pdo, $account, $ip) >= $perHour) {
            $fail('You have reached the upload limit of ' . $perHour . ' per hour. Please try again later.', 429);
        }
        $quota = (int)$settings['img_quota_mb'] * 1048576;
        $incoming = (int)($_FILES['file']['size'] ?? 0) + (int)($_FILES['thumb']['size'] ?? 0);
        if ($quota > 0 && img_recent_bytes($pdo, $account, $ip) + $incoming > $quota) {
            $fail('You have reached the daily upload allowance of ' . img_format_bytes($quota) . '. Please try again tomorrow.', 429);
        }
    }

    $expiry = is_string($_POST['expiry'] ?? null) && isset(IMG_EXPIRY[$_POST['expiry']]) ? $_POST['expiry'] : 'N';

    try {
        if ($encrypted) {
            $row = img_store_encrypted($pdo, (array)($_FILES['file'] ?? []),
                isset($_FILES['thumb']) ? (array)$_FILES['thumb'] : null,
                is_string($_POST['meta'] ?? null) ? $_POST['meta'] : '',
                is_string($_POST['kind'] ?? null) ? $_POST['kind'] : '',
                $account, $ip, $expiry, $settings);
        } else {
            $strip = ($_POST['strip_exif'] ?? '') === '1';
            $row = img_store_upload($pdo, (array)($_FILES['file'] ?? []), $account, $ip, $strip, $expiry, $settings,
                img_clean_title($_POST['title'] ?? null));
        }
    } catch (ImgUploadError $e) {
        $fail($e->getMessage(), 422);
    } catch (Throwable $e) {
        error_log('img upload: ' . $e->getMessage());
        $fail('The upload could not be stored. Please try again.', 500);
    }

    img_sweep($pdo);

    if ($username === null) {
        $mine = (array)($_SESSION['img_uploads'] ?? []);
        $mine[] = $row['slug'];
        $_SESSION['img_uploads'] = array_slice($mine, -50);
    }

    $linkName = (string)$row['_name'];
    if ($username !== null && $row['encrypted'] === FILECRYPT_MODE) {
        img_keep_link($pdo, $linkName, ($_POST['keep'] ?? '') === '1');
    }
    $page = img_page_url($linkName, $baseurl, $rewrite);
    if ($wantsJson) {
        $json([
            'success'   => true,
            'slug'      => $row['slug'],
            'key'       => $row['encrypted'] === FILECRYPT_MODE ? $linkName : null,
            'url'       => $page,
            'file'      => img_file_url($linkName, $row['ext'], $baseurl, $rewrite),
            'ext'       => $row['ext'],
            'encrypted' => $row['encrypted'] === 1,
            'kind'      => $row['encrypted'] === 1 ? null : (IMG_TYPES[$row['mime']][0] ?? 'file'),
            'name'      => $row['encrypted'] === 1 ? null : img_display_name($row),
            'title'     => $row['encrypted'] === 1 ? null : img_display_title($row),
            'size'      => (int)$row['size'],
        ], 201);
    }
    header('Location: ' . $page, true, 303);
    exit;
}

/* ---- GET: view page of an upload or an album ---- */
if (isset($_GET['v'])) {
    $v = is_string($_GET['v']) ? $_GET['v'] : '';
    $row = img_find($pdo, $v);
    $album = $row === null ? img_album_find($pdo, $v) : null;
    if (!$row && !$album) {
        paste_error($lang['notfound'] ?? 'That upload does not exist or has expired.', 404);
    }
    if ($album) {
        // The page address is the album's key
        $privacyMode();
        $uploads = img_album_uploads($pdo, $album);
        $canManage = $canManageAlbum($album);
        // Uploads deleted since are dropped from the album when its maker opens it
        if ($canManage && count($uploads) !== count($album['_meta']['items'])) {
            $album['_meta']['items'] = array_map(static fn($r) => (string)$r['_name'], $uploads);
            img_album_save($pdo, $album, $album['_meta']['title'], $album['_meta']['desc'], $album['_meta']['items']);
        }
        if ($username !== null && $album['account'] === $username && ($album['owner_key'] ?? null) === null) {
            $pdo->prepare("UPDATE img_albums SET owner_key = ? WHERE slug = ? AND owner_key IS NULL")
                ->execute([img_owner_seal($album['_name']), $album['slug']]);
        }
        $flash = (string)($_SESSION['img_flash'] ?? '');
        unset($_SESSION['img_flash']);
        $p_title = img_album_title($album);
        $render('img_album.php', [
            'img_album'      => $album,
            'img_uploads'    => $uploads,
            'img_album_url'  => $albumUrl($album['_name']),
            'img_can_manage' => $canManage,
            'img_flash'      => $flash,
            'img_owner_url'  => $album['account'] !== 'Guest'
                ? ($rewrite ? $baseurl . 'user/' . rawurlencode($album['account']) : $baseurl . 'user.php?user=' . rawurlencode($album['account']))
                : null,
            'img_add_url'    => img_home_url($baseurl) . '?album=' . rawurlencode($album['_name']),
            'img_album_max'  => img_album_limit($settings, $album['account'] !== 'Guest'),
        ]);
    }
    $linkName = (string)$row['_name'];
    // Uploads made before links were kept for accounts are kept when their owner opens them
    if ($username !== null && $row['account'] === $username && $row['encrypted'] === FILECRYPT_MODE
        && ($row['owner_key'] ?? null) === null) {
        img_keep_link($pdo, $linkName, true);
    }
    $vars = [
        'img_row'        => $row,
        'img_encrypted'  => $row['encrypted'] === 1,
        'img_link_key'   => $row['encrypted'] === FILECRYPT_MODE,
        'img_name'       => $linkName,
        'img_title'      => img_display_title($row) ?? img_display_name($row),
        'img_page'       => img_page_url($linkName, $baseurl, $rewrite),
        'img_file'       => img_file_url($linkName, $row['ext'], $baseurl, $rewrite),
        'img_thumb'      => (int)($row['thumb_size'] ?? 0) > 0 ? img_file_url($row['slug'], 'thumb', $baseurl, $rewrite) : '',
        'img_can_delete' => $canDelete($row),
        'img_owner_url'  => $row['account'] !== 'Guest'
            ? ($rewrite ? $baseurl . 'user/' . rawurlencode($row['account']) : $baseurl . 'user.php?user=' . rawurlencode($row['account']))
            : null,
        'img_kind'       => null,
        'img_dims'       => null,
        'img_playable'   => false,
    ];
    if ($row['encrypted'] === 1) {
        $privacyMode();
        $p_title = 'Encrypted upload';
    } else {
        // The page address is the key for link-encrypted uploads, so it gets the same protection
        if ($row['encrypted'] === FILECRYPT_MODE) {
            $privacyMode();
        }
        $vars['img_kind'] = IMG_TYPES[$row['mime']][0] ?? 'file';
        $vars['img_playable'] = in_array($row['mime'], IMG_PLAYABLE, true);
        if ($vars['img_kind'] === 'image') {
            $stored = filehost_storage_dir() . '/' . $row['slug'];
            $head = $row['encrypted'] === FILECRYPT_MODE
                ? filecrypt_read_head($stored . '.enc', $linkName)
                : @file_get_contents($stored . '.' . $row['ext'], false, null, 0, 262144);
            $info = is_string($head) ? @getimagesizefromstring($head) : false;
            if ($info) $vars['img_dims'] = [(int)$info[0], (int)$info[1]];
        }
        $p_title = $vars['img_title'] ?: ($vars['img_kind'] === 'video' ? 'Video' : 'Image');
    }
    $render('img_view.php', $vars);
}

/* ---- GET: upload form ---- */
$flash = (string)($_SESSION['img_flash'] ?? '');
unset($_SESSION['img_flash']);
$perPage = 60;
$page = max(1, (int)($_GET['page'] ?? 1));
if ($username !== null) {
    $recentTotal = img_user_upload_count($pdo, $username);
    $page = min($page, max(1, (int)ceil($recentTotal / $perPage)));
    $recent = img_user_uploads($pdo, $username, $perPage, ($page - 1) * $perPage);
    $albums = img_user_albums($pdo, $username);
} else {
    $recent = img_uploads_by_slugs($pdo, (array)($_SESSION['img_uploads'] ?? []));
    $recentTotal = count($recent);
    $albums = img_albums_by_names($pdo, (array)($_SESSION['img_albums'] ?? []));
}
$addAlbum = null;
if (isset($_GET['album'])) {
    $addAlbum = img_album_find($pdo, is_string($_GET['album']) ? $_GET['album'] : '');
    if ($addAlbum === null || !$canManageAlbum($addAlbum)) {
        paste_error('That album does not exist or is not yours.', 404);
    }
}
$quietPage();
$p_title = $addAlbum ? 'Add to ' . img_album_title($addAlbum) : ($lang['img_upload_title'] ?? 'Upload images and videos');
$render('img_upload.php', [
    'img_flash'        => $flash,
    'img_recent'       => $recent,
    'img_recent_total' => $recentTotal,
    'img_page'         => $page,
    'img_pages'        => max(1, (int)ceil($recentTotal / $perPage)),
    'img_albums'       => $albums,
    'img_album_max'    => img_album_limit($settings, $username !== null),
    'img_add_album'    => $addAlbum,
    'img_album_url'    => $albumUrl,
]);
