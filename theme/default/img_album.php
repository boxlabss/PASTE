<?php
/*
 * Paste https://github.com/boxlabss/PASTE
 *
 * Album page: the uploads in an album, share links, and the maker's controls.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

$e = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$albumTitle = img_album_title($img_album);
$albumDesc  = (string)($img_album['_meta']['desc'] ?? '');
$items = [];
foreach ($img_uploads as $u) {
    $n = (string)$u['_name'];
    $kind = IMG_TYPES[$u['mime']][0] ?? 'file';
    $title = img_display_title($u);
    $items[] = [
        'row'      => $u,
        'name'     => $n,
        'kind'     => $kind,
        'title'    => $title,
        'label'    => $title ?? (img_display_name($u) ?? ($n . '.' . $u['ext'])),
        'page'     => img_page_url($n, $baseurl, $rewrite),
        'file'     => img_file_url($n, $u['ext'], $baseurl, $rewrite),
        'playable' => in_array($u['mime'], IMG_PLAYABLE, true),
    ];
}
$direct = implode("\n", array_column($items, 'file'));
$bbcode = implode("\n", array_map(static fn($i) => $i['kind'] === 'image'
    ? '[url=' . $i['page'] . '][img]' . $i['file'] . '[/img][/url]'
    : '[url=' . $i['page'] . ']' . strtr($i['label'], ['[' => '(', ']' => ')']) . '[/url]', $items));
$markdown = implode("\n", array_map(static fn($i) => ($i['kind'] === 'image' ? '!' : '')
    . '[' . strtr($i['label'], ['[' => '(', ']' => ')']) . '](' . ($i['kind'] === 'image' ? $i['file'] : $i['page']) . ')', $items));
$canAdd = $img_can_manage && count($items) < (int)$img_album_max;
?>
<style>
  .alb-item { background:#0b0d12; border-radius:8px; overflow:hidden; display:flex; align-items:center; justify-content:center; }
  .alb-item img, .alb-item video { max-width:100%; max-height:85vh; display:block; }
  .alb-cap { display:flex; align-items:center; gap:.5rem; padding:.5rem .1rem 1.25rem; font-size:.9rem; }
  .alb-cap .t { min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .alb-desc { white-space:pre-line; }
</style>

<div class="container-xxl my-4">
  <?php if (!empty($img_flash)): ?>
    <div class="alert alert-success"><?= $e($img_flash) ?></div>
  <?php endif; ?>
  <div class="row g-4">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
          <h1 class="h6 mb-0 text-truncate" title="<?= $e($albumTitle) ?>"><i class="bi bi-collection me-1 text-primary"></i><?= $e($albumTitle) ?></h1>
          <a class="btn btn-sm btn-outline-secondary flex-shrink-0" href="<?= $e(img_home_url($baseurl)) ?>"><i class="bi bi-upload me-1"></i>Upload</a>
        </div>
        <div class="card-body">
          <?php if ($albumDesc !== ''): ?>
            <p class="alb-desc mb-4"><?= $e($albumDesc) ?></p>
          <?php endif; ?>
          <?php if (!$items): ?>
            <p class="text-muted mb-0">This album is empty.<?= $canAdd ? ' Add some uploads to it.' : '' ?></p>
          <?php endif; ?>
          <?php foreach ($items as $pos => $i): ?>
            <div class="alb-item" id="item-<?= $e($i['name']) ?>">
              <?php if ($i['kind'] === 'image'): ?>
                <a href="<?= $e($i['page']) ?>"><img src="<?= $e($i['file']) ?>" alt="<?= $e($i['label']) ?>" loading="lazy"></a>
              <?php elseif ($i['playable']): ?>
                <video src="<?= $e($i['file']) ?>" controls preload="metadata" playsinline></video>
              <?php else: ?>
                <a class="text-center p-5 d-block" href="<?= $e($i['page']) ?>"><i class="bi bi-film d-block mb-2" style="font-size:3rem"></i><?= $e(strtoupper($i['row']['ext'])) ?> video</a>
              <?php endif; ?>
            </div>
            <div class="alb-cap">
              <a class="t text-decoration-none" href="<?= $e($i['page']) ?>" title="<?= $e($i['label']) ?>"><?= $e($i['title'] ?? $i['label']) ?></a>
              <span class="text-muted small flex-shrink-0"><?= $e(img_format_bytes((int)$i['row']['size'])) ?></span>
              <?php if ($img_can_manage): ?>
                <form method="post" action="<?= $e(img_home_url($baseurl)) ?>" class="ms-auto d-flex gap-2">
                  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                  <input type="hidden" name="action" value="album_move">
                  <input type="hidden" name="album" value="<?= $e($img_album['_name']) ?>">
                  <input type="hidden" name="item" value="<?= $e($i['name']) ?>">
                  <button class="btn btn-link btn-sm p-0" name="dir" value="up" title="Move up"<?= $pos === 0 ? ' disabled' : '' ?>><i class="bi bi-arrow-up"></i></button>
                  <button class="btn btn-link btn-sm p-0" name="dir" value="down" title="Move down"<?= $pos === count($items) - 1 ? ' disabled' : '' ?>><i class="bi bi-arrow-down"></i></button>
                </form>
                <form method="post" action="<?= $e(img_home_url($baseurl)) ?>" onsubmit="return confirm('Remove this upload from the album? It stays at its own link.')">
                  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                  <input type="hidden" name="action" value="album_remove">
                  <input type="hidden" name="album" value="<?= $e($img_album['_name']) ?>">
                  <input type="hidden" name="item" value="<?= $e($i['name']) ?>">
                  <button class="btn btn-link btn-sm text-danger p-0" title="Remove from album"><i class="bi bi-x-circle"></i></button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card mb-4">
        <div class="card-header"><h2 class="h6 mb-0">Album</h2></div>
        <ul class="list-group list-group-flush small">
          <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Uploads</span><span><?= count($items) ?></span></li>
          <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Made by</span>
            <span><?php if ($img_owner_url): ?><a href="<?= $e($img_owner_url) ?>"><?= $e($img_album['account']) ?></a><?php else: ?><?= $e($img_album['account']) ?><?php endif; ?></span></li>
          <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Made</span><span><?= $e(date('j M Y, H:i', strtotime((string)$img_album['created']))) ?> UTC</span></li>
          <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Expires</span>
            <span><?= $img_album['expires'] ? $e(date('j M Y, H:i', strtotime((string)$img_album['expires']))) . ' UTC' : 'Never' ?></span></li>
        </ul>
        <?php if ($img_can_manage): ?>
          <div class="card-body d-flex gap-2 flex-wrap">
            <?php if ($canAdd): ?>
              <a class="btn btn-sm btn-outline-primary" href="<?= $e($img_add_url) ?>"><i class="bi bi-plus-lg me-1"></i>Add uploads</a>
            <?php endif; ?>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#albEdit" aria-expanded="false" aria-controls="albEdit"><i class="bi bi-pencil me-1"></i>Edit</button>
            <button class="btn btn-sm btn-outline-danger ms-auto" type="button" data-bs-toggle="collapse" data-bs-target="#albDelete" aria-expanded="false" aria-controls="albDelete"><i class="bi bi-trash me-1"></i>Delete</button>
          </div>
          <form class="card-body border-top collapse" id="albEdit" method="post" action="<?= $e(img_home_url($baseurl)) ?>">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="album_edit">
            <input type="hidden" name="album" value="<?= $e($img_album['_name']) ?>">
            <label class="form-label small mb-1" for="albTitle">Title</label>
            <input class="form-control form-control-sm mb-2" id="albTitle" name="title" maxlength="<?= (int)IMG_TITLE_MAX ?>" value="<?= $e($img_album['_meta']['title'] ?? '') ?>">
            <label class="form-label small mb-1" for="albDesc">Description</label>
            <textarea class="form-control form-control-sm mb-2" id="albDesc" name="desc" rows="3" maxlength="<?= (int)IMG_DESC_MAX ?>"><?= $e($albumDesc) ?></textarea>
            <button class="btn btn-sm btn-primary">Save</button>
          </form>
          <form class="card-body border-top collapse" id="albDelete" method="post" action="<?= $e(img_home_url($baseurl)) ?>">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="album_delete">
            <input type="hidden" name="album" value="<?= $e($img_album['_name']) ?>">
            <p class="small mb-2">Delete just the album, or the album and every upload in it?</p>
            <div class="d-flex gap-2 flex-wrap">
              <button class="btn btn-sm btn-outline-danger" name="uploads" value="0" onclick="return confirm('Delete this album? Its uploads stay at their own links.')">Album only</button>
              <button class="btn btn-sm btn-danger" name="uploads" value="1" onclick="return confirm('Delete this album and all its uploads? This cannot be undone.')">Album and uploads</button>
            </div>
          </form>
        <?php endif; ?>
      </div>

      <div class="card">
        <div class="card-header"><h2 class="h6 mb-0">Share</h2></div>
        <div class="card-body">
          <label class="form-label small mb-1" for="albLink">Album link</label>
          <div class="input-group input-group-sm mb-3">
            <input class="form-control font-monospace" id="albLink" value="<?= $e($img_album_url) ?>" readonly>
            <button class="btn btn-outline-secondary" type="button" data-copy="#albLink" title="Copy"><i class="bi bi-clipboard"></i></button>
          </div>
          <?php if ($items): ?>
            <?php foreach ([['albDirect', 'Direct links', $direct], ['albMd', 'Markdown', $markdown], ['albBb', 'BBCode', $bbcode]] as [$id, $label, $value]): ?>
              <div class="d-flex align-items-end justify-content-between mb-1">
                <label class="form-label small mb-0" for="<?= $id ?>"><?= $e($label) ?></label>
                <button class="btn btn-link btn-sm p-0" type="button" data-copy="#<?= $id ?>" title="Copy"><i class="bi bi-clipboard"></i></button>
              </div>
              <textarea class="form-control form-control-sm font-monospace mb-3" id="<?= $id ?>" rows="3" readonly><?= $e($value) ?></textarea>
            <?php endforeach; ?>
          <?php endif; ?>
          <p class="small text-muted mb-0"><i class="bi bi-lock-fill text-primary me-1"></i>Stored encrypted. The album link is the key to the album, and the links in it open each upload.</p>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="<?= $e(paste_theme_asset($baseurl, $default_theme, 'js/img.js')) ?>" defer></script>
