<?php
/*
 * Paste $v3.2 2025/09/02 https://github.com/boxlabss/PASTE
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

// Calculate paste size based on $op_content
$paste_size = isset($op_content) ? formatSize(strlen($op_content)) : '0 bytes';

// highlighter theme switcher - only if $highlighter = 'highlight' in config.php
$effective_code    = $p_code_effective ?? $p_code ?? 'text';
$showThemeSwitcher = (($highlighter ?? 'geshi') === 'highlight') && ($effective_code !== 'markdown');
$hl_theme_options  = [];
$initialTheme      = null;

// display label preferring detected label, then map, then friendly fallback
$display_code = $p_code_label
  ?? ($geshiformats[$effective_code] ?? (function_exists('paste_friendly_label') ? paste_friendly_label($effective_code) : strtoupper($effective_code)));

if ($showThemeSwitcher) {
    $candidatesWeb = ['includes/Highlight/styles'];
    $stylesRel = null;
    foreach ($candidatesWeb as $rel) {
        $abs = __DIR__ . '/../../' . $rel;
        if (is_dir($abs)) { $stylesRel = $rel; break; }
    }
    if ($stylesRel) {
        $stylesAbs = __DIR__ . '/../../' . $stylesRel;
        $seen = [];
        foreach (glob($stylesAbs . '/*.css') ?: [] as $f) {
            $file = basename($f);
            $base = preg_replace('~\.min\.css$~', '.css', $file);
            $id   = basename($base, '.css');
            if (isset($seen[$id])) continue;
            $seen[$id] = true;

            $name = ucwords(str_replace(['-', '_'], ' ', $id));
            $hl_theme_options[] = [
                'id'   => $id,
                'name' => $name,
                'href' => rtrim($baseurl ?? '/', '/') . '/' . $stylesRel . '/' . $file,
            ];
        }
        usort($hl_theme_options, fn($a,$b) => strnatcasecmp($a['name'], $b['name']));
    }

    // query param seed (?theme=dracula, ?theme=atelier estuary dark, etc.)
    if (isset($_GET['theme'])) {
        $g = (string) $_GET['theme'];
        $g = strtolower($g);
        $g = str_replace(['+', ' '], '-', $g);
        $g = str_replace('_', '-', $g);
        $g = preg_replace('~-+~', '-', $g);
        $g = preg_replace('~\.css$~', '', $g);
        $g = preg_replace('~[^a-z0-9.-]~', '', $g);
        $initialTheme = $g;
    }
}

// Detection labels (used in modal)
$srcMap = [
    'shebang'=>'Shebang','modeline'=>'Editor modeline','fence'=>'Code fence',
    'filename'=>'Filename','markdown'=>'Markdown','heuristic'=>'Heuristics',
    'hljs'=>'Highlighter auto','hljs-auto'=>'Highlighter auto',
    'php-tag'=>'PHP tag','explicit'=>'Selected','fallback'=>'Fallback'
];
$srcLabel = $srcMap[$p_code_source ?? ''] ?? null;

// Common URLs for comment actions + login return
$basePasteUrl = ($mod_rewrite == '1')
    ? rtrim($baseurl ?? '/', '/') . '/' . (int)($paste_id ?? 0)
    : rtrim($baseurl ?? '/', '/') . '/paste.php?id=' . (int)($paste_id ?? 0);

$commentsActionUrl = $basePasteUrl . '#comments';

$loginNext = function(string $frag = '#comments') use ($basePasteUrl, $baseurl) {
    return rtrim($baseurl ?? '/', '/') . '/login.php?next=' . rawurlencode($basePasteUrl . $frag);
};

// Quick diff: current paste
$diffQuickUrl = null; //if no pastes yet
if (!empty($paste_id)) {
  $diffQuickUrl = rtrim($baseurl ?? '/', '/') . '/diff.php?a=' . (int)$paste_id . '&b=' . (int)$paste_id;
}

$diffUrl = null;
// Diff URL (if this paste is a fork of another - future idea)
//$diffUrl = (isset($paste_parent_id, $paste_id) && (int)$parent_id > 0 && (int)$paste_id > 0)
//  ? rtrim($baseurl ?? '/', '/') . '/diff.php?a=' . (int)$parent_id . '&b=' . (int)$paste_id
//  : null;

// Convenience: detect if comments are globally disabled (from paste.php logic)
$show_comments_ui = isset($show_comments) ? (bool)$show_comments : true;

/* ---------------------------
   Comment helpers (safe + UX)
   --------------------------- */

// total count including replies (for badge)
if (!function_exists('count_comments_total')) {
    function count_comments_total($list): int {
        if (!is_array($list) || !$list) return 0;
        // If it’s a flat list (no 'children'), just count
        if (!isset($list[0]['children'])) return (int)count($list);
        $sum = 0;
        foreach ($list as $n) {
            $sum++;
            if (!empty($n['children']) && is_array($n['children'])) {
                $sum += count_comments_total($n['children']);
            }
        }
        return $sum;
    }
}

// descendants counter for a single node (used by "Show X more replies")
if (!function_exists('count_descendants')) {
    function count_descendants(array $node): int {
        $sum = 0;
        if (!empty($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $ch) {
                $sum++;
                $sum += count_descendants($ch);
            }
        }
        return $sum;
    }
}

// Safe body_html fallback (sanitizes body when needed)
if (!function_exists('comment_body_html_safe')) {
    function comment_body_html_safe(array $c): string {
        return isset($c['body_html'])
            ? (string)$c['body_html']
            : render_comment_html((string)($c['body'] ?? ''));
    }
}

