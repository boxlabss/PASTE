<?php
/*
 * Paste $v3.2 2025/09/01 https://github.com/boxlabss/PASTE
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

// Fallback URL builder if controller didn't provide $profile_url()
if (!isset($profile_url) || !is_callable($profile_url)) {
  $profile_url = function(array $add = [], array $drop = []) use ($baseurl, $mod_rewrite, $profile_username) {
    $base = $baseurl . ($mod_rewrite == '1'
      ? ('user/' . rawurlencode($profile_username))
      : ('user.php?user=' . rawurlencode($profile_username)));
    $q = $_GET;
    foreach ($drop as $k) unset($q[$k]);
    foreach ($add as $k => $v) { if ($v === null) unset($q[$k]); else $q[$k] = $v; }
    $qs = http_build_query($q);
    return $base . ($mod_rewrite == '1' ? ($qs ? ('?' . $qs) : '') : ($qs ? ('&' . $qs) : ''));
  };
}

// small helpers
$__vlabel = function($v) use ($lang) {
  switch ((string)$v) {
    case '0': return $lang['public']   ?? 'Public';
    case '1': return $lang['unlisted'] ?? 'Unlisted';
    case '2': return $lang['private']  ?? 'Private';
    default:  return (string)$v;
  }
};

// who’s viewing?
$is_me_local = isset($is_me) ? (bool)$is_me : (isset($_SESSION['username']) && $_SESSION['username'] == ($profile_username ?? ''));
$main_col_class = $is_me_local ? 'col-lg-9 order-lg-1' : 'col-lg-12 order-lg-1';
?>

<div class="text-light min-vh-100 py-4">
<div class="container-xl">
  <div class="row g-4">

    <!-- Start Main Content -->
    <div class="<?php echo $main_col_class; ?>">

      <!-- User Pastes -->
      <div class="card text-light border-0 rounded-3 shadow-sm mb-4">
        <div class="card-header bg-secondary border-0 rounded-top-3">
          <h5 class="mb-0">
            <?php
              echo htmlspecialchars($profile_username) . '' .
                   htmlspecialchars($lang['user_public_pastes'] ?? 'Public Pastes');
              if ($is_me_local) {
                echo ' <small class="ms-2 text-muted">' . htmlspecialchars($lang['mypastestitle'] ?? 'My Pastes') . '</small>';
              }
            ?>
          </h5>
          <small class="text-muted">
            <?php echo htmlspecialchars((string)($lang['membersince'] ?? 'Member since'), ENT_QUOTES, 'UTF-8'); ?>
            <?php
              $__joined_raw = isset($profile_join_date) && is_string($profile_join_date) ? $profile_join_date : '';
              $__joined_fmt = '—';
              if ($__joined_raw !== '') {
                $ts = strtotime($__joined_raw);
                $__joined_fmt = ($ts !== false) ? date('M j, Y', $ts) : $__joined_raw;
              }
              echo ' ' . htmlspecialchars($__joined_fmt, ENT_QUOTES, 'UTF-8');
            ?>
          </small>
        </div>

        <div class="card-body p-4">
          <?php 
          if (isset($_GET['del'])) {	
            if (!empty($success)) {
              echo '<div class="alert alert-success text-center rounded-3">' . htmlspecialchars($success) . '</div>'; 
            } elseif (!empty($error)) {
              echo '<div class="alert alert-danger text-center rounded-3">' . htmlspecialchars($error) . '</div>'; 
            }
          }
          ?>

          <!-- Top toolbar: range + per-page -->
          <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
            <div class="small text-muted">
              <?php
                $per_now = (int)($per ?? 25);
                $pg_now  = max(1, (int)($page ?? 1));
                $tz      = (int)($total_pastes ?? 0);

                // compute range
                $rf = (int)($range_from ?? ($tz ? (($pg_now - 1) * $per_now + 1) : 0));
                $rt = (int)($range_to   ?? min($tz, $pg_now * $per_now));

                $label = $lang['showing'] ?? 'Showing';
                echo htmlspecialchars($label . ' ' . $rf . '–' . $rt . ' of ' . $tz, ENT_QUOTES, 'UTF-8');
              ?>
            </div>
            <div>
              <label for="per" class="form-label me-2 mb-0 small text-muted"><?php echo htmlspecialchars($lang['perpage'] ?? 'Per page'); ?></label>
              <select id="per" class="form-select form-select-sm d-inline-block w-auto"
                      onchange="location.href='<?php echo htmlspecialchars($profile_url(['per'=>'__PER__','page'=>1]), ENT_QUOTES, 'UTF-8'); ?>'.replace('__PER__', this.value)">
                <?php
                  $per_now = (int)($per ?? 25);
                  foreach ([10,25,50,100] as $opt) {
                    $sel = ($per_now === $opt) ? ' selected' : '';
                    echo '<option value="' . (int)$opt . '"' . $sel . '>' . (int)$opt . '</option>';
                  }
                ?>
              </select>
            </div>
          </div>

          <div class="table-responsive">
            <table id="archive" class="table table-striped table-hover align-middle">
              <thead>
                <tr>
                  <th><?php echo htmlspecialchars($lang['pastetitle'] ?? 'Paste Title'); ?></th>
                  <th><?php echo htmlspecialchars($lang['pastetime'] ?? 'Time'); ?></th>
                  <?php if ($is_me_local) { ?>
                    <th><?php echo htmlspecialchars($lang['visibility'] ?? 'Visibility'); ?></th>
                  <?php } ?>
                  <th><?php echo htmlspecialchars($lang['pasteviews'] ?? 'Views'); ?></th>
                  <th><?php echo htmlspecialchars($lang['pastesyntax'] ?? 'Syntax'); ?></th>
                  <?php if ($is_me_local) { ?>
                    <th style="min-width:60px;"><?php echo htmlspecialchars($lang['delete'] ?? 'Delete'); ?></th>
                  <?php } ?>
                </tr>
              </thead>
              <tbody>
                <?php
                  if (empty($pastesPage)) {
                    $colspan = $is_me_local ? 6 : 4;
                    echo '<tr><td colspan="' . $colspan . '" class="text-center text-muted">' 
                         . htmlspecialchars($lang['emptypastebin'] ?? 'No pastes found') 
                         . '</td></tr>';
                  } else {
                    foreach ($pastesPage as $row) {
                      $title     = trim((string)($row['title'] ?? 'Untitled'));
                      $p_id      = (int)($row['id'] ?? 0);
                      $p_code    = trim((string)($row['code'] ?? 'text'));
                      $p_date    = trim((string)($row['date'] ?? ''));
                      $p_views   = (int)($row['views'] ?? 0);
                      $visible_v = (string)($row['visible'] ?? '0');

                      // visibility label (only shown to owner in a dedicated column)
                      if     ($visible_v === '0') $visible_label = $lang['public']   ?? 'Public';
                      elseif ($visible_v === '1') $visible_label = $lang['unlisted'] ?? 'Unlisted';
                      else                        $visible_label = $lang['private']  ?? 'Private';

                      // link
                      $p_link = ($mod_rewrite == '1') ? (string)$p_id : ('paste.php?id=' . $p_id);

                      // truncate title nicely
                      $title_short = truncate($title, 20, 50);

                      echo '<tr>';
                      echo '  <td><a href="' . htmlspecialchars($baseurl . $p_link, ENT_QUOTES, 'UTF-8') . '"'
                        . ' title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"'
                        . ' class="text-light fw-medium">' . ucfirst(htmlspecialchars($title_short, ENT_QUOTES, 'UTF-8')) . '</a></td>';
                      echo '  <td>' . htmlspecialchars($p_date, ENT_QUOTES, 'UTF-8') . '</td>';

                      if ($is_me_local) {
                        echo '  <td>' . htmlspecialchars($visible_label, ENT_QUOTES, 'UTF-8') . '</td>';
                      }

                      echo '  <td>' . htmlspecialchars((string)$p_views, ENT_QUOTES, 'UTF-8') . '</td>';
                      echo '  <td><span class="badge bg-primary">' . htmlspecialchars(strtoupper($p_code), ENT_QUOTES, 'UTF-8') . '</span></td>';

                      if ($is_me_local) {
                        $p_delete_link = ($mod_rewrite == '1')
                          ? ('user.php?del&user=' . rawurlencode($profile_username) . '&id=' . $p_id)
                          : ('user.php?del&user=' . rawurlencode($profile_username) . '&id=' . $p_id);

                        echo '  <td class="text-center"><a href="' . htmlspecialchars($baseurl . $p_delete_link, ENT_QUOTES, 'UTF-8')
                          . '" class="btn btn-sm btn-outline-danger" title="'
                          . htmlspecialchars(sprintf($lang['delete'] ?? 'Delete %s', $title_short), ENT_QUOTES, 'UTF-8')
                          . '"><i class="bi bi-trash" aria-hidden="true"></i></a></td>';
                      }

                      echo '</tr>';
                    }
                  }
                ?>
              </tbody>
              <tfoot>
                <tr>
                  <th><?php echo htmlspecialchars($lang['pastetitle'] ?? 'Paste Title'); ?></th>
                  <th><?php echo htmlspecialchars($lang['pastetime'] ?? 'Time'); ?></th>
                  <?php if ($is_me_local) { ?>
                    <th><?php echo htmlspecialchars($lang['visibility'] ?? 'Visibility'); ?></th>
                  <?php } ?>
                  <th><?php echo htmlspecialchars($lang['pasteviews'] ?? 'Views'); ?></th>
                  <th><?php echo htmlspecialchars($lang['pastesyntax'] ?? 'Syntax'); ?></th>
                  <?php if ($is_me_local) { ?>
                    <th><?php echo htmlspecialchars($lang['delete'] ?? 'Delete'); ?></th>
                  <?php } ?>
                </tr>
              </tfoot>
            </table>
          </div>

          <!-- Pagination -->
          <?php
            $tp = max(1, (int)($total_pages ?? 1));
            $pg = max(1, min((int)($page ?? 1), $tp));
            if ($tp > 1):
              // compute window
              $win = 5;
              $start = max(1, $pg - 2);
              $end   = min($tp, $start + $win - 1);
              $start = max(1, min($start, $end - $win + 1));
          ?>
            <nav aria-label="Paginaton" class="mt-3">
              <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?php echo $pg <= 1 ? 'disabled' : ''; ?>">
                  <a class="page-link" href="<?php echo htmlspecialchars($profile_url(['page'=>1]), ENT_QUOTES, 'UTF-8'); ?>" aria-label="First">&laquo;</a>
                </li>
                <li class="page-item <?php echo $pg <= 1 ? 'disabled' : ''; ?>">
                  <a class="page-link" href="<?php echo htmlspecialchars($profile_url(['page'=>max(1,$pg-1)]), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Previous">&lsaquo;</a>
                </li>

                <?php if ($start > 1): ?>
                  <li class="page-item">
                    <a class="page-link" href="<?php echo htmlspecialchars($profile_url(['page'=>1]), ENT_QUOTES, 'UTF-8'); ?>">1</a>
                  </li>
                  <?php if ($start > 2): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                  <?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $start; $i <= $end; $i++): ?>
                  <li class="page-item <?php echo $i == $pg ? 'active' : ''; ?>">
                    <a class="page-link" href="<?php echo htmlspecialchars($profile_url(['page'=>$i]), ENT_QUOTES, 'UTF-8'); ?>"><?php echo (int)$i; ?></a>
                  </li>
                <?php endfor; ?>

                <?php if ($end < $tp): ?>
                  <?php if ($end < $tp - 1): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                  <?php endif; ?>
                  <li class="page-item">
                    <a class="page-link" href="<?php echo htmlspecialchars($profile_url(['page'=>$tp]), ENT_QUOTES, 'UTF-8'); ?>"><?php echo (int)$tp; ?></a>
                  </li>
                <?php endif; ?>

                <li class="page-item <?php echo $pg >= $tp ? 'disabled' : ''; ?>">
                  <a class="page-link" href="<?php echo htmlspecialchars($profile_url(['page'=>min($tp,$pg+1)]), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Next">&rsaquo;</a>
                </li>
                <li class="page-item <?php echo $pg >= $tp ? 'disabled' : ''; ?>">
                  <a class="page-link" href="<?php echo htmlspecialchars($profile_url(['page'=>$tp]), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Last">&raquo;</a>
                </li>
              </ul>
            </nav>
          <?php endif; ?>
        </div>
      </div>

      <!-- User Comments (latest 200) -->
      <div class="card text-light border-0 rounded-3 shadow-sm mb-4" id="my-comments">
        <div class="card-header bg-secondary border-0 rounded-top-3 d-flex justify-content-between align-items-center">
          <h5 class="mb-0">
            <?php
              echo htmlspecialchars($is_me_local ? ($lang['mycomments'] ?? 'My Comments')
                                                 : sprintf($lang['usercomments'] ?? 'Comments by %s', $profile_username));
            ?>
          </h5>
          <span class="badge bg-dark"><?php echo (int)($total_user_comments ?? 0); ?></span>
        </div>
        <div class="card-body p-4">

          <?php if (isset($_GET['c_ok'])): ?>
            <div class="alert alert-success rounded-3"><?php echo htmlspecialchars($_GET['c_ok'], ENT_QUOTES, 'UTF-8'); ?></div>
          <?php elseif (isset($_GET['c_err'])): ?>
            <div class="alert alert-danger rounded-3"><?php echo htmlspecialchars($_GET['c_err'], ENT_QUOTES, 'UTF-8'); ?></div>
          <?php endif; ?>

          <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
              <thead>
                <tr>
                  <th><?php echo htmlspecialchars($lang['pastetitle'] ?? 'On Paste'); ?></th>
                  <th><?php echo htmlspecialchars($lang['comment'] ?? 'Comment'); ?></th>
                  <th><?php echo htmlspecialchars($lang['pastetime'] ?? 'Time'); ?></th>
                  <?php if ($is_me_local) { ?>
                    <th style="min-width: 60px;"><?php echo htmlspecialchars($lang['delete'] ?? 'Delete'); ?></th>
                  <?php } ?>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($user_comments)): ?>
                  <tr>
                    <td colspan="<?php echo $is_me_local ? 4 : 3; ?>" class="text-center text-muted">
                      <?php echo htmlspecialchars($lang['nocomments'] ?? 'No comments found.'); ?>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($user_comments as $row):
                    $cid    = (int)($row['id'] ?? 0);
                    $pid    = (int)($row['paste_id'] ?? 0);
                    $ptitle = (string)($row['title'] ?? 'Untitled');
                    $purl   = ($mod_rewrite == '1')
                              ? $baseurl . $pid . '#c-' . $cid
                              : $baseurl . 'paste.php?id=' . $pid . '#c-' . $cid;
                    $snippet = trim((string)($row['body'] ?? ''));
                    $snippet = preg_replace('~\s+~', ' ', $snippet);
                    $snippet = truncate($snippet, 20, 160);
                    $ts  = strtotime((string)($row['created_at'] ?? ''));
                    $ago = $ts ? conTime($ts) . ' ago' : ($row['created_at'] ?? '');
                  ?>
                    <tr>
                      <td>
                        <a href="<?php echo htmlspecialchars($purl, ENT_QUOTES, 'UTF-8'); ?>"
                           class="text-light fw-medium"
                           title="<?php echo htmlspecialchars($ptitle, ENT_QUOTES, 'UTF-8'); ?>">
                          <?php echo ucfirst(htmlspecialchars(truncate($ptitle, 20, 60), ENT_QUOTES, 'UTF-8')); ?>
                        </a>
                      </td>
                      <td class="text-muted"><?php echo htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8'); ?></td>
                      <td><?php echo htmlspecialchars($ago, ENT_QUOTES, 'UTF-8'); ?></td>
                      <?php if ($is_me_local): ?>
                        <td class="text-center">
                          <form method="post"
                                action="<?php echo htmlspecialchars($baseurl . ($mod_rewrite == '1'
                                                          ? 'user/' . $profile_username
                                                          : 'user.php?user=' . $profile_username), ENT_QUOTES, 'UTF-8'); ?>"
                                class="d-inline"
                                onsubmit="return confirm('<?php echo htmlspecialchars($lang['confirmdelete'] ?? 'Delete this comment?', ENT_QUOTES, 'UTF-8'); ?>');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="delete_comment">
                            <input type="hidden" name="comment_id" value="<?php echo (int)$cid; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete comment">
                              <i class="bi bi-trash" aria-hidden="true"></i>
                            </button>
                          </form>
                        </td>
                      <?php endif; ?>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
              <tfoot>
                <tr>
                  <th><?php echo htmlspecialchars($lang['pastetitle'] ?? 'On Paste'); ?></th>
                  <th><?php echo htmlspecialchars($lang['comment'] ?? 'Comment'); ?></th>
                  <th><?php echo htmlspecialchars($lang['pastetime'] ?? 'Time'); ?></th>
                  <?php if ($is_me_local) { ?>
                    <th><?php echo htmlspecialchars($lang['delete'] ?? 'Delete'); ?></th>
                  <?php } ?>
                </tr>
              </tfoot>
            </table>
            <small class="text-muted"><?php echo htmlspecialchars($lang['showinglatest'] ?? 'Showing latest'); ?> 200</small>
          </div>

        </div>
      </div>

      <!-- Recent Pastes -->
      <div class="card text-light border-0 rounded-3 shadow-sm">
        <div class="card-header bg-secondary border-0 rounded-top-3">
          <h5 class="mb-0"><?php echo htmlspecialchars($lang['recentpastes'] ?? 'Recent Pastes'); ?></h5>
        </div>
        <div class="card-body p-4">
          <div class="table-responsive">
            <table id="recent-pastes" class="table table-striped table-hover align-middle">
              <thead>
                <tr>
                  <th><?php echo htmlspecialchars($lang['pastetitle'] ?? 'Paste Title'); ?></th>
                  <th><?php echo htmlspecialchars($lang['pastetime'] ?? 'Time'); ?></th>
                  <th><?php echo htmlspecialchars($lang['pastesyntax'] ?? 'Syntax'); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php
                try {
                  $pastes = getRecent($pdo, 10);
                  if (empty($pastes)) {
                    echo '<tr><td colspan="3" class="text-center text-muted">' . htmlspecialchars($lang['emptypastebin'] ?? 'No pastes found') . '</td></tr>';
                  } else {
                    foreach ($pastes as $row) {
                      $title  = (string) ($row['title'] ?? 'Untitled');
                      $p_id   = (string) ($row['id'] ?? '');
                      $p_date = (string) ($row['date'] ?? '');
                      $p_code = (string) ($row['code'] ?? 'Unknown');
                      $p_link = ($mod_rewrite == '1') ? "$p_id" : "paste.php?id=$p_id";
                      $title  = truncate($title, 20, 50);
                      echo '<tr> 
                        <td><a href="' . htmlspecialchars($baseurl . $p_link) . '" title="' . htmlspecialchars($title) . '" class="text-light fw-medium">' . ucfirst(htmlspecialchars($title)) . '</a></td>    
                        <td>' . htmlspecialchars($p_date) . '</td>
                        <td><span class="badge bg-primary">' . htmlspecialchars(strtoupper($p_code)) . '</span></td>
                      </tr>'; 
                    }
                  }
                } catch (Exception $e) {
                  echo '<tr><td colspan="3" class="text-center text-danger">' . htmlspecialchars('Error fetching recent pastes: ' . $e->getMessage()) . '</td></tr>';
                }
                ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div><!-- /main column -->

    <!-- Sidebar -->
    <?php if ($is_me_local): ?>
    <div class="col-lg-3 order-lg-2">
      <div class="card bg-secondary text-light mb-4 border-0 rounded-3 position-relative welcome-card">
        <div class="card-body p-3">
          <h6 class="d-flex align-items-center gap-2 mb-3 text-light">
            <i class="bi bi-person-circle fs-5"></i>
            <?php echo htmlspecialchars(($lang['hello'] ?? 'Hello') . ', ' . $profile_username); ?>
          </h6>
          <p class="mb-3 small"><?php echo htmlspecialchars($lang['profile-message'] ?? 'Manage your pastes here.'); ?></p>
          <ul class="list-group list-group-flush">
            <li class="list-group-item bg-dark text-light d-flex align-items-center justify-content-between gap-2 py-2">
              <div class="d-flex align-items-center gap-2">
                <i class="bi bi-file-code fs-5 text-primary"></i>
                <small class="text-muted text-uppercase"><?php echo htmlspecialchars($lang['totalpastes'] ?? 'Total Pastes'); ?></small>
              </div>
              <div class="fw-bold"><?php echo htmlspecialchars($profile_total_pastes); ?></div>
            </li>
            <li class="list-group-item bg-dark text-light d-flex align-items-center justify-content-between gap-2 py-2">
              <div class="d-flex align-items-center gap-2">
                <i class="bi bi-globe fs-5 text-primary"></i>
                <small class="text-muted text-uppercase"><?php echo htmlspecialchars($lang['profile-total-pub'] ?? 'Public'); ?></small>
              </div>
              <div class="fw-bold"><?php echo htmlspecialchars($profile_total_public); ?></div>
            </li>
            <li class="list-group-item bg-dark text-light d-flex align-items-center justify-content-between gap-2 py-2">
              <div class="d-flex align-items-center gap-2">
                <i class="bi bi-eye-slash fs-5 text-primary"></i>
                <small class="text-muted text-uppercase"><?php echo htmlspecialchars($lang['profile-total-unl'] ?? 'Unlisted'); ?></small>
              </div>
              <div class="fw-bold"><?php echo htmlspecialchars($profile_total_unlisted); ?></div>
            </li>
            <li class="list-group-item bg-dark text-light d-flex align-items-center justify-content-between gap-2 py-2">
              <div class="d-flex align-items-center gap-2">
                <i class="bi bi-lock fs-5 text-primary"></i>
                <small class="text-muted text-uppercase"><?php echo htmlspecialchars($lang['profile-total-pri'] ?? 'Private'); ?></small>
              </div>
              <div class="fw-bold"><?php echo htmlspecialchars($profile_total_private); ?></div>
            </li>
            <li class="list-group-item bg-dark text-light d-flex align-items-center justify-content-between gap-2 py-2">
              <div class="d-flex align-items-center gap-2">
                <i class="bi bi-bar-chart fs-5 text-primary"></i>
                <small class="text-muted text-uppercase"><?php echo htmlspecialchars($lang['profile-total-views'] ?? 'Total Views'); ?></small>
              </div>
              <div class="fw-bold"><?php echo htmlspecialchars($profile_total_paste_views); ?></div>
            </li>
          </ul>
        </div>
      </div>
      <?php echo $ads_2 ?? ''; ?>
    </div>
    <?php endif; ?>
    <!-- End Sidebar -->

  </div>
</div>
</div>