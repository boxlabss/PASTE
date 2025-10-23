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
$cap_e = $_SESSION['cap_e'] ?? 'off';
$mode = $_SESSION['mode'] ?? 'normal';
$recaptcha_version = $_SESSION['recaptcha_version'] ?? 'v2';
$recaptcha_sitekey = $_SESSION['recaptcha_sitekey'] ?? '';
$turnstile_sitekey = $_SESSION['turnstile_sitekey'] ?? '';
$captcha_enabled = ($cap_e === 'on');
$captcha_mode = $_SESSION['captcha_mode'] ?? 'none';
?>

<!DOCTYPE html>
<html lang="<?= htmlspecialchars(basename($default_lang ?? 'en.php', '.php'), ENT_QUOTES, 'UTF-8') ?>" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= isset($p_title) ? htmlspecialchars($p_title, ENT_QUOTES, 'UTF-8') . ' - ' : '' ?><?= htmlspecialchars($title ?? '', ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="description" content="<?= htmlspecialchars($des ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <meta name="keywords" content="<?= htmlspecialchars($keyword ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <link rel="shortcut icon" href="<?= htmlspecialchars($baseurl . 'theme/' . ($default_theme ?? 'default') . '/img/favicon.ico', ENT_QUOTES, 'UTF-8') ?>">
    <link href="//cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="//cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
	<?php if (($highlighter ?? 'geshi') === 'highlight'): ?>
	  <?php
	  // Highlight.php theme CSS (only when using highlight.php)
	  $stylesRel = 'includes/Highlight/styles';
	  $styleFile = $hl_style ?? 'hybrid.css';
	  $href = rtrim($baseurl ?? '/', '/') . '/' . $stylesRel . '/' . $styleFile;
	  ?>
	  <link rel="stylesheet" id="hljs-theme-link" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>">
	<?php endif; ?>
	<?php if (isset($ges_style)): ?><?= $ges_style ?><?php endif; ?>
    <link href="<?= htmlspecialchars($baseurl . 'theme/' . ($default_theme ?? 'default') . '/css/paste.css', ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet" type="text/css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:ital,wght@0,222;1,222&display=swap');
    </style>
	<?php if ($captcha_enabled): ?>
	  <?php if ($mode === 'reCAPTCHA' && strtolower($recaptcha_version) === 'v2'): ?>
		<!-- reCAPTCHA v2 -->
		<script src="https://www.google.com/recaptcha/api.js" async defer></script>
	  <?php elseif ($mode === 'reCAPTCHA' && strtolower($recaptcha_version) === 'v3'): ?>
		<!-- reCAPTCHA v3 -->
		<script src="https://www.google.com/recaptcha/api.js?render=<?php echo urlencode($recaptcha_sitekey); ?>" async defer></script>
	  <?php elseif ($mode === 'turnstile'): ?>
		<!-- Cloudflare Turnstile -->
		<script src="https://challenges.cloudflare.com/turnstile/v0/api.js"></script>
	  <?php endif; ?>
	<?php endif; ?>
</head>
<body>
    <!-- Header -->
    <nav class="navbar navbar-expand-lg bg-dark">
        <div class="container-xxl d-flex align-items-center">
            <a class="navbar-brand" href="<?= htmlspecialchars($baseurl ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <i class="bi bi-clipboard"></i> <?= htmlspecialchars($site_name ?? '', ENT_QUOTES, 'UTF-8') ?>
            </a>
			<?php if (!isset($privatesite) || $privatesite != 'on'): ?>
						<div class="navbar-center">
							<form class="search-form" action="<?= htmlspecialchars($baseurl . ($mod_rewrite == '1' ? 'archive' : 'archive.php'), ENT_QUOTES, 'UTF-8') ?>" method="get">
								<input class="form-control me-2" type="search" name="q" id="searchInput" placeholder="<?= htmlspecialchars($lang['search'] ?? 'Search pastes...', ENT_QUOTES, 'UTF-8') ?>" aria-label="Search" value="<?= htmlspecialchars($_GET['q'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
								<button class="btn btn-outline-light" type="submit"><i class="bi bi-search"></i></button>
							</form>
						</div>
			<?php endif; ?>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
				<?php
				// Archive link
				if (!isset($privatesite) || $privatesite != 'on') {
					if ($mod_rewrite == '1') {
						echo '<li class="nav-item"><a class="nav-link" href="' . htmlspecialchars($baseurl . 'archive', ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($lang['archive'] ?? 'Archive', ENT_QUOTES, 'UTF-8') . '</a></li>';
					} else {
						echo '<li class="nav-item"><a class="nav-link" href="' . htmlspecialchars($baseurl . 'archive.php', ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($lang['archive'] ?? 'Archive', ENT_QUOTES, 'UTF-8') . '</a></li>';
					}
				}
				// Dynamic pages (header/both) from `pages` table
				$headerLinks = getNavLinks($pdo, 'header');
				echo renderBootstrapNav($headerLinks);
				?>
                    <!-- Account / Guest dropdown -->
                    <li class="nav-item dropdown navbar-nav-guest">
					<?php if (isset($_SESSION['token'])): ?>
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-person"></i> <?= htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><h6 class="dropdown-header"><?= htmlspecialchars($lang['my_account'] ?? 'My Account', ENT_QUOTES, 'UTF-8') ?></h6></li>
						<?php
						if ($mod_rewrite == '1') {
							echo '<li><a class="dropdown-item" href="' . htmlspecialchars($baseurl . 'user/' . urlencode($_SESSION['username'] ?? ''), ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-clipboard"></i> ' . htmlspecialchars($lang['pastes'] ?? 'Pastes', ENT_QUOTES, 'UTF-8') . '</a></li>';
							echo '<li><a class="dropdown-item" href="' . htmlspecialchars($baseurl . 'profile', ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-person"></i> ' . htmlspecialchars($lang['settings'] ?? 'Settings', ENT_QUOTES, 'UTF-8') . '</a></li>';
							echo '<li><hr class="dropdown-divider"></li>';
							echo '<li><a class="dropdown-item" href="' . htmlspecialchars($baseurl . 'login.php?action=logout', ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-box-arrow-right"></i> ' . htmlspecialchars($lang['logout'] ?? 'Logout', ENT_QUOTES, 'UTF-8') . '</a></li>';
						} else {
							echo '<li><a class="dropdown-item" href="' . htmlspecialchars($baseurl . 'user.php?user=' . urlencode($_SESSION['username'] ?? ''), ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-clipboard"></i> ' . htmlspecialchars($lang['pastes'] ?? 'Pastes', ENT_QUOTES, 'UTF-8') . '</a></li>';
							echo '<li><a class="dropdown-item" href="' . htmlspecialchars($baseurl . 'profile.php', ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-person"></i> ' . htmlspecialchars($lang['settings'] ?? 'Settings', ENT_QUOTES, 'UTF-8') . '</a></li>';
							echo '<li><hr class="dropdown-divider"></li>';
							echo '<li><a class="dropdown-item" href="' . htmlspecialchars($baseurl . 'login.php?action=logout', ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-box-arrow-right"></i> ' . htmlspecialchars($lang['logout'] ?? 'Logout', ENT_QUOTES, 'UTF-8') . '</a></li>';
						}
						?>
                        </ul>
						<?php else: ?>
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-person"></i> <?= htmlspecialchars($lang['guest'] ?? 'Guest', ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#signin"><i class="bi bi-box-arrow-in-right"></i> <?= htmlspecialchars($lang['login'] ?? 'Login', ENT_QUOTES, 'UTF-8') ?></a></li>
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#signup"><i class="bi bi-person-plus"></i> <?= htmlspecialchars($lang['signup'] ?? 'Register', ENT_QUOTES, 'UTF-8') ?></a></li>
							<?php if ($enablegoog == 'yes'): ?>
                            <li><a class="dropdown-item btn-oauth" href="<?= htmlspecialchars($baseurl . 'login.php?login=google', ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-google oauth-icon"></i> <?= htmlspecialchars($lang['login_with_google'] ?? 'Google', ENT_QUOTES, 'UTF-8') ?></a></li>
							<?php endif; ?>
							<?php if ($enablefb == 'yes'): ?>
                            <li><a class="dropdown-item btn-oauth" href="<?= htmlspecialchars($baseurl . 'login.php?login=facebook', ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-facebook oauth-icon"></i> <?= htmlspecialchars($lang['login_with_facebook'] ?? 'Facebook', ENT_QUOTES, 'UTF-8') ?></a></li>
							<?php endif; ?>
                        </ul>
						<?php endif; ?>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
<?php if (!isset($privatesite) || $privatesite != 'on'): ?>
    <!-- Sign in Modal -->
    <div class="modal fade" id="signin" tabindex="-1" aria-labelledby="signinModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-md">
            <div class="modal-content">
                <div class="modal-header">
                    <h1 class="modal-title fs-5" id="signinModalLabel"><?= htmlspecialchars($lang['login'] ?? 'Login', ENT_QUOTES, 'UTF-8') ?></h1>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="signin-feedback" class="mb-3"></div>
                    <form method="POST" action="<?= htmlspecialchars($baseurl . 'login.php', ENT_QUOTES, 'UTF-8') ?>" id="signin-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="signin" value="1">
                        <input type="hidden" name="ajax" value="1">
                        <input type="hidden" name="redirect" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? $baseurl, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="mb-3">
                            <label for="signinModalUsername" class="form-label"><?= htmlspecialchars($lang['username'] ?? 'Username', ENT_QUOTES, 'UTF-8') ?></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" name="username" class="form-control" id="signinModalUsername" placeholder="<?= htmlspecialchars($lang['username'] ?? 'Username', ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="signinModalPassword" class="form-label"><?= htmlspecialchars($lang['password'] ?? 'Password', ENT_QUOTES, 'UTF-8') ?></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-key"></i></span>
                                <input type="password" name="password" class="form-control" id="signinModalPassword" placeholder="<?= htmlspecialchars($lang['password'] ?? 'Password', ENT_QUOTES, 'UTF-8') ?>" autocomplete="current-password" required>
                            </div>
                        </div>
						<?php if ($captcha_enabled): ?>
						<?php if ($captcha_mode === 'recaptcha'): ?>
                        <!-- reCAPTCHA v2 checkbox -->
                        <div class="g-recaptcha mb-3" data-sitekey="<?= htmlspecialchars($recaptcha_sitekey, ENT_QUOTES, 'UTF-8') ?>" data-callback="onRecaptchaSuccessSignin"></div>
                        <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response-signin">
						<?php elseif ($captcha_mode === 'recaptcha_v3'): ?>
                        <!-- reCAPTCHA v3 -->
                        <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response-signin">
						<?php elseif ($captcha_mode === 'turnstile'): ?>
                        <!-- Cloudflare Turnstile -->
						<input type="hidden" id="cf-turnstile-response-signin" name="cf-turnstile-response">
						<div class="cf-turnstile mb-3"
						   data-sitekey="<?php echo htmlspecialchars($turnstile_sitekey, ENT_QUOTES, 'UTF-8'); ?>"
						   data-callback="onTurnstileSuccessSignin"
						   data-error-callback="onTurnstileError"
						   data-action="login"
						   data-appearance="execute"
						   data-retry="auto"></div>
						<script>
						(function() {
						  if (typeof turnstile !== 'undefined') {
							  turnstile.ready(function() {
								  document.getElementById('signin-form').addEventListener('submit', function(e) {
									  var tokenInput = document.getElementById('cf-turnstile-response-signin');
									  if (!tokenInput.value) {
										  e.preventDefault();
										  turnstile.render('.cf-turnstile', {
											  sitekey: '<?php echo htmlspecialchars($turnstile_sitekey, ENT_QUOTES, 'UTF-8'); ?>',
											  callback: function(token) { tokenInput.value = token; document.getElementById('signin-form').submit(); },
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
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="signinRememberme" name="rememberme" checked>
                            <label class="form-check-label" for="signinRememberme"><?= htmlspecialchars($lang['rememberme'] ?? 'Keep me signed in.', ENT_QUOTES, 'UTF-8') ?></label>
                        </div>
                        <button type="submit" class="btn btn-primary btn-perky w-100 mt-3" id="signinSubmit"><?= htmlspecialchars($lang['login'] ?? 'Login', ENT_QUOTES, 'UTF-8') ?></button>
                        <a class="btn btn-outline-light w-100 mt-2" href="<?= htmlspecialchars($baseurl . 'login.php?action=forgot', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($lang['forgot_password'] ?? 'Forgot Password', ENT_QUOTES, 'UTF-8') ?></a>
                    </form>
                </div>
                <div class="modal-footer">
                    <a href="<?= htmlspecialchars($baseurl . 'login.php?action=signup', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($lang['signup'] ?? 'Register', ENT_QUOTES, 'UTF-8') ?></a>
                    <a href="<?= htmlspecialchars($baseurl . 'login.php?action=resend', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($lang['resend_verification'] ?? 'Resend Verification Email', ENT_QUOTES, 'UTF-8') ?></a>
                </div>
            </div>
        </div>
    </div>
    <!-- Sign up Modal -->
    <div class="modal fade" id="signup" tabindex="-1" aria-labelledby="signupModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h1 class="modal-title fs-5" id="signupModalLabel"><?= htmlspecialchars($lang['signup'] ?? 'Register', ENT_QUOTES, 'UTF-8') ?></h1>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="signup-feedback" class="mb-3"></div>
                    <form action="<?= htmlspecialchars($baseurl . 'login.php', ENT_QUOTES, 'UTF-8') ?>" method="post" id="signup-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="signup" value="1">
                        <input type="hidden" name="ajax" value="1">
                        <input type="hidden" name="redirect" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? $baseurl, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="mb-3">
                            <label for="signupModalUsername" class="form-label"><?= htmlspecialchars($lang['username'] ?? 'Username', ENT_QUOTES, 'UTF-8') ?></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" class="form-control" id="signupModalUsername" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="signupModalEmail" class="form-label"><?= htmlspecialchars($lang['email'] ?? 'Email', ENT_QUOTES, 'UTF-8') ?></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" class="form-control" id="signupModalEmail" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="email" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="signupModalFullname" class="form-label"><?= htmlspecialchars($lang['full_name'] ?? 'Full Name', ENT_QUOTES, 'UTF-8') ?></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" class="form-control" id="signupModalFullname" name="full" value="<?= htmlspecialchars($_POST['full'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="name" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="signupModalPassword" class="form-label"><?= htmlspecialchars($lang['password'] ?? 'Password', ENT_QUOTES, 'UTF-8') ?></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input type="password" class="form-control" id="signupModalPassword" name="password" autocomplete="new-password" required>
                            </div>
                        </div>
						<?php if ($captcha_enabled): ?>
						<?php if ($captcha_mode === 'recaptcha'): ?>
                        <!-- reCAPTCHA v2 checkbox -->
                        <div class="g-recaptcha mb-3" data-sitekey="<?= htmlspecialchars($recaptcha_sitekey, ENT_QUOTES, 'UTF-8') ?>" data-callback="onRecaptchaSuccessSignup"></div>
                        <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response-signup">
						<?php elseif ($captcha_mode === 'recaptcha_v3'): ?>
                        <!-- reCAPTCHA v3 -->
                        <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response-signup">
						<?php elseif ($captcha_mode === 'turnstile'): ?>
                        <!-- Cloudflare Turnstile -->
						<input type="hidden" id="cf-turnstile-response-signup" name="cf-turnstile-response">
						<div class="cf-turnstile mb-3"
						   data-sitekey="<?php echo htmlspecialchars($turnstile_sitekey, ENT_QUOTES, 'UTF-8'); ?>"
						   data-callback="onTurnstileSuccessSignup"
						   data-error-callback="onTurnstileError"
						   data-action="signup"
						   data-appearance="execute"
						   data-retry="auto"></div>
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
					<?php endif; ?>
                        <button type="submit" name="signup" id="signupSubmit" class="btn btn-primary btn-perky fw-bold w-100"><?= htmlspecialchars($lang['signup'] ?? 'Register', ENT_QUOTES, 'UTF-8') ?></button>
                    </form>
                </div>
                <div class="modal-footer">
                    <a href="<?= htmlspecialchars($baseurl . 'login.php', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($lang['already_have_account'] ?? 'Already have an account?', ENT_QUOTES, 'UTF-8') ?></a>
                    <a href="<?= htmlspecialchars($baseurl . 'login.php?action=resend', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($lang['resend_verification'] ?? 'Resend Verification Email', ENT_QUOTES, 'UTF-8') ?></a>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
    <!-- // Header -->