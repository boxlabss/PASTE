<?php
/*
 * Paste $v3.3 2025/10/24 https://github.com/boxlabss/PASTE
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License in LICENCE for more details.
 */
 
$__cap_color      = isset($captcha_color)      && is_string($captcha_color)      ? $captcha_color      : '#0a58ca';
$__cap_mode       = isset($captcha_difficulty) && is_string($captcha_difficulty) ? $captcha_difficulty : 'Normal';   // Easy|Normal|Tough
$__cap_multibg    = isset($captcha_multibg)    && is_string($captcha_multibg)    ? $captcha_multibg    : 'on';       // 'on' or ''
$__cap_allowed    = isset($captcha_allowed)    && is_string($captcha_allowed)    ? $captcha_allowed    : 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$__input_name     = isset($captcha_input_name) && is_string($captcha_input_name) ? $captcha_input_name : 'scode';
$__placeholder    = (isset($lang) && is_array($lang) && isset($lang['entercode']) && is_string($lang['entercode']))
                    ? htmlspecialchars($lang['entercode'], ENT_QUOTES, 'UTF-8')
                    : 'Enter CAPTCHA';

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$imgSrc = (string)($_SESSION['captcha']['image_src'] ?? '');
$allowGenerate = (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST');

if ($imgSrc === '' && $allowGenerate) {
    $lib = dirname(__DIR__, 2) . '/includes/captcha.php';
    if (is_file($lib)) {
        require_once $lib;
        if (function_exists('captcha')) {
            $cap    = captcha($captcha_color ?? '#0a58ca', $captcha_difficulty ?? 'Normal', $captcha_multibg ?? 'on', $captcha_allowed ?? 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789');
            $imgSrc = (string)($cap['image_src'] ?? '');
        }
    }
}
if ($imgSrc === '') {
    $imgSrc = '/includes/captcha.php?_CAPTCHA=1';
}
if (strpos($imgSrc, '_CAPTCHA=') === false) {
    $imgSrc .= (strpos($imgSrc,'?') !== false ? '&' : '?') . '_CAPTCHA=1';
}


// Unique IDs per instance
$GLOBALS['__CAPTCHA_WIDGET_SEQ'] = ($GLOBALS['__CAPTCHA_WIDGET_SEQ'] ?? 0) + 1;
$seq    = (int)$GLOBALS['__CAPTCHA_WIDGET_SEQ'];
$imgId  = "captcha-img-$seq";
$btnId  = "captcha-refresh-$seq";


$inpId = 'scode';
$placeholder = htmlspecialchars($lang['entercode'] ?? 'Enter CAPTCHA code', ENT_QUOTES, 'UTF-8');
?>
<div class="row g-3 align-items-center mb-3" data-captcha>
  <div class="col-12 col-md-auto">
    <div class="d-flex align-items-center gap-2 p-2 border rounded-3 bg-body-tertiary">
      <img
        src="<?php echo htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8'); ?>"
        alt="CAPTCHA image"
        class="captcha-img shadow-sm"
        id="<?php echo $imgId; ?>"
        loading="lazy"
        decoding="async">
      <button
        type="button"
        class="btn btn-outline-secondary d-inline-flex align-items-center"
        id="<?php echo $btnId; ?>"
        aria-label="Refresh captcha"
        title="Refresh">
        <i class="bi bi-arrow-clockwise"></i>
        <span class="ms-1 d-none d-sm-inline">Refresh</span>
      </button>
    </div>
  </div>

  <div class="col-12 col-md-3">
    <label for="scode" class="form-label mb-1">Human verification</label>
    <div class="input-group">
      <span class="input-group-text"><i class="bi bi-shield-lock"></i></span>
      <input
        type="text"
        class="form-control"
        name="scode"
        id="scode"
        value=""
        autocomplete="off" autocapitalize="off" spellcheck="false"
        required
        placeholder="<?php echo $placeholder; ?>"
        maxlength="5" pattern="[A-Za-z0-9]{5}" aria-describedby="scode-help">
    </div>
    <div id="scode-help" class="form-text">Type the characters shown on the left.</div>
  </div>
</div>

<?php if (!defined('CAPTCHA_BOOTSTRAP_WIDGET_JS')): define('CAPTCHA_BOOTSTRAP_WIDGET_JS', true); ?>
<script>
(function(){
  function refresh(img){
    try{
      const url = new URL(img.src, location.href);
      url.searchParams.set('_CAPTCHA','1');
      url.searchParams.set('regen','1');
      url.searchParams.set('t', Date.now().toString());
      img.src = url.toString();
      const root = img.closest('[data-captcha]');
      const input = root && root.querySelector('input.form-control');
      if (input) input.value = '';
    }catch(e){  }
  }
  document.addEventListener('click', function(e){
    const btn = e.target.closest('[id^="captcha-refresh-"]');
    if (btn){
      const root = btn.closest('[data-captcha]');
      const img  = root && root.querySelector('.captcha-img');
      if (img) refresh(img);
    }
    const img = e.target.closest('[data-captcha] .captcha-img');
    if (img) refresh(img);
  }, {passive:true});
})();
</script>
<?php endif; ?>