// Simple recursive render for threaded comments
if (!function_exists('render_comment_node')) {
    /**
     * @param array    $c           Comment node (may contain children[])
     * @param bool     $can_comment Current user can reply?
     * @param string   $commentsActionUrl POST target for comment actions
     * @param string   $csrf       CSRF token
     * @param callable $loginNext  login redirect builder
     * @param int      $depth      current nesting depth (0-based)
     * @param int      $maxDepth   depth after which children are collapsed
     */
    function render_comment_node(
        array $c,
        bool $can_comment,
        string $commentsActionUrl,
        string $csrf,
        callable $loginNext,
        int $depth = 0,
        int $maxDepth = 3
    ) {
        // bring file-scope into function
        global $baseurl, $mod_rewrite;
        $___base = rtrim((string)($baseurl ?? ''), '/') . '/';
        $___mr   = ((string)($mod_rewrite ?? '1') === '1');

        $id         = (int)($c['id'] ?? 0);
        $username   = (string)($c['username'] ?? 'Guest');
        $ts         = isset($c['created_at']) ? strtotime((string)$c['created_at']) : 0;
        $ago        = $ts ? (conTime($ts) . ' ago') : '';
        $body_html  = comment_body_html_safe($c);
        $can_delete = !empty($c['can_delete']);
        $children   = is_array($c['children'] ?? null) ? $c['children'] : [];
        $initial    = strtoupper(mb_substr($username !== '' ? $username : 'G', 0, 1, 'UTF-8'));
        $hasKids    = !empty($children);
        $clamped    = ($depth >= $maxDepth) && $hasKids;
        $threadId   = 'thread-' . $id;
        $descCnt    = $clamped ? count_descendants($c) : 0;

        // For visual clamp: cap left padding and show a subtle thread rail
        $depthStyle = '--d:' . (int)$depth . ';';

        ?>
        <li id="c-<?php echo $id; ?>"
            class="list-group-item bg-body comment-item depth-<?php echo (int)$depth; ?>"
            style="<?php echo $depthStyle; ?>">
          <div class="d-flex gap-3">
            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 comment-avatar">
              <span class="fw-bold"><?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>

            <div class="flex-grow-1 comment-main">
              <div class="d-flex align-items-center justify-content-between mb-1">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                  <span class="fw-semibold">
                    <?php
                      $cu = trim($username);
                      if ($cu !== '' && strcasecmp($cu, 'Guest') !== 0) {
                          $cu_href = $___mr
                              ? ($___base . 'user/' . rawurlencode($cu))
                              : ($___base . 'user.php?user=' . rawurlencode($cu));
                          echo '<a href="' . htmlspecialchars($cu_href, ENT_QUOTES, 'UTF-8') . '" class="text-decoration-none">'
                             . htmlspecialchars($cu, ENT_QUOTES, 'UTF-8') . '</a>';
                      } else {
                          echo htmlspecialchars($cu ?: 'Guest', ENT_QUOTES, 'UTF-8');
                      }
                    ?>
                  </span>
                  <?php if ($ago): ?><span class="text-muted small"><?php echo htmlspecialchars($ago, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                  <a href="#c-<?php echo $id; ?>" class="comment-permalink ms-1 text-decoration-none" aria-label="Permalink">#</a>
                </div>
                <div class="d-flex align-items-center gap-2">
                  <?php if ($can_comment): ?>
                    <button type="button" class="btn btn-link btn-sm p-0 comment-reply" data-target="#reply-form-<?php echo $id; ?>">
                      <i class="bi bi-reply"></i> Reply
                    </button>
                  <?php endif; ?>
                  <?php if ($can_delete): ?>
                    <form method="post"
                          action="<?php echo htmlspecialchars($commentsActionUrl, ENT_QUOTES, 'UTF-8'); ?>"
                          class="d-inline"
                          onsubmit="return confirm('Delete this comment? This will remove its entire thread.');">
                      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                      <input type="hidden" name="action" value="delete_comment">
                      <input type="hidden" name="comment_id" value="<?php echo $id; ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger border-0" title="Delete">
                        <i class="bi bi-trash"></i>
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>

              <div class="comment-body lh-base">
                <?php echo $body_html; // already sanitized/rendered ?>
              </div>

              <?php if ($can_comment): ?>
                <div id="reply-form-<?php echo $id; ?>" class="mt-2 d-none">
                  <form method="post" action="<?php echo htmlspecialchars($commentsActionUrl, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="add_comment">
                    <input type="hidden" name="parent_id" value="<?php echo $id; ?>">
                    <div class="mb-2">
                      <textarea
                        class="form-control"
                        name="comment_body"
                        rows="3"
                        minlength="1"
                        maxlength="4000"
                        placeholder="Write a reply…"
                        required
                      ></textarea>
                      <div class="d-flex justify-content-end mt-1">
                        <small class="text-muted"><span class="c-remaining">4000</span> chars left</small>
                      </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2">
                      <button type="button" class="btn btn-outline-secondary btn-sm reply-cancel" data-target="#reply-form-<?php echo $id; ?>">Cancel</button>
                      <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-send"></i> Post reply</button>
                    </div>
                  </form>
                </div>
              <?php else: ?>
                <div class="mt-2">
                  <a class="btn btn-link btn-sm p-0" href="<?php echo htmlspecialchars($loginNext('#reply-form-' . $id), ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="bi bi-box-arrow-in-right"></i> Log in to reply
                  </a>
                </div>
              <?php endif; ?>

              <?php if (!empty($children)): ?>
                <?php if ($clamped): ?>
                  <!-- Collapsed tail -->
                  <div class="mt-2">
                    <button class="btn btn-outline-secondary btn-sm comment-expand" data-target="#<?php echo $threadId; ?>">
                      <i class="bi bi-chevron-down"></i> Show <?php echo (int)$descCnt; ?> more repl<?php echo $descCnt===1?'y':'ies'; ?>
                    </button>
                  </div>
                  <ul id="<?php echo $threadId; ?>" class="list-group list-group-flush mt-2 d-none" style="--pd: <?php echo (int)$depth; ?>;">
                    <?php foreach ($children as $ch) { render_comment_node($ch, $can_comment, $commentsActionUrl, $csrf, $loginNext, $depth + 1, $maxDepth); } ?>
                  </ul>
                <?php else: ?>
                  <!-- Inline children -->
                  <ul class="list-group list-group-flush mt-2" style="--pd: <?php echo (int)$depth; ?>;">
                    <?php foreach ($children as $ch) { render_comment_node($ch, $can_comment, $commentsActionUrl, $csrf, $loginNext, $depth + 1, $maxDepth); } ?>
                  </ul>
                <?php endif; ?>
              <?php endif; ?>

            </div>
          </div>
        </li>
        <?php
    }
}
?>

<!-- Content -->
<div class="container-xl my-4">
  <div class="row">
<?php if (isset($privatesite) && $privatesite === "on"): ?>

    <!-- Private site: Main content full width, sidebar below -->
    <div class="col-lg-12">
      <?php if (!isset($_SESSION['username'])): ?>
        <div class="card">
          <div class="card-body">
            <div class="alert alert-warning d-flex align-items-center justify-content-between">
              <div>
                <?php echo htmlspecialchars($lang['login_required'] ?? 'You must be logged in to view this paste.', ENT_QUOTES, 'UTF-8'); ?>
              </div>
              <a href="<?php echo htmlspecialchars($loginNext('#comments'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary mt-2 mt-sm-0">Login</a>
            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <!-- Paste Info -->
            <div class="paste-info">
              <h1 class="h3 mb-2"><?php echo ucfirst(htmlspecialchars($p_title ?? 'Untitled')); ?></h1>
              <div class="meta d-flex flex-wrap gap-2 text-muted small align-items-center">
                <span class="badge bg-primary"><?php echo htmlspecialchars($display_code); ?></span>
                <?php if (!empty($p_code_source) && $p_code_source !== 'explicit' && !empty($p_code_explain)): ?>
                    <span class="text-muted small">
                      Detected
                      <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-bs-toggle="modal" data-bs-target="#detectedExplainModal" title="Why this language?">
                        <i class="bi bi-question-circle"></i>
                      </button>
                    </span>
                <?php endif; ?>
                <span>
                <?php
                  $p_member_display = $p_member ?? 'Guest';
                  if ($p_member_display === 'Guest') {
                      echo 'Guest';
                  } else {
                      $user_link = $mod_rewrite ?? false
                          ? htmlspecialchars($baseurl . 'user/' . $p_member_display)
                          : htmlspecialchars($baseurl . 'user.php?user=' . $p_member_display);
                      echo '<a href="' . $user_link . '" class="text-decoration-none">' . htmlspecialchars($p_member_display) . '</a>';
                  }
                ?>
                </span>
                <span><i class="bi bi-eye me-1"></i><?php echo htmlspecialchars((string) ($p_views ?? 0)); ?> <?php echo htmlspecialchars($lang['views'] ?? 'Views'); ?></span>
                <span>Size: <?php echo htmlspecialchars($paste_size); ?></span>
                <span>Posted on: <?php echo htmlspecialchars($p_date ? date('M j, y @ g:i A', strtotime($p_date)) : date('M j, Y, g:i A')); ?></span>
              </div>
            </div>

            <!-- Paste Actions -->
            <div class="btn-group btn-group-sm ms-auto" role="group" aria-label="Paste actions">
              <a href="#comments" class="btn btn-outline-secondary" title="Jump to comments">
                <i class="bi bi-chat-square-text"></i>
              </a>
              <?php if (!empty($showThemeSwitcher) && !empty($hl_theme_options)): ?>
                <select class="form-select form-select-sm btn-select hljs-theme-select order-first me-0" title="Code Theme">
                  <?php foreach ($hl_theme_options as $opt): 
                        $sel = ($initialTheme && $opt['id'] === $initialTheme) ? ' selected' : '';
                  ?>
                    <option value="<?php echo htmlspecialchars($opt['id']); ?>" data-href="<?php echo htmlspecialchars($opt['href']); ?>"<?php echo $sel; ?>>
                      <?php echo htmlspecialchars($opt['name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              <?php endif; ?>

              <?php if ($effective_code !== "markdown"): ?>
                <button type="button" class="btn btn-outline-secondary toggle-line-numbers" title="Toggle Line Numbers" onclick="togglev()">
                  <i class="bi bi-list-ol"></i>
                </button>
              <?php endif; ?>
              <button type="button" class="btn btn-outline-secondary toggle-fullscreen" title="Full Screen" onclick="toggleFullScreen()">
                <i class="bi bi-arrows-fullscreen"></i>
              </button>
              <button type="button" class="btn btn-outline-secondary copy-clipboard" title="Copy to Clipboard" onclick="copyToClipboard()">
                <i class="bi bi-clipboard"></i>
              </button>
              <?php
                $embed_url  = getEmbedUrl($paste_id ?? '', $mod_rewrite ?? false, $baseurl ?? '');
                $embed_code = $paste_id ? '<iframe src="' . htmlspecialchars($embed_url, ENT_QUOTES, 'UTF-8') . '" width="100%" height="400px" frameborder="0" allowfullscreen></iframe>' : '';
              ?>
              <button type="button" class="btn btn-outline-secondary embed-tool" title="Embed Paste" onclick="showEmbedCode('<?php echo addslashes(htmlspecialchars($embed_code, ENT_QUOTES, 'UTF-8')); ?>')">
                <i class="bi bi-code-square"></i>
              </button>
              <a href="<?php echo htmlspecialchars($p_raw ?? ($baseurl . '/raw.php?id=' . ($paste_id ?? ''))); ?>" class="btn btn-outline-secondary" title="Raw Paste">
                <i class="bi bi-file-text"></i>
              </a>
              <a href="<?php echo htmlspecialchars($p_download ?? ($baseurl . '/download.php?id=' . ($paste_id ?? ''))); ?>" class="btn btn-outline-secondary" title="Download">
                <i class="bi bi-file-arrow-down"></i>
              </a>

              <?php if ($diffUrl): ?>
                <!-- Compact Diff button -->
                <a href="<?php echo htmlspecialchars($diffUrl, ENT_QUOTES, 'UTF-8'); ?>"
                   class="btn btn-outline-secondary" title="View differences from parent">
                  <i class="bi bi-arrow-left-right"></i>
                </a>
              <?php endif; ?>
            </div>
            <div id="notification" class="notification"></div>
          </div>

          <div class="card-body">
            <?php if (isset($error)): ?>
              <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php else: ?>
              <!-- Code block: movable into fullscreen modal -->
              <div class="code-content" id="code-content"><?php echo $p_content ?? ''; ?></div>
              <span id="code-content-home"></span>
            <?php endif; ?>

            <!-- Raw paste (lazy load; JS wraps textarea later) -->
            <div class="mb-3 position-relative" id="raw-block"
                 data-raw-url="<?php echo htmlspecialchars($p_raw ?? ($baseurl . '/raw.php?id=' . ($paste_id ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
              <p><?php echo htmlspecialchars($lang['rawpaste'] ?? 'Raw Paste'); ?></p>
              <button type="button" id="load-raw" class="btn btn-outline-secondary btn-sm">Load Raw</button>
              <textarea class="form-control d-none" rows="15" id="code" readonly></textarea>
              <div id="line-number-tooltip" class="line-number-tooltip"></div>
            </div>

            <!-- Fork/Edit buttons for guests -->
            <div class="btn-group" role="group" aria-label="Fork and Edit actions">
              <?php if (!isset($_SESSION['username']) && (!isset($privatesite) || $privatesite != "on")): ?>
                <a href="#" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#signin" title="Login or Register to fork this paste">
                  <i class="bi bi-git"></i> Fork
                </a>
                <a href="#" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#signin" title="Login or Register to edit this paste">
                  <i class="bi bi-pencil"></i> Edit
                </a>
              <?php endif; ?>

              <?php if ($diffUrl): ?>
                <!-- Big Diff button (guests & logged-in) -->
                <a class="btn btn-outline-secondary"
                   href="<?php echo htmlspecialchars($diffUrl, ENT_QUOTES, 'UTF-8'); ?>"
                   title="View differences from parent">
                  <i class="bi bi-arrow-left-right"></i> View differences
                </a>
              <?php endif; ?>
            </div>

            <?php if (isset($_SESSION['username'])): ?>
              <!-- Paste Edit/Fork Form -->
              <div class="mt-3">
                <div class="card">
                  <div class="card-header"><?php echo htmlspecialchars($lang['modpaste'] ?? 'Modify Paste'); ?></div>
                  <div class="card-body">
                    <form class="form-horizontal" name="mainForm" action="index.php" method="POST">
                      <div class="row mb-3 g-3">
                        <div class="col-sm-4">
                          <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-fonts"></i></span>
                            <input type="text" class="form-control" name="title" placeholder="<?php echo htmlspecialchars($lang['pastetitle'] ?? 'Paste Title'); ?>" value="<?php echo htmlspecialchars(ucfirst($p_title ?? 'Untitled')); ?>">
                          </div>
                        </div>
                        <div class="col-sm-4">
                          <select class="form-select" name="format">
                            <?php
                              $geshiformats     = $geshiformats ?? [];
                              $popular_formats  = $popular_formats ?? [];
                              foreach ($geshiformats as $code => $name) {
                                  if (in_array($code, $popular_formats)) {
                                      $sel = ($effective_code === $code) ? 'selected' : '';
                                      echo '<option ' . $sel . ' value="' . htmlspecialchars($code) . '">' . htmlspecialchars($name) . '</option>';
                                  }
                              }
                              echo '<option value="text">-------------------------------------</option>';
                              foreach ($geshiformats as $code => $name) {
                                  if (!in_array($code, $popular_formats)) {
                                      $sel = ($effective_code === $code) ? 'selected' : '';
                                      echo '<option ' . $sel . ' value="' . htmlspecialchars($code) . '">' . htmlspecialchars($name) . '</option>';
                                  }
                              }
                            ?>
                          </select>
                        </div>
                        <div class="col-sm-2 ms-auto">
                          <a class="btn btn-secondary highlight-line" href="#" title="Highlight selected lines"><i class="bi bi-text-indent-left"></i> Highlight</a>
                        </div>
                      </div>

                      <div class="mb-3">
                        <textarea class="form-control" rows="15" id="edit-code" name="paste_data" placeholder="helloworld"><?php echo htmlspecialchars($op_content ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                      </div>

                      <div class="row mb-3">
                        <label class="col-sm-2 col-form-label"><?php echo htmlspecialchars($lang['expiration'] ?? 'Expiration'); ?></label>
                        <div class="col-sm-10">
                          <select class="form-select" name="paste_expire_date">
                            <option value="N"    <?php echo ($p_expire_date ?? 'N')  == "N"    ? 'selected' : ''; ?>>Never</option>
                            <option value="self" <?php echo ($p_expire_date ?? 'N')  == "self" ? 'selected' : ''; ?>>View Once</option>
                            <option value="10M"  <?php echo ($p_expire_date ?? 'N')  == "10M"  ? 'selected' : ''; ?>>10 Minutes</option>
                            <option value="1H"   <?php echo ($p_expire_date ?? 'N')  == "1H"   ? 'selected' : ''; ?>>1 Hour</option>
                            <option value="1D"   <?php echo ($p_expire_date ?? 'N')  == "1D"   ? 'selected' : ''; ?>>1 Day</option>
                            <option value="1W"   <?php echo ($p_expire_date ?? 'N')  == "1W"   ? 'selected' : ''; ?>>1 Week</option>
                            <option value="2W"   <?php echo ($p_expire_date ?? 'N')  == "2W"   ? 'selected' : ''; ?>>2 Weeks</option>
                            <option value="1M"   <?php echo ($p_expire_date ?? 'N')  == "1M"   ? 'selected' : ''; ?>>1 Month</option>
                          </select>
                        </div>
                      </div>

                      <div class="row mb-3">
                        <label class="col-sm-2 col-form-label"><?php echo htmlspecialchars($lang['visibility'] ?? 'Visibility'); ?></label>
                        <div class="col-sm-10">
                          <select class="form-select" name="visibility">
                            <option value="0" <?php echo ($p_visible ?? '0') == "0" ? 'selected' : ''; ?>>Public</option>
                            <option value="1" <?php echo ($p_visible ?? '0') == "1" ? 'selected' : ''; ?>>Unlisted</option>
                            <option value="2" <?php echo ($p_visible ?? '0') == "2" ? 'selected' : ''; ?>>Private</option>
                          </select>
                        </div>
                      </div>

                      <div class="mb-3">
                        <div class="input-group">
                          <span class="input-group-text"><i class="bi bi-lock"></i></span>
                          <input type="text" class="form-control" name="pass" id="pass" placeholder="<?php echo htmlspecialchars($lang['pwopt'] ?? 'Optional Password'); ?>">
                        </div>
                      </div>

                      <div class="d-grid gap-2">
                        <input type="hidden" name="paste_id" value="<?php echo htmlspecialchars($paste_id ?? ''); ?>" />
                        <?php if (isset($_SESSION['username']) && $_SESSION['username'] == ($p_member ?? 'Guest')): ?>
                          <input class="btn btn-primary paste-button" type="submit" name="edit" id="edit" value="<?php echo htmlspecialchars($lang['editpaste'] ?? 'Edit Paste'); ?>" />
                        <?php endif; ?>
                        <input class="btn btn-primary paste-button" type="submit" name="submit" id="submit" value="<?php echo htmlspecialchars($lang['forkpaste'] ?? 'Fork Paste'); ?>" />

                        <?php if ($diffUrl): ?>
                          <!-- Diff link styled like a button -->
                          <a class="btn btn-outline-secondary paste-button"
                             href="<?php echo htmlspecialchars($diffUrl, ENT_QUOTES, 'UTF-8'); ?>"
                             title="View differences from parent">
                            <i class="bi bi-arrow-left-right"></i> View differences
                          </a>
                        <?php endif; ?>
                      </div>
                    </form>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          </div>

          <!-- Full Screen Modal -->
          <div class="modal fade" id="fullscreenModal" tabindex="-1" aria-labelledby="fullscreenModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-fullscreen">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title" id="fullscreenModalLabel"><?php echo htmlspecialchars($p_title ?? 'Untitled'); ?></h5>
                  <?php if (!empty($showThemeSwitcher) && !empty($hl_theme_options)): ?>
                  <div class="ms-2" style="min-width:180px">
                    <select class="form-select form-select-sm hljs-theme-select" title="Code Theme">
                      <?php foreach ($hl_theme_options as $opt): 
                            $sel = ($initialTheme && $opt['id'] === $initialTheme) ? ' selected' : '';
                      ?>
                        <option value="<?php echo htmlspecialchars($opt['id']); ?>" data-href="<?php echo htmlspecialchars($opt['href']); ?>"<?php echo $sel; ?>>
                          <?php echo htmlspecialchars($opt['name']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <?php endif; ?>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                  <!-- host where #code-content is moved to -->
                  <div id="fullscreen-host"></div>
                </div>
              </div>
            </div>
          </div>

          <?php if (!empty($p_code_source) && $p_code_source !== 'explicit' && !empty($p_code_explain)): ?>
          <!-- Detection Explain Modal -->
          <div class="modal fade" id="detectedExplainModal" tabindex="-1" aria-labelledby="detectedExplainLabel" aria-hidden="true">
            <div class="modal-dialog">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title" id="detectedExplainLabel">How we detected the language</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                  <p class="mb-1"><strong>Language:</strong> <?php echo htmlspecialchars($display_code); ?></p>
                  <?php if (!empty($srcLabel)): ?>
                  <p class="mb-2"><strong>Detected from:</strong> <?php echo htmlspecialchars($srcLabel); ?></p>
                  <?php endif; ?>
                  <pre class="small bg-dark p-2 border rounded" style="white-space: pre-wrap;"><?php echo htmlspecialchars($p_code_explain); ?></pre>
                  <hr>
                  <p class="small text-muted mb-0">
                    Tips: Add a file extension to the title (e.g., <code>.php</code>, <code>.py</code>) or choose a language explicitly to override autodetection.
                  </p>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
              </div>
            </div>
          </div>
          <?php endif; ?>

        </div>

        <!-- ===== Comments (Private) ===== -->
        <?php if ($show_comments_ui): ?>
        <div class="mt-5" id="comments">
          <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-light d-flex align-items-center justify-content-between">
              <div class="d-flex align-items-center gap-2">
                <i class="bi bi-chat-square-text"></i>
                <span class="fw-semibold">Comments</span>
                <a class="badge bg-secondary text-decoration-none" href="#comments">
                  <span id="comments-count"><?php echo (int)count_comments_total($comments ?? []); ?></span>
                </a>
              </div>
              <?php if (!$can_comment): ?>
                <small class="text-light-50">Login to join the discussion</small>
              <?php endif; ?>
            </div>

            <div class="card-body p-0">
              <?php if (!empty($comment_error)): ?>
                <div class="alert alert-danger rounded-0 m-0"><?php echo htmlspecialchars($comment_error, ENT_QUOTES, 'UTF-8'); ?></div>
              <?php endif; ?>

              <!-- List -->
              <ul class="list-group list-group-flush">
                <?php if (!empty($comments)): ?>
                  <?php
                    $hasTree = isset($comments[0]) && is_array($comments[0]) && array_key_exists('children', $comments[0]);
                    if ($hasTree) {
                        foreach ($comments as $c) { render_comment_node($c, $can_comment, $commentsActionUrl, $_SESSION['csrf_token'] ?? '', $loginNext, 0, 3); }
                    } else {
                        foreach ($comments as $c):
                          $cid = (int)$c['id'];
                  ?>
                    <li id="c-<?php echo $cid; ?>" class="list-group-item bg-body comment-item" style="--d:0;">
                      <div class="d-flex gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 comment-avatar">
                          <span class="fw-bold"><?php echo htmlspecialchars(strtoupper(mb_substr($c['username'],0,1,'UTF-8')), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>

                        <div class="flex-grow-1 comment-main">
                          <div class="d-flex align-items-center justify-content-between mb-1">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                              <span class="fw-semibold">
                                <?php
                                  $cu = trim((string)($c['username'] ?? ''));
                                  if ($cu !== '' && strcasecmp($cu, 'Guest') !== 0) {
                                      $cu_href = ($mod_rewrite ?? false)
                                          ? ($baseurl . 'user/' . rawurlencode($cu))
                                          : ($baseurl . 'user.php?user=' . rawurlencode($cu));
                                      echo '<a href="' . htmlspecialchars($cu_href, ENT_QUOTES, 'UTF-8') . '" class="text-decoration-none">'
                                         . htmlspecialchars($cu, ENT_QUOTES, 'UTF-8') . '</a>';
                                  } else {
                                      echo htmlspecialchars($cu ?: 'Guest', ENT_QUOTES, 'UTF-8');
                                  }
                                ?>
                              </span>
                              <span class="text-muted small">
                                <?php
                                  $ts = strtotime($c['created_at']);
                                  echo $ts ? htmlspecialchars(conTime($ts) . ' ago', ENT_QUOTES, 'UTF-8') : '';
                                ?>
                              </span>
                              <a href="#c-<?php echo $cid; ?>" class="comment-permalink ms-1 text-decoration-none" aria-label="Permalink">#</a>
                            </div>
                            <?php if (!empty($c['can_delete'])): ?>
                              <form method="post"
                                    action="<?php echo htmlspecialchars($commentsActionUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                    class="d-inline"
                                    onsubmit="return confirm('Delete this comment? This will remove its entire thread.');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="delete_comment">
                                <input type="hidden" name="comment_id" value="<?php echo $cid; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger border-0" title="Delete">
                                  <i class="bi bi-trash"></i>
                                </button>
                              </form>
                            <?php endif; ?>
                          </div>

                          <div class="comment-body lh-base">
                            <?php echo comment_body_html_safe($c); ?>
                          </div>

                          <?php if ($can_comment): ?>
                            <div class="mt-2">
                              <button type="button" class="btn btn-link btn-sm p-0 comment-reply" data-target="#reply-form-<?php echo $cid; ?>">
                                <i class="bi bi-reply"></i> Reply
                              </button>
                            </div>
                            <div id="reply-form-<?php echo $cid; ?>" class="mt-2 d-none">
                              <form method="post" action="<?php echo htmlspecialchars($commentsActionUrl, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="add_comment">
                                <input type="hidden" name="parent_id" value="<?php echo $cid; ?>">
                                <div class="mb-2">
                                  <textarea class="form-control" name="comment_body" rows="3" minlength="1" maxlength="4000" placeholder="Write a reply…" required></textarea>
                                  <div class="d-flex justify-content-end mt-1">
                                    <small class="text-muted"><span class="c-remaining">4000</span> chars left</small>
                                  </div>
                                </div>
                                <div class="d-flex justify-content-end gap-2">
                                  <button type="button" class="btn btn-outline-secondary btn-sm reply-cancel" data-target="#reply-form-<?php echo $cid; ?>">Cancel</button>
                                  <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-send"></i> Post reply</button>
                                </div>
                              </form>
                            </div>
                          <?php else: ?>
                            <div class="mt-2">
                              <a class="btn btn-link btn-sm p-0" href="<?php echo htmlspecialchars($loginNext('#reply-form-' . $cid), ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="bi bi-box-arrow-in-right"></i> Log in to reply
                              </a>
                            </div>
                          <?php endif; ?>
                        </div>
                      </div>
                    </li>
                  <?php endforeach; } ?>
                <?php else: ?>
                  <li class="list-group-item bg-body text-center text-muted py-5">
                    No comments yet — be the first!
                  </li>
                <?php endif; ?>
              </ul>

              <!-- Composer -->
              <div class="p-3 border-top">
                <?php if ($can_comment): ?>
                  <form method="post" action="<?php echo htmlspecialchars($commentsActionUrl, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="add_comment">

                    <div class="mb-2">
                      <textarea
                        class="form-control"
                        id="comment-body-main"
                        name="comment_body"
                        rows="3"
                        minlength="1"
                        maxlength="4000"
                        placeholder="Write a thoughtful comment…"
                        required
                      ></textarea>
                      <div class="d-flex justify-content-between mt-1">
                        <small class="text-muted">Markdown is not enabled; links will be auto-linked.</small>
                        <small class="text-muted"><span id="c-remaining">4000</span> chars left</small>
                      </div>
                    </div>
                    <div class="d-flex justify-content-end">
                      <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send"></i> Post comment
                      </button>
                    </div>
                  </form>
                <?php else: ?>
                  <div class="alert alert-info mb-0">
                    <div class="d-flex align-items-center justify-content-between">
                      <span>Login to post a comment.</span>
                      <a class="btn btn-sm btn-outline-primary"
                         href="<?php echo htmlspecialchars($loginNext('#comments'), ENT_QUOTES, 'UTF-8'); ?>">
                        <i class="bi bi-box-arrow-in-right"></i> Login
                      </a>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
        <?php else: ?>
          <div class="mt-5" id="comments">
            <div class="alert alert-secondary">Comments have been disabled.</div>
          </div>
        <?php endif; ?>

      <?php endif; ?>
    </div>

    <div class="sidebar-below<?php echo (isset($privatesite) && $privatesite === 'on') ? ' sidebar-below' : ''; ?>">
      <?php require_once('theme/' . ($default_theme ?? 'default') . '/sidebar.php'); ?>
    </div>

<?php else: ?>

    <!-- Non-private site: Main content and sidebar side by side -->
    <div class="col-lg-10">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
          <!-- Paste Info -->
          <div class="paste-info">
            <h1 class="h3 mb-2"><?php echo ucfirst(htmlspecialchars($p_title ?? 'Untitled')); ?></h1>
            <div class="meta d-flex flex-wrap gap-2 text-muted small align-items-center">
              <span class="badge bg-primary"><?php echo htmlspecialchars($display_code); ?></span>
              <?php if (!empty($p_code_source) && $p_code_source !== 'explicit' && !empty($p_code_explain)): ?>
                  <span class="text-muted small">
                    Detected
                    <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-bs-toggle="modal" data-bs-target="#detectedExplainModal" title="Why this language?">
                      <i class="bi bi-question-circle"></i>
                    </button>
                  </span>
              <?php endif; ?>
              <span>
              <?php
                $p_member_display = $p_member ?? 'Guest';
                if ($p_member_display === 'Guest') {
                    echo 'Guest';
                } else {
                    $user_link = $mod_rewrite ?? false
                        ? htmlspecialchars($baseurl . 'user/' . $p_member_display)
                        : htmlspecialchars($baseurl . 'user.php?user=' . $p_member_display);
                    echo '<a href="' . $user_link . '" class="text-decoration-none">' . htmlspecialchars($p_member_display) . '</a>';
                }
              ?>
              </span>
              <span><i class="bi bi-eye me-1"></i><?php echo htmlspecialchars((string) ($p_views ?? 0)); ?> <?php echo htmlspecialchars($lang['views'] ?? 'Views'); ?></span>
              <span>Size: <?php echo htmlspecialchars($paste_size); ?></span>
              <span>Posted on: <?php echo htmlspecialchars($p_date ? date('M j, y @ g:i A', strtotime($p_date)) : date('M j, Y, g:i A')); ?></span>
            </div>
          </div>

          <!-- Actions -->
          <div class="btn-group btn-group-sm ms-auto" role="group" aria-label="Paste actions">
            <a href="#comments" class="btn btn-outline-secondary" title="Jump to comments">
              <i class="bi bi-chat-square-text"></i>
            </a>
            <?php if (!empty($showThemeSwitcher) && !empty($hl_theme_options)): ?>
              <select
                class="form-select form-select-sm btn-select hljs-theme-select order-first me-0"
                title="Code Theme">
                <?php foreach ($hl_theme_options as $opt): 
                      $sel = ($initialTheme && $opt['id'] === $initialTheme) ? ' selected' : '';
                ?>
                  <option value="<?php echo htmlspecialchars($opt['id']); ?>" data-href="<?php echo htmlspecialchars($opt['href']); ?>"<?php echo $sel; ?>>
                    <?php echo htmlspecialchars($opt['name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>

            <?php if ($effective_code !== "markdown"): ?>
              <button type="button" class="btn btn-outline-secondary toggle-line-numbers" title="Toggle Line Numbers" onclick="togglev()">
                <i class="bi bi-list-ol"></i>
              </button>
            <?php endif; ?>
            <button type="button" class="btn btn-outline-secondary toggle-fullscreen" title="Full Screen" onclick="toggleFullScreen()">
              <i class="bi bi-arrows-fullscreen"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary copy-clipboard" title="Copy to Clipboard" onclick="copyToClipboard()">
              <i class="bi bi-clipboard"></i>
            </button>
            <?php
              $embed_url  = getEmbedUrl($paste_id ?? '', $mod_rewrite ?? false, $baseurl ?? '');
              $embed_code = $paste_id ? '<iframe src="' . htmlspecialchars($embed_url, ENT_QUOTES, 'UTF-8') . '" width="100%" height="400px" frameborder="0" allowfullscreen></iframe>' : '';
            ?>
            <button type="button" class="btn btn-outline-secondary embed-tool" title="Embed Paste" onclick="showEmbedCode('<?php echo addslashes(htmlspecialchars($embed_code, ENT_QUOTES, 'UTF-8')); ?>')">
              <i class="bi bi-code-square"></i>
            </button>
            <a href="<?php echo htmlspecialchars($p_raw ?? ($baseurl . '/raw.php?id=' . ($paste_id ?? ''))); ?>" class="btn btn-outline-secondary" title="Raw Paste">
              <i class="bi bi-file-text"></i>
            </a>
            <a href="<?php echo htmlspecialchars($p_download ?? ($baseurl . '/download.php?id=' . ($paste_id ?? ''))); ?>" class="btn btn-outline-secondary" title="Download">
              <i class="bi bi-file-arrow-down"></i>
            </a>

            <?php if ($diffUrl): ?>
              <!-- Compact Diff button -->
              <a href="<?php echo htmlspecialchars($diffUrl, ENT_QUOTES, 'UTF-8'); ?>"
                 class="btn btn-outline-secondary" title="View differences from parent">
                <i class="bi bi-arrow-left-right"></i>
              </a>
            <?php endif; ?>

            <?php if (!isset($_SESSION['username']) && (!isset($privatesite) || $privatesite != "on") && ($disableguest ?? '') != "on"): ?>
              <a href="#" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#signin" title="Login or Register">
                <i class="bi bi-person"></i>
              </a>
            <?php endif; ?>
          </div>
          <div id="notification" class="notification"></div>
        </div>

        <div class="card-body">
          <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
          <?php else: ?>
            <!-- Code block: movable into fullscreen modal -->
            <div class="code-content" id="code-content"><?php echo $p_content ?? ''; ?></div>
            <span id="code-content-home"></span>
          <?php endif; ?>

          <!-- Raw paste (lazy load; textarea prefilled as fallback) -->
          <div class="mb-3 position-relative" id="raw-block"
               data-raw-url="<?php echo htmlspecialchars($p_raw ?? ($baseurl . '/raw.php?id=' . ($paste_id ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
            <p><?php echo htmlspecialchars($lang['rawpaste'] ?? 'Raw Paste'); ?></p>
            <button type="button" id="load-raw" class="btn btn-outline-secondary btn-sm">Load raw</button>
            <textarea class="form-control d-none" rows="15" id="code" readonly><?php
              echo htmlspecialchars($op_content ?? '', ENT_QUOTES, 'UTF-8');
            ?></textarea>
            <div id="line-number-tooltip" class="line-number-tooltip"></div>
          </div>

          <?php if (($disableguest ?? '') != "on" || isset($_SESSION['username'])): ?>
            <div class="btn-group" role="group" aria-label="Fork and Edit actions">
              <?php if (!isset($_SESSION['username']) && (!isset($privatesite) || $privatesite != "on")): ?>
                <a href="#" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#signin" title="Login or Register to fork this paste">
                  <i class="bi bi-git"></i> Fork
                </a>
                <a href="#" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#signin" title="Login or Register to edit this paste">
                  <i class="bi bi-pencil"></i> Edit
                </a>
              <?php endif; ?>

              <?php if ($diffUrl): ?>
                <!-- Big Diff button (guests & logged-in) -->
                <a class="btn btn-outline-secondary"
                   href="<?php echo htmlspecialchars($diffUrl, ENT_QUOTES, 'UTF-8'); ?>"
                   title="View differences from parent">
                  <i class="bi bi-arrow-left-right"></i> View differences
                </a>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if (isset($_SESSION['username'])): ?>
            <!-- Paste Edit/Fork Form -->
            <div class="mt-3">
              <div class="card">
                <div class="card-header"><?php echo htmlspecialchars($lang['modpaste'] ?? 'Modify Paste'); ?></div>
                <div class="card-body">
                  <form class="form-horizontal" name="mainForm" action="index.php" method="POST">
                    <div class="row mb-3 g-3">
                      <div class="col-sm-4">
                        <div class="input-group">
                          <span class="input-group-text"><i class="bi bi-fonts"></i></span>
                          <input type="text" class="form-control" name="title" placeholder="<?php echo htmlspecialchars($lang['pastetitle'] ?? 'Paste Title'); ?>" value="<?php echo htmlspecialchars(ucfirst($p_title ?? 'Untitled')); ?>">
                        </div>
                      </div>
                      <div class="col-sm-4">
                        <select class="form-select" name="format">
                          <?php
                            $geshiformats     = $geshiformats ?? [];
                            $popular_formats  = $popular_formats ?? [];
                            foreach ($geshiformats as $code => $name) {
                                if (in_array($code, $popular_formats)) {
                                    $sel = ($effective_code === $code) ? 'selected' : '';
                                    echo '<option ' . $sel . ' value="' . htmlspecialchars($code) . '">' . htmlspecialchars($name) . '</option>';
                                }
                            }
                            echo '<option value="text">-------------------------------------</option>';
                            foreach ($geshiformats as $code => $name) {
                                if (!in_array($code, $popular_formats)) {
                                    $sel = ($effective_code === $code) ? 'selected' : '';
                                    echo '<option ' . $sel . ' value="' . htmlspecialchars($code) . '">' . htmlspecialchars($name) . '</option>';
                                }
                            }
                          ?>
                        </select>
                      </div>
					  
                      <div class="col-sm-2 ms-auto">
						<?php if ($diffQuickUrl): ?>
						  <a href="<?php echo htmlspecialchars($diffQuickUrl, ENT_QUOTES, 'UTF-8'); ?>"
							 class="btn btn-secondary" title="View differences">
							<i class="bi bi-arrow-left-right"></i> Make.diff
						  </a>
						<?php endif; ?>
                      </div>

                      <div class="col-sm-2 ms-auto">
                        <a class="btn btn-secondary highlight-line" href="#" title="Highlight selected lines"><i class="bi bi-text-indent-left"></i> Highlight</a>
                      </div>
                    </div>
					
                    <div class="mb-3">
                      <textarea class="form-control" rows="15" id="edit-code" name="paste_data" placeholder="helloworld"><?php echo htmlspecialchars($op_content ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>

                    <div class="row mb-3">
                      <label class="col-sm-2 col-form-label"><?php echo htmlspecialchars($lang['expiration'] ?? 'Expiration'); ?></label>
                      <div class="col-sm-10">
                        <select class="form-select" name="paste_expire_date">
                          <option value="N"    <?php echo ($p_expire_date ?? 'N')  == "N"    ? 'selected' : ''; ?>>Never</option>
                          <option value="self" <?php echo ($p_expire_date ?? 'N')  == "self" ? 'selected' : ''; ?>>View Once</option>
                          <option value="10M"  <?php echo ($p_expire_date ?? 'N')  == "10M"  ? 'selected' : ''; ?>>10 Minutes</option>
                          <option value="1H"   <?php echo ($p_expire_date ?? 'N')  == "1H"   ? 'selected' : ''; ?>>1 Hour</option>
                          <option value="1D"   <?php echo ($p_expire_date ?? 'N')  == "1D"   ? 'selected' : ''; ?>>1 Day</option>
                          <option value="1W"   <?php echo ($p_expire_date ?? 'N')  == "1W"   ? 'selected' : ''; ?>>1 Week</option>
                          <option value="2W"   <?php echo ($p_expire_date ?? 'N')  == "2W"   ? 'selected' : ''; ?>>2 Weeks</option>
                          <option value="1M"   <?php echo ($p_expire_date ?? 'N')  == "1M"   ? 'selected' : ''; ?>>1 Month</option>
                        </select>
                      </div>
                    </div>

                    <div class="row mb-3">
                      <label class="col-sm-2 col-form-label"><?php echo htmlspecialchars($lang['visibility'] ?? 'Visibility'); ?></label>
                      <div class="col-sm-10">
                        <select class="form-select" name="visibility">
                          <option value="0" <?php echo ($p_visible ?? '0') == "0" ? 'selected' : ''; ?>>Public</option>
                          <option value="1" <?php echo ($p_visible ?? '0') == "1" ? 'selected' : ''; ?>>Unlisted</option>
                          <option value="2" <?php echo ($p_visible ?? '0') == "2" ? 'selected' : ''; ?>>Private</option>
                        </select>
                      </div>
                    </div>

                    <div class="mb-3">
                      <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="text" class="form-control" name="pass" id="pass" placeholder="<?php echo htmlspecialchars($lang['pwopt'] ?? 'Optional Password'); ?>">
                      </div>
                    </div>

                    <div class="d-grid gap-2">
                      <input type="hidden" name="paste_id" value="<?php echo htmlspecialchars($paste_id ?? ''); ?>" />
                      <?php if (isset($_SESSION['username']) && $_SESSION['username'] == ($p_member ?? 'Guest')): ?>
                        <input class="btn btn-primary paste-button" type="submit" name="edit" id="edit" value="<?php echo htmlspecialchars($lang['editpaste'] ?? 'Edit Paste'); ?>" />
                      <?php endif; ?>
                      <input class="btn btn-primary paste-button" type="submit" name="submit" id="submit" value="<?php echo htmlspecialchars($lang['forkpaste'] ?? 'Fork Paste'); ?>" />

                      <?php if ($diffUrl): ?>
                        <!-- Diff link styled like a button -->
                        <a class="btn btn-outline-secondary paste-button"
                           href="<?php echo htmlspecialchars($diffUrl, ENT_QUOTES, 'UTF-8'); ?>"
						   name="diff"
                           title="View differences from parent">
                          <i class="bi bi-arrow-left-right"></i> View differences
                        </a>
                      <?php endif; ?>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <!-- Full Screen Modal -->
        <div class="modal fade" id="fullscreenModal" tabindex="-1" aria-labelledby="fullscreenModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-fullscreen">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="fullscreenModalLabel"><?php echo htmlspecialchars($p_title ?? 'Untitled'); ?></h5>
                <?php if (!empty($showThemeSwitcher) && !empty($hl_theme_options)): ?>
                <div class="ms-2" style="min-width:180px">
                  <select class="form-select form-select-sm hljs-theme-select" title="Code Theme">
                    <?php foreach ($hl_theme_options as $opt): 
                          $sel = ($initialTheme && $opt['id'] === $initialTheme) ? ' selected' : '';
                    ?>
                      <option value="<?php echo htmlspecialchars($opt['id']); ?>" data-href="<?php echo htmlspecialchars($opt['href']); ?>"<?php echo $sel; ?>>
                        <?php echo htmlspecialchars($opt['name']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <!-- host where #code-content is moved to -->
                <div id="fullscreen-host"></div>
              </div>
            </div>
          </div>
        </div>

        <?php if (!empty($p_code_source) && $p_code_source !== 'explicit' && !empty($p_code_explain)): ?>
        <!-- Detection Explain Modal -->
        <div class="modal fade" id="detectedExplainModal" tabindex="-1" aria-labelledby="detectedExplainLabel" aria-hidden="true">
          <div class="modal-dialog">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="detectedExplainLabel">How we detected the language</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <p class="mb-1"><strong>Language:</strong> <?php echo htmlspecialchars($display_code); ?></p>
                <?php if (!empty($srcLabel)): ?>
                <p class="mb-2"><strong>Detected from:</strong> <?php echo htmlspecialchars($srcLabel); ?></p>
                <?php endif; ?>
                <pre class="small bg-dark p-2 border rounded" style="white-space: pre-wrap;"><?php echo htmlspecialchars($p_code_explain); ?></pre>
                <hr>
                <p class="small text-muted mb-0">
                  Tips: Add a file extension to the title (e.g., <code>.php</code>, <code>.py</code>) or choose a language explicitly to override autodetection.
                </p>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

      </div>

      <!-- ===== Comments (Public) ===== -->
      <?php if ($show_comments_ui): ?>
      <div class="mt-5" id="comments">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-dark text-light d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
              <i class="bi bi-chat-square-text"></i>
              <span class="fw-semibold">Comments</span>
              <a class="badge bg-secondary text-decoration-none" href="#comments">
                <span id="comments-count"><?php echo (int)count_comments_total($comments ?? []); ?></span>
              </a>
            </div>
            <?php if (!$can_comment): ?>
              <small class="text-light-50">Login to join the discussion</small>
            <?php endif; ?>
          </div>

          <div class="card-body p-0">
            <?php if (!empty($comment_error)): ?>
              <div class="alert alert-danger rounded-0 m-0"><?php echo htmlspecialchars($comment_error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <!-- List -->
            <ul class="list-group list-group-flush">
              <?php if (!empty($comments)): ?>
                <?php
                  $hasTree = isset($comments[0]) && is_array($comments[0]) && array_key_exists('children', $comments[0]);
                  if ($hasTree) {
                      foreach ($comments as $c) { render_comment_node($c, $can_comment, $commentsActionUrl, $_SESSION['csrf_token'] ?? '', $loginNext, 0, 3); }
                  } else {
                      foreach ($comments as $c):
                        $cid = (int)$c['id'];
                ?>
                  <li id="c-<?php echo $cid; ?>" class="list-group-item bg-body comment-item" style="--d:0;">
                    <div class="d-flex gap-3">
                      <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 comment-avatar">
                        <span class="fw-bold"><?php echo htmlspecialchars(strtoupper(mb_substr($c['username'],0,1,'UTF-8')), ENT_QUOTES, 'UTF-8'); ?></span>
                      </div>

                      <div class="flex-grow-1 comment-main">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                          <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="fw-semibold">
                              <?php
                                $cu = trim((string)($c['username'] ?? ''));
                                if ($cu !== '' && strcasecmp($cu, 'Guest') !== 0) {
                                    $cu_href = ($mod_rewrite ?? false)
                                        ? ($baseurl . 'user/' . rawurlencode($cu))
                                        : ($baseurl . 'user.php?user=' . rawurlencode($cu));
                                    echo '<a href="' . htmlspecialchars($cu_href, ENT_QUOTES, 'UTF-8') . '" class="text-decoration-none">'
                                       . htmlspecialchars($cu, ENT_QUOTES, 'UTF-8') . '</a>';
                                } else {
                                    echo htmlspecialchars($cu ?: 'Guest', ENT_QUOTES, 'UTF-8');
                                }
                              ?>
                            </span>
                            <span class="text-muted small">
                              <?php
                                $ts = strtotime($c['created_at']);
                                echo $ts ? htmlspecialchars(conTime($ts) . ' ago', ENT_QUOTES, 'UTF-8') : '';
                              ?>
                            </span>
                            <a href="#c-<?php echo $cid; ?>" class="comment-permalink ms-1 text-decoration-none" aria-label="Permalink">#</a>
                          </div>
                          <?php if (!empty($c['can_delete'])): ?>
                            <form method="post"
                                  action="<?php echo htmlspecialchars($commentsActionUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                  class="d-inline"
                                  onsubmit="return confirm('Delete this comment? This will remove its entire thread.');">
                              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                              <input type="hidden" name="action" value="delete_comment">
                              <input type="hidden" name="comment_id" value="<?php echo $cid; ?>">
                              <button type="submit" class="btn btn-sm btn-outline-danger border-0" title="Delete">
                                <i class="bi bi-trash"></i>
                              </button>
                            </form>
                          <?php endif; ?>
                        </div>

                        <div class="comment-body lh-base">
                          <?php echo comment_body_html_safe($c); ?>
                        </div>

                        <?php if ($can_comment): ?>
                          <div class="mt-2">
                            <button type="button" class="btn btn-link btn-sm p-0 comment-reply" data-target="#reply-form-<?php echo $cid; ?>">
                              <i class="bi bi-reply"></i> Reply
                            </button>
                          </div>
                          <div id="reply-form-<?php echo $cid; ?>" class="mt-2 d-none">
                            <form method="post" action="<?php echo htmlspecialchars($commentsActionUrl, ENT_QUOTES, 'UTF-8'); ?>">
                              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                              <input type="hidden" name="action" value="add_comment">
                              <input type="hidden" name="parent_id" value="<?php echo $cid; ?>">
                              <div class="mb-2">
                                <textarea class="form-control" name="comment_body" rows="3" minlength="1" maxlength="4000" placeholder="Write a reply…" required></textarea>
                                <div class="d-flex justify-content-end mt-1">
                                  <small class="text-muted"><span class="c-remaining">4000</span> chars left</small>
                                </div>
                              </div>
                              <div class="d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm reply-cancel" data-target="#reply-form-<?php echo $cid; ?>">Cancel</button>
                                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-send"></i> Post reply</button>
                              </div>
                            </form>
                          </div>
                        <?php else: ?>
                          <div class="mt-2">
                            <a class="btn btn-link btn-sm p-0" href="<?php echo htmlspecialchars($loginNext('#reply-form-' . $cid), ENT_QUOTES, 'UTF-8'); ?>">
                              <i class="bi bi-box-arrow-in-right"></i> Log in to reply
                            </a>
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>
                  </li>
                <?php endforeach; } ?>
              <?php else: ?>
                <li class="list-group-item bg-body text-center text-muted py-5">
                  No comments yet — be the first!
                </li>
              <?php endif; ?>
            </ul>

            <!-- Composer -->
            <div class="p-3 border-top">
              <?php if ($can_comment): ?>
                <form method="post" action="<?php echo htmlspecialchars($commentsActionUrl, ENT_QUOTES, 'UTF-8'); ?>">
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                  <input type="hidden" name="action" value="add_comment">

                  <div class="mb-2">
                    <textarea
                      class="form-control"
                      id="comment-body-main"
                      name="comment_body"
                      rows="3"
                      minlength="1"
                      maxlength="4000"
                      placeholder="Write a thoughtful comment…"
                      required
                    ></textarea>
                    <div class="d-flex justify-content-between mt-1">
                      <small class="text-muted">Markdown is not enabled; links will be auto-linked.</small>
                      <small class="text-muted"><span id="c-remaining">4000</span> chars left</small>
                    </div>
                  </div>
                  <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">
                      <i class="bi bi-send"></i> Post comment
                    </button>
                  </div>
                </form>
              <?php else: ?>
                <div class="alert alert-info mb-0">
                  <div class="d-flex align-items-center justify-content-between">
                    <span>Login to post a comment.</span>
                    <a class="btn btn-sm btn-outline-primary"
                       href="<?php echo htmlspecialchars($loginNext('#comments'), ENT_QUOTES, 'UTF-8'); ?>">
                      <i class="bi bi-box-arrow-in-right"></i> Login
                    </a>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <?php else: ?>
        <div class="mt-5" id="comments">
          <div class="alert alert-secondary">Comments have been disabled.</div>
        </div>
      <?php endif; ?>

    </div>

    <div class="col-lg-2 mt-4 mt-lg-0">
      <?php require_once('theme/' . ($default_theme ?? 'default') . '/sidebar.php'); ?>
    </div>

<?php endif; ?>
  </div>
</div>
