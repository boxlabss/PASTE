<?php
/*
 * Paste https://github.com/boxlabss/PASTE
 *
 * API documentation page body, between the theme header and footer.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

$api_rate_limit       = (int)($api_rate_limit ?? 60);
$api_guest_pastes     = !empty($api_guest_pastes);
$api_guest_uploads    = !empty($api_guest_uploads);
$api_guest_rate_limit = (int)($api_guest_rate_limit ?? 10);

$docNav = [
    [null, [['overview', 'Overview'], ['authentication', 'Authentication'], ['rate-limits', 'Rate Limits']]],
    ['Endpoints', [
        ['create-paste', 'Create Paste'], ['get-paste', 'Get Paste'], ['update-paste', 'Update Paste'],
        ['delete-paste', 'Delete Paste'], ['list-pastes', 'List Pastes'], ['search-pastes', 'Search Pastes'],
        ['user-info', 'User Info'], ['upload', 'Upload Image or Video'], ['upload-delete', 'Delete Upload'], ['album', 'Make an Album'],
    ]],
    ['Resources', [['errors', 'Error Codes'], ['examples', 'Code Examples']]],
];

/** Section links for the docs sidebar. */
$renderDocNav = static function (string $id) use ($docNav, $baseurl): void {
    echo '<div class="list-group" id="' . $id . '">';
    foreach ($docNav as [$heading, $items]) {
        if ($heading !== null) {
            echo '<div class="docs-nav-heading">' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        foreach ($items as [$anchor, $label]) {
            $active = $anchor === 'overview' ? ' active' : '';
            echo '<a class="list-group-item' . $active . '" href="#' . $anchor . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
        }
    }
    echo '<a class="list-group-item" href="' . htmlspecialchars($baseurl . 'docs/paste_client.py', ENT_QUOTES, 'UTF-8') . '" download>'
       . '<i class="bi bi-download me-1"></i>Python Client</a>';
    echo '</div>';
};
?>
<?php if (($highlighter ?? 'geshi') !== 'highlight'): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($baseurl . 'includes/Highlight/styles/' . basename($hl_style ?? 'hybrid.css'), ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
<style>
  .api-docs .docs-nav { position: sticky; top: 1rem; max-height: calc(100vh - 2rem); overflow-y: auto; background: #222; border-radius: var(--bs-border-radius-lg); padding: .75rem; }
  .api-docs .list-group-item { background: transparent; color: #dee2e6; border: 0; border-radius: var(--bs-border-radius); padding: .45rem .75rem; margin-bottom: 2px; }
  .api-docs .list-group-item:hover { background: #2a2a2a; color: #fff; }
  .api-docs .list-group-item.active { background: #0d6efd; color: #fff; }
  .api-docs .docs-nav-heading { color: #adb5bd; font-size: .75rem; font-weight: 500; letter-spacing: .05em; text-transform: uppercase; padding: 1rem .75rem .35rem; }
  .api-docs .docs-body { padding: 1.5rem !important; }
  @media (min-width: 992px) { .api-docs .docs-body { padding: 2.5rem !important; } }
  .api-docs .card .card, .api-docs .card .card .card-body { background: #2a2a2a !important; }
  .api-docs .card .card { border: 1px solid #444; }
  .api-docs .lead { color: #ced4da; }
  .api-docs code { color: #9ec5fe; }
  .api-docs pre { background: #212529; color: #e6e6e6; border: 1px solid rgba(255,255,255,.1); border-radius: var(--bs-border-radius); padding: 1rem; overflow-x: auto; }
  .api-docs pre code { color: inherit; }
  .api-docs pre code.hljs { background: transparent; padding: 0; }
  .api-docs .table { --bs-table-bg: transparent; --bs-table-border-color: #444; }
  .api-docs .table thead th { background: #2a2a2a; }
  .api-docs .method-badge { font-family: monospace; font-weight: 600; padding: .35em .65em; border-radius: var(--bs-border-radius-sm); font-size: .82rem; vertical-align: middle; }
  .api-docs .method-get    { background: rgba(var(--bs-success-rgb), .18); color: #75b798; }
  .api-docs .method-post   { background: rgba(var(--bs-primary-rgb), .18); color: #6ea8fe; }
  .api-docs .method-put    { background: rgba(var(--bs-warning-rgb), .18); color: #ffda6a; }
  .api-docs .method-delete { background: rgba(var(--bs-danger-rgb), .22);  color: #ff6b6b; }
  .api-docs .required { color: #fff; font-weight: 600; }
  .api-docs .optional { color: #adb5bd; }
  .api-docs .response-example { border-left: 4px solid var(--bs-success); }
  .api-docs .error-example { border-left: 4px solid var(--bs-danger); }
  .api-docs .endpoint { scroll-margin-top: 1rem; }
</style>

<div class="offcanvas offcanvas-start api-docs" tabindex="-1" id="sidebarOffcanvas" aria-labelledby="sidebarOffcanvasLabel">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title" id="sidebarOffcanvasLabel">API Documentation</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body"><?php $renderDocNav('mobileNav'); ?></div>
</div>

<div class="container-xxl my-4 api-docs">
  <div class="row g-4">
    <div class="col-lg-3 col-xl-2 d-none d-lg-block">
      <nav class="docs-nav" aria-label="API documentation sections"><?php $renderDocNav('desktopNav'); ?></nav>
    </div>

    <div class="col-lg-9 col-xl-10">
      <button class="btn btn-outline-light btn-sm d-lg-none mb-3" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas" aria-controls="sidebarOffcanvas">
        <i class="bi bi-list me-1"></i>Contents
      </button>
      <div class="card">
        <div class="card-body docs-body">

            <!-- Overview -->
            <section id="overview" class="endpoint mb-5">
                <h1 class="mb-4"><?= htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8') ?> API Documentation</h1>
                <p class="lead mb-4">The <?= htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8') ?> API allows you to programmatically create, retrieve, update, and delete pastes.</p>

                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3">Base URL</h5>
                        <code class="fs-6 d-block p-3 bg-dark border border-secondary rounded">
                            <?= htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8') ?>
                        </code>
                    </div>
                </div>

                <div class="alert alert-info border-0">
                    <i class="bi bi-info-circle me-2"></i>
                    All API responses are in JSON format. Set <code>Accept: application/json</code> header for best results.
                </div>
            </section>

            <!-- Authentication -->
            <section id="authentication" class="endpoint mb-5">
                <h2 class="mb-4"><i class="bi bi-key me-2"></i>Authentication</h2>
                <p>All authenticated endpoints require an API key. You can generate and manage API keys from your <a href="<?= htmlspecialchars($baseurl . 'profile.php', ENT_QUOTES, 'UTF-8') ?>">profile page</a>.</p>

                <h5 class="mt-4 mb-3">Authentication Methods</h5>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <h6 class="card-title"><i class="bi bi-shield-check me-1"></i>Header (Recommended)</h6>
                                <pre class="mb-0"><code>X-API-Key: your_api_key_here</code></pre>
                                <p class="small text-muted mt-2 mb-0">or <code>Authorization: Bearer your_api_key_here</code></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <h6 class="card-title"><i class="bi bi-link-45deg me-1"></i>Query Parameter</h6>
                                <pre class="mb-0"><code>?api_key=your_api_key_here</code></pre>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <h6 class="card-title"><i class="bi bi-body-text me-1"></i>POST Body</h6>
                                <pre class="mb-0"><code>{"api_key": "your_api_key_here"}</code></pre>
                            </div>
                        </div>
                    </div>
                </div>
                <p class="mt-3 mb-0 small text-muted">Prefer a header: query strings end up in server and proxy logs.</p>

                <h5 class="mt-4 mb-3">Key Scopes</h5>
                <p>When you create a key you choose what it can do. A request outside the key's scopes gets <code>403</code>.</p>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Scope</th><th>Allows</th></tr></thead>
                        <tbody>
                            <tr><td><code>read</code></td><td><code>get</code>, <code>list</code>, <code>search</code> and <code>user</code></td></tr>
                            <tr><td><code>paste</code></td><td><code>paste</code>, <code>update</code> and <code>delete</code></td></tr>
                            <tr><td><code>upload</code></td><td><code>upload</code>, <code>upload_delete</code> and <code>album</code></td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="mb-0">Keys are stored hashed, so they cannot be shown again. If you lose one, regenerate it on your profile page; it keeps its name and scopes.
                    Endpoints that take an <code>id</code> accept a slug or a number; when the site blocks numeric URLs, numbers only find your own pastes.</p>

                <h5 class="mt-4 mb-3">Guest Access</h5>
                <?php if ($api_guest_pastes || $api_guest_uploads): ?>
                    <p>This site lets guests
                        <?= $api_guest_pastes && $api_guest_uploads ? 'create pastes and upload images and videos' : ($api_guest_pastes ? 'create pastes' : 'upload images and videos') ?>
                        without a key: leave the key out and the request is made as <code>Guest</code>.
                        Guests can make <?= $api_guest_rate_limit > 0 ? (int)$api_guest_rate_limit . ' requests per minute per IP address' : 'requests without a per-minute limit' ?>.
                        <?= $api_guest_pastes ? 'Guest pastes cannot be private, and guests' : 'Guests' ?> cannot read, change or delete anything, so keep the links from the reply.</p>
                <?php else: ?>
                    <p class="mb-0">This site needs an API key for every request except <code>languages</code>.</p>
                <?php endif; ?>
                </section>

            <!-- Rate Limits -->
            <section id="rate-limits" class="endpoint mb-5">
                <h2 class="mb-4"><i class="bi bi-speedometer me-2"></i>Rate Limits</h2>
                <p>Each key can make <strong><?= (int)$api_rate_limit ?> requests per minute</strong><?= (int)$api_rate_limit === 0 ? ' (no limit is set on this site)' : '' ?>. Every reply says where you are:</p>
                <pre><code>X-RateLimit-Limit: 60
X-RateLimit-Remaining: 57
X-RateLimit-Reset: 1738411260</code></pre>
                <ul class="mb-0">
                    <li><code>X-RateLimit-Reset</code> is the Unix time the minute ends</li>
                    <li>Over the limit the API answers <code>429</code> with a <code>Retry-After</code> header in seconds</li>
                    <li>Each key gets 10 wrong paste passwords per 10 minutes</li>
                    <li>Uploads also count against the site's hourly upload limit and daily allowance</li>
                </ul>
            </section>

            <!-- Create Paste -->
            <section id="create-paste" class="endpoint mb-5">
                <h2 class="mb-4">
                    <span class="method-badge method-post me-2">POST</span>
                    Create Paste
                </h2>
                <p>Create a new paste with the provided content and optional settings.</p>

                <h5 class="mt-4 mb-3">Endpoint</h5>
                <pre><code>POST <?= htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8') ?>?action=paste</code></pre>

                <h5 class="mt-4 mb-3">Parameters</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead>
                            <tr>
                                <th>Parameter</th>
                                <th>Type</th>
                                <th>Required</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td><code>content</code></td><td>string</td><td class="required">Required</td><td>The main paste content, as UTF-8 text</td></tr>
                            <tr><td><code>title</code></td><td>string</td><td class="optional">Optional</td><td>Paste title (default: "Untitled")</td></tr>
                            <tr><td><code>syntax</code></td><td>string</td><td class="optional">Optional</td><td>Syntax highlighting (e.g. python, javascript, php)</td></tr>
                            <tr><td><code>visibility</code></td><td>string</td><td class="optional">Optional</td><td>"public" (default), "unlisted" or "private"</td></tr>
                            <tr><td><code>expiry</code></td><td>string</td><td class="optional">Optional</td><td><code>N</code> (never, default), <code>10M</code>, <code>1H</code>, <code>1D</code>, <code>1W</code>, <code>2W</code>, <code>1M</code>, <code>6M</code>, <code>1Y</code>,
                                or <code>SELF</code> (view once: deleted after its first reader). <code>never</code>, <code>NULL</code>, <code>T</code>, <code>H</code>, <code>D</code>, <code>W</code>, <code>M</code>, <code>B</code> and <code>Y</code> also work.</td></tr>
                            <tr><td><code>password</code></td><td>string</td><td class="optional">Optional</td><td>Password protect the paste</td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="small text-muted">Text that is not valid UTF-8, an unknown visibility or an unknown expiry is refused with <code>400</code>.</p>

                <h5 class="mt-4 mb-3">Example Request</h5>
                <pre><code class="language-bash">curl -X POST "<?= htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8') ?>?action=paste" \
  -H "X-API-Key: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "content": "print(\"Hello, World!\")",
    "title": "My Python Script",
    "syntax": "python",
    "visibility": "public"
  }'</code></pre>

                <h5 class="mt-4 mb-3">Example Response</h5>
                <pre class="response-example"><code class="language-json">{
  "success": true,
  "paste": {
    "id": 123,
    "slug": "aBcDeFgH",
    "url": "<?= htmlspecialchars($baseurl, ENT_QUOTES, 'UTF-8') ?>aBcDeFgH",
    "title": "My Python Script",
    "syntax": "python",
    "visibility": "public",
    "expiry": "1D",
    "expires_at": "2026-02-04T14:30:00+00:00",
    "view_once": false,
    "password_protected": false,
    "encrypted": false,
    "encrypted_at_rest": true,
    "created_at": "2026-02-03 14:30:00"
  }
}</code></pre>
            </section>

            <!-- Get Paste -->
            <section id="get-paste" class="endpoint mb-5">
                <h2 class="mb-4">
                    <span class="method-badge method-get me-2">GET</span>
                    Get Paste
                </h2>
                <p>Retrieve an existing paste by its numeric ID or slug.</p>

                <h5 class="mt-4 mb-3">Endpoint</h5>
                <pre><code>GET <?= htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8') ?>?action=get&id={id_or_slug}</code></pre>

                <h5 class="mt-4 mb-3">Parameters</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead>
                            <tr>
                                <th>Parameter</th>
                                <th>Type</th>
                                <th>Required</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td><code>id</code></td><td>string</td><td class="required">Required</td><td>Paste ID (numeric) or slug</td></tr>
                            <tr><td><code>password</code></td><td>string</td><td class="optional">Optional</td><td>For password-protected pastes: send it in the <code>X-Paste-Password</code> header or the request body (a query parameter also works, but ends up in logs)</td></tr>
                            <tr><td><code>format</code></td><td>string</td><td class="optional">Optional</td><td><code>raw</code> returns the text only, as text/plain</td></tr>
                        </tbody>
                    </table>
                </div>
                <p>Reading a view-once paste counts as its view, so it is deleted after the first reader and the reply has <code>"deleted": true</code>.
                    <code>encrypted</code> is true when <code>content</code> is ciphertext from a paste encrypted in the browser; <code>encrypted_at_rest</code> is true for pastes the server stores encrypted and decrypts for you.</p>

                <h5 class="mt-4 mb-3">Example Request</h5>
                <pre><code class="language-bash">curl "<?= htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8') ?>?action=get&id=aBcDeFgH" \
  -H "X-API-Key: YOUR_API_KEY"

# Text only, from a password-protected paste
curl "<?= htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8') ?>?action=get&id=aBcDeFgH&format=raw" \
  -H "X-API-Key: YOUR_API_KEY" \
  -H "X-Paste-Password: hunter2"</code></pre>

                <h5 class="mt-4 mb-3">Example Response</h5>
                <pre class="response-example"><code class="language-json">{
  "success": true,
  "paste": {
    "id": 123,
    "slug": "aBcDeFgH",
    "title": "My Python Script",
    "content": "print(\"Hello, World!\")",
    "syntax": "python",
    "visibility": "public",
    "expiry": "NULL",
    "expires_at": null,
    "view_once": false,
    "deleted": false,
    "password_protected": false,
    "encrypted": false,
    "encrypted_at_rest": true,
    "views": 42,
    "created_at": "2026-02-03 14:30:00",
    "author": "johndoe"
  }
}</code></pre>
            </section>

            <!-- Update Paste -->
            <section id="update-paste" class="endpoint mb-5">
                <h2 class="mb-4">
                    <span class="method-badge method-put me-2">PUT</span>
                    Update Paste
                </h2>
                <p>Modify an existing paste you own. Only supply the fields you want to change.</p>

                <h5 class="mt-4 mb-3">Endpoint</h5>
                <pre><code>POST/PUT <?= htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8') ?>?action=update&id={id_or_slug}</code></pre>

                <h5 class="mt-4 mb-3">Parameters</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead>
                            <tr>
                                <th>Parameter</th>
                                <th>Type</th>
                                <th>Required</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td><code>id</code></td><td>string</td><td class="required">Required</td><td>Paste ID or slug to update</td></tr>
                            <tr><td><code>content</code></td><td>string</td><td class="optional">Optional</td><td>New content</td></tr>
                            <tr><td><code>title</code></td><td>string</td><td class="optional">Optional</td><td>New title</td></tr>
                            <tr><td><code>syntax</code></td><td>string</td><td class="optional">Optional</td><td>New syntax</td></tr>
                            <tr><td><code>visibility</code></td><td>string</td><td class="optional">Optional</td><td>New visibility</td></tr>
                        </tbody>
                    </table>
                </div>

                <h5 class="mt-4 mb-3">Example Request</h5>
                <pre><code class="language-bash">curl -X PUT "<?= htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8') ?>?action=update&id=aBcDeFgH" \
  -H "X-API-Key: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"title": "Updated Title", "content": "print(\"Updated content!\")"}'</code></pre>

                <div class="alert alert-info mt-3">
                    Returns same format as Create Paste on success.
                </div>
            </section>

            <!-- Delete Paste -->
            <section id="delete-paste" class="endpoint mb-5">
                <h2 class="mb-4">
                    <span class="method-badge method-delete me-2">DELETE</span>
                    Delete Paste
                </h2>
                <p>Permanently delete a paste you own.</p>

                <h5 class="mt-4 mb-3">Endpoint</h5>
                <pre><code>DELETE <?= htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8') ?>?action=delete&id={id_or_slug}</code></pre>

                <h5 class="mt-4 mb-3">Example Request</h5>
                <pre><code class="language-bash">curl -X DELETE "<?= htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8') ?>?action=delete&id=aBcDeFgH" \
  -H "X-API-Key: YOUR_API_KEY"</code></pre>

                <h5 class="mt-4 mb-3">Example Response</h5>
                <pre class="response-example"><code class="language-json">{
  "success": true,
  "message": "Paste deleted successfully"
}</code></pre>
            </section>

            <!-- List Pastes -->
            <section id="list-pastes" class="endpoint mb-5">
                <h2 class="mb-4">
                    <span class="method-badge method-get me-2">GET</span>
                    List Pastes
                </h2>
                <p>Retrieve a paginated list of pastes belonging to the authenticated user.</p>

                <h5 class="mt-4 mb-3">Endpoint</h5>
                <pre><code>GET <?= htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8') ?>?action=list</code></pre>

                <h5 class="mt-4 mb-3">Parameters</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead>
                            <tr>
                                <th>Parameter</th>
                                <th>Type</th>
                                <th>Required</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td><code>limit</code></td><td>integer</td><td class="optional">Optional</td><td>Results per page (default: 20, max: 100)</td></tr>
                            <tr><td><code>offset</code></td><td>integer</td><td class="optional">Optional</td><td>Skip this many records (default: 0)</td></tr>
                        </tbody>
                    </table>
                </div>

                <h5 class="mt-4 mb-3">Example Request</h5>
                <pre><code class="language-bash">curl "<?= htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8') ?>?action=list&limit=10" \
  -H "X-API-Key: YOUR_API_KEY"</code></pre>
            </section>

            <!-- Search Pastes -->
            <section id="search-pastes" class="endpoint mb-5">
                <h2 class="mb-4">
                    <span class="method-badge method-get me-2">GET</span>
                    Search Pastes
                </h2>
                <p>Search your own pastes by title or content.</p>

                <h5 class="mt-4 mb-3">Endpoint</h5>
                <pre><code>GET <?= htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8') ?>?action=search&q={query}</code></pre>

                <h5 class="mt-4 mb-3">Parameters</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead>
                            <tr>
                                <th>Parameter</th>
                                <th>Type</th>
                                <th>Required</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td><code>q</code></td><td>string</td><td class="required">Required</td><td>Search term (min 2 characters)</td></tr>
                            <tr><td><code>limit</code></td><td>integer</td><td class="optional">Optional</td><td>Max results (default: 20)</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- User Info -->
            <section id="user-info" class="endpoint mb-5">
                <h2 class="mb-4">
                    <span class="method-badge method-get me-2">GET</span>
                    User Info
                </h2>
                <p>Get basic information about the authenticated account.</p>

                <h5 class="mt-4 mb-3">Endpoint</h5>
                <pre><code>GET <?= htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8') ?>?action=user</code></pre>

                <h5 class="mt-4 mb-3">Example Response</h5>
                <pre class="response-example"><code class="language-json">{
  "success": true,
  "user": {
    "username": "johndoe",
    "email": "john@example.com",
    "paste_count": 42,
    "api_key_count": 2
  }
}</code></pre>
            </section>

            <!-- Upload -->
            <section id="upload" class="endpoint mb-5">
                <h2 class="mb-4">
                    <span class="method-badge method-post me-2">POST</span>
                    Upload Image or Video
                </h2>
                <p>Upload a file to /img. It is stored encrypted with its link as the key, like uploads from the website, and the same limits apply.
                    JPEG, PNG, GIF and WebP images and MP4, WebM, MOV, AVI and MKV videos are accepted.</p>

                <h5 class="mt-4 mb-3">Endpoint</h5>
                <pre><code>POST <?= htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8') ?>?action=upload</code></pre>

                <h5 class="mt-4 mb-3">Parameters (multipart/form-data)</h5>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Field</th><th>Required</th><th>Description</th></tr></thead>
                        <tbody>
                            <tr><td><code>file</code></td><td>Yes</td><td>The image or video</td></tr>
                            <tr><td><code>strip_exif</code></td><td>No</td><td><code>1</code> (default) removes EXIF, GPS location and other metadata; <code>0</code> keeps it</td></tr>
                            <tr><td><code>expiry</code></td><td>No</td><td><code>N</code> (never, default), <code>1H</code>, <code>1D</code>, <code>1W</code> or <code>1M</code></td></tr>
                        </tbody>
                    </table>
                </div>

                <h5 class="mt-4 mb-3">Example Request</h5>
                <pre><code class="language-bash">curl -X POST "<?= htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8') ?>?action=upload" \
  -H "X-API-Key: YOUR_API_KEY" \
  -F "file=@photo.jpg"</code></pre>

                <h5 class="mt-4 mb-3">Example Response (201)</h5>
                <pre class="response-example"><code class="language-json">{
  "success": true,
  "upload": {
    "key": "nD3SrAgZive8g4Y4JcsFV8",
    "url": "https://paste.example.com/img/nD3SrAgZive8g4Y4JcsFV8",
    "file": "https://paste.example.com/img/nD3SrAgZive8g4Y4JcsFV8.jpg",
    "kind": "image",
    "mime": "image/jpeg",
    "ext": "jpg",
    "name": "photo.jpg",
    "size": 183204,
    "expires": null
  }
}</code></pre>
                <p class="mt-3 mb-0"><code>file</code> is the direct link and <code>url</code> the view page. The key is in both links and the server does not keep it,
                    so save the reply if you may want to delete the upload later. Errors: <code>413</code> too large for the server, <code>422</code> wrong type or size,
                    <code>429</code> hourly limit or daily allowance reached, <code>503</code> uploads turned off.</p>
            </section>

            <!-- Delete Upload -->
            <section id="upload-delete" class="endpoint mb-5">
                <h2 class="mb-4">
                    <span class="method-badge method-delete me-2">DELETE</span>
                    Delete Upload
                </h2>
                <p>Delete an upload made by your account. <code>key</code> is the key, or either link, from the upload reply.</p>

                <h5 class="mt-4 mb-3">Endpoint</h5>
                <pre><code>DELETE <?= htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8') ?>?action=upload_delete&key={key}</code></pre>

                <h5 class="mt-4 mb-3">Example Request</h5>
                <pre><code class="language-bash">curl -X DELETE "<?= htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8') ?>?action=upload_delete&key=nD3SrAgZive8g4Y4JcsFV8" \
  -H "X-API-Key: YOUR_API_KEY"</code></pre>

                <h5 class="mt-4 mb-3">Example Response</h5>
                <pre class="response-example"><code class="language-json">{
  "success": true,
  "deleted": true
}</code></pre>
            </section>

            <!-- Make Album -->
            <section id="album" class="endpoint mb-5">
                <h2 class="mb-4">
                    <span class="method-badge method-post me-2">POST</span>
                    Make an Album
                </h2>
                <p>Put uploads made by your account into an album with one link. <code>keys</code> lists their keys or links in album order
                    (a JSON list, <code>keys[]</code> fields, or comma-separated). Optional: <code>title</code>, <code>description</code>,
                    <code>expiry</code>, and <code>keep=0</code> to leave the album out of your account's list on the website.</p>

                <h5 class="mt-4 mb-3">Endpoint</h5>
                <pre><code>POST <?= htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8') ?>?action=album</code></pre>

                <h5 class="mt-4 mb-3">Example Request</h5>
                <pre><code class="language-bash">curl -X POST "<?= htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8') ?>?action=album" \
  -H "X-API-Key: YOUR_API_KEY" \
  -F "keys=nD3SrAgZive8g4Y4JcsFV8,Qm7pXb2Ld9vRt4Kw8YzHs3" \
  -F "title=Weekend walk"</code></pre>

                <h5 class="mt-4 mb-3">Example Response</h5>
                <pre class="response-example"><code class="language-json">{
  "success": true,
  "album": {
    "key": "Hk4Tz9WbN2qLs7Vd3XpRm8",
    "url": "https://paste.example.com/img/Hk4Tz9WbN2qLs7Vd3XpRm8",
    "uploads": 2,
    "title": "Weekend walk",
    "expiry": "N"
  }
}</code></pre>
            </section>

            <!-- Error Codes -->
            <section id="errors" class="endpoint mb-5">
                <h2 class="mb-4"><i class="bi bi-exclamation-triangle me-2"></i>Error Codes</h2>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Error</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>400</td><td>Bad Request</td><td>Invalid or missing parameters</td></tr>
                            <tr><td>401</td><td>Unauthorized</td><td>Missing or invalid API key</td></tr>
                            <tr><td>403</td><td>Forbidden</td><td>No permission (e.g. not your paste)</td></tr>
                            <tr><td>404</td><td>Not Found</td><td>Paste does not exist</td></tr>
                            <tr><td>429</td><td>Too Many Requests</td><td>Rate limit exceeded</td></tr>
                            <tr><td>500</td><td>Server Error</td><td>Internal server error</td></tr>
                        </tbody>
                    </table>
                </div>

                <h5 class="mt-4 mb-3">Error Response Format</h5>
                <pre class="error-example"><code class="language-json">{
  "success": false,
  "error": "Human-readable error message"
}</code></pre>
            </section>

            <!-- Code Examples -->
            <section id="examples" class="endpoint mb-5">
                <h2 class="mb-4"><i class="bi bi-code-slash me-2"></i>Code Examples</h2>

                <ul class="nav nav-tabs mb-4" id="exampleTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="python-tab" data-bs-toggle="tab" data-bs-target="#python" type="button" role="tab">Python</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="bash-tab" data-bs-toggle="tab" data-bs-target="#bash" type="button" role="tab">Bash</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="javascript-tab" data-bs-toggle="tab" data-bs-target="#javascript" type="button" role="tab">JavaScript</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="php-tab" data-bs-toggle="tab" data-bs-target="#php" type="button" role="tab">PHP</button>
                    </li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade show active" id="python" role="tabpanel">
                        <pre><code class="language-python">import requests

API_URL = "<?php echo htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8'); ?>"
API_KEY = "your_api_key_here"

headers = {"X-API-Key": API_KEY}

# Create a paste
response = requests.post(
    f"{API_URL}?action=paste",
    headers=headers,
    json={
"content": "print('Hello, World!')",
"title": "My Python Paste",
"syntax": "python"
    }
)
result = response.json()
print(f"Paste URL: {result['paste']['url']}")

# Get a paste
response = requests.get(
    f"{API_URL}?action=get&id=aBcDeFgH",
    headers=headers
)
paste = response.json()['paste']
print(f"Content: {paste['content']}")</code></pre>
                    </div>
                    <div class="tab-pane fade" id="bash" role="tabpanel">
                        <pre><code class="language-bash">#!/bin/bash
API_URL="<?php echo htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8'); ?>"
API_KEY="your_api_key_here"

# Create a paste from file
paste_file() {
    curl -s -X POST "$API_URL?action=paste" \
-H "X-API-Key: $API_KEY" \
-H "Content-Type: application/json" \
-d "{\"content\": $(cat "$1" | jq -Rs .), \"title\": \"$1\", \"syntax\": \"text\"}"
}

# Create paste from stdin
echo "Hello, World!" | curl -s -X POST "$API_URL?action=paste" \
    -H "X-API-Key: $API_KEY" \
    -H "Content-Type: application/json" \
    -d "{\"content\": \"$(cat)\"}"</code></pre>
                    </div>
                    <div class="tab-pane fade" id="javascript" role="tabpanel">
                        <pre><code class="language-javascript">const API_URL = '<?php echo htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8'); ?>';
const API_KEY = 'your_api_key_here';

// Create a paste
async function createPaste(content, title, syntax) {
    const response = await fetch(`${API_URL}?action=paste`, {
method: 'POST',
headers: {
    'X-API-Key': API_KEY,
    'Content-Type': 'application/json'
},
body: JSON.stringify({ content, title, syntax })
    });
    return response.json();
}

// Get a paste
async function getPaste(id) {
    const response = await fetch(`${API_URL}?action=get&id=${id}`, {
headers: { 'X-API-Key': API_KEY }
    });
    return response.json();
}

// Usage
createPaste('console.log("Hello!");', 'My JS Paste', 'javascript')
    .then(result => console.log('Created:', result.paste.url));</code></pre>
                    </div>

                    <div class="tab-pane fade" id="php" role="tabpanel">
                        <pre><code class="language-php">&lt;?php
$api_url = '<?php echo htmlspecialchars($api_url, ENT_QUOTES, 'UTF-8'); ?>';
$api_key = 'your_api_key_here';

// Create a paste
$data = [
    'content' => '&lt;?php echo "Hello, World!"; ?>',
    'title' => 'My PHP Paste',
    'syntax' => 'php'
];

$ch = curl_init("$api_url?action=paste");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
"X-API-Key: $api_key",
"Content-Type: application/json"
    ],
    CURLOPT_POSTFIELDS => json_encode($data)
]);

$response = json_decode(curl_exec($ch), true);
curl_close($ch);

echo "Paste URL: " . $response['paste']['url'];
?></code></pre>
                    </div>
                </div>

                <div class="alert alert-success mt-4">
                    <i class="bi bi-download me-2"></i>
                    Download the <a href="<?= htmlspecialchars($baseurl . 'docs/paste_client.py', ENT_QUOTES, 'UTF-8') ?>" class="alert-link" download>Python CLI client</a>
                </div>
            </section>

        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@highlightjs/cdn-assets@11.9.0/highlight.min.js" integrity="sha384-F/bZzf7p3Joyp5psL90p/p89AZJsndkSoGwRpXcZhleCWhd8SnRuoYo4d0yirjJp" crossorigin="anonymous"></script>
<script>
  hljs.highlightAll();

  // Highlight the sidebar link of the section in view
  function updateActiveNav() {
    let current = 'overview';
    document.querySelectorAll('.api-docs .endpoint').forEach(section => {
      if (window.scrollY >= section.getBoundingClientRect().top + window.scrollY - 120) {
        current = section.id;
      }
    });
    document.querySelectorAll('#desktopNav .list-group-item, #mobileNav .list-group-item').forEach(item => {
      item.classList.toggle('active', item.getAttribute('href') === '#' + current);
    });
  }
  window.addEventListener('scroll', updateActiveNav, { passive: true });
  updateActiveNav();

  // On small screens, close the contents panel, then scroll to the section
  document.querySelectorAll('#mobileNav .list-group-item[href^="#"]').forEach(link => {
    link.addEventListener('click', e => {
      const target = document.getElementById(link.getAttribute('href').substring(1));
      const panel = document.getElementById('sidebarOffcanvas');
      if (!target) return;
      e.preventDefault();
      panel.addEventListener('hidden.bs.offcanvas', () => target.scrollIntoView({ behavior: 'smooth', block: 'start' }), { once: true });
      bootstrap.Offcanvas.getOrCreateInstance(panel).hide();
    });
  });
</script>
