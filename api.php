<?php
/*
 * Paste API v1.1
 *
 * Endpoints:
 *   POST   /api.php?action=paste                  Create a paste
 *   GET    /api.php?action=get&id=X               Get a paste (format=raw for the text only)
 *   POST   /api.php?action=update&id=X            Update a paste (PUT and PATCH also work)
 *   GET    /api.php?action=list                   List your pastes
 *   DELETE /api.php?action=delete&id=X            Delete a paste
 *   GET    /api.php?action=search&q=X             Search pastes
 *   GET    /api.php?action=user                   Your account and this key's scopes
 *   GET    /api.php?action=languages              Syntax languages (no key needed)
 *   POST   /api.php?action=upload                 Upload an image or video (multipart field "file")
 *   DELETE /api.php?action=upload_delete&key=X    Delete one of your uploads
 *
 * Authentication: an API key in the X-API-Key header, an Authorization:
 * Bearer header, or an api_key parameter (query string, form or JSON body).
 * When Admin > Configuration > API allows it, paste and upload also work
 * without a key, as Guest.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

define('PASTE_JSON_ERRORS', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/api_keys.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, X-Paste-Password');
header('Access-Control-Expose-Headers: X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/** Expiry codes accepted for pastes and uploads: input => canonical code. */
const API_EXPIRY_CODES = [
    'N' => 'N', 'NULL' => 'N', 'NEVER' => 'N', '' => 'N',
    '10M' => '10M', 'T' => '10M',
    '1H' => '1H', 'H' => '1H',
    '1D' => '1D', 'D' => '1D',
    '1W' => '1W', 'W' => '1W',
    '2W' => '2W',
    '1M' => '1M', 'M' => '1M',
    '6M' => '6M', 'B' => '6M',
    '1Y' => '1Y', 'Y' => '1Y',
    'SELF' => 'SELF',
];

/** Canonical paste expiry code => code paste_expiry_value() takes. */
const API_EXPIRY_STORE = [
    'N' => 'NULL', '10M' => '10M', '1H' => '1H', '1D' => '1D', '1W' => '1W',
    '2W' => '2W', '1M' => '1M', '6M' => 'B', '1Y' => 'Y', 'SELF' => 'SELF',
];

/** Visibility names => stored value. */
const API_VISIBILITY = ['public' => '0', 'unlisted' => '1', 'private' => '2'];

/** Scope each action needs. */
const API_ACTION_SCOPES = [
    'paste' => 'paste', 'create' => 'paste', 'update' => 'paste', 'delete' => 'paste',
    'get' => 'read', 'list' => 'read', 'search' => 'read', 'user' => 'read', 'me' => 'read',
    'upload' => 'upload', 'upload_delete' => 'upload', 'album' => 'upload',
];

/** Wrong paste passwords allowed per key in ten minutes. */
const API_PASSWORD_TRIES = 10;

/** Send a JSON response and exit. */
function apiResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Send an error response and exit. */
function apiError(string $message, int $status = 400): never
{
    apiResponse(['success' => false, 'error' => $message], $status);
}

// Uncaught errors are logged and answered as JSON
set_exception_handler(static function (Throwable $e): void {
    error_log('api.php: ' . get_class($e) . ': ' . $e->getMessage());
    apiError('The server could not complete the request', 500);
});

