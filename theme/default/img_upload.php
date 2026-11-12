<?php
/*
 * Paste https://github.com/boxlabss/PASTE
 *
 * Upload form for images and videos, with the visitor's uploads and albums.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 */

$e = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$capOn = ($_SESSION['cap_e'] ?? 'off') === 'on';
$needCaptcha = $canUpload && $username === null && $capOn
    && (int)($_SESSION['img_human_until'] ?? 0) <= time();
$capKind = 'none';
if ($needCaptcha) {
    $capKind = match ((string)($_SESSION['mode'] ?? 'normal')) {
        'reCAPTCHA' => (($_SESSION['recaptcha_version'] ?? 'v2') === 'v3' ? 'recaptcha_v3' : 'recaptcha'),
        'turnstile' => 'turnstile',
        default     => 'internal',
    };
}
$capSitekey = $capKind === 'turnstile' ? (string)($_SESSION['turnstile_sitekey'] ?? '') : (string)($_SESSION['recaptcha_sitekey'] ?? '');

$stripDefault   = (int)($settings['img_strip_default'] ?? 1) === 1;
$encryptDefault = (int)($settings['img_encrypt_default'] ?? 1) === 1;
$loginUrl = $baseurl . 'login.php?next=' . rawurlencode(img_home_url($baseurl));
$albumMax = (int)($img_album_max ?? 0);
$addAlbum = $img_add_album ?? null;
$addLeft  = $addAlbum ? max(0, $albumMax - count($addAlbum['_meta']['items'])) : 0;
$pageLink = static fn(int $p): string => img_home_url($baseurl) . ($p > 1 ? '?page=' . $p : '') . '#imgMine';
?>
<style>
  .img-wrap { max-width: 760px; margin: 0 auto; }
  .img-card { background: var(--bs-body-bg); border: 1px solid var(--bs-border-color); border-radius: 14px; overflow: hidden; margin-bottom: 1rem; }
  .img-card-hd { padding: .75rem 1.25rem; border-bottom: 1px solid var(--bs-border-color); background: var(--bs-tertiary-bg);
                 font-size: .7rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--bs-secondary-color);
                 display: flex; align-items: center; gap: .45rem; }
  .img-card-hd i { color: var(--bs-primary); font-size: .9rem; }
  .img-drop { display: block; margin: 1rem; border: 2px dashed var(--bs-border-color); border-radius: 12px; padding: 2.25rem 1rem;
              text-align: center; cursor: pointer; transition: border-color .15s, background .15s; }
  .img-drop:hover, .img-drop.is-over { border-color: var(--bs-primary); background: rgba(13,110,253,.08); }
  .img-drop .ico { font-size: 2rem; color: var(--bs-primary); }
  .img-queue { padding: 0 1rem 1rem; }
  .img-queue-item { display: grid; grid-template-columns: 48px 1fr auto; gap: .7rem; align-items: center; padding: .55rem .25rem; border-bottom: 1px solid var(--bs-border-color); }
  .img-queue-item:last-child { border-bottom: 0; }
  .img-queue-item .thumb { width: 48px; height: 48px; border-radius: 9px; background: var(--bs-tertiary-bg) center/cover no-repeat;
                           display: grid; place-items: center; color: var(--bs-primary); }
  .img-queue-item .meta { min-width: 0; }
  .img-queue-item .name { font-size: .85rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .img-queue-item .title { margin: .3rem 0 .2rem; max-width: 420px; }
  .img-queue-item .bar { height: 3px; background: var(--bs-border-color); border-radius: 2px; overflow: hidden; margin-top: .35rem; display: none; }
  .img-queue-item .bar > div { height: 100%; width: 0; background: var(--bs-primary); transition: width .2s; }
  .img-queue-item.busy .bar { display: block; }
  .img-queue-item.done .name { color: var(--bs-success); }
  .img-queue-item.error .name { color: var(--bs-danger); }
  .img-opts { padding: 1rem 1.25rem; display: flex; flex-direction: column; gap: .9rem; }
  .img-opt { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
  .img-opt .lbl { font-size: .9rem; font-weight: 600; display: flex; align-items: center; gap: .45rem; }
  .img-opt .lbl i { color: var(--bs-primary); }
  .img-opt .desc { font-size: .75rem; color: var(--bs-secondary-color); }
  .img-sub { padding: .9rem 1.25rem 1rem; border-top: 1px solid var(--bs-border-color); background: var(--bs-tertiary-bg); }
  .img-dims { display: grid; grid-template-columns: 1fr 38px 1fr; gap: .5rem; align-items: end; }
  .img-lock.on { color: var(--bs-primary); border-color: var(--bs-primary); }
  .img-results .item { display: flex; gap: .65rem; align-items: flex-start; padding: .65rem .85rem; border-radius: 10px; margin-bottom: .5rem; font-size: .85rem; }
  .img-results .item.ok { background: rgba(25,135,84,.12); border: 1px solid rgba(25,135,84,.3); }
  .img-results .item.fail { background: rgba(220,53,69,.12); border: 1px solid rgba(220,53,69,.3); }
  .img-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: .75rem; }
  .img-tile { border: 1px solid var(--bs-border-color); border-radius: 10px; overflow: hidden; background: var(--bs-tertiary-bg); }
  .img-tile .preview { display: flex; align-items: center; justify-content: center; height: 120px; background: #0b0d12; color: var(--bs-secondary-color); }
  .img-tile .preview img { width: 100%; height: 100%; object-fit: cover; }
  .img-tile .preview i { font-size: 2rem; }
  .img-tile .caption { padding: .4rem .55rem; font-size: .8rem; }
  .img-tile .caption .name { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .img-tile .count { position: absolute; top: .4rem; right: .4rem; }
  .img-tile .preview { position: relative; }
</style>

<div class="container-xxl my-4">
  <div class="img-wrap">
    <?php if (!empty($img_flash)): ?>
      <div class="alert alert-success"><?= $e($img_flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($img_error)): ?>
      <div class="alert alert-danger"><?= $e($img_error) ?></div>
    <?php endif; ?>

    <?php if ($addAlbum): ?>
      <div class="alert alert-info d-flex align-items-center gap-2 flex-wrap">
        <i class="bi bi-collection"></i>
        <span>Adding to <a href="<?= $e($img_album_url($addAlbum['_name'])) ?>"><?= $e(img_album_title($addAlbum)) ?></a>:
          room for <?= (int)$addLeft ?> more <?= $addLeft === 1 ? 'upload' : 'uploads' ?>.</span>
      </div>
    <?php endif; ?>

    <?php if (!$canUpload): ?>
      <div class="img-card">
        <div class="img-card-hd"><i class="bi bi-upload"></i> Upload</div>
        <div class="p-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
          <span>You need to be logged in to upload.</span>
          <a class="btn btn-primary btn-sm" href="<?= $e($loginUrl) ?>">Log in</a>
        </div>
      </div>
    <?php else: ?>
    <form id="imgForm" method="post" enctype="multipart/form-data" action="<?= $e(img_home_url($baseurl)) ?>"
          data-max-image="<?= (int)$limits['image'] ?>"
          data-max-video="<?= (int)$limits['video'] ?>"
          data-captcha="<?= $e($capKind) ?>"
          data-sitekey="<?= $e($capSitekey) ?>"
          data-album-max="<?= $albumMax ?>"
          data-album-guest="<?= $username === null ? '1' : '0' ?>"
          data-album-add="<?= $e($addAlbum['_name'] ?? '') ?>"
          data-album-left="<?= (int)$addLeft ?>">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">

      <div class="img-card">
        <div class="img-card-hd"><i class="bi bi-upload"></i> Select files</div>
        <label class="img-drop" id="imgDrop" for="imgFile">
          <span class="ico d-block mb-2"><i class="bi bi-images"></i></span>
          <span class="d-block fw-semibold">Drop files here, paste with Ctrl+V, or click to browse</span>
          <span class="d-block small text-muted mt-1">
            Images (JPEG, PNG, GIF, WebP) up to <?= $e(img_format_bytes($limits['image'])) ?><br>
            Videos (MP4, MOV, WebM, AVI, MKV) up to <?= $e(img_format_bytes($limits['video'])) ?>
          </span>
          <input class="visually-hidden" type="file" name="file" id="imgFile" multiple
                 accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/quicktime,video/x-msvideo,video/x-matroska,.avi,.mkv,.mov,.m4v">
        </label>
        <div class="px-3 pb-3">
          <label class="form-label small mb-1" for="imgTitle"><span id="imgTitleLabel">Title</span> <span class="text-muted">(optional)</span></label>
          <input class="form-control" type="text" name="title" id="imgTitle" maxlength="<?= (int)IMG_TITLE_MAX ?>" placeholder="Shown instead of the file name" autocomplete="off">
          <div class="form-text d-none" id="imgTitleHelp">Used for every file below unless you give one its own title.</div>
          <?php if ($albumMax > 0 && !$addAlbum): ?>
            <div class="d-none mt-3" id="imgAlbumBox">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="imgAlbumOn" checked>
                <label class="form-check-label" for="imgAlbumOn"><i class="bi bi-collection me-1"></i>Put these in an album</label>
              </div>
              <div class="form-text" id="imgAlbumNote">One page and one link for all of them. Turn off for a separate link to each file.</div>
              <div class="form-text text-warning d-none" id="imgAlbumWarn"></div>
              <div class="d-none mt-2" id="imgAlbumDescWrap">
                <label class="form-label small mb-1" for="imgAlbumDesc">Album description <span class="text-muted">(optional)</span></label>
                <textarea class="form-control form-control-sm" id="imgAlbumDesc" rows="2" maxlength="<?= (int)IMG_DESC_MAX ?>"></textarea>
              </div>
            </div>
          <?php endif; ?>
        </div>
        <div class="img-queue" id="imgQueue"></div>
      </div>

      <div class="img-card">
        <div class="img-card-hd"><i class="bi bi-sliders2"></i> Options</div>
        <div class="img-opts">
          <div class="small text-muted"><i class="bi bi-lock-fill text-primary me-1"></i>Uploads are encrypted and its link is the key: anyone you give the link to can view it, and nobody can without it.<?php if ($username !== null): ?> With "Keep links in my account", your account keeps a copy of each link, encrypted with this site's key, so your uploads are listed on any device.<?php else: ?><?php endif; ?></div>
          <div class="img-opt">
            <div>
              <div class="lbl"><i class="bi bi-lock"></i> Encrypt in my browser</div>
              <div class="desc">Extra privacy: the file is encrypted before it leaves this browser and the key goes after # in the link, so the server never sees it. Direct links and embeds will not work.</div>
            </div>
            <div class="form-check form-switch m-0">
              <input class="form-check-input" type="checkbox" role="switch" id="imgEncrypt" name="encrypt_requested" value="1" <?= $encryptDefault ? 'checked' : '' ?>>
            </div>
          </div>
          <div class="img-opt">
            <div>
              <div class="lbl"><i class="bi bi-shield-check"></i> Strip EXIF &amp; metadata</div>
              <div class="desc">Removes GPS, camera details and hidden data.</div>
            </div>
            <div class="form-check form-switch m-0">
              <input class="form-check-input" type="checkbox" role="switch" id="imgStrip" name="strip_exif" value="1" <?= $stripDefault ? 'checked' : '' ?>>
            </div>
          </div>
          <div class="img-opt">
            <div>
              <div class="lbl"><i class="bi bi-aspect-ratio"></i> Resize images before upload</div>
              <div class="desc">Re-encodes to your target size in the browser. GIFs are never resized.</div>
            </div>
            <div class="form-check form-switch m-0">
              <input class="form-check-input" type="checkbox" role="switch" id="imgResizeOn">
            </div>
          </div>
        </div>

        <div class="img-sub d-none" id="imgResizePanel">
          <div class="img-dims mb-3">
            <div>
              <label class="form-label small mb-1" for="imgW">Width (px)</label>
              <input type="number" class="form-control form-control-sm" id="imgW" placeholder="auto" min="1">
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary img-lock on" id="imgLock" title="Keep aspect ratio"><i class="bi bi-link-45deg"></i></button>
            <div>
              <label class="form-label small mb-1" for="imgH">Height (px)</label>
              <input type="number" class="form-control form-control-sm" id="imgH" placeholder="auto" min="1">
            </div>
          </div>
          <label class="form-label small mb-1" for="imgQuality">JPEG quality: <span id="imgQualityLbl">92</span>%</label>
          <input type="range" class="form-range" id="imgQuality" min="10" max="100" value="92">
        </div>

        <div class="img-sub">
          <div class="row g-3">
            <div class="col-sm-7<?= $encryptDefault ? '' : ' d-none' ?>" id="imgPassCol">
              <label class="form-label small mb-1" for="imgPass">Passphrase <span class="text-muted">(optional)</span></label>
              <input type="password" class="form-control form-control-sm" id="imgPass" autocomplete="new-password" placeholder="Leave empty for a random key per file">
              <div class="form-text">At least 12 characters. A random key is safer unless you want one passphrase for several files.</div>
            </div>
            <div class="col-sm-5">
              <label class="form-label small mb-1" for="imgExpiry">Delete after</label>
              <select class="form-select form-select-sm" id="imgExpiry" name="expiry">
                <option value="N">Never</option>
                <option value="1H">1 hour</option>
                <option value="1D">1 day</option>
                <option value="1W">1 week</option>
                <option value="1M">1 month</option>
              </select>
              <?php if ($username !== null): ?>
                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" id="imgKeep" name="keep" value="1" checked>
                  <label class="form-check-label small" for="imgKeep">Keep links in my account</label>
                </div>
              <?php endif; ?>
              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="imgRemember" checked>
                <label class="form-check-label small" for="imgRemember">Remember links in this browser</label>
              </div>
            </div>
          </div>
        </div>
      </div>

      <noscript><div class="alert alert-warning">Encryption in the browser, resizing and previews need JavaScript. Without it you can upload one file at a time with "Encrypt in my browser" unticked.</div></noscript>

      <?php if ($capKind === 'recaptcha'): ?>
        <div class="g-recaptcha mb-3" data-sitekey="<?= $e($capSitekey) ?>"></div>
      <?php elseif ($capKind === 'turnstile'): ?>
        <div class="cf-turnstile mb-3" id="imgTurnstile" data-sitekey="<?= $e($capSitekey) ?>" data-action="upload"></div>
      <?php elseif ($capKind === 'internal'): ?>
        <div class="mb-3" style="max-width:360px"><?php include __DIR__ . '/captcha_bootstrap.php'; ?></div>
      <?php endif; ?>

      <div id="imgStatus" class="small mb-2" aria-live="polite"></div>
      <button class="btn btn-primary w-100 py-2 fw-semibold" type="submit" id="imgSubmit">
        <i class="bi bi-cloud-arrow-up-fill me-1"></i> Upload files <span class="badge bg-light text-dark ms-1 d-none" id="imgCount"></span>
      </button>
    </form>

    <div class="img-card mt-3 d-none" id="imgResultsCard">
      <div class="img-card-hd"><i class="bi bi-check2-circle"></i> Results</div>
      <div class="p-3 img-results" id="imgResults"></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($img_albums)): ?>
    <div class="img-card mt-3">
      <div class="img-card-hd"><i class="bi bi-collection"></i> <?= $username !== null ? 'Your albums' : 'Albums made in this session' ?></div>
      <div class="p-3">
        <div class="img-grid">
          <?php foreach ($img_albums as $a):
            $aOpen = $a['_name'] !== null;
            $aCover = $aOpen ? img_album_cover($pdo, $a) : null;
            $aCoverImage = $aCover && (IMG_TYPES[$aCover['mime']][0] ?? '') === 'image';
          ?>
            <div class="img-tile">
              <a class="preview"<?= $aOpen ? ' href="' . $e($img_album_url($a['_name'])) . '"' : '' ?>>
                <?php if ($aCoverImage): ?>
                  <img src="<?= $e(img_file_url((string)$aCover['_name'], $aCover['ext'], $baseurl, $rewrite)) ?>" alt="" loading="lazy">
                <?php else: ?>
                  <i class="bi <?= $aOpen ? 'bi-collection' : 'bi-lock' ?>"></i>
                <?php endif; ?>
                <span class="badge bg-dark count"><?= (int)$a['items'] ?></span>
              </a>
              <div class="caption">
                <div class="name" title="<?= $e($aOpen ? img_album_title($a) : 'Album') ?>"><?= $e($aOpen ? img_album_title($a) : 'Album') ?></div>
                <div class="text-muted"><?= $e(date('j M', strtotime((string)$a['created']))) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($img_recent)): ?>
    <div class="img-card mt-3" id="imgMine">
      <div class="img-card-hd">
        <i class="bi bi-images"></i> <?= $username !== null ? 'Your uploads' : 'Uploaded in this session' ?>
        <?php if ($username !== null): ?><span class="badge bg-secondary"><?= (int)$img_recent_total ?></span><?php endif; ?>
        <button type="button" class="btn btn-link btn-sm ms-auto p-0 text-decoration-none" id="imgForget">Forget links on this device</button>
      </div>
      <div class="p-3">
        <?php if ($username === null): ?>
          <p class="small text-muted mb-3">The site does not keep upload links, so names and previews show only in a browser that remembers them.</p>
        <?php endif; ?>
        <div class="img-grid" data-page-url="<?= $e(img_page_url('KEYNAME', $baseurl, $rewrite)) ?>" data-file-url="<?= $e(img_file_url('KEYNAME', 'EXTNAME', $baseurl, $rewrite)) ?>">
          <?php foreach ($img_recent as $u):
            $uEnc  = (int)($u['encrypted'] ?? 0) === 1;
            $uKind = $uEnc ? 'locked' : (IMG_TYPES[$u['mime']][0] ?? 'file');
            $uName = $uEnc ? null : ($u['_name'] ?? null);
            $uPage = $uEnc ? img_page_url($u['slug'], $baseurl, $rewrite) : ($uName !== null ? img_page_url($uName, $baseurl, $rewrite) : '');
            $uThumb = $uEnc && (int)($u['thumb_size'] ?? 0) > 0 ? img_file_url($u['slug'], 'thumb', $baseurl, $rewrite) : '';
            $uLabel = $uName !== null ? (img_display_title($u) ?? img_display_name($u) ?? 'Upload') : 'Encrypted';
            $uKept = $uEnc || $uName !== null || ($u['owner_key'] ?? null) !== null;
          ?>
            <div class="img-tile" data-slug="<?= $e($u['slug']) ?>" data-encrypted="<?= $uEnc ? '1' : '0' ?>"
                 data-mode="<?= $uEnc ? 'browser' : ($uName !== null ? 'kept' : 'link') ?>" data-kind="<?= $e($uKind) ?>" data-ext="<?= $e($u['ext']) ?>"
                 data-meta="<?= $e($uEnc ? (string)$u['meta'] : '') ?>" data-thumb="<?= $e($uThumb) ?>" data-kept="<?= $uKept ? '1' : '0' ?>">
              <a class="preview"<?= $uPage !== '' ? ' href="' . $e($uPage) . '"' : '' ?>>
                <?php if ($uName !== null && $uKind === 'image'): ?>
                  <img src="<?= $e(img_file_url($uName, $u['ext'], $baseurl, $rewrite)) ?>" alt="<?= $e($uLabel) ?>" loading="lazy">
                <?php elseif ($uName !== null): ?>
                  <i class="bi <?= $uKind === 'video' ? 'bi-film' : 'bi-file-earmark' ?>"></i>
                <?php else: ?>
                  <i class="bi bi-lock"></i>
                <?php endif; ?>
              </a>
              <div class="caption">
                <div class="name" title="<?= $e($uLabel) ?>"><?= $e($uLabel) ?></div>
                <div class="d-flex justify-content-between align-items-center text-muted">
                  <span><?= $e(img_format_bytes((int)$u['size'] - ($uEnc ? IMG_CRYPT_OVERHEAD : 0))) ?> · <?= $e(date('j M', strtotime((string)$u['created']))) ?></span>
                  <form method="post" action="<?= $e(img_home_url($baseurl)) ?>" onsubmit="return confirm('Delete this upload?')">
                    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="slug" value="<?= $e($u['slug']) ?>">
                    <button class="btn btn-link btn-sm text-danger p-0" title="Delete"><i class="bi bi-trash"></i></button>
                  </form>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if (($img_pages ?? 1) > 1): ?>
          <nav class="d-flex justify-content-between align-items-center mt-3 small">
            <?php if ($img_page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="<?= $e($pageLink($img_page - 1)) ?>">Newer</a><?php else: ?><span></span><?php endif; ?>
            <span class="text-muted">Page <?= (int)$img_page ?> of <?= (int)$img_pages ?></span>
            <?php if ($img_page < $img_pages): ?><a class="btn btn-sm btn-outline-secondary" href="<?= $e($pageLink($img_page + 1)) ?>">Older</a><?php else: ?><span></span><?php endif; ?>
          </nav>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<script src="<?= $e(paste_theme_asset($baseurl, $default_theme, 'js/img.js')) ?>" defer></script>
