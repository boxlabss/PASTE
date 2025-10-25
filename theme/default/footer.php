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
 
// reCAPTCHA and Turnstile config
$cap_e = $_SESSION['cap_e'] ?? 'off';
$mode = $_SESSION['mode'] ?? 'normal';
$recaptcha_version = $_SESSION['recaptcha_version'] ?? 'v2';
$recaptcha_sitekey = $_SESSION['recaptcha_sitekey'] ?? '';
$turnstile_sitekey = $_SESSION['turnstile_sitekey'] ?? '';
$captcha_enabled = ($cap_e === 'on' && in_array($mode, ['reCAPTCHA', 'turnstile']) && (!empty($recaptcha_sitekey) || !empty($turnstile_sitekey)));
?>
<!-- Footer -->
<footer class="container-xxl py-3 my-4 border-top">
  <div class="row align-items-center gy-2">
    <div class="col-md-4 mb-0 text-muted">
      Copyright &copy; <?php echo date("Y"); ?>
      <a href="<?php echo htmlspecialchars($baseurl, ENT_QUOTES, 'UTF-8'); ?>" class="text-decoration-none">
        <?php echo htmlspecialchars($site_name ?? 'Paste', ENT_QUOTES, 'UTF-8'); ?>
      </a>. All rights reserved.
    </div>
    <div class="col-md-4 text-center">
      <a href="<?php echo htmlspecialchars($baseurl, ENT_QUOTES, 'UTF-8'); ?>" class="d-inline-flex align-items-center text-decoration-none" aria-label="Paste Home">
        <i class="bi bi-clipboard me-2" style="font-size: 1.5rem;"></i>
      </a>
      <?php
      // Footer inline links:
      $footerLinks = getNavLinks($pdo, 'footer');
      echo renderNavListSimple($footerLinks, ' &middot; ');
      ?>
    </div>
    <div class="col-md-4 text-md-end text-muted">
      <button type="button" class="btn btn-link p-0 me-3" data-bs-toggle="modal" data-bs-target="#cookieSettingsModal" aria-label="Cookie Settings">Cookie Settings</button>
      <a href="https://phpaste.sourceforge.io/" target="_blank" class="text-decoration-none">Powered by Paste</a>
    </div>
  </div>
</footer>
<?php if (!isset($_SESSION['username'])): ?>
  <div class="text-center mb-4">
    <?php echo $ads_2 ?? ''; ?>
  </div>
<?php endif; ?>
<!-- GDPR stuff -->
<div id="cookieBanner" class="position-fixed bottom-0 start-0 end-0 border-top shadow-sm">
  <div class="container-xxl py-1 d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
    <div class="me-lg-2">
      <h6 class="mb-1">We use cookies. To comply with GDPR in the EU and the UK we have to show you these.</h6>
      <p class="mb-0 text-muted small">
        We use cookies and similar technologies to keep this website functional (including spam protection via Google reCAPTCHA or Cloudflare Turnstile), and — with your consent — to measure usage and show ads.
        See <a href="<?php echo htmlspecialchars(($baseurl ?? '/') . 'page/privacy', ENT_QUOTES, 'UTF-8'); ?>">Privacy</a>.
      </p>
    </div>
    <div class="ms-lg-auto d-flex gap-3">
      <button id="cookieReject" type="button" class="btn btn-outline-secondary">Reject non-essential</button>
      <button id="cookieSettings" type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#cookieSettingsModal">Settings</button>
      <button id="cookieAcceptAll" type="button" class="btn btn-primary">Accept all</button>
    </div>
  </div>