/** A paste_options value, or $default. */
function apiOption(PDO $pdo, string $name, string $default): string
{
    try {
        $stmt = $pdo->prepare("SELECT option_value FROM paste_options WHERE option_name = ?");
        $stmt->execute([$name]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? $default : (string)$value;
    } catch (PDOException $e) {
        return $default;
    }
}

/** Request body fields: the decoded JSON body, or the form fields. */
function apiInput(): array
{
    static $input = null;
    if ($input === null) {
        if (str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            $decoded = json_decode((string)file_get_contents('php://input'), true);
            $input = is_array($decoded) ? $decoded : [];
        } else {
            $input = $_POST;
        }
    }
    return $input;
}

/** A request header by name, case-insensitive, or ''. */
function apiHeader(string $name): string
{
    $server = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (!empty($_SERVER[$server])) {
        return (string)$_SERVER[$server];
    }
    if ($name === 'Authorization' && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (function_exists('getallheaders')) {
        foreach ((array)getallheaders() as $k => $v) {
            if (strcasecmp((string)$k, $name) === 0) {
                return (string)$v;
            }
        }
    }
    return '';
}

/** The API key sent with the request, or null. */
function getApiKey(): ?string
{
    $key = apiHeader('X-API-Key');
    if ($key !== '') {
        return $key;
    }
    if (preg_match('/^Bearer\s+(\S+)\s*$/i', apiHeader('Authorization'), $m)) {
        return $m[1];
    }
    foreach ([$_GET, $_POST, apiInput()] as $src) {
        if (isset($src['api_key']) && is_string($src['api_key']) && $src['api_key'] !== '') {
            return $src['api_key'];
        }
    }
    return null;
}

/** The active key's details, with its user, or null. Banned accounts' keys do not work. */
function validateApiKey(PDO $pdo, string $apiKey): ?array
{
    try {
        $key = api_key_lookup($pdo, $apiKey);
        if ($key === null || (string)($key['verified'] ?? '') === '2' || paste_is_guest_name((string)$key['username'])) {
            return null;
        }
        $pdo->prepare("UPDATE api_keys SET last_used_at = NOW() WHERE id = ?")->execute([$key['key_id']]);
        return $key;
    } catch (PDOException $e) {
        error_log("API key validation error: " . $e->getMessage());
        return null;
    }
}

/** String field from a decoded request body. */
function apiInputString(array $input, array $keys, string $default = ''): string
{
    foreach ($keys as $k) {
        if (isset($input[$k]) && is_scalar($input[$k])) {
            return (string)$input[$k];
        }
    }
    return $default;
}

/** Refuse a field that is not valid UTF-8. */
function apiRequireUtf8(string $value, string $field): void
{
    if (!mb_check_encoding($value, 'UTF-8')) {
        apiError(ucfirst($field) . ' must be UTF-8 text', 400);
    }
}

/** Stored (HTML-escaped) paste text back to what the author wrote. */
function apiPlain(string $stored): string
{
    return htmlspecialchars_decode($stored, ENT_QUOTES);
}

/** A value encrypted at rest, decrypted; the stored value when it does not decrypt. */
function apiDecrypt(string $stored): string
{
    if (!defined('SECRET') || SECRET === '') {
        return $stored;
    }
    $plain = decrypt($stored, hex2bin(SECRET));
    return $plain ?? $stored;
}

/** Canonical expiry code for an input code, or null when it is not one. */
function apiExpiryCode(string $input): ?string
{
    return API_EXPIRY_CODES[strtoupper(trim($input))] ?? null;
}

/** Refuse an unknown expiry code; return the canonical one. */
function apiRequireExpiry(string $input, array $allowed): string
{
    $code = apiExpiryCode($input);
    if ($code === null || !in_array($code, $allowed, true)) {
        apiError('Unknown expiry "' . $input . '". Use one of: ' . implode(', ', $allowed), 400);
    }
    return $code;
}

/** ISO 8601 time a stored paste expiry ends, or null for never and view-once. */
function apiExpiresAt(?string $stored): ?string
{
    $stored = trim((string)$stored);
    return ctype_digit($stored) && (int)$stored > 0 ? gmdate('c', (int)$stored) : null;
}

/** Stored visibility for an input name or number, or null when it is not one. */
function apiVisibility(string $input): ?string
{
    $input = strtolower(trim($input));
    if (isset(API_VISIBILITY[$input])) {
        return API_VISIBILITY[$input];
    }
    return in_array($input, API_VISIBILITY, true) ? $input : null;
}

/** Visibility name for a stored value. */
function apiVisibilityName(string $stored): string
{
    return array_search($stored, API_VISIBILITY, true) ?: 'public';
}

/**
 * A paste by id or slug. Numeric ids only find the caller's own pastes when
 * the site blocks numeric URLs, so unlisted pastes cannot be enumerated.
 */
function apiFindPaste(PDO $pdo, string $id, array $user): ?array
{
    $paste = getPasteByIdentifier($pdo, $id);
    if ($paste && ctype_digit(trim($id)) && shouldBlockNumericUrls($pdo) && $paste['member'] !== $user['username']) {
        return null;
    }
    return $paste;
}

/** The paste password sent with the request: X-Paste-Password header, body, or query. */
function apiPastePassword(): string
{
    $header = apiHeader('X-Paste-Password');
    if ($header !== '') {
        return $header;
    }
    $body = apiInputString(apiInput(), ['password']);
    if ($body !== '') {
        return $body;
    }
    return is_string($_GET['password'] ?? null) ? $_GET['password'] : '';
}

/** Generate a unique paste slug. */
function generateApiSlug(PDO $pdo, int $length = 8): string
{
    $charset = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $charsetLen = strlen($charset);
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM pastes WHERE slug = ?");

    do {
        $slug = '';
        for ($i = 0; $i < $length; $i++) {
            $slug .= $charset[random_int(0, $charsetLen - 1)];
        }
        $checkStmt->execute([$slug]);
    } while ((int)$checkStmt->fetchColumn() > 0);

    return $slug;
}

/** Page and limit from the query (page, or offset), clamped to $max per page. */
function apiPaging(int $max): array
{
    $limit = min($max, max(1, (int)($_GET['limit'] ?? 20)));
    if (isset($_GET['offset']) && !isset($_GET['page'])) {
        $offset = max(0, (int)$_GET['offset']);
        return [intdiv($offset, $limit) + 1, $limit, $offset];
    }
    $page = max(1, (int)($_GET['page'] ?? 1));
    return [$page, $limit, ($page - 1) * $limit];
}

// ============================================================================
// Main API Logic
// ============================================================================

try {
    $pdo = new PDO(
        "mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4",
        $dbuser,
        $dbpassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    apiError('Database connection failed', 500);
}

if (apiOption($pdo, 'api_enabled', '1') === '0') {
    apiError('API is currently disabled', 503);
}

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

// Actions that don't require authentication
$publicActions = ['languages'];

// Actions guests may use without a key, when the admin allows it
$guestActions = [];
if (apiOption($pdo, 'api_guest_pastes', '0') === '1') {
    array_push($guestActions, 'paste', 'create');
}
if (apiOption($pdo, 'api_guest_uploads', '0') === '1') {
    $guestActions[] = 'upload';
}

$user = null;
if (!in_array($action, $publicActions, true)) {
    api_keys_ensure($pdo);
    $apiKey = getApiKey();
    if (!$apiKey && in_array($action, $guestActions, true)) {
        $user = ['key_id' => null, 'user_id' => null, 'username' => 'Guest', 'email' => null, 'scopes' => null, 'guest' => true];
    } elseif (!$apiKey) {
        apiError('API key required. Provide via X-API-Key header, Authorization: Bearer header, or api_key parameter.', 401);
    } else {
        $user = validateApiKey($pdo, $apiKey);
        if (!$user) {
            apiError('Invalid or inactive API key', 401);
        }
        $user['guest'] = false;
    }

    $scope = API_ACTION_SCOPES[$action] ?? null;
    if ($scope !== null && !api_key_can($user, $scope)) {
        apiError('This API key cannot use action=' . $action . ' (it needs the "' . $scope . '" scope)', 403);
    }

    // Keys are limited per key; guests per IP address
    $rateLimit = $user['guest']
        ? max(0, (int)apiOption($pdo, 'api_guest_rate_limit', '10'))
        : max(0, (int)apiOption($pdo, 'api_rate_limit', '60'));
    $bucket = $user['guest'] ? 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? '') : 'key:' . $user['key_id'];
    if ($rateLimit > 0) {
        $hits = api_rate($pdo, $bucket, 60);
        if ($hits !== null) {
            header('X-RateLimit-Limit: ' . $rateLimit);
            header('X-RateLimit-Remaining: ' . max(0, $rateLimit - $hits));
            header('X-RateLimit-Reset: ' . api_rate_reset(60));
            if ($hits > $rateLimit) {
                header('Retry-After: ' . max(1, api_rate_reset(60) - time()));
                apiError('Rate limit exceeded: ' . $rateLimit . ' requests per minute', 429);
            }
        }
    }
}

if (is_banned($pdo, (string)($_SERVER['REMOTE_ADDR'] ?? ''))) {
    apiError('Access denied', 403);
}

$stmt = $pdo->query("SELECT baseurl FROM site_info WHERE id = 1");
$siteInfo = $stmt->fetch() ?: [];
$baseurl = rtrim($siteInfo['baseurl'] ?? '', '/') . '/';

$mod_rewrite = ((string)($mod_rewrite ?? '0') === '1');

$urlMode = getPasteUrlMode($pdo);
$slugLength = getPasteSlugLength($pdo);

/** Page URL of a paste. */
$pasteUrl = static fn(string $identifier): string => $mod_rewrite ? $baseurl . $identifier : $baseurl . 'paste.php?id=' . $identifier;

switch ($action) {
    // ========================================================================
    // CREATE PASTE
    // ========================================================================
    case 'paste':
    case 'create':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            apiError('POST method required', 405);
        }

        $input = apiInput();
        $content = apiInputString($input, ['content']);
        $title = trim(apiInputString($input, ['title']));
        $syntax = apiInputString($input, ['syntax', 'language', 'code'], 'text');
        $visibilityIn = apiInputString($input, ['visibility', 'visible'], 'public');
        $expiryIn = apiInputString($input, ['expiry', 'expire'], 'N');
        $password = apiInputString($input, ['password']);

        apiRequireUtf8($content, 'content');
        apiRequireUtf8($title, 'title');
        if (trim($content) === '') {
            apiError('Content is required');
        }
        if (strlen($content) > 1024 * 1024 * (float)($pastelimit ?? 10)) {
            apiError('Content is too large', 413);
        }
        if ($title === '') {
            $title = 'Untitled';
        }
        if (!preg_match('/^[A-Za-z0-9._+#-]{1,50}$/', $syntax)) {
            $syntax = 'text';
        }

        $p_visible = apiVisibility($visibilityIn);
        if ($p_visible === null) {
            apiError('Unknown visibility "' . $visibilityIn . '". Use public, unlisted or private', 400);
        }
        if ($user['guest']) {
            $perm = $pdo->query("SELECT disableguest FROM site_permissions WHERE id = 1")->fetch() ?: [];
            if (trim((string)($perm['disableguest'] ?? 'off')) === 'on') {
                apiError('Only registered users can paste on this site. Send an API key.', 403);
            }
            if ($p_visible === '2') {
                apiError('Private pastes need an API key', 400);
            }
        }
        $expiry = apiRequireExpiry($expiryIn, array_keys(API_EXPIRY_STORE));
        $expires = paste_expiry_value(API_EXPIRY_STORE[$expiry]);

        // Stored like web pastes: HTML-escaped, content encrypted at rest
        if (!defined('SECRET') || SECRET === '') {
            apiError('Server configuration error', 500);
        }
        $p_title = paste_store_title($title) ?: 'Untitled';
        $title = apiPlain($p_title);
        $p_content = encrypt(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'), hex2bin(SECRET));
        $p_encrypt = '1';
        $p_password = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : 'NONE';

        $slug = $urlMode === 'slug' ? generateApiSlug($pdo, $slugLength) : null;

        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Spam filter (scans plaintext title/content)
        $verdict = spam_evaluate($pdo, $ip, (string)$user['username'], $title, $content, $user['guest'], 'api', null,
            $p_visible === '2' || $p_password !== 'NONE');
        if ($verdict['action'] === 'block') {
            apiError('Paste rejected: flagged as spam', 422);
        }
        if ($verdict['action'] === 'hold') {
            $row = [
                'slug'     => $slug,
                'title'    => $p_title,
                'content'  => $p_content,
                'visible'  => $p_visible,
                'code'     => $syntax,
                'expiry'   => $expires,
                'password' => $p_password,
                'encrypt'  => $p_encrypt,
                'member'   => (string)$user['username'],
                'date'     => $now,
                'ip'       => $ip,
                'now_time' => (string)time(),
                's_date'   => $today,
            ];
            if ($slug === null) unset($row['slug']);
            if (spam_enqueue($pdo, $row, $ip, (string)$user['username'], (int)$verdict['score'], $verdict['reasons'] ?? [], $title, mb_substr($content, 0, 500))) {
                apiResponse([
                    'success' => true,
                    'status'  => 'pending_review',
                    'message' => 'Paste accepted and is awaiting moderator approval.',
                    'url'     => $slug ? $pasteUrl($slug) : null,
                ], 202);
            }
        }

        if ($slug) {
            $stmt = $pdo->prepare("
                INSERT INTO pastes (slug, title, content, visible, code, expiry, password, encrypt, member, date, ip, now_time, s_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP(), ?)
            ");
            $stmt->execute([$slug, $p_title, $p_content, $p_visible, $syntax, $expires, $p_password, $p_encrypt, $user['username'], $now, $ip, $today]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO pastes (title, content, visible, code, expiry, password, encrypt, member, date, ip, now_time, s_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP(), ?)
            ");
            $stmt->execute([$p_title, $p_content, $p_visible, $syntax, $expires, $p_password, $p_encrypt, $user['username'], $now, $ip, $today]);
        }

        $pasteId = (int)$pdo->lastInsertId();
        $identifier = $slug ?: (string)$pasteId;

        // A view-once paste is gone after its second viewer; the author counts as the first, as on the website
        if ($expiry === 'SELF') {
            updateMyView($pdo, $pasteId);
        }

        apiResponse([
            'success' => true,
            'paste' => [
                'id' => $pasteId,
                'slug' => $slug,
                'url' => $pasteUrl($identifier),
                'raw_url' => $mod_rewrite ? $baseurl . 'raw/' . $identifier : $baseurl . 'paste.php?raw&id=' . $identifier,
                'title' => $title,
                'syntax' => $syntax,
                'visibility' => apiVisibilityName($p_visible),
                'expiry' => $expiry,
                'expires_at' => apiExpiresAt($expires),
                'view_once' => $expiry === 'SELF',
                'password_protected' => $p_password !== 'NONE',
                'encrypted' => false,
                'encrypted_at_rest' => true,
                'created_at' => $now
            ]
        ], 201);

    // ========================================================================
    // GET PASTE
    // ========================================================================
    case 'get':
        $id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
        if ($id === '') {
            apiError('Paste ID required');
        }

        $paste = apiFindPaste($pdo, $id, $user);
        if (!$paste || paste_is_expired($paste['expiry'] ?? null)) {
            apiError('Paste not found', 404);
        }

        if ($paste['visible'] === '2' && $paste['member'] !== $user['username']) {
            apiError('Access denied', 403);
        }

        if ($paste['password'] !== 'NONE') {
            $bucket = 'pw:' . $user['key_id'];
            $fails = api_rate($pdo, $bucket, 600, false);
            if ($fails !== null && $fails >= API_PASSWORD_TRIES) {
                header('Retry-After: ' . max(1, api_rate_reset(600) - time()));
                apiError('Too many wrong passwords. Try again in a few minutes.', 429);
            }
            $given = apiPastePassword();
            if ($given === '') {
                apiError('This paste needs a password', 401);
            }
            if (!password_verify($given, $paste['password'])) {
                api_rate($pdo, $bucket, 600);
                apiError('Wrong password', 401);
            }
        }

        $clientEncrypted = $paste['encrypt'] === '2';
        $content = $paste['encrypt'] === '1' ? apiDecrypt((string)$paste['content']) : (string)$paste['content'];
        $title = apiPlain($paste['encrypt'] === '1' ? apiDecrypt((string)$paste['title']) : (string)$paste['title']);
        if (!$clientEncrypted) {
            $content = apiPlain($content);
        }

        $format = (string)($_GET['format'] ?? 'json');
        if ($format === 'raw' && $clientEncrypted) {
            apiError('This paste was encrypted in the browser; only its link can decrypt it', 422);
        }

        // View-once pastes count this read and are deleted after their second viewer
        $viewOnce = trim((string)$paste['expiry']) === 'SELF';
        $deleted = false;
        if ($viewOnce) {
            updateMyView($pdo, (int)$paste['id']);
            if (getPasteViewCount($pdo, (int)$paste['id']) >= 2) {
                $deleted = deleteMyPaste($pdo, (int)$paste['id']);
            }
        }

        if ($format === 'raw') {
            header('Content-Type: text/plain; charset=utf-8');
            echo $content;
            exit;
        }

        $identifier = $paste['slug'] ?: (string)$paste['id'];
        apiResponse([
            'success' => true,
            'paste' => [
                'id' => (int)$paste['id'],
                'slug' => $paste['slug'] ?? null,
                'url' => $pasteUrl($identifier),
                'title' => $title,
                'content' => $content,
                'syntax' => $paste['code'],
                'visibility' => apiVisibilityName((string)$paste['visible']),
                'expiry' => $paste['expiry'],
                'expires_at' => apiExpiresAt($paste['expiry']),
                'view_once' => $viewOnce,
                'deleted' => $deleted,
                'password_protected' => $paste['password'] !== 'NONE',
                'encrypted' => $clientEncrypted,
                'encrypted_at_rest' => $paste['encrypt'] === '1',
                'author' => $paste['member'],
                'created_at' => $paste['date'],
                'views' => $deleted ? 0 : getPasteViewCount($pdo, (int)$paste['id'])
            ]
        ]);

    // ========================================================================
    // LIST USER'S PASTES
    // ========================================================================
    case 'list':
        [$page, $limit, $offset] = apiPaging(100);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pastes WHERE member = ?");
        $stmt->execute([$user['username']]);
        $total = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT id, slug, title, code, visible, expiry, password, encrypt, date
            FROM pastes
            WHERE member = ?
            ORDER BY id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$user['username'], $limit, $offset]);

        $result = [];
        foreach ($stmt->fetchAll() as $paste) {
            $identifier = $paste['slug'] ?: (string)$paste['id'];
            $result[] = [
                'id' => (int)$paste['id'],
                'slug' => $paste['slug'] ?? null,
                'url' => $pasteUrl($identifier),
                'title' => apiPlain($paste['encrypt'] === '1' ? apiDecrypt((string)$paste['title']) : (string)$paste['title']),
                'syntax' => $paste['code'],
                'visibility' => apiVisibilityName((string)$paste['visible']),
                'expiry' => $paste['expiry'],
                'expires_at' => apiExpiresAt($paste['expiry']),
                'expired' => paste_is_expired($paste['expiry'] ?? null),
                'view_once' => trim((string)$paste['expiry']) === 'SELF',
                'password_protected' => $paste['password'] !== 'NONE',
                'encrypted' => $paste['encrypt'] === '2',
                'encrypted_at_rest' => $paste['encrypt'] === '1',
                'created_at' => $paste['date']
            ];
        }

        apiResponse([
            'success' => true,
            'pastes' => $result,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'offset' => $offset,
                'total' => $total,
                'pages' => (int)ceil($total / $limit)
            ]
        ]);

    // ========================================================================
    // DELETE PASTE
    // ========================================================================
    case 'delete':
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            apiError('DELETE or POST method required', 405);
        }

        $id = $_GET['id'] ?? apiInput()['id'] ?? '';
        $id = is_scalar($id) ? (string)$id : '';
        if ($id === '') {
            apiError('Paste ID required');
        }

        $paste = apiFindPaste($pdo, $id, $user);
        if (!$paste) {
            apiError('Paste not found', 404);
        }
        if ($paste['member'] !== $user['username']) {
            apiError('Access denied', 403);
        }

        $stmt = $pdo->prepare("DELETE FROM pastes WHERE id = ?");
        $stmt->execute([$paste['id']]);

        apiResponse(['success' => true, 'message' => 'Paste deleted']);

    // ========================================================================
    // SEARCH PASTES
    // ========================================================================
    case 'search':
        $query = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
        if (strlen($query) < 2) {
            apiError('Search query must be at least 2 characters');
        }
        [$page, $limit, $offset] = apiPaging(50);

        // Public pastes as the archive lists them, plus your own; never expired ones.
        // Titles are searchable everywhere; content only where it is stored unencrypted.
        $like = static fn(string $s): string => '%' . addcslashes($s, '%_\\') . '%';
        $titleTerm = $like(htmlspecialchars($query, ENT_QUOTES, 'UTF-8'));
        $contentTerm = $like($query);
        $where = "((visible = '0' AND password = 'NONE' AND COALESCE(expiry, '') <> 'SELF') OR member = ?)
                  AND (expiry IS NULL OR expiry NOT REGEXP '^[0-9]+$' OR CAST(expiry AS UNSIGNED) = 0 OR CAST(expiry AS UNSIGNED) >= UNIX_TIMESTAMP())
                  AND (title LIKE ? OR (encrypt = '0' AND content LIKE ?))";

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pastes WHERE $where");
        $stmt->execute([$user['username'], $titleTerm, $contentTerm]);
        $total = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT id, slug, title, code, visible, member, date
            FROM pastes
            WHERE $where
            ORDER BY id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$user['username'], $titleTerm, $contentTerm, $limit, $offset]);

        $result = [];
        foreach ($stmt->fetchAll() as $paste) {
            $identifier = $paste['slug'] ?: (string)$paste['id'];
            $result[] = [
                'id' => (int)$paste['id'],
                'slug' => $paste['slug'] ?? null,
                'url' => $pasteUrl($identifier),
                'title' => apiPlain((string)$paste['title']),
                'syntax' => $paste['code'],
                'author' => $paste['member'],
                'created_at' => $paste['date']
            ];
        }

        apiResponse([
            'success' => true,
            'query' => $query,
            'pastes' => $result,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'offset' => $offset,
                'total' => $total,
                'pages' => (int)ceil($total / $limit)
            ]
        ]);

    // ========================================================================
    // LIST LANGUAGES
    // ========================================================================
    case 'languages':
        require_once __DIR__ . '/includes/list_languages.php';

        $highlighter = $highlighter ?? 'highlight';
        if ($highlighter === 'highlight') {
            $languages = highlight_language_map(highlight_supported_languages());
        } else {
            $languages = geshi_language_map();
        }

        apiResponse([
            'success' => true,
            'languages' => $languages
        ]);

    // ========================================================================
    // USER INFO
    // ========================================================================
    case 'user':
    case 'me':
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pastes WHERE member = ?");
        $stmt->execute([$user['username']]);
        $pasteCount = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM api_keys WHERE user_id = ?");
        $stmt->execute([$user['user_id']]);
        $keyCount = (int)$stmt->fetchColumn();

        apiResponse([
            'success' => true,
            'user' => [
                'username' => $user['username'],
                'email' => $user['email'],
                'paste_count' => $pasteCount,
                'api_key_count' => $keyCount,
                'key_scopes' => $user['scopes'] ?? array_keys(API_SCOPES)
            ]
        ]);

    // ========================================================================
    // UPDATE PASTE
    // ========================================================================
    case 'update':
        if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'PATCH'], true)) {
            apiError('POST, PUT, or PATCH method required', 405);
        }

        $input = apiInput();
        $id = $_GET['id'] ?? $input['id'] ?? '';
        $id = is_scalar($id) ? (string)$id : '';
        if ($id === '') {
            apiError('Paste ID required');
        }

        $paste = apiFindPaste($pdo, $id, $user);
        if (!$paste) {
            apiError('Paste not found', 404);
        }
        if ($paste['member'] !== $user['username']) {
            apiError('Access denied', 403);
        }

        $clientEncrypted = ($paste['encrypt'] === '2');
        if ($clientEncrypted && isset($input['content'])) {
            apiError('Client-side encrypted pastes cannot have their content changed through the API');
        }
        if (!defined('SECRET') || SECRET === '') {
            apiError('Server configuration error', 500);
        }

        // Current values as the author wrote them
        $currentContent = $paste['encrypt'] === '1' ? apiDecrypt((string)$paste['content']) : (string)$paste['content'];
        $currentTitle = apiPlain($paste['encrypt'] === '1' ? apiDecrypt((string)$paste['title']) : (string)$paste['title']);
        if (!$clientEncrypted) {
            $currentContent = apiPlain($currentContent);
        }

        $newContent = apiInputString($input, ['content'], $currentContent);
        $newTitle = trim(apiInputString($input, ['title'], $currentTitle));
        apiRequireUtf8($newContent, 'content');
        apiRequireUtf8($newTitle, 'title');
        if ($newTitle === '') {
            $newTitle = 'Untitled';
        }
        $newSyntax = apiInputString($input, ['syntax', 'code'], (string)$paste['code']);
        if (!preg_match('/^[A-Za-z0-9._+#-]{1,50}$/', $newSyntax)) {
            $newSyntax = (string)$paste['code'];
        }
        $newVisible = (string)$paste['visible'];
        if (isset($input['visibility']) && is_scalar($input['visibility'])) {
            $newVisible = apiVisibility((string)$input['visibility']) ?? apiError('Unknown visibility "' . $input['visibility'] . '". Use public, unlisted or private', 400);
        }
        if (!$clientEncrypted && trim($newContent) === '') {
            apiError('Content is required');
        }
        if (strlen($newContent) > 1024 * 1024 * (float)($pastelimit ?? 10)) {
            apiError('Content is too large', 413);
        }

        // Spam filter: updates are checked like new pastes, but cannot be queued
        $scan = $clientEncrypted ? '' : $newContent;
        $verdict = spam_evaluate($pdo, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', (string)$user['username'], $newTitle, $scan, false, 'api-edit', null,
            $newVisible === '2' || $paste['password'] !== 'NONE');
        if ($verdict['action'] !== 'allow') {
            apiError('Update rejected: flagged as spam', 422);
        }

        // Stored like web pastes: HTML-escaped, content encrypted at rest
        $p_title = paste_store_title($newTitle) ?: 'Untitled';
        $newTitle = apiPlain($p_title);
        if ($clientEncrypted) {
            $p_content = $paste['content'];
            $p_encrypt = '2';
        } else {
            $p_content = encrypt(htmlspecialchars($newContent, ENT_QUOTES, 'UTF-8'), hex2bin(SECRET));
            $p_encrypt = '1';
        }

        $stmt = $pdo->prepare("UPDATE pastes SET title = ?, content = ?, encrypt = ?, code = ?, visible = ? WHERE id = ?");
        $stmt->execute([$p_title, $p_content, $p_encrypt, $newSyntax, $newVisible, $paste['id']]);

        $identifier = $paste['slug'] ?: (string)$paste['id'];
        apiResponse([
            'success' => true,
            'paste' => [
                'id' => (int)$paste['id'],
                'slug' => $paste['slug'] ?? null,
                'url' => $pasteUrl($identifier),
                'title' => $newTitle,
                'syntax' => $newSyntax,
                'visibility' => apiVisibilityName($newVisible),
                'encrypted' => $clientEncrypted,
                'encrypted_at_rest' => $p_encrypt === '1',
                'updated' => true
            ]
        ]);

    // ========================================================================
    // UPLOAD AN IMAGE OR VIDEO (stored like /img uploads)
    // ========================================================================
    case 'upload':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            apiError('POST method required', 405);
        }
        $imgSettings = img_settings($pdo);
        if ((int)$imgSettings['img_enabled'] !== 1) {
            apiError('Uploads are disabled on this site', 503);
        }
        $imgLimits = img_limits($imgSettings);
        $declared = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if (empty($_FILES) && $imgLimits['php'] > 0 && $declared > $imgLimits['php']) {
            apiError('That file is larger than the server accepts (' . img_format_bytes($imgLimits['php']) . ')', 413);
        }
        if (!isset($_FILES['file'])) {
            apiError('Send the file as multipart/form-data in the field "file"');
        }
        $expiry = apiRequireExpiry((string)($_POST['expiry'] ?? $_GET['expiry'] ?? 'N'), array_keys(IMG_EXPIRY));

        $account = (string)$user['username'];
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!spam_member_is_exempt($pdo, $account) && img_ensure_table($pdo)) {
            $perHour = (int)$imgSettings['img_per_hour'];
            if ($perHour > 0 && img_recent_count($pdo, $account, $ip) >= $perHour) {
                apiError('Upload limit reached: ' . $perHour . ' per hour', 429);
            }
            $quota = (int)$imgSettings['img_quota_mb'] * 1048576;
            if ($quota > 0 && img_recent_bytes($pdo, $account, $ip) + (int)($_FILES['file']['size'] ?? 0) > $quota) {
                apiError('Daily upload allowance of ' . img_format_bytes($quota) . ' reached', 429);
            }
        }

        $strip = !in_array((string)($_POST['strip_exif'] ?? '1'), ['0', 'false', 'no'], true);
        $title = $_POST['title'] ?? null;
        if (is_string($title) && $title !== '' && !mb_check_encoding($title, 'UTF-8')) {
            apiError('Titles must be UTF-8 text');
        }

        try {
            $row = img_store_upload($pdo, (array)$_FILES['file'], $account, $ip, $strip, $expiry, $imgSettings,
                img_clean_title($title));
        } catch (ImgUploadError $e) {
            apiError($e->getMessage(), 422);
        } catch (Throwable $e) {
            error_log('api upload: ' . $e->getMessage());
            apiError('The upload could not be stored', 500);
        }
        img_sweep($pdo);

        $key = (string)$row['_name'];
        // Keyed uploads are listed in the account on the website unless keep=0
        if (!$user['guest']) {
            img_keep_link($pdo, $key, !in_array((string)($_POST['keep'] ?? '1'), ['0', 'false', 'no'], true));
        }
        apiResponse([
            'success' => true,
            'upload' => [
                'key'     => $key,
                'url'     => img_page_url($key, $baseurl, $mod_rewrite),
                'file'    => img_file_url($key, (string)$row['ext'], $baseurl, $mod_rewrite),
                'kind'    => IMG_TYPES[$row['mime']][0] ?? 'file',
                'mime'    => (string)$row['mime'],
                'ext'     => (string)$row['ext'],
                'name'    => img_display_name($row),
                'title'   => img_display_title($row),
                'size'    => (int)$row['size'],
                'expiry'  => $expiry,
                'expires' => $row['expires'] ?: null,
            ]
        ], 201);

    // ========================================================================
    // DELETE ONE OF YOUR UPLOADS
    // ========================================================================
    case 'upload_delete':
        if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'DELETE'], true)) {
            apiError('POST or DELETE method required', 405);
        }
        $link = (string)($_GET['key'] ?? apiInput()['key'] ?? '');
        $name = filecrypt_name_from_link($link);
        $row = $name !== null ? img_find($pdo, $name) : null;
        if (!$row) {
            apiError('Upload not found', 404);
        }
        if ($row['account'] !== $user['username']) {
            apiError('Access denied', 403);
        }
        img_delete($pdo, (string)$row['slug']);
        apiResponse(['success' => true, 'deleted' => true]);

    // ========================================================================
    // MAKE AN ALBUM FROM YOUR UPLOADS
    // ========================================================================
    case 'album':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            apiError('POST method required', 405);
        }
        $input = apiInput();
        $keys = $input['keys'] ?? [];
        if (is_string($keys)) $keys = preg_split('/[\s,]+/', trim($keys), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($keys) || !$keys) {
            apiError('Send the uploads as keys: a list (or comma-separated) of their keys or links');
        }
        if ($user['guest']) apiError('Albums need an API key', 401);
        $max = img_album_limit(img_settings($pdo), true);
        if ($max === 0) apiError('Albums are turned off', 403);
        if (count($keys) > $max) apiError('An album can hold up to ' . $max . ' uploads', 422);
        $names = [];
        foreach ($keys as $k) {
            $name = is_string($k) ? filecrypt_name_from_link($k) : null;
            $row = $name !== null ? img_find($pdo, $name) : null;
            if (!$row || $row['encrypted'] !== FILECRYPT_MODE || $row['account'] !== $user['username']) {
                apiError('Upload not found or not yours: ' . (is_string($k) ? $k : '?'), 404);
            }
            $names[] = $name;
        }
        $title = $input['title'] ?? null;
        $desc = $input['description'] ?? null;
        foreach (['title' => $title, 'description' => $desc] as $field => $v) {
            if (is_string($v) && $v !== '' && !mb_check_encoding($v, 'UTF-8')) apiError(ucfirst($field) . ' must be UTF-8 text');
        }
        $expiry = apiRequireExpiry((string)($input['expiry'] ?? 'N'), array_keys(IMG_EXPIRY));
        $albumKey = img_album_create($pdo, $names, img_clean_title($title), img_clean_desc($desc), (string)$user['username'],
            (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), $expiry,
            !in_array((string)($input['keep'] ?? '1'), ['0', 'false', 'no'], true));
        apiResponse([
            'success' => true,
            'album' => [
                'key'     => $albumKey,
                'url'     => img_page_url($albumKey, $baseurl, $mod_rewrite),
                'uploads' => count(array_unique($names)),
                'title'   => img_clean_title($title),
                'expiry'  => $expiry,
            ]
        ], 201);

    // ========================================================================
    // DEFAULT - API INFO
    // ========================================================================
    default:
        $rate = (int)apiOption($pdo, 'api_rate_limit', '60');
        apiResponse([
            'success' => true,
            'message' => 'Paste API v1.1',
            'documentation' => $baseurl . 'api-docs.php',
            'endpoints' => [
                'POST /api.php?action=paste' => 'Create a new paste',
                'GET /api.php?action=get&id=X' => 'Get paste content (format=raw for the text only)',
                'POST /api.php?action=update&id=X' => 'Update a paste',
                'GET /api.php?action=list' => 'List your pastes',
                'DELETE /api.php?action=delete&id=X' => 'Delete a paste',
                'GET /api.php?action=search&q=X' => 'Search pastes',
                'GET /api.php?action=user' => 'Get your user info',
                'GET /api.php?action=languages' => 'List available languages (no auth required)',
                'POST /api.php?action=upload' => 'Upload an image or video (multipart field "file")',
                'POST /api.php?action=upload_delete&key=X' => 'Delete one of your uploads',
                'POST /api.php?action=album' => 'Make an album from your uploads (keys, title, description)'
            ],
            'authentication' => 'Provide API key via X-API-Key header, Authorization: Bearer header, or api_key parameter',
            'rate_limit' => $rate > 0 ? $rate . ' requests per minute per key' : 'none',
            'guest_access' => array_values(array_intersect(['paste', 'upload'], $guestActions))
        ]);
}
