<?php
/*
 * Paste $v3.3 2025/10/24 https://github.com/boxlabss/PASTE
 * demo: https://paste.boxlabs.uk/
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
 * ---
 * Theme: default — Login / Register / Forgot / Reset
 * Controller includes header.php before and footer.php after.
 * Captcha settings provided via session:
 * $_SESSION['cap_e'] === 'on' // is captcha on in the first place?
 * $_SESSION['mode'] ('reCAPTCHA'|'normal'|'turnstile' for Cloudflare)
 * $_SESSION['recaptcha_version'] ('v2'|'v3') // choose either
 * $_SESSION['recaptcha_sitekey']
 * $_SESSION['turnstile_sitekey']
 */
 
$cap_e = $_SESSION['cap_e'] ?? 'off';
$mode = $_SESSION['mode'] ?? 'normal';
$recaptcha_version = $_SESSION['recaptcha_version'] ?? 'v2';
$recaptcha_sitekey = $_SESSION['recaptcha_sitekey'] ?? '';
$turnstile_sitekey = $_SESSION['turnstile_sitekey'] ?? '';

// Map to consistent captcha_mode
if ($mode === 'reCAPTCHA' && $recaptcha_version === 'v3') {
    $captcha_mode = 'recaptcha_v3';
} elseif ($mode === 'reCAPTCHA') {
    $captcha_mode = 'recaptcha';
} elseif ($mode === 'turnstile') {
    $captcha_mode = 'turnstile';
} elseif ($mode === 'normal') {
    $captcha_mode = 'internal';
} else {
    $captcha_mode = 'none';
}
$captcha_enabled = ($cap_e === 'on' && $captcha_mode !== 'none' && (!empty($recaptcha_sitekey) || !empty($turnstile_sitekey) || $captcha_mode === 'internal'));
?>
<div class="container mt-5">
  <div class="row justify-content-center">
    <div class="col-md-6">
      <h2 class="text-center mb-4"><?php echo htmlspecialchars($p_title ?? ($lang['login/register'] ?? 'Login / Register'), ENT_QUOTES, 'UTF-8'); ?></h2>
      <div id="global-feedback"></div>
      <?php
        $flashError = isset($error) && $error !== '' ? $error : ($_GET['error'] ?? '');
        $flashSuccess = isset($success) && $success !== '' ? $success : ($_GET['success'] ?? '');
      ?>
      <?php if ($flashError): ?>
        <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php elseif ($flashSuccess): ?>
        <div class="alert alert-success" role="alert"><?php echo htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>
      <?php if (isset($_GET['action']) && $_GET['action'] === 'reset' && isset($_GET['username'], $_GET['code'])): ?>
        <!-- Reset -->
        <div class="card mb-4">
          <div class="card-body">
            <h5 class="card-title"><?php echo htmlspecialchars($lang['reset_password'] ?? 'Reset Password', ENT_QUOTES, 'UTF-8'); ?></h5>
            <div id="reset-feedback"></div>
            <form method="POST" action="<?php echo htmlspecialchars($baseurl . 'login.php?action=reset&username=' . urlencode($_GET['username']) . '&code=' . urlencode($_GET['code']), ENT_QUOTES, 'UTF-8'); ?>" id="reset-form">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
              <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? $baseurl, ENT_QUOTES, 'UTF-8'); ?>">
              <div class="mb-3">
                <label for="password" class="form-label"><?php echo htmlspecialchars($lang['new_password'] ?? 'New Password', ENT_QUOTES, 'UTF-8'); ?></label>
                <div class="input-group">
                  <span class="input-group-text"><i class="bi bi-lock"></i></span>
                  <input type="password" class="form-control" id="password" name="password" autocomplete="new-password" required>
                </div>
                <div class="form-text">
                  Need help?
                  <a href="<?php echo htmlspecialchars($baseurl . 'generatepasswd.php', ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">Generate a password</a>.
                </div>
              </div>
              <?php if ($captcha_enabled): ?>
                <?php if ($captcha_mode === 'recaptcha' && $recaptcha_version === 'v2'): ?>
                  <input type="hidden" id="g-recaptcha-response-reset" name="g-recaptcha-response">
                  <div class="g-recaptcha mb-3" data-sitekey="<?php echo htmlspecialchars($recaptcha_sitekey, ENT_QUOTES, 'UTF-8'); ?>" data-callback="onRecaptchaSuccessReset"></div>
                <?php elseif ($captcha_mode === 'recaptcha_v3'): ?>
                  <input type="hidden" id="g-recaptcha-response-reset" name="g-recaptcha-response">
                <?php elseif ($captcha_mode === 'turnstile'): ?>
                  <input type="hidden" id="cf-turnstile-response-reset" name="cf-turnstile-response">
                  <div class="cf-turnstile mb-3"
                       data-sitekey="<?php echo htmlspecialchars($turnstile_sitekey, ENT_QUOTES, 'UTF-8'); ?>"
                       data-callback="onTurnstileSuccessReset"
                       data-error-callback="onTurnstileError"
                       data-action="reset"
                       data-appearance="execute"
                       data-retry="auto"></div>
                <?php elseif ($captcha_mode === 'internal'): ?>
                  <?php include __DIR__ . '/captcha_bootstrap.php'; ?>
                <?php endif; ?>
              <?php endif; ?>
              <button type="submit" class="btn btn-primary btn-perky fw-bold w-100"><?php echo htmlspecialchars($lang['reset_password'] ?? 'Reset Password', ENT_QUOTES, 'UTF-8'); ?></button>
            </form>
          </div>
        </div>
        <?php if ($captcha_mode === 'turnstile'): ?>
          <script>
          (function() {
              if (typeof turnstile !== 'undefined') {
                  turnstile.ready(function() {
                      document.getElementById('reset-form').addEventListener('submit', function(e) {
                          var tokenInput = document.getElementById('cf-turnstile-response-reset');
                          if (!tokenInput.value) {
                              e.preventDefault();
                              turnstile.render('.cf-turnstile', {
                                  sitekey: '<?php echo htmlspecialchars($turnstile_sitekey, ENT_QUOTES, 'UTF-8'); ?>',
                                  callback: function(token) { tokenInput.value = token; document.getElementById('reset-form').submit(); },
                                  action: 'reset',
                                  size: 'compact',
                                  retry: 'auto',
                                  'retry-interval': 1000
                              });
                          }
                      });
                  });
              }
          })();
          </script>
        <?php endif; ?>
      <?php elseif (isset($_GET['action']) && $_GET['action'] === 'forgot'): ?>
        <!-- Forgot -->
        <div class="card mb-4">
          <div class="card-body">
            <h5 class="card-title"><?php echo htmlspecialchars($lang['forgot_password'] ?? 'Forgot Password', ENT_QUOTES, 'UTF-8'); ?></h5>
            <div id="forgot-feedback"></div>
            <form method="POST" action="<?php echo htmlspecialchars($baseurl . 'login.php?action=forgot', ENT_QUOTES, 'UTF-8'); ?>" id="forgot-form">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
              <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? $baseurl, ENT_QUOTES, 'UTF-8'); ?>">
              <div class="mb-3">
                <label for="email" class="form-label"><?php echo htmlspecialchars($lang['email'] ?? 'Email', ENT_QUOTES, 'UTF-8'); ?></label>
                <div class="input-group">
                  <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                  <input type="email" class="form-control" id="email" name="email" autocomplete="email" required>
                </div>
              </div>
              <?php if ($captcha_enabled): ?>
                <?php if ($captcha_mode === 'recaptcha' && $recaptcha_version === 'v2'): ?>
                  <input type="hidden" id="g-recaptcha-response-forgot" name="g-recaptcha-response">
                  <div class="g-recaptcha mb-3" data-sitekey="<?php echo htmlspecialchars($recaptcha_sitekey, ENT_QUOTES, 'UTF-8'); ?>" data-callback="onRecaptchaSuccessForgot"></div>
                <?php elseif ($captcha_mode === 'recaptcha_v3'): ?>
                  <input type="hidden" id="g-recaptcha-response-forgot" name="g-recaptcha-response">
                <?php elseif ($captcha_mode === 'turnstile'): ?>
                  <input type="hidden" id="cf-turnstile-response-forgot" name="cf-turnstile-response">
                  <div class="cf-turnstile mb-3"
                       data-sitekey="<?php echo htmlspecialchars($turnstile_sitekey, ENT_QUOTES, 'UTF-8'); ?>"
                       data-callback="onTurnstileSuccessForgot"
                       data-error-callback="onTurnstileError"
                       data-action="forgot"
                       data-appearance="execute"
                       data-retry="auto"></div>
                <?php elseif ($captcha_mode === 'internal'): ?>
                  <?php include __DIR__ . '/captcha_bootstrap.php'; ?>
                <?php endif; ?>
              <?php endif; ?>
              <button type="submit" class="btn btn-primary btn-perky fw-bold w-100"><?php echo htmlspecialchars($lang['send_reset_link'] ?? 'Send Reset Link', ENT_QUOTES, 'UTF-8'); ?></button>
            </form>
          </div>
        </div>
        <?php if ($captcha_mode === 'turnstile'): ?>
          <script>
          (function() {
              if (typeof turnstile !== 'undefined') {
                  turnstile.ready(function() {
                      document.getElementById('forgot-form').addEventListener('submit', function(e) {
                          var tokenInput = document.getElementById('cf-turnstile-response-forgot');
                          if (!tokenInput.value) {
                              e.preventDefault();
                              turnstile.render('.cf-turnstile', {
                                  sitekey: '<?php echo htmlspecialchars($turnstile_sitekey, ENT_QUOTES, 'UTF-8'); ?>',
                                  callback: function(token) { tokenInput.value = token; document.getElementById('forgot-form').submit(); },
                                  action: 'forgot',
                                  size: 'compact',
                                  retry: 'auto',
                                  'retry-interval': 1000
                              });
                          }
                      });
                  });
              }
          })();
          </script>
        <?php endif; ?>
      <?php elseif (isset($_GET['action']) && $_GET['action'] === 'resend'): ?>
        <!-- Resend -->
        <div class="card mb-4">
          <div class="card-body">
            <h5 class="card-title"><?php echo htmlspecialchars($lang['resend_verification'] ?? 'Resend Verification Email', ENT_QUOTES, 'UTF-8'); ?></h5>
            <div id="resend-feedback"></div>
            <form method="POST" action="<?php echo htmlspecialchars($baseurl . 'login.php?action=resend', ENT_QUOTES, 'UTF-8'); ?>" id="resend-form">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
              <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? $baseurl, ENT_QUOTES, 'UTF-8'); ?>">
              <div class="mb-3">
                <label for="email" class="form-label"><?php echo htmlspecialchars($lang['email'] ?? 'Email', ENT_QUOTES, 'UTF-8'); ?></label>
                <div class="input-group">
                  <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                  <input type="email" class="form-control" id="email" name="email" autocomplete="email" required>
                </div>
              </div>
              <?php if ($captcha_enabled): ?>
                <?php if ($captcha_mode === 'recaptcha' && $recaptcha_version === 'v2'): ?>
                  <input type="hidden" id="g-recaptcha-response-resend" name="g-recaptcha-response">
                  <div class="g-recaptcha mb-3" data-sitekey="<?php echo htmlspecialchars($recaptcha_sitekey, ENT_QUOTES, 'UTF-8'); ?>" data-callback="onRecaptchaSuccessResend"></div>
                <?php elseif ($captcha_mode === 'recaptcha_v3'): ?>
                  <input type="hidden" id="g-recaptcha-response-resend" name="g-recaptcha-response">
                <?php elseif ($captcha_mode === 'turnstile'): ?>
                  <input type="hidden" id="cf-turnstile-response-resend" name="cf-turnstile-response">
                  <div class="cf-turnstile mb-3"
                       data-sitekey="<?php echo htmlspecialchars($turnstile_sitekey, ENT_QUOTES, 'UTF-8'); ?>"
                       data-callback="onTurnstileSuccessResend"
                       data-error-callback="onTurnstileError"
                       data-action="resend"
                       data-appearance="execute"
                       data-retry="auto"></div>
                <?php elseif ($captcha_mode === 'internal'): ?>
                  <?php include __DIR__ . '/captcha_bootstrap.php'; ?>
                <?php endif; ?>
              <?php endif; ?>
              <button type="submit" class="btn btn-primary btn-perky fw-bold w-100"><?php echo htmlspecialchars($lang['resend_verification'] ?? 'Resend Verification Email', ENT_QUOTES, 'UTF-8'); ?></button>
            </form>
          </div>
        </div>
        <?php if ($captcha_mode === 'turnstile'): ?>
          <script>
          (function() {
              if (typeof turnstile !== 'undefined') {
                  turnstile.ready(function() {
                      document.getElementById('resend-form').addEventListener('submit', function(e) {
                          var tokenInput = document.getElementById('cf-turnstile-response-resend');
                          if (!tokenInput.value) {
                              e.preventDefault();
                              turnstile.render('.cf-turnstile', {
                                  sitekey: '<?php echo htmlspecialchars($turnstile_sitekey, ENT_QUOTES, 'UTF-8'); ?>',
                                  callback: function(token) { tokenInput.value = token; document.getElementById('resend-form').submit(); },
                                  action: 'resend',
                                  size: 'compact',
                                  retry: 'auto',
                                  'retry-interval': 1000
                              });
                          }
                      });
                  });
              }
          })();
          </script>
        <?php endif; ?>
      <?php elseif (isset($_GET['action']) && $_GET['action'] === 'signup'): ?>
        <!-- Signup -->
        <div class="card mb-4">
          <div class="card-body">
            <h5 class="card-title"><?php echo htmlspecialchars($lang['signup'] ?? 'Register', ENT_QUOTES, 'UTF-8'); ?></h5>
            <div id="signup-feedback"></div>
            <form method="POST" action="<?php echo htmlspecialchars($baseurl . 'login.php?action=signup', ENT_QUOTES, 'UTF-8'); ?>" id="signup-form">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
              <input type="hidden" name="signup" value="1">
              <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? $baseurl, ENT_QUOTES, 'UTF-8'); ?>">
              <div class="mb-3">
                <label for="signupUsername" class="form-label"><?php echo htmlspecialchars($lang['username'] ?? 'Username', ENT_QUOTES, 'UTF-8'); ?></label>
                <div class="input-group">
                  <span class="input-group-text"><i class="bi bi-person"></i></span>
                  <input type="text" class="form-control" id="signupUsername" name="username" value="<?php echo htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" autocomplete="username" required>
                </div>
              </div>
              <div class="mb-3">
                <label for="signupEmail" class="form-label"><?php echo htmlspecialchars($lang['email'] ?? 'Email', ENT_QUOTES, 'UTF-8'); ?></label>
                <div class="input-group">
                  <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                  <input type="email" class="form-control" id="signupEmail" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" autocomplete="email" required>
                </div>
              </div>
              <div class="mb-3">
                <label for="signupFullname" class="form-label"><?php echo htmlspecialchars($lang['full_name'] ?? 'Full Name', ENT_QUOTES, 'UTF-8'); ?></label>
                <div class="input-group">
                  <span class="input-group-text"><i class="bi bi-person"></i></span>
                  <input type="text" class="form-control" id="signupFullname" name="full" value="<?php echo htmlspecialchars($_POST['full'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" autocomplete="name" required>
                </div>
              </div>
              <div class="mb-3">
                <label for="signupPassword" class="form-label">
                  <?php echo htmlspecialchars($lang['password'] ?? 'Password', ENT_QUOTES, 'UTF-8'); ?>
                </label>
                <div class="input-group">
                  <span class="input-group-text"><i class="bi bi-lock"></i></span>
                  <input type="password" class="form-control" id="signupPassword" name="password" autocomplete="new-password" required>
                </div>
                <div class="form-text">
                  Need help?
                  <a href="<?php echo htmlspecialchars($baseurl . 'generatepasswd.php', ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">Generate a password</a>.
                </div>
              </div>
              <?php if ($captcha_enabled): ?>
                <?php if ($captcha_mode === 'recaptcha' && $recaptcha_version === 'v2'): ?>
                  <input type="hidden" id="g-recaptcha-response-signup" name="g-recaptcha-response">
                  <div class="g-recaptcha mb-3" data-sitekey="<?php echo htmlspecialchars($recaptcha_sitekey, ENT_QUOTES, 'UTF-8'); ?>" data-callback="onRecaptchaSuccessSignup"></div>
                <?php elseif ($captcha_mode === 'recaptcha_v3'): ?>
                  <input type="hidden" id="g-recaptcha-response-signup" name="g-recaptcha-response">
                <?php elseif ($captcha_mode === 'turnstile'): ?>
                  <input type="hidden" id="cf-turnstile-response-signup" name="cf-turnstile-response">
                  <div class="cf-turnstile mb-3"
                       data-sitekey="<?php echo htmlspecialchars($turnstile_sitekey, ENT_QUOTES, 'UTF-8'); ?>"
                       data-callback="onTurnstileSuccessSignup"
                       data-error-callback="onTurnstileError"
                       data-action="signup"
                       data-appearance="execute"
                       data-retry="auto"></div>
                <?php elseif ($captcha_mode === 'internal'): ?>
                  <?php include __DIR__ . '/captcha_bootstrap.php'; ?>
                <?php endif; ?>
              <?php endif; ?>
              <button type="submit" class="btn btn-primary btn-perky fw-bold w-100"><?php echo htmlspecialchars($lang['signup'] ?? 'Register', ENT_QUOTES, 'UTF-8'); ?></button>
            </form>
          </div>
        </div>
        <?php if ($captcha_mode === 'turnstile'): ?>
          <script>
          (function() {
              if (typeof turnstile !== 'undefined') {
                  turnstile.ready(function() {
                      document.getElementById('signup-form').addEventListener('submit', function(e) {
                          var tokenInput = document.getElementById('cf-turnstile-response-signup');
                          if (!tokenInput.value) {
                              e.preventDefault();
                              turnstile.render('.cf-turnstile', {
                                  sitekey: '<?php echo htmlspecialchars($turnstile_sitekey, ENT_QUOTES, 'UTF-8'); ?>',
                                  callback: function(token) { tokenInput.value = token; document.getElementById('signup-form').submit(); },
                                  action: 'signup',
                                  size: 'compact',
                                  retry: 'auto',
                                  'retry-interval': 1000
                              });
                          }
                      });
                  });
              }
          })();
          </script>
        <?php endif; ?>
      <?php else: ?>
        <!-- Login -->
        <div class="card mb-4">
          <div class="card-body">
            <h5 class="card-title"><?php echo htmlspecialchars($lang['login'] ?? 'Login', ENT_QUOTES, 'UTF-8'); ?></h5>
            <div id="direct-signin-feedback"></div>
            <form method="POST" action="<?php echo htmlspecialchars($baseurl . 'login.php', ENT_QUOTES, 'UTF-8'); ?>" id="direct-signin-form">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
              <input type="hidden" name="ajax" value="1">
              <input type="hidden" name="signin" value="1">
              <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? $baseurl, ENT_QUOTES, 'UTF-8'); ?>">
              <div class="mb-3">
                <label for="directSigninUsername" class="form-label"><?php echo htmlspecialchars($lang['username'] ?? 'Username', ENT_QUOTES, 'UTF-8'); ?></label>
                <div class="input-group">
                  <span class="input-group-text"><i class="bi bi-person"></i></span>
                  <input type="text" name="username" class="form-control" id="directSigninUsername" autocomplete="username" required>
                </div>
              </div>
              <div class="mb-3">
                <label for="directSigninPassword" class="form-label"><?php echo htmlspecialchars($lang['password'] ?? 'Password', ENT_QUOTES, 'UTF-8'); ?></label>
                <div class="input-group">
                  <span class="input-group-text"><i class="bi bi-key"></i></span>
                  <input type="password" name="password" class="form-control" id="directSigninPassword" autocomplete="current-password" required>
                </div>
              </div>
              <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="directSigninRememberme" name="rememberme" checked>
                <label class="form-check-label" for="directSigninRememberme"><?php echo htmlspecialchars($lang['rememberme'] ?? 'Keep me signed in.', ENT_QUOTES, 'UTF-8'); ?></label>
              </div>
              <?php if ($captcha_enabled): ?>
                <?php if ($captcha_mode === 'recaptcha' && $recaptcha_version === 'v2'): ?>
                  <input type="hidden" id="g-recaptcha-response-direct-signin" name="g-recaptcha-response">
                  <div class="g-recaptcha mb-3" data-sitekey="<?php echo htmlspecialchars($recaptcha_sitekey, ENT_QUOTES, 'UTF-8'); ?>" data-callback="onRecaptchaSuccessDirectSignin"></div>
                <?php elseif ($captcha_mode === 'recaptcha_v3'): ?>
                  <input type="hidden" id="g-recaptcha-response-direct-signin" name="g-recaptcha-response">
                <?php elseif ($captcha_mode === 'turnstile'): ?>
                  <input type="hidden" id="cf-turnstile-response-direct-signin" name="cf-turnstile-response">
                  <div class="cf-turnstile mb-3"
                       data-sitekey="<?php echo htmlspecialchars($turnstile_sitekey, ENT_QUOTES, 'UTF-8'); ?>"
                       data-callback="onTurnstileSuccessDirectSignin"
                       data-error-callback="onTurnstileError"
                       data-action="login"
                       data-appearance="execute"
                       data-retry="auto"></div>
                <?php elseif ($captcha_mode === 'internal'): ?>
                  <?php include __DIR__ . '/captcha_bootstrap.php'; ?>
                <?php endif; ?>
              <?php endif; ?>
              <button type="submit" class="btn btn-primary btn-perky fw-bold w-100"><?php echo htmlspecialchars($lang['login'] ?? 'Login', ENT_QUOTES, 'UTF-8'); ?></button>
              <?php if (($enablegoog ?? 'no') === 'yes' || ($enablefb ?? 'no') === 'yes'): ?>
                <div class="d-grid gap-2 mt-3">
                  <?php if (($enablegoog ?? 'no') === 'yes'): ?>
                    <a class="btn btn-outline-light btn-oauth" href="<?php echo htmlspecialchars($baseurl . 'login.php?login=google', ENT_QUOTES, 'UTF-8'); ?>">
                      <i class="bi bi-google oauth-icon"></i> <?php echo htmlspecialchars($lang['login_with_google'] ?? 'Login with Google', ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                  <?php endif; ?>
                  <?php if (($enablefb ?? 'no') === 'yes'): ?>
                    <a class="btn btn-outline-light btn-oauth" href="<?php echo htmlspecialchars($baseurl . 'login.php?login=facebook', ENT_QUOTES, 'UTF-8'); ?>">
                      <i class="bi bi-facebook oauth-icon"></i> <?php echo htmlspecialchars($lang['login_with_facebook'] ?? 'Login with Facebook', ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </form>
            <div class="text-center mt-3">
              <a href="<?php echo htmlspecialchars($baseurl . 'login.php?action=forgot', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($lang['forgot_password'] ?? 'Forgot Password', ENT_QUOTES, 'UTF-8'); ?></a><br>
              <a href="<?php echo htmlspecialchars($baseurl . 'login.php?action=signup', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($lang['signup'] ?? 'Register', ENT_QUOTES, 'UTF-8'); ?></a><br>
              <a href="<?php echo htmlspecialchars($baseurl . 'login.php?action=resend', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($lang['resend_verification'] ?? 'Resend Verification Email', ENT_QUOTES, 'UTF-8'); ?></a>
            </div>
          </div>
        </div>
        <?php if ($captcha_mode === 'turnstile'): ?>
          <script>
          (function() {
              if (typeof turnstile !== 'undefined') {
                  turnstile.ready(function() {
                      document.getElementById('direct-signin-form').addEventListener('submit', function(e) {
                          var tokenInput = document.getElementById('cf-turnstile-response-direct-signin');
                          if (!tokenInput.value) {
                              e.preventDefault();
                              turnstile.render('.cf-turnstile', {
                                  sitekey: '<?php echo htmlspecialchars($turnstile_sitekey, ENT_QUOTES, 'UTF-8'); ?>',
                                  callback: function(token) { tokenInput.value = token; document.getElementById('direct-signin-form').submit(); },
                                  action: 'login',
                                  size: 'compact',
                                  retry: 'auto',
                                  'retry-interval': 1000
                              });
                          }
                      });
                  });
              }
          })();
          </script>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
function onTurnstileError(code) {
    console.warn('Turnstile error code: ' + code);
    // Handle error (e.g., show message to user)
    return true; // Mark as handled to prevent default console warning
}
</script>