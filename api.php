<?php
/*
 * Paste API v1.0
 * 
 * Endpoints:
 *   POST /api.php?action=paste     - Create a new paste
 *   GET  /api.php?action=get&id=X  - Get paste content
 *   GET  /api.php?action=list      - List user's pastes
 *   DELETE /api.php?action=delete&id=X - Delete a paste
 *   GET  /api.php?action=search&q=X - Search pastes
 *   GET  /api.php?action=languages - List available languages
 * 
 * Authentication: API key via header "X-API-Key" or query param "api_key"
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

// CORS headers for API access
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * Send JSON response and exit
 */
function apiResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send error response
 */
function apiError(string $message, int $status = 400): void
{
    apiResponse(['success' => false, 'error' => $message], $status);
}

/**
 * Check if API is enabled
 */
function isApiEnabled(PDO $pdo): bool
{
    try {
        $stmt = $pdo->prepare("SELECT option_value FROM paste_options WHERE option_name = 'api_enabled'");
        $stmt->execute();
        $result = $stmt->fetchColumn();
        return $result !== '0'; // Default to enabled if not set
    } catch (PDOException $e) {
        return true; // Default to enabled
    }
}

/**
 * Validate API key and return user info
 */
function validateApiKey(PDO $pdo, string $apiKey): ?array
{
    try {
        $stmt = $pdo->prepare("
            SELECT ak.id as key_id, ak.user_id, ak.is_active, u.username, u.email_id as email, u.verified
            FROM api_keys ak
            JOIN users u ON ak.user_id = u.id
            WHERE ak.api_key = ? AND ak.is_active = 1
        ");
        $stmt->execute([$apiKey]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        // A banned account's keys must not work, even if still marked active.
        if ($result && (string)($result['verified'] ?? '') === '2') {
            return null;
        }
        
        if ($result) {
            // Update last_used_at
            $pdo->prepare("UPDATE api_keys SET last_used_at = NOW() WHERE id = ?")
                ->execute([$result['key_id']]);
        }
        
        return $result ?: null;
    } catch (PDOException $e) {
        error_log("API key validation error: " . $e->getMessage());
        return null;
    }
}

/**
 * Get API key from request
 */
function getApiKey(): ?string
{
    // Header first (any case; HTTP/2 sends lowercase names)
    if (!empty($_SERVER['HTTP_X_API_KEY'])) {
        return (string)$_SERVER['HTTP_X_API_KEY'];
    }
    if (function_exists('getallheaders')) {
        foreach ((array)getallheaders() as $name => $value) {
            if (strcasecmp((string)$name, 'X-API-Key') === 0 && $value !== '') {
                return (string)$value;
            }
        }
    }

    // Check query param
    if (isset($_GET['api_key']) && is_string($_GET['api_key'])) {
        return $_GET['api_key'];
    }
    if (isset($_POST['api_key']) && is_string($_POST['api_key'])) {
        return $_POST['api_key'];
    }

    return null;
}

/** Input string field from a decoded request body. */
function apiInputString(array $input, array $keys, string $default = ''): string
{
    foreach ($keys as $k) {
        if (isset($input[$k]) && is_scalar($input[$k])) {
            return (string)$input[$k];
        }
    }
    return $default;
}

/** Stored (HTML-escaped) paste text back to what the author wrote. */
function apiPlain(string $stored): string
{
    return htmlspecialchars_decode($stored, ENT_QUOTES);
}

/**
 * Generate a unique paste slug
 */
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

// ============================================================================
// Main API Logic
// ============================================================================

try {
    // Database connection
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

// Check if API is enabled
if (!isApiEnabled($pdo)) {
    apiError('API is currently disabled', 503);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Actions that don't require authentication
$publicActions = ['languages'];

// Get and validate API key for protected actions
$user = null;
if (!in_array($action, $publicActions)) {
    $apiKey = getApiKey();
    if (!$apiKey) {
        apiError('API key required. Provide via X-API-Key header or api_key parameter.', 401);
    }
    
    $user = validateApiKey($pdo, $apiKey);
    if (!$user) {
        apiError('Invalid or inactive API key', 401);
    }
}

if (is_banned($pdo, (string)($_SERVER['REMOTE_ADDR'] ?? ''))) {
    apiError('Access denied', 403);
}

// Get site info for URLs
$stmt = $pdo->query("SELECT baseurl FROM site_info WHERE id = 1");
$siteInfo = $stmt->fetch() ?: [];
$baseurl = rtrim($siteInfo['baseurl'] ?? '', '/') . '/';

// mod_rewrite comes from config.php (required at top of file)
$mod_rewrite = ((string)($mod_rewrite ?? '0') === '1');

// Get URL mode
$urlMode = getPasteUrlMode($pdo);
$slugLength = getPasteSlugLength($pdo);

switch ($action) {
    // ========================================================================
    // CREATE PASTE
    // ========================================================================
    case 'paste':
    case 'create':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            apiError('POST method required', 405);
        }
        
        // Get input (support both form data and JSON)
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') !== false) {
            $input = json_decode((string)file_get_contents('php://input'), true);
            $input = is_array($input) ? $input : [];
        } else {
            $input = $_POST;
        }
        
        $content = apiInputString($input, ['content']);
        $title = trim(apiInputString($input, ['title'], 'Untitled')) ?: 'Untitled';
        $syntax = apiInputString($input, ['syntax', 'language', 'code'], 'text');
        $visibility = apiInputString($input, ['visibility', 'visible'], 'public');
        $expiry = strtoupper(apiInputString($input, ['expiry', 'expire'], 'NULL'));
        $password = apiInputString($input, ['password']);

        // Validate content
        if (trim($content) === '') {
            apiError('Content is required');
        }
        if (strlen($content) > 1024 * 1024 * (float)($pastelimit ?? 10)) {
            apiError('Content is too large', 413);
        }
        if (!preg_match('/^[A-Za-z0-9._+#-]{1,50}$/', $syntax)) {
            $syntax = 'text';
        }

        // Validate visibility
        $visibilityMap = ['public' => '0', 'unlisted' => '1', 'private' => '2'];
        $p_visible = $visibilityMap[$visibility] ?? '0';

        // Validate expiry
        $validExpiries = ['NULL', 'T', 'H', 'W', 'M', 'B', 'Y'];
        if (!in_array($expiry, $validExpiries, true)) {
            $expiry = 'NULL';
        }
        $expires = paste_expiry_value($expiry);

        // Stored like web pastes: HTML-escaped, content encrypted at rest
        if (!defined('SECRET') || SECRET === '') {
            apiError('Server configuration error', 500);
        }
        $p_title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $p_content = encrypt(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'), hex2bin(SECRET));
        $p_encrypt = '1';
        $p_password = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : 'NONE';

        // Generate slug
        $slug = null;
        if ($urlMode === 'slug') {
            $slug = generateApiSlug($pdo, $slugLength);
        }

        // Insert paste
        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // ---- Spam filter (scans plaintext title/content; API users are authenticated) ----
        if (function_exists('spam_evaluate')) {
            $verdict = spam_evaluate($pdo, $ip, (string)$user['username'], $title, $content, false, 'api');
            if ($verdict['action'] === 'block') {
                apiError('Paste rejected: flagged as spam', 422);
            }
            if ($verdict['action'] === 'hold' && function_exists('spam_enqueue')) {
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
                    ], 202);
                }
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
        
        $pasteId = $pdo->lastInsertId();
        $identifier = $slug ?: $pasteId;
        
        // Build URL
        $pasteUrl = $mod_rewrite ? $baseurl . $identifier : $baseurl . 'paste.php?id=' . $identifier;
        $rawUrl = $mod_rewrite ? $baseurl . 'raw/' . $identifier : $baseurl . 'paste.php?raw&id=' . $identifier;
        
        apiResponse([
            'success' => true,
            'paste' => [
                'id' => (int)$pasteId,
                'slug' => $slug,
                'url' => $pasteUrl,
                'raw_url' => $rawUrl,
                'title' => $title,
                'syntax' => $syntax,
                'visibility' => $visibility,
                'expiry' => $expiry,
                'encrypted' => $p_encrypt === '1',
                'created_at' => $now
            ]
        ], 201);
        break;
    
    // ========================================================================
    // GET PASTE
    // ========================================================================
    case 'get':
        $id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
        if ($id === '') {
            apiError('Paste ID required');
        }

        $paste = getPasteByIdentifier($pdo, $id);
        if (!$paste || paste_is_expired($paste['expiry'] ?? null)) {
            apiError('Paste not found', 404);
        }

        // Check visibility (private pastes only accessible by owner)
        if ($paste['visible'] === '2' && $paste['member'] !== $user['username']) {
            apiError('Access denied', 403);
        }

        // Check if password protected
        if ($paste['password'] !== 'NONE') {
            $providedPassword = is_string($_GET['password'] ?? null) ? $_GET['password'] : '';
            if (!password_verify($providedPassword, $paste['password'])) {
                apiError('Password required or incorrect', 401);
            }
        }

        // Decrypt if needed
        $content = $paste['content'];
        $title = $paste['title'];
        if ($paste['encrypt'] === '1' && defined('SECRET') && SECRET) {
            $content = decrypt($content, hex2bin(SECRET)) ?: $content;
            $title = decrypt($title, hex2bin(SECRET)) ?: $title;
        }
        if ($paste['encrypt'] !== '2') {
            $content = apiPlain($content);
        }
        $title = apiPlain($title);
        
        $identifier = $paste['slug'] ?: $paste['id'];
        $pasteUrl = $mod_rewrite ? $baseurl . $identifier : $baseurl . 'paste.php?id=' . $identifier;
        
        apiResponse([
            'success' => true,
            'paste' => [
                'id' => (int)$paste['id'],
                'slug' => $paste['slug'] ?? null,
                'url' => $pasteUrl,
                'title' => $title,
                'content' => $content,
                'syntax' => $paste['code'],
                'visibility' => ['public', 'unlisted', 'private'][(int)$paste['visible']] ?? 'public',
                'expiry' => $paste['expiry'],
                'encrypted' => $paste['encrypt'] === '1',
                'author' => $paste['member'],
                'created_at' => $paste['date'],
                'views' => getPasteViewCount($pdo, (int)$paste['id'])
            ]
        ]);
        break;
    
    // ========================================================================
    // LIST USER'S PASTES
    // ========================================================================
    case 'list':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        // Count total
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pastes WHERE member = ?");
        $stmt->execute([$user['username']]);
        $total = (int)$stmt->fetchColumn();
        
        // Get pastes
        $stmt = $pdo->prepare("
            SELECT id, slug, title, code, visible, expiry, encrypt, date, now_time
            FROM pastes 
            WHERE member = ?
            ORDER BY id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$user['username'], $limit, $offset]);
        $pastes = $stmt->fetchAll();
        
        $result = [];
        foreach ($pastes as $paste) {
            $title = $paste['title'];
            if ($paste['encrypt'] === '1' && defined('SECRET') && SECRET) {
                $title = decrypt($title, hex2bin(SECRET)) ?: $title;
            }
            $title = apiPlain($title);
            $identifier = $paste['slug'] ?: $paste['id'];

            $result[] = [
                'id' => (int)$paste['id'],
                'slug' => $paste['slug'] ?? null,
                'url' => $mod_rewrite ? $baseurl . $identifier : $baseurl . 'paste.php?id=' . $identifier,
                'title' => $title,
                'syntax' => $paste['code'],
                'visibility' => ['public', 'unlisted', 'private'][(int)$paste['visible']] ?? 'public',
                'expiry' => $paste['expiry'],
                'encrypted' => $paste['encrypt'] === '1',
                'created_at' => $paste['date']
            ];
        }
        
        apiResponse([
            'success' => true,
            'pastes' => $result,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ]);
        break;
    
    // ========================================================================
    // DELETE PASTE
    // ========================================================================
    case 'delete':
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            apiError('DELETE or POST method required', 405);
        }
        
        $id = $_GET['id'] ?? $_POST['id'] ?? '';
        $id = is_string($id) ? $id : '';
        if ($id === '') {
            apiError('Paste ID required');
        }

        $paste = getPasteByIdentifier($pdo, $id);
        if (!$paste) {
            apiError('Paste not found', 404);
        }

        // Only owner can delete
        if ($paste['member'] !== $user['username']) {
            apiError('Access denied', 403);
        }
        
        $stmt = $pdo->prepare("DELETE FROM pastes WHERE id = ?");
        $stmt->execute([$paste['id']]);
        
        apiResponse(['success' => true, 'message' => 'Paste deleted']);
        break;
    
    // ========================================================================
    // SEARCH PASTES
    // ========================================================================
    case 'search':
        $query = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
        if (strlen($query) < 2) {
            apiError('Search query must be at least 2 characters');
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        // Titles are searchable everywhere; content only where it is stored unencrypted
        $like = static fn(string $s): string => '%' . addcslashes($s, '%_\\') . '%';
        $titleTerm = $like(htmlspecialchars($query, ENT_QUOTES, 'UTF-8'));
        $contentTerm = $like($query);
        $where = "(visible = '0' OR member = ?) AND (title LIKE ? OR (encrypt = '0' AND content LIKE ?))";

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
        $pastes = $stmt->fetchAll();

        $result = [];
        foreach ($pastes as $paste) {
            $identifier = $paste['slug'] ?: $paste['id'];
            $result[] = [
                'id' => (int)$paste['id'],
                'slug' => $paste['slug'] ?? null,
                'url' => $mod_rewrite ? $baseurl . $identifier : $baseurl . 'paste.php?id=' . $identifier,
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
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ]);
        break;
    
    // ========================================================================
    // LIST LANGUAGES
    // ========================================================================
    case 'languages':
        global $geshiformats;
        
        // Include language list
        require_once __DIR__ . '/includes/list_languages.php';
        
        $highlighter = $highlighter ?? 'highlight';
        if ($highlighter === 'highlight') {
            $langs = highlight_supported_languages();
            $languages = highlight_language_map($langs);
        } else {
            $languages = geshi_language_map();
        }
        
        apiResponse([
            'success' => true,
            'languages' => $languages
        ]);
        break;
    
    // ========================================================================
    // USER INFO
    // ========================================================================
    case 'user':
    case 'me':
        // Get user's paste count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pastes WHERE member = ?");
        $stmt->execute([$user['username']]);
        $pasteCount = (int)$stmt->fetchColumn();
        
        // Get user's API key count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM api_keys WHERE user_id = ?");
        $stmt->execute([$user['user_id']]);
        $keyCount = (int)$stmt->fetchColumn();
        
        apiResponse([
            'success' => true,
            'user' => [
                'username' => $user['username'],
                'email' => $user['email'],
                'paste_count' => $pasteCount,
                'api_key_count' => $keyCount
            ]
        ]);
        break;
    
    // ========================================================================
    // UPDATE PASTE
    // ========================================================================
    case 'update':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'PATCH') {
            apiError('POST, PUT, or PATCH method required', 405);
        }
        
        // Get input
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') !== false) {
            $input = json_decode((string)file_get_contents('php://input'), true);
            $input = is_array($input) ? $input : [];
        } else {
            $input = $_POST;
        }
        
        $id = $_GET['id'] ?? $input['id'] ?? '';
        $id = is_scalar($id) ? (string)$id : '';
        if ($id === '') {
            apiError('Paste ID required');
        }

        $paste = getPasteByIdentifier($pdo, $id);
        if (!$paste) {
            apiError('Paste not found', 404);
        }

        // Only owner can update
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
        $currentContent = $paste['content'];
        $currentTitle = $paste['title'];
        if ($paste['encrypt'] === '1') {
            $currentContent = decrypt($currentContent, hex2bin(SECRET)) ?: $currentContent;
            $currentTitle = decrypt($currentTitle, hex2bin(SECRET)) ?: $currentTitle;
        }
        $currentTitle = apiPlain($currentTitle);
        if (!$clientEncrypted) {
            $currentContent = apiPlain($currentContent);
        }

        $newContent = apiInputString($input, ['content'], $currentContent);
        $newTitle = trim(apiInputString($input, ['title'], $currentTitle)) ?: 'Untitled';
        $newSyntax = apiInputString($input, ['syntax', 'code'], (string)$paste['code']);
        if (!preg_match('/^[A-Za-z0-9._+#-]{1,50}$/', $newSyntax)) {
            $newSyntax = (string)$paste['code'];
        }
        $newVisibility = isset($input['visibility']) && is_string($input['visibility']) ? $input['visibility'] : null;
        if (!$clientEncrypted && trim($newContent) === '') {
            apiError('Content is required');
        }
        if (strlen($newContent) > 1024 * 1024 * (float)($pastelimit ?? 10)) {
            apiError('Content is too large', 413);
        }

        // Map visibility
        if ($newVisibility !== null) {
            $visibilityMap = ['public' => '0', 'unlisted' => '1', 'private' => '2'];
            $newVisible = $visibilityMap[$newVisibility] ?? $paste['visible'];
        } else {
            $newVisible = $paste['visible'];
        }

        // Spam filter: updates are checked like new pastes, but cannot be queued
        if (function_exists('spam_evaluate')) {
            $scan = $clientEncrypted ? '' : $newContent;
            $verdict = spam_evaluate($pdo, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', (string)$user['username'], $newTitle, $scan, false, 'api-edit');
            if ($verdict['action'] !== 'allow') {
                apiError('Update rejected: flagged as spam', 422);
            }
        }

        // Stored like web pastes: HTML-escaped, content encrypted at rest
        $p_title = htmlspecialchars($newTitle, ENT_QUOTES, 'UTF-8');
        if ($clientEncrypted) {
            $p_content = $paste['content'];
            $p_encrypt = '2';
        } else {
            $p_content = encrypt(htmlspecialchars($newContent, ENT_QUOTES, 'UTF-8'), hex2bin(SECRET));
            $p_encrypt = '1';
        }

        $stmt = $pdo->prepare("UPDATE pastes SET title = ?, content = ?, encrypt = ?, code = ?, visible = ? WHERE id = ?");
        $stmt->execute([$p_title, $p_content, $p_encrypt, $newSyntax, $newVisible, $paste['id']]);
        
        $identifier = $paste['slug'] ?: $paste['id'];
        $pasteUrl = $mod_rewrite ? $baseurl . $identifier : $baseurl . 'paste.php?id=' . $identifier;
        
        apiResponse([
            'success' => true,
            'paste' => [
                'id' => (int)$paste['id'],
                'slug' => $paste['slug'] ?? null,
                'url' => $pasteUrl,
                'title' => $newTitle,
                'syntax' => $newSyntax,
                'visibility' => ['public', 'unlisted', 'private'][(int)$newVisible] ?? 'public',
                'updated' => true
            ]
        ]);
        break;
    
    // ========================================================================
    // DEFAULT - API INFO
    // ========================================================================
    default:
        $docUrl = rtrim($baseurl, '/') . '/docs/api.md';
        apiResponse([
            'success' => true,
            'message' => 'Paste API v1.0',
            'documentation' => $docUrl,
            'endpoints' => [
                'POST /api.php?action=paste' => 'Create a new paste',
                'GET /api.php?action=get&id=X' => 'Get paste content',
                'POST /api.php?action=update&id=X' => 'Update a paste',
                'GET /api.php?action=list' => 'List your pastes',
                'DELETE /api.php?action=delete&id=X' => 'Delete a paste',
                'GET /api.php?action=search&q=X' => 'Search pastes',
                'GET /api.php?action=user' => 'Get your user info',
                'GET /api.php?action=languages' => 'List available languages (no auth required)'
            ],
            'authentication' => 'Provide API key via X-API-Key header or api_key parameter',
            'rate_limit' => '60 requests per minute'
        ]);
        break;
}
