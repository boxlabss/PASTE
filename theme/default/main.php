<?php
/*
 * Paste $v3.1 2025/08/16 https://github.com/boxlabss/PASTE
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
$cap_e = $_SESSION['cap_e'] ?? 'off'; // Define $cap_e to avoid PHP errors
$captcha_mode = $_SESSION['captcha_mode'] ?? 'none'; // 'recaptcha' (v2 checkbox), 'recaptcha_v3', 'turnstile', 'internal', 'none'
$main_sitekey = $_SESSION['captcha'] ?? ''; // sitekey for this main form (set in index during GET)
?>

<div class="container-xxl my-4">
  <div class="row">
    <?php if (isset($privatesite) && $privatesite === "on"): ?>
      <div class="col-lg-12">
        <?php if (!isset($_SESSION['username'])): ?>
          <div class="card">
            <div class="card-body">
              <div class="alert alert-warning">
                <?php echo htmlspecialchars($lang['login_required'] ?? 'You must be logged in to create a paste.', ENT_QUOTES, 'UTF-8'); ?>
                <a href="<?php echo htmlspecialchars($baseurl . 'login.php', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary mt-2">Login</a>
              </div>
            </div>
          </div>
        <?php else: ?>
          <!-- Paste form (private site, logged-in user) -->
          <div class="card">
            <div class="card-header">
              <h1><?php echo htmlspecialchars($lang['newpaste'] ?? 'New Paste'); ?></h1>
			<?php
			// Quick diff
			$diffQuickUrl = rtrim($baseurl ?? '/', '/') . '/diff.php?a=oldpaste&b=newpaste';
			?>
              <a href="<?php echo htmlspecialchars($diffQuickUrl, ENT_QUOTES, 'UTF-8'); ?>"
                 title="View differences">
                <i class="bi bi-arrow-left-right"></i> .diff
              </a>
            </div>
            <div class="card-body">
              <?php if (!empty($flash_error)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8'); ?></div>
              <?php elseif (isset($_GET['error'])): ?>
                <div class="alert alert-warning"><?php echo htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8'); ?></div>
              <?php elseif (isset($_GET['success'])): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?></div>
              <?php elseif (isset($error)): ?>
                <div class="alert alert-warning"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
              <?php endif; ?>
              <form class="form-horizontal" name="mainForm" id="mainForm" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" method="POST">
                <div class="row mb-3 g-3">
                  <div class="col-sm-4">
                    <div class="input-group">
                      <span class="input-group-text"><i class="bi bi-fonts"></i></span>
                      <input type="text" class="form-control" name="title" placeholder="<?php echo htmlspecialchars($lang['pastetitle'] ?? 'Paste Title'); ?>">
                    </div>
                  </div>
                  <div class="col-sm-4">
                    <select class="form-select" name="format" id="format">
					<?php
					$geshiformats = $geshiformats ?? [];
					$popular_formats = $popular_formats ?? [];
					foreach ($geshiformats as $code => $name) {
					if (in_array($code, $popular_formats)) {
						$sel = ($p_code ?? 'autodetect') == $code ? 'selected' : '';
						echo '<option ' . $sel . ' value="' . htmlspecialchars($code) . '">' . htmlspecialchars($name) . '</option>';
						}
					}
					echo '<option value="text">-------------------------------------</option>';
					foreach ($geshiformats as $code => $name) {
						if (!in_array($code, $popular_formats)) {
						$sel = ($p_code ?? 'autodetect') == $code ? 'selected' : '';
						echo '<option ' . $sel . ' value="' . htmlspecialchars($code) . '">' . htmlspecialchars($name) . '</option>';
						}
					}
					?>
                    </select>
                  </div>
                  <div class="col-sm-4 d-flex justify-content-end align-items-center gap-2">
                    <a class="btn btn-secondary highlight-line" href="#" title="Highlight selected lines">
                      <i class="bi bi-text-indent-left"></i> Highlight
                    </a>
                    <!-- Load file -->
                    <button type="button" class="btn btn-outline-secondary" id="load_file_btn" title="Load file into editor (no upload)">
                      <i class="bi bi-upload"></i> Load
                    </button>
                    <!-- Clear -->
                    <button type="button" class="btn btn-outline-secondary" id="clear_file_btn" title="Clear editor">
                      <i class="bi bi-x-circle"></i> Clear
                    </button>
                    <!-- Accepted formats -->
                    <input type="file" id="code_file" class="visually-hidden"
                           accept=".txt,.md,.php,.js,.ts,.jsx,.tsx,.py,.rb,.java,.c,.cpp,.h,.cs,.go,.rs,.kt,.swift,.sh,.ps1,.sql,.html,.htm,.css,.scss,.json,.xml,.yml,.yaml,.ini,.conf,text/*">
                  </div>
                </div>
                <!-- For screen readers -->
                <div id="file-announce" class="visually-hidden" aria-live="polite"></div>
                <div class="mb-3">
                  <textarea class="form-control" rows="15" id="edit-code" name="paste_data" placeholder="hello world" data-max-bytes="<?php echo 1024*1024*($pastelimit ?? 10); ?>"><?php echo htmlspecialchars($paste_data ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="row mb-3">
                  <label class="col-sm-2 col-form-label"><?php echo htmlspecialchars($lang['expiration'] ?? 'Expiration'); ?></label>
                  <div class="col-sm-10">
                    <select class="form-select" name="paste_expire_date">
                      <option value="N" <?php echo ($paste_expire_date ?? 'N') == "N" ? 'selected' : ''; ?>>Never</option>
                      <option value="self" <?php echo ($paste_expire_date ?? 'N') == "self" ? 'selected' : ''; ?>>View Once</option>
                      <option value="10M" <?php echo ($paste_expire_date ?? 'N') == "10M" ? 'selected' : ''; ?>>10 Minutes</option>
                      <option value="1H" <?php echo ($paste_expire_date ?? 'N') == "1H" ? 'selected' : ''; ?>>1 Hour</option>
                      <option value="1D" <?php echo ($paste_expire_date ?? 'N') == "1D" ? 'selected' : ''; ?>>1 Day</option>
                      <option value="1W" <?php echo ($paste_expire_date ?? 'N') == "1W" ? 'selected' : ''; ?>>1 Week</option>
                      <option value="2W" <?php echo ($paste_expire_date ?? 'N') == "2W" ? 'selected' : ''; ?>>2 Weeks</option>
                      <option value="1M" <?php echo ($paste_expire_date ?? 'N') == "1M" ? 'selected' : ''; ?>>1 Month</option>
                    </select>
                  </div>
                </div>
                <div class="row mb-3">
                  <label class="col-sm-2 col-form-label"><?php echo htmlspecialchars($lang['visibility'] ?? 'Visibility'); ?></label>
                  <div class="col-sm-10">
                    <select class="form-select" name="visibility">
                      <option value="0" <?php echo ($p_visible ?? '0') == "0" ? 'selected' : ''; ?>><?php echo htmlspecialchars($lang['public'] ?? 'Public', ENT_QUOTES, 'UTF-8'); ?></option>
                      <option value="1" <?php echo ($p_visible ?? '0') == "1" ? 'selected' : ''; ?>><?php echo htmlspecialchars($lang['unlisted'] ?? 'Unlisted', ENT_QUOTES, 'UTF-8'); ?></option>
                      <option value="2" <?php echo ($p_visible ?? '0') == "2" ? 'selected' : ''; ?>><?php echo htmlspecialchars($lang['private'] ?? 'Private', ENT_QUOTES, 'UTF-8'); ?></option>
                    </select>
                  </div>
                </div>
                <div class="mb-3">
                  <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="text" class="form-control" name="pass" id="pass" placeholder="<?php echo htmlspecialchars($lang['pwopt'] ?? 'Optional Password'); ?>">
                  </div>
                </div>
                <div class="row mb-3">
                  <p class="text-muted"><small><?php echo htmlspecialchars($lang['encrypt'] ?? 'Encryption', ENT_QUOTES, 'UTF-8'); ?></small></p>
                </div>
				<div class="mb-3 form-check">
				  <input type="checkbox" class="form-check-input" id="client_encrypt" name="client_encrypt">
				  <label class="form-check-label" for="client_encrypt">Client side encryption?</label>
				</div>
				<input type="hidden" name="is_client_encrypted" id="is_client_encrypted" value="0">
				<!-- Encryption Passphrase Modal -->
				<div class="modal fade" id="encryptPassModal" tabindex="-1" aria-labelledby="encryptPassLabel" aria-hidden="true">
				  <div class="modal-dialog">
					<div class="modal-content">
					  <div class="modal-header">
						<h5 class="modal-title" id="encryptPassLabel">Set Encryption Passphrase</h5>
						<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
					  </div>
					  <div class="modal-body">
						<p>Enter a strong passphrase (and remember it):</p>
						<input type="password" class="form-control" name="encrypt_pass" id="encryptPassInput" autocomplete="off">
						<div class="mt-2">
						  <div class="progress" style="--height: 5px;">
							<div id="passStrengthBar" class="progress-bar" role="progressbar" style="width: 0%;"></div>
						  </div>
						  <small id="passStrengthText" class="text-muted">Strength: Weak</small>
						</div>
						<small class="text-muted d-block mt-1">Min 12 characters; mix upper/lower, numbers, symbols.</small>
					  </div>
					  <div class="modal-footer">
						<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
						<button type="button" class="btn btn-primary" id="encryptConfirm" disabled>Encrypt</button>
					  </div>
					</div>
				  </div>
				</div>

                <?php
                // Debug CAPTCHA condition
                $captcha_condition = $cap_e == "on" && !isset($_SESSION['username']) && (!isset($disableguest) || $disableguest !== "on");
                error_log("main.php: CAPTCHA condition result: " . ($captcha_condition ? 'true' : 'false'));
                if ($captcha_condition): ?>
                  <?php if ($captcha_mode === "recaptcha"): ?>
                    <!-- reCAPTCHA v2 checkbox -->
                    <div class="g-recaptcha mb-3"
                         data-sitekey="<?php echo htmlspecialchars($main_sitekey, ENT_QUOTES, 'UTF-8'); ?>"
                         data-callback="onRecaptchaSuccess"></div>
                    <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response">
                  <?php elseif ($captcha_mode === "recaptcha_v3"): ?>
                    <!-- v3: hidden field only; token populated by footer -->
                    <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response">
                  <?php elseif ($captcha_mode === "turnstile"): ?>
                    <!-- Cloudflare Turnstile -->
                    <div class="cf-turnstile mb-3"
                         data-sitekey="<?php echo htmlspecialchars($main_sitekey, ENT_QUOTES, 'UTF-8'); ?>"
                         data-callback="onTurnstileSuccess"
                         data-action="create_paste"
                         data-appearance="execute"
                         data-retry-interval="1000"></div>
                    <input type="hidden" name="cf-turnstile-response" id="cf-turnstile-response">
                  <?php else: ?>
                    <!-- Internal CAPTCHA -->
                    <?php include __DIR__ . '/captcha_bootstrap.php'; ?>
                  <?php endif; ?>
                <?php endif; ?>
                <div class="row mb-3">
                  <div class="d-grid gap-2">
                    <input class="btn btn-primary paste-button" type="submit" id="submit" data-recaptcha-action="create_paste" value="<?php echo htmlspecialchars($lang['createpaste'] ?? 'Paste'); ?>">
                  </div>
                </div>
              </form>
            </div>
            <?php if ($captcha_mode === "turnstile"): ?>
              <!-- Explicit Turnstile rendering for mainForm as fallback -->
              <script>
              (function() {
                  if (typeof turnstile !== 'undefined') {
                      turnstile.ready(function() {
                          document.getElementById('mainForm').addEventListener('submit', function(e) {
                              var tokenInput = document.getElementById('cf-turnstile-response');
                              if (!tokenInput.value) {
                                  e.preventDefault();
                                  turnstile.render('.cf-turnstile', {
                                      sitekey: '<?php echo htmlspecialchars($main_sitekey, ENT_QUOTES, 'UTF-8'); ?>',
                                      callback: function(token) { tokenInput.value = token; document.getElementById('mainForm').submit(); },
                                      action: 'create_paste',
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
          </div>
        <?php endif; ?>
      </div>
      <div class="col-lg-2 mt-4 mt-lg-0">
        <?php
        $__sidebar = __DIR__ . '/sidebar.php';
        if (is_file($__sidebar)) { include $__sidebar; }
        ?>
      </div>
    <?php else: ?>
      <!-- Non-private site: Main content + sidebar -->
      <div class="col-lg-10">
        <?php if (!isset($_SESSION['username']) && (!isset($privatesite) || $privatesite != "on")): ?>
          <div class="card guest-welcome text-center">
            <div class="btn-group" role="group" aria-label="Download Paste">
              <a href="https://sourceforge.net/projects/phpaste/files/latest/download" class="btn btn-success">Get Paste <?=$currentversion?></a>
              <a href="https://github.com/boxlabss/PASTE" class="btn btn-dark">
				<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-github" viewBox="0 0 16 16">
					<path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27s1.36.09 2 .27c1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8">
					</path>
				</svg> 
				GitHub</a>
            </div>
          </div>
        <?php endif; ?>
        <?php if (!isset($_SESSION['username']) && ($disableguest === "on")): ?>
          <div class="card">
            <div class="card-body">
              <div class="alert alert-warning">
                <?php echo htmlspecialchars($lang['login_required'] ?? 'You must be logged in to create a paste.', ENT_QUOTES, 'UTF-8'); ?>
                <a href="<?php echo htmlspecialchars($baseurl . 'login.php', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary mt-2">Login</a>
              </div>
            </div>
          </div>
        <?php else: ?>
          <!-- Paste form (public site) -->
          <div class="card">
            <div class="card-header">
              <h1><?php echo htmlspecialchars($lang['newpaste'] ?? 'New Paste'); ?></h1>
			<?php
			// Quick diff
			$diffQuickUrl = rtrim($baseurl ?? '/', '/') . '/diff.php?a=oldpaste&b=newpaste';
			?>
              <a href="<?php echo htmlspecialchars($diffQuickUrl, ENT_QUOTES, 'UTF-8'); ?>"
                 title="View differences">
                <i class="bi bi-arrow-left-right"></i> .diff
              </a>
            </div>
            <div class="card-body">
              <?php if (!empty($flash_error)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8'); ?></div>
              <?php elseif (isset($_GET['error'])): ?>
                <div class="alert alert-warning"><?php echo htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8'); ?></div>
              <?php elseif (isset($_GET['success'])): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?></div>
              <?php elseif (isset($error)): ?>
                <div class="alert alert-warning"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
              <?php endif; ?>
              <form class="form-horizontal" name="mainForm" id="mainForm" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" method="POST">
                <div class="row mb-3 g-3">
                  <div class="col-sm-4">
                    <div class="input-group">
                      <span class="input-group-text"><i class="bi bi-fonts"></i></span>
                      <input type="text" class="form-control" name="title" placeholder="<?php echo htmlspecialchars($lang['pastetitle'] ?? 'Paste Title'); ?>">
                    </div>
                  </div>
                  <div class="col-sm-4">
                    <select class="form-select" name="format" id="format">
					<?php
					$geshiformats = $geshiformats ?? [];
					$popular_formats = $popular_formats ?? [];
					foreach ($geshiformats as $code => $name) {
					if (in_array($code, $popular_formats)) {
						$sel = ($p_code ?? 'autodetect') == $code ? 'selected' : '';
						echo '<option ' . $sel . ' value="' . htmlspecialchars($code) . '">' . htmlspecialchars($name) . '</option>';
						}
					}
					echo '<option value="text">-------------------------------------</option>';
						foreach ($geshiformats as $code => $name) {
						if (!in_array($code, $popular_formats)) {
						$sel = ($p_code ?? 'autodetect') == $code ? 'selected' : '';
						echo '<option ' . $sel . ' value="' . htmlspecialchars($code) . '">' . htmlspecialchars($name) . '</option>';
						}
					}
					?>
                    </select>
                  </div>
                  <div class="col-sm-4 d-flex justify-content-end align-items-center gap-2">
                    <a class="btn btn-secondary highlight-line" href="#" title="Highlight selected lines">
                      <i class="bi bi-text-indent-left"></i> Highlight
                    </a>
                    <!-- Load file button -->
                    <button type="button" class="btn btn-outline-secondary" id="load_file_btn" title="Load file into editor">
                      <i class="bi bi-upload"></i> Load
                    </button>
                    <!-- Clear -->
                    <button type="button" class="btn btn-outline-secondary" id="clear_file_btn" title="Clear editor">
                      <i class="bi bi-x-circle"></i> Clear
                    </button>
                    <!-- Accepted formats -->
                    <input type="file" id="code_file" class="visually-hidden"
                           accept=".txt,.md,.php,.js,.ts,.jsx,.tsx,.py,.rb,.java,.c,.cpp,.h,.cs,.go,.rs,.kt,.swift,.sh,.ps1,.sql,.html,.htm,.css,.scss,.json,.xml,.yml,.yaml,.ini,.conf,text/*">
                  </div>
                </div>
                <!-- For screen readers -->
                <div id="file-announce" class="visually-hidden" aria-live="polite"></div>
                <div class="mb-3">
                  <textarea class="form-control" rows="15" id="edit-code" name="paste_data" placeholder="hello world" data-max-bytes="<?php echo 1024*1024*($pastelimit ?? 10); ?>"><?php echo htmlspecialchars($paste_data ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="row mb-3">
                  <label class="col-sm-2 col-form-label"><?php echo htmlspecialchars($lang['expiration'] ?? 'Expiration'); ?></label>
                  <div class="col-sm-10">
                    <select class="form-select" name="paste_expire_date">
                      <option value="N" <?php echo ($paste_expire_date ?? 'N') == "N" ? 'selected' : ''; ?>>Never</option>
                      <option value="self" <?php echo ($paste_expire_date ?? 'N') == "self" ? 'selected' : ''; ?>>View Once</option>
                      <option value="10M" <?php echo ($paste_expire_date ?? 'N') == "10M" ? 'selected' : ''; ?>>10 Minutes</option>
                      <option value="1H" <?php echo ($paste_expire_date ?? 'N') == "1H" ? 'selected' : ''; ?>>1 Hour</option>
                      <option value="1D" <?php echo ($paste_expire_date ?? 'N') == "1D" ? 'selected' : ''; ?>>1 Day</option>
                      <option value="1W" <?php echo ($paste_expire_date ?? 'N') == "1W" ? 'selected' : ''; ?>>1 Week</option>
                      <option value="2W" <?php echo ($paste_expire_date ?? 'N') == "2W" ? 'selected' : ''; ?>>2 Weeks</option>
                      <option value="1M" <?php echo ($paste_expire_date ?? 'N') == "1M" ? 'selected' : ''; ?>>1 Month</option>
                    </select>
                  </div>
                </div>
                <div class="row mb-3">
                  <label class="col-sm-2 col-form-label"><?php echo htmlspecialchars($lang['visibility'] ?? 'Visibility'); ?></label>
                  <div class="col-sm-10">
                    <select class="form-select" name="visibility">
                      <option value="0" <?php echo ($p_visible ?? '0') == "0" ? 'selected' : ''; ?>><?php echo htmlspecialchars($lang['public'] ?? 'Public', ENT_QUOTES, 'UTF-8'); ?></option>
                      <option value="1" <?php echo ($p_visible ?? '0') == "1" ? 'selected' : ''; ?>><?php echo htmlspecialchars($lang['unlisted'] ?? 'Unlisted', ENT_QUOTES, 'UTF-8'); ?></option>
                      <option value="2" <?php echo ($p_visible ?? '0') == "2" ? 'selected' : ''; ?>><?php echo htmlspecialchars($lang['private'] ?? 'Private', ENT_QUOTES, 'UTF-8'); ?></option>
                    </select>
                  </div>
                </div>
                <div class="mb-3">
                  <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="text" class="form-control" name="pass" id="pass" placeholder="<?php echo htmlspecialchars($lang['pwopt'] ?? 'Optional Password'); ?>">
                  </div>
                </div>
				<div class="mb-3 form-check">
				  <input type="checkbox" class="form-check-input" id="client_encrypt" name="client_encrypt">
				  <label class="form-check-label" for="client_encrypt">Enable client side encryption? AES-256-GCM</label>
				</div>
				<input type="hidden" name="is_client_encrypted" id="is_client_encrypted" value="0">
				<!-- Encryption Passphrase Modal -->
				<div class="modal fade" id="encryptPassModal" tabindex="-1" aria-labelledby="encryptPassLabel" aria-hidden="true">
				  <div class="modal-dialog">
					<div class="modal-content">
					  <div class="modal-header">
						<h5 class="modal-title" id="encryptPassLabel">Set Encryption Passphrase</h5>
						<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
					  </div>
					  <div class="modal-body">
						<p>Enter a strong passphrase (and remember it):</p>
						<div class="input-group mb-3">
						  <input type="password" class="form-control" id="encryptPassInput" autocomplete="new-password" placeholder="Enter passphrase">
						  <button type="button" class="btn btn-outline-secondary" id="toggleEncryptPass" title="Show/Hide Password">
							<i class="bi bi-eye" id="encryptPassIcon"></i>
						  </button>
						</div>
						<div class="mt-2">
						  <div class="progress" style="--height: 5px;">
							<div id="passStrengthBar" class="progress-bar" role="progressbar" style="width: 0%;"></div>
						  </div>
						  <small id="passStrengthText" class="text-muted">Strength: Weak</small>
						</div>
						<small class="text-muted d-block mt-1">Min 12 characters; mix upper/lower, numbers, symbols.</small>
					  </div>
					  <div class="modal-footer">
						<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
						<button type="button" class="btn btn-primary" id="encryptConfirm" disabled>Encrypt</button>
					  </div>
					</div>
				  </div>
				</div>
                <div class="row mb-3">
                  <p class="text-muted"><small><?php echo htmlspecialchars($lang['encrypt'] ?? 'Encryption', ENT_QUOTES, 'UTF-8'); ?></small></p>
                </div>
                <?php
                // Debug CAPTCHA condition
                $captcha_condition = $cap_e == "on" && !isset($_SESSION['username']) && (!isset($disableguest) || $disableguest !== "on");
                error_log("main.php: CAPTCHA condition result: " . ($captcha_condition ? 'true' : 'false'));
                if ($captcha_condition): ?>
                  <?php if ($captcha_mode === "recaptcha"): ?>
                    <!-- reCAPTCHA v2 checkbox -->
                    <div class="g-recaptcha mb-3"
                         data-sitekey="<?php echo htmlspecialchars($main_sitekey, ENT_QUOTES, 'UTF-8'); ?>"
                         data-callback="onRecaptchaSuccess"></div>
                    <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response">
                  <?php elseif ($captcha_mode === "recaptcha_v3"): ?>
                    <!-- v3: hidden field only; token populated by footer -->
                    <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response">
                  <?php elseif ($captcha_mode === "turnstile"): ?>
                    <!-- Cloudflare Turnstile -->
                    <div class="cf-turnstile mb-3"
                         data-sitekey="<?php echo htmlspecialchars($main_sitekey, ENT_QUOTES, 'UTF-8'); ?>"
                         data-callback="onTurnstileSuccess"
                         data-action="create_paste"
                         data-appearance="execute"
                         data-retry-interval="1000"></div>
                    <input type="hidden" name="cf-turnstile-response" id="cf-turnstile-response">
                  <?php else: ?>
                    <!-- Internal CAPTCHA -->
                    <?php include __DIR__ . '/captcha_bootstrap.php'; ?>
                  <?php endif; ?>
                <?php endif; ?>
                <div class="row mb-3">
                  <div class="d-grid gap-2">
                    <input class="btn btn-primary paste-button" type="submit" id="submit" data-recaptcha-action="create_paste" value="<?php echo htmlspecialchars($lang['createpaste'] ?? 'Paste'); ?>">
                  </div>
                </div>
              </form>
            </div>
            <?php if ($captcha_mode === "turnstile"): ?>
              <!-- Explicit Turnstile rendering for mainForm as fallback -->
              <script>
              (function() {
                  if (typeof turnstile !== 'undefined') {
                      turnstile.ready(function() {
                          document.getElementById('mainForm').addEventListener('submit', function(e) {
                              var tokenInput = document.getElementById('cf-turnstile-response');
                              if (!tokenInput.value) {
                                  e.preventDefault();
                                  turnstile.render('.cf-turnstile', {
                                      sitekey: '<?php echo htmlspecialchars($main_sitekey, ENT_QUOTES, 'UTF-8'); ?>',
                                      callback: function(token) { tokenInput.value = token; document.getElementById('mainForm').submit(); },
                                      action: 'create_paste',
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
          </div>
        <?php endif; ?>
      </div>
      <div class="col-lg-2 mt-4 mt-lg-0">
        <?php
        $__sidebar = __DIR__ . '/sidebar.php';
        if (is_file($__sidebar)) { include $__sidebar; }
        ?>
      </div>
    <?php endif; ?>
  </div>
</div>