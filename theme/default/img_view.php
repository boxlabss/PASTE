<?php
/*
 * Paste https://github.com/boxlabss/PASTE
 *
 * View page for one uploaded image or video, with share links.
 * Link-encrypted uploads are decrypted by the server with the name in the
 * link; uploads encrypted in the browser are decrypted here with the key
 * after #pass= in the link.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

$e = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$name = $img_encrypted
    ? 'Encrypted upload'
    : ((string)($img_title ?? '') !== '' ? (string)$img_title : $img_name . '.' . $img_row['ext']);

$links = [];
if (!$img_encrypted) {
    // Brackets swapped so a title cannot end the Markdown or BBCode link early
    $label = strtr($name, ['[' => '(', ']' => ')']);
    $links = [['Page link', $img_page], ['Direct link', $img_file]];
    if ($img_kind === 'image') {
        $links[] = ['Markdown', '![' . $label . '](' . $img_file . ')'];
        $links[] = ['BBCode', '[url=' . $img_page . '][img]' . $img_file . '[/img][/url]'];
        $links[] = ['HTML', '<a href="' . $img_page . '"><img src="' . $img_file . '" alt="' . $e($name) . '"></a>'];
    } else {
        $links[] = ['Markdown', '[' . $label . '](' . $img_page . ')'];
        $links[] = ['BBCode', '[url=' . $img_page . ']' . $label . '[/url]'];
        $links[] = ['HTML', '<video src="' . $img_file . '" controls title="' . $e($name) . '"></video>'];
    }
}
$plainSize = $img_encrypted ? max(0, (int)$img_row['size'] - IMG_CRYPT_OVERHEAD) : (int)$img_row['size'];
?>
<style>
  .img-stage { background:#0b0d12; border-radius:8px; display:flex; align-items:center; justify-content:center; min-height:240px; overflow:hidden; }
  .img-stage img, .img-stage video { max-width:100%; max-height:80vh; display:block; }
  .img-unlock { max-width:380px; width:100%; }
</style>

<div class="container-xxl my-4">
  <div class="row g-4">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
          <h1 class="h6 mb-0 text-truncate" id="imgName" title="<?= $e($name) ?>">
            <?php if ($img_encrypted): ?><i class="bi bi-lock-fill text-primary me-1"></i><?php endif; ?><span><?= $e($name) ?></span>
          </h1>
          <a class="btn btn-sm btn-outline-secondary flex-shrink-0" href="<?= $e(img_home_url($baseurl)) ?>"><i class="bi bi-upload me-1"></i>Upload</a>
        </div>
        <div class="card-body">
          <?php if ($img_encrypted): ?>
            <div class="img-stage" id="imgStage"
                 data-slug="<?= $e($img_row['slug']) ?>"
                 data-file="<?= $e($img_file) ?>"
                 data-thumb="<?= $e($img_thumb) ?>"
                 data-meta="<?= $e((string)$img_row['meta']) ?>"
                 data-size="<?= (int)$img_row['size'] ?>">
              <div class="text-center p-4" id="imgBusy">
                <div class="spinner-border text-primary mb-2" role="status"></div>
                <div class="small text-muted" id="imgBusyText">Decrypting…</div>
              </div>
              <form class="img-unlock text-center p-4 d-none" id="imgKeyForm" autocomplete="off">
                <i class="bi bi-lock d-block mb-2 text-primary" style="font-size:2.5rem"></i>
                <p class="mb-3">This upload is encrypted. Enter the key or passphrase to view it.</p>
                <div class="alert alert-danger small py-2 d-none" id="imgKeyError"></div>
                <div class="input-group">
                  <input type="password" class="form-control" id="imgKeyInput" placeholder="Key or passphrase" aria-label="Key or passphrase">
                  <button class="btn btn-primary" type="submit">Unlock</button>
                </div>
              </form>
              <noscript><div class="text-center p-4">This upload is encrypted and is decrypted in your browser. Turn on JavaScript to view it.</div></noscript>
            </div>
          <?php else: ?>
            <div class="img-stage">
              <?php if ($img_kind === 'image'): ?>
                <a href="<?= $e($img_file) ?>"><img src="<?= $e($img_file) ?>" alt="<?= $e($name) ?>"></a>
              <?php elseif ($img_playable): ?>
                <video src="<?= $e($img_file) ?>" controls preload="metadata" playsinline></video>
              <?php else: ?>
                <div class="text-center p-5">
                  <i class="bi bi-film d-block mb-2" style="font-size:3rem"></i>
                  <p class="mb-3">Browsers can't play <?= $e(strtoupper($img_row['ext'])) ?> videos. Download it to watch.</p>
                  <a class="btn btn-primary" href="<?= $e($img_file) ?>" download><i class="bi bi-download me-1"></i>Download</a>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card mb-4">
        <div class="card-header"><h2 class="h6 mb-0">Details</h2></div>
        <ul class="list-group list-group-flush small">
          <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Type</span>
            <span id="imgDetType"><?= $img_encrypted ? 'Encrypted' : $e(strtoupper($img_row['ext'])) ?></span></li>
          <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Size</span>
            <span id="imgDetSize"><?= $e(img_format_bytes($plainSize)) ?></span></li>
          <li class="list-group-item justify-content-between<?= $img_dims ? ' d-flex' : ' d-none' ?>" id="imgDetDimsRow"><span class="text-muted">Dimensions</span>
            <span id="imgDetDims"><?= $img_dims ? (int)$img_dims[0] . ' × ' . (int)$img_dims[1] : '' ?></span></li>
          <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Uploaded by</span>
            <span><?php if ($img_owner_url): ?><a href="<?= $e($img_owner_url) ?>"><?= $e($img_row['account']) ?></a><?php else: ?><?= $e($img_row['account']) ?><?php endif; ?></span>
          </li>
          <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Uploaded</span><span><?= $e(date('j M Y, H:i', strtotime((string)$img_row['created']))) ?> UTC</span></li>
          <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Expires</span>
            <span><?= $img_row['expires'] ? $e(date('j M Y, H:i', strtotime((string)$img_row['expires']))) . ' UTC' : 'Never' ?></span>
          </li>
        </ul>
        <div class="card-body d-flex gap-2 flex-wrap">
          <?php if ($img_encrypted): ?>
            <a class="btn btn-sm btn-outline-primary disabled" id="imgDownload" href="#" aria-disabled="true"><i class="bi bi-download me-1"></i>Download</a>
          <?php else: ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= $e($img_file) ?>" download><i class="bi bi-download me-1"></i>Download</a>
          <?php endif; ?>
          <?php if ($img_can_delete): ?>
            <form method="post" action="<?= $e(img_home_url($baseurl)) ?>" onsubmit="return confirm('Delete this upload?')">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="slug" value="<?= $e($img_row['slug']) ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete</button>
            </form>
          <?php endif; ?>
          <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="imgReportToggle"><i class="bi bi-flag me-1"></i>Report</button>
        </div>
        <form class="card-body border-top d-none" id="imgReportForm" action="<?= $e(img_home_url($baseurl)) ?>">
          <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
          <input type="hidden" name="action" value="report">
          <input type="hidden" name="slug" value="<?= $e($img_name) ?>">
          <label class="form-label small mb-1" for="imgReportReason">What is wrong with this upload?</label>
          <textarea class="form-control form-control-sm mb-2" id="imgReportReason" name="reason" rows="3" maxlength="500" required></textarea>
          <?php if ($img_encrypted): ?>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" id="imgReportKey" checked>
              <label class="form-check-label small" for="imgReportKey">Include the key so moderators can see it</label>
            </div>
          <?php else: ?>
            <p class="small text-muted mb-2">The report includes this link, so moderators can see the file.</p>
          <?php endif; ?>
          <div class="small mb-2" id="imgReportStatus" aria-live="polite"></div>
          <button class="btn btn-sm btn-danger" type="submit">Send report</button>
        </form>
      </div>

      <div class="card">
        <div class="card-header"><h2 class="h6 mb-0">Share</h2></div>
        <div class="card-body">
          <?php if ($img_encrypted): ?>
            <div class="d-none" id="imgKeyLinks">
              <label class="form-label small mb-1" for="imgLinkKey">Link with key</label>
              <div class="input-group input-group-sm mb-2">
                <input class="form-control font-monospace" id="imgLinkKey" readonly>
                <button class="btn btn-outline-secondary" type="button" data-copy="#imgLinkKey" title="Copy"><i class="bi bi-clipboard"></i></button>
              </div>
              <label class="form-label small mb-1" for="imgKeyOnly">Key</label>
              <div class="input-group input-group-sm mb-2">
                <input class="form-control font-monospace" id="imgKeyOnly" readonly>
                <button class="btn btn-outline-secondary" type="button" data-copy="#imgKeyOnly" title="Copy"><i class="bi bi-clipboard"></i></button>
              </div>
            </div>
            <label class="form-label small mb-1" for="imgLinkPlain">Link without key</label>
            <div class="input-group input-group-sm mb-2">
              <input class="form-control font-monospace" id="imgLinkPlain" value="<?= $e($img_page) ?>" readonly>
              <button class="btn btn-outline-secondary" type="button" data-copy="#imgLinkPlain" title="Copy"><i class="bi bi-clipboard"></i></button>
            </div>
            <p class="small text-muted mb-0">Anyone with the link with key can view this. For extra safety, send the link without key and the key separately. The server cannot decrypt it.</p>
          <?php else: ?>
            <?php foreach ($links as $i => [$label, $value]): ?>
              <label class="form-label small mb-1" for="imgLink<?= $i ?>"><?= $e($label) ?></label>
              <div class="input-group input-group-sm mb-2">
                <input class="form-control font-monospace" id="imgLink<?= $i ?>" value="<?= $e($value) ?>" readonly>
                <button class="btn btn-outline-secondary" type="button" data-copy="#imgLink<?= $i ?>" title="Copy"><i class="bi bi-clipboard"></i></button>
              </div>
            <?php endforeach; ?>
            <p class="small text-muted mb-0"><i class="bi bi-lock-fill text-primary me-1"></i>Stored encrypted. The link is the key: anyone you give it to can view the file, and nobody can without it, including this site's admins.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="<?= $e(paste_theme_asset($baseurl, $default_theme, 'js/img.js')) ?>" defer></script>