</div>
<!-- Cookie Settings Modal -->
<div class="modal fade" id="cookieSettingsModal" tabindex="-1" aria-labelledby="cookieSettingsLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="cookieSettingsLabel">Cookie Settings</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small">
          We use cookies to make our site work and keep it safe and secure (e.g., reCAPTCHA or Turnstile). You can choose to enable additional categories.
        </p>
        <div class="list-group">
          <label class="list-group-item d-flex align-items-start">
            <div class="form-check form-switch me-3 mt-1">
              <input class="form-check-input" type="checkbox" role="switch" checked disabled>
            </div>
            <div>
              <div class="fw-semibold">Strictly necessary</div>
              <div class="small text-muted">
                Required for security and core features (sessions, preferences, rate-limiting, and Google reCAPTCHA or Cloudflare Turnstile). These are always on.
              </div>
            </div>
          </label>
          <label class="list-group-item d-flex align-items-start">
            <div class="form-check form-switch me-3 mt-1">
              <input id="consentAnalytics" class="form-check-input" type="checkbox" role="switch">
            </div>
            <div>
              <div class="fw-semibold">Analytics</div>
              <div class="small text-muted">
                Helps us understand usage and improve the site (Google Analytics).
              </div>
            </div>
          </label>
          <label class="list-group-item d-flex align-items-start">
            <div class="form-check form-switch me-3 mt-1">
              <input id="consentAds" class="form-check-input" type="checkbox" role="switch">
            </div>
            <div>
              <div class="fw-semibold">Advertising</div>
              <div class="small text-muted">
                Enables ad networks like Google AdSense. Ads may use cookies to personalize/measure performance.
              </div>
            </div>
          </label>
        </div>
      </div>
      <div class="modal-footer">
        <button id="cookieSave" type="button" class="btn btn-primary" data-bs-dismiss="modal">Save preferences</button>
      </div>
    </div>
  </div>
</div>
<!-- Scripts -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/zxcvbn/4.4.2/zxcvbn.js">// Client side passphrase</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  var encryptModalEl = document.getElementById('encryptPassModal');
  var encryptModal = new bootstrap.Modal(encryptModalEl);
  var encryptChk = document.getElementById('client_encrypt');
  var encryptInput = document.getElementById('encryptPassInput');
  var strengthBar = document.getElementById('passStrengthBar');
  var strengthText = document.getElementById('passStrengthText');
  var encryptConfirm = document.getElementById('encryptConfirm');
  var toggleEncryptPass = document.getElementById('toggleEncryptPass');
  var encryptPassIcon = document.getElementById('encryptPassIcon');
  var mainForm = document.getElementById('mainForm');
  var clientPass = ''; // Temporary store for client passphrase (cleared after use)

  // Show modal on checkbox tick
  encryptChk.addEventListener('change', function() {
    if (this.checked) {
      encryptModal.show();
      encryptInput.focus();
    } else {
      encryptModal.hide();
      document.getElementById('is_client_encrypted').value = '0'; // Reset flag
    }
  });

  // Toggle password visibility
  toggleEncryptPass.addEventListener('click', function() {
    if (encryptInput.type === 'password') {
      encryptInput.type = 'text';
      encryptPassIcon.className = 'bi bi-eye-slash'; // Hide icon
    } else {
      encryptInput.type = 'password';
      encryptPassIcon.className = 'bi bi-eye'; // Show icon
    }
  });

  // Strength meter
  encryptInput.addEventListener('input', function() {
    var val = this.value;
    var result = zxcvbn(val);
    var strength = result.score;
    var labels = ['Very Weak', 'Weak', 'Fair', 'Good', 'Strong'];
    var colors = ['bg-danger', 'bg-warning', 'bg-info', 'bg-primary', 'bg-success'];
    var widths = [20, 40, 60, 80, 100];
    
    strengthBar.style.width = widths[strength] + '%';
    strengthBar.className = 'progress-bar ' + colors[strength];
    strengthText.textContent = 'Strength: ' + labels[strength];
    
    encryptConfirm.disabled = (val.length < 12 || strength < 2);
  });

  // Clear on hide ONLY if not encrypted
  encryptModalEl.addEventListener('hidden.bs.modal', function() {
    encryptInput.value = '';
    strengthBar.style.width = '0%';
    strengthText.textContent = 'Strength: Weak';
    encryptConfirm.disabled = true;
    if (encryptChk.checked && document.getElementById('is_client_encrypted').value !== '1') {
      encryptChk.checked = false; // Untick only if cancelled (flag not set)
      document.getElementById('is_client_encrypted').value = '0';
    }
    // Reset password type and icon
    encryptInput.type = 'password';
    encryptPassIcon.className = 'bi bi-eye';
  });

  // Confirm: Encrypt
  encryptConfirm.addEventListener('click', async function() {
    var pass = encryptInput.value.trim();
    if (encryptConfirm.disabled) return;
    clientPass = pass; // Store for later #pass= append (client-side only)
    var dataField = document.querySelector('[name="paste_data"]');
    var data = new TextEncoder().encode(dataField.value);
    
    // Derive key (PBKDF2)
    var salt = crypto.getRandomValues(new Uint8Array(16));
    var keyMaterial = await crypto.subtle.importKey(
      'raw', new TextEncoder().encode(pass), 'PBKDF2', false, ['deriveBits', 'deriveKey']
    );
    var key = await crypto.subtle.deriveKey(
      { name: 'PBKDF2', salt, iterations: 100000, hash: 'SHA-256' },
      keyMaterial, { name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']
    );
    
    // Encrypt
    var iv = crypto.getRandomValues(new Uint8Array(12));
    var encrypted = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, data);
    
    // Combine
    var combined = new Uint8Array(salt.byteLength + iv.byteLength + encrypted.byteLength);
    combined.set(salt, 0);
    combined.set(iv, salt.byteLength);
    combined.set(new Uint8Array(encrypted), salt.byteLength + iv.byteLength);
    
    dataField.value = btoa(String.fromCharCode(...combined));
    document.getElementById('is_client_encrypted').value = '1';
    encryptModal.hide();
  });

  // Intercept submit for client-encrypted pastes: Use fetch to get redirect URL, append #pass= client-side
  mainForm.addEventListener('submit', function(e) {
    if (document.getElementById('is_client_encrypted').value === '1' && clientPass) {
      e.preventDefault();
      fetch(this.action, {
        method: 'POST',
        body: new FormData(this),
        credentials: 'same-origin' // Include cookies/sessions
      }).then(res => {
        if (res.redirected || res.ok) {
          let url = res.url;
          window.location.href = url + '#pass=' + encodeURIComponent(clientPass);
          clientPass = ''; // Clear sensitive data
        } else {
          return res.text().then(text => { throw new Error(text || 'Submit failed'); });
        }
      }).catch(err => {
        alert('Error creating paste: ' + err.message);
      });
    }
    // For non-client-encrypted, normal submit (with server password if set)
  });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="<?php echo htmlspecialchars($baseurl . 'theme/' . ($default_theme ?? 'default') . '/js/paste.min.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php if (!empty($showThemeSwitcher) && !empty($hl_theme_options)): ?>
  <script>
    // Highlight.php theme picker
    window.__HL_THEMES = <?php echo json_encode($hl_theme_options, JSON_UNESCAPED_SLASHES); ?>;
    window.__HL_INITIAL = <?php echo isset($initialTheme) ? json_encode($initialTheme) : 'null'; ?>;
  </script>
  <script src="<?php echo htmlspecialchars($baseurl . 'theme/' . ($default_theme ?? 'default') . '/js/highlightTheme.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php endif; ?>
<script>
(function () {
  // --- Simple consent storage in a cookie (JSON payload) ---
  var CONSENT_COOKIE = 'paste_consent';
  var CONSENT_MAX_DAYS = 365;
  function setCookie(name, value, days) {
    var expires = '';
    if (days) {
      var d = new Date();
      d.setTime(d.getTime() + (days*24*60*60*1000));
      expires = '; expires=' + d.toUTCString();
    }
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = name + '=' + encodeURIComponent(value) + expires + '; Path=/' + secure + '; SameSite=Lax';
  }
  function getCookie(name) {
    var cname = name + '=';
    var ca = document.cookie.split(';');
    for (var i = 0; i < ca.length; i++) {
      var c = ca[i].trim();
      if (c.indexOf(cname) === 0) return decodeURIComponent(c.substring(cname.length, c.length));
    }
    return null;
  }
  function getDefaultConsent() {
    return { decided: false, analytics: false, ads: false };
  }
  function readConsent() {
    try {
      var raw = getCookie(CONSENT_COOKIE);
      return raw ? JSON.parse(raw) : getDefaultConsent();
    } catch(e) {
      return getDefaultConsent();
    }
  }
  function saveConsent(c) {
    c.decided = true;
    setCookie(CONSENT_COOKIE, JSON.stringify(c), CONSENT_MAX_DAYS);
  }
  function qs(id) { return document.getElementById(id); }
  // --- Script loaders gated by consent ---
  var hasLoadedGA = false;
  var hasLoadedAds = false;
  function loadGoogleAnalytics(measurementId) {
    if (hasLoadedGA || !measurementId) return;
    hasLoadedGA = true;
    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(measurementId);
    s.onload = function () {
      window.dataLayer = window.dataLayer || [];
      function gtag() { dataLayer.push(arguments); }
      window.gtag = gtag;
      gtag('js', new Date());
      gtag('config', measurementId);
    };
    document.head.appendChild(s);
  }
  function loadAdSense(clientId) {
    if (hasLoadedAds || !clientId) return;
    hasLoadedAds = true;
    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' + encodeURIComponent(clientId);
    s.setAttribute('crossorigin', 'anonymous');
    document.head.appendChild(s);
  }
  // Apply consent now
  function applyConsent(consent) {
    // Analytics (Google Analytics)
    <?php if (!empty($ga)): ?>
      if (consent.analytics) { loadGoogleAnalytics(<?php echo json_encode($ga); ?>); }
    <?php endif; ?>
    // Advertising (Google AdSense)
    // If you’re using AdSense, put your pub id below; otherwise leave blank.
    var adsClient = '';
    if (adsClient && consent.ads) { loadAdSense(adsClient); }
  }
  // --- UI wiring ---
  var banner = qs('cookieBanner');
  var btnAcceptAll = qs('cookieAcceptAll');
  var btnReject = qs('cookieReject');
  var btnSettings = qs('cookieSettings');
  var btnSave = qs('cookieSave');
  var chkAnalytics = qs('consentAnalytics');
  var chkAds = qs('consentAds');
  var consent = readConsent();
  // Initialize toggles from stored consent
  if (chkAnalytics) chkAnalytics.checked = !!consent.analytics;
  if (chkAds) chkAds.checked = !!consent.ads;
  // Show banner if user hasn’t decided yet
  if (!consent.decided && banner) {
    banner.style.display = 'block';
  }
  // Apply consent for this page load if already decided
  applyConsent(consent);
  // Handlers
  if (btnAcceptAll) {
    btnAcceptAll.addEventListener('click', function () {
      consent.analytics = true;
      consent.ads = true;
      saveConsent(consent);
      if (banner) banner.style.display = 'none';
      applyConsent(consent);
    });
  }
  if (btnReject) {
    btnReject.addEventListener('click', function () {
      consent.analytics = false;
      consent.ads = false;
      saveConsent(consent);
      if (banner) banner.style.display = 'none';
    });
  }
  if (btnSave) {
    btnSave.addEventListener('click', function () {
      consent.analytics = !!(chkAnalytics && chkAnalytics.checked);
      consent.ads = !!(chkAds && chkAds.checked);
      saveConsent(consent);
      if (banner) banner.style.display = 'none';
      applyConsent(consent);
    });
  }
})();
</script>
<?php if ($captcha_enabled): ?>
  <?php if ($mode === 'reCAPTCHA' && strtolower($recaptcha_version) === 'v3'): ?>
    <script>
      window.pasteConfig = {
        enabled: true,
        mode: 'reCAPTCHA',
        version: 'v3',
        siteKey: <?php echo json_encode($recaptcha_sitekey); ?>
      };
      (function () {
        function logOk(m) { console.log('%c' + m, 'color:#16a34a;font-weight:600'); }
        function logErr(m) { console.log('%c' + m, 'color:#ef4444;font-weight:700'); }
        function logWarn(m) { console.log('%c' + m, 'color:#f59e0b;font-weight:600'); }
        logOk('[reCAPTCHA] v3 enabled; loading api.js…');
        var s = document.createElement('script');
        s.async = true;
        s.defer = true;
        s.src = 'https://www.google.com/recaptcha/api.js?render=' + encodeURIComponent(window.pasteConfig.siteKey);
        s.onload = function () {
          if (!window.grecaptcha) { logErr('[reCAPTCHA] api.js loaded but grecaptcha missing'); return; }
          grecaptcha.ready(function () {
            logOk('[reCAPTCHA] grecaptcha ready.');
            var actionMap = {
              'mainForm': 'create_paste',
              'signin-form': 'login',
              'direct-signin-form': 'login',
              'signup-form': 'signup',
              'forgot-form': 'forgot',
              'reset-form': 'reset',
              'resend-form': 'resend'
            };
			setInterval(function() {
			  grecaptcha.execute(window.pasteConfig.siteKey, { action: 'keepalive' })
				.then(function(t) { console.log('[reCAPTCHA] keepalive token generated'); })
				.catch(function(e) { logErr('[reCAPTCHA] keepalive failed: ' + e); });
			}, 110000); // Every ~1.8 minutes
            function ensureHidden(form) {
              var h = form.querySelector('input[name="g-recaptcha-response"]');
              if (!h) {
                h = document.createElement('input');
                h.type = 'hidden';
                h.name = 'g-recaptcha-response';
                form.appendChild(h);
              }
              return h;
            }
            if (!window.__rcBoundSubmit) {
              document.addEventListener('submit', function (e) {
                var form = e.target;
                if (!(form instanceof HTMLFormElement)) return;
                var id = form.id || '';
                var action = actionMap[id] || 'submit';
                var hidden = form.querySelector('input[name="g-recaptcha-response"]');
                if (hidden && hidden.value) { logWarn('[reCAPTCHA] token already present for "' + action + '"; allow submit.'); return; }
                e.preventDefault();
                grecaptcha.execute(window.pasteConfig.siteKey, { action: action }).then(function (token) {
                  console.log('[reCAPTCHA] action="%s" token: %s…', action, token.slice(0, 28));
                  ensureHidden(form).value = token;
                  HTMLFormElement.prototype.submit.call(form);
                }).catch(function (e2) {
                  logErr('[reCAPTCHA] execute failed for "' + action + '": ' + (e2 && e2.message || e2));
                  HTMLFormElement.prototype.submit.call(form);
                });
              }, { capture: true });
              window.__rcBoundSubmit = true;
            }
          });
        };
        s.onerror = function() { logErr('[reCAPTCHA] failed to load api.js'); };
        document.head.appendChild(s);
      })();
    </script>
  <?php elseif ($mode === 'turnstile'): ?>
    <script>
      (function () {
        function setToken(id, token) {
          var el = document.getElementById(id);
          if (el) el.value = token;
        }
        // Global callbacks for all forms
        window.onTurnstileSuccess = window.onTurnstileSuccess || function(t) { setToken('cf-turnstile-response', t); };
        window.onTurnstileSuccessDirectSignin = window.onTurnstileSuccessDirectSignin || function(t) { setToken('cf-turnstile-response-direct-signin', t); };
        window.onTurnstileSuccessSignin = window.onTurnstileSuccessSignin || function(t) { setToken('cf-turnstile-response-signin', t); };
        window.onTurnstileSuccessSignup = window.onTurnstileSuccessSignup || function(t) { setToken('cf-turnstile-response-signup', t); };
        window.onTurnstileSuccessForgot = window.onTurnstileSuccessForgot || function(t) { setToken('cf-turnstile-response-forgot', t); };
        window.onTurnstileSuccessResend = window.onTurnstileSuccessResend || function(t) { setToken('cf-turnstile-response-resend', t); };
        window.onTurnstileSuccessReset = window.onTurnstileSuccessReset || function(t) { setToken('cf-turnstile-response-reset', t); };
        // Handle execute mode for all forms
        if (typeof turnstile !== 'undefined') {
          turnstile.ready(function() {
            var actionMap = {
              'mainForm': 'create_paste',
              'signin-form': 'login',
              'direct-signin-form': 'login',
              'signup-form': 'signup',
              'forgot-form': 'forgot',
              'reset-form': 'reset',
              'resend-form': 'resend'
            };
            document.addEventListener('submit', function(e) {
              var form = e.target;
              if (!(form instanceof HTMLFormElement)) return;
              var id = form.id || '';
              var action = actionMap[id] || 'submit';
              var turnstileInput = form.querySelector('input[name="cf-turnstile-response"]');
              if (!turnstileInput) return;
              if (turnstileInput.value) return;
              var turnstileWidget = form.querySelector('.cf-turnstile');
              if (!turnstileWidget) return;
              e.preventDefault();
			  turnstile.execute(turnstileWidget, { action: action, retry: 'never' });
            }, { capture: true });
          });
        }
      })();
    </script>
  <?php endif; ?>
<?php endif; ?>
<!-- Additional Script -->
<?php echo $additional_scripts ?? ''; ?>
</body>
</html>