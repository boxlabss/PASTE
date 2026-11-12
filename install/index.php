<?php
/*
 * Paste https://github.com/boxlabss/PASTE
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
$date = date('jS F Y');
$ip   = $_SERVER['REMOTE_ADDR'] ?? '';

// Security headers
header("Content-Security-Policy: default-src 'self'; script-src 'self' code.jquery.com cdn.jsdelivr.net; style-src 'self' cdn.jsdelivr.net; img-src 'self' data:; connect-src 'self'");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");

// PHP version check (require 8.1+)
$php_version = phpversion();
$php_ok      = version_compare($php_version, '8.1', '>=');

// Extension checks
$required_extensions = ['pdo_mysql'];
$optional_extensions = ['openssl' => 'OAuth/SMTP', 'curl' => 'OAuth/SMTP', 'fileinfo' => 'image uploads', 'gd' => 'image uploads'];
$extension_status    = [];
foreach ($required_extensions as $ext) {
    $extension_status[$ext] = extension_loaded($ext) ? 'Enabled' : 'Missing';
}
foreach ($optional_extensions as $ext => $needed_for) {
    $extension_status[$ext] = extension_loaded($ext) ? 'Enabled' : 'Missing (required for ' . $needed_for . ')';
}

// Guess web user (for help text)
$web_user = $_SERVER['USER']
    ?? getenv('APACHE_RUN_USER')
    ?? getenv('USER')
    ?? 'www-data';

// Existing installation, upload folder defaults and PHP upload limit
require_once __DIR__ . '/lib.php';
$existing = installer_existing('../config.php');
$installed = $existing['installed'];
$config_exists = $existing['config'];
$h = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$has_upload_dir = $config_exists && is_readable('../config.php')
    && isset(installer_config_defines((string)file_get_contents('../config.php'), ['FILEHOST_DIR'])['FILEHOST_DIR']);
$default_upload_dir = installer_default_upload_dir();

$ini_bytes = static function (string $v): int {
    $v = trim($v);
    $n = (float)$v;
    return (int)match (strtolower(substr($v, -1))) {
        'g' => $n * 1073741824,
        'm' => $n * 1048576,
        'k' => $n * 1024,
        default => $n,
    };
};
$php_upload_limits = array_filter([$ini_bytes((string)ini_get('upload_max_filesize')), $ini_bytes((string)ini_get('post_max_size'))], static fn($b) => $b > 0);
$php_upload_limit  = $php_upload_limits ? min($php_upload_limits) : 0;
$php_upload_ok     = $php_upload_limit === 0 || $php_upload_limit >= 26 * 1048576;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Paste 3 - Install</title>
    <link href="//cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <link href="install.css" rel="stylesheet">
</head>
<body>
<div id="top" class="clearfix">
    <div class="applogo">
        <a href="#" class="logo">Paste</a>
    </div>
</div>

<div class="content container">
    <div class="row">
        <!-- INSTALL PANEL -->
        <div id="install">
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title">Pre-installation Checks</h5>
                        <table class="table table-hover">
                            <tbody>
                                <tr>
                                    <th>PHP Version</th>
                                    <td>
                                        <span class="badge <?php echo $php_ok ? 'bg-success' : 'bg-danger'; ?>">
                                            <?php echo htmlspecialchars($php_version); ?>
                                        </span>
                                        <?php if (!$php_ok): ?>
                                            <br><small class="text-danger">PHP 8.1 or higher is required. Please upgrade your PHP version.</small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php foreach ($extension_status as $ext => $status): ?>
                                    <tr>
                                        <th><?php echo htmlspecialchars($ext); ?></th>
                                        <td>
                                            <span class="badge <?php echo (strpos($status, 'Enabled') !== false) ? 'bg-success' : 'bg-danger'; ?>">
                                                <?php echo htmlspecialchars($status); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr>
                                    <th>PHP upload limit</th>
                                    <td>
                                        <span class="badge <?php echo $php_upload_ok ? 'bg-success' : 'bg-warning text-dark'; ?>">
                                            <?php echo $php_upload_limit === 0 ? 'No limit' : htmlspecialchars(round($php_upload_limit / 1048576, 1) . ' MB'); ?>
                                        </span>
                                        <?php if (!$php_upload_ok): ?>
                                            <br><small class="text-muted">Videos need 26 MB. <code>img/.user.ini</code> raises it for /img under php-fpm; with mod_php set <code>upload_max_filesize</code> and <code>post_max_size</code> in php.ini.</small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>File/Directory</th>
                                    <th>Status</th>
                                </tr>
                                <?php
                                // Only check config.php and sitemap.xml (no tmp)
                                $files = ['../config.php', '../sitemap.xml'];
                                foreach ($files as $filename) {
                                    echo "<tr><td>" . htmlspecialchars(basename($filename)) . "</td>";
                                    $dir = dirname($filename);
                                    if (!is_dir($dir)) {
                                        echo '<td><span class="badge bg-danger">Directory Missing</span> Run: <code>mkdir -p ' . htmlspecialchars($dir, ENT_QUOTES, 'UTF-8') . ' && chmod 775 ' . htmlspecialchars($dir, ENT_QUOTES, 'UTF-8') . ' && chown ' . htmlspecialchars($web_user, ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($dir, ENT_QUOTES, 'UTF-8') . '</code></td>';
                                    } elseif (is_writable($filename) || (!file_exists($filename) && is_writable($dir))) {
                                        echo '<td><span class="badge bg-success">Writable</span></td>';
                                    } else {
                                        echo '<td><span class="badge bg-danger">Not Writable</span> Run: <code>chmod 664 ' . htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') . '</code> or <code>chown ' . htmlspecialchars($web_user, ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') . '</code></td>';
                                    }
                                    echo "</tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <?php if ($installed): ?>
                <div class="card mb-4" id="upgrade-card">
                    <div class="card-body">
                        <h5 class="card-title">Upgrade Paste</h5>
                        <p>
                            Found Paste <strong><?php echo $h($existing['version'] ?? 'unknown'); ?></strong> using the database <code><?php echo $h($existing['database']); ?></code>.
                            <?php if ($existing['upgradable']): ?>
                                Upgrading to <?php echo $h(PASTE_VERSION); ?> brings the database up to date and sets the version in config.php.
                                Pastes, users, admin accounts, settings, pages and the rest of config.php are kept.
                            <?php else: ?>
                                <span class="text-danger"><?php echo $h($existing['reason']); ?></span>
                            <?php endif; ?>
                        </p>
                        <p class="small text-muted">
                            Back up the database first, for example <code>mysqldump -u USER -p <?php echo $h($existing['database']); ?> &gt; paste-backup.sql</code>.
                            <?php if ($existing['upgradable']): ?>From a shell you can also run <code>php install/upgrade.php</code>.<?php endif; ?>
                        </p>
                        <div class="alert alert-danger" id="upgrade-alert" role="alert" hidden></div>
                        <form id="upgrade-signin" class="row g-3"<?php echo !$php_ok ? ' hidden' : ''; ?>>
                            <div class="col-12"><small class="text-muted">Sign in with an admin account of this site to continue.</small></div>
                            <div class="col-md-6">
                                <label for="up_user" class="form-label">Admin Username</label>
                                <input type="text" class="form-control" id="up_user" name="admin_user" autocomplete="username" required>
                            </div>
                            <div class="col-md-6">
                                <label for="up_pass" class="form-label">Admin Password</label>
                                <input type="password" class="form-control" id="up_pass" name="admin_pass" autocomplete="current-password" required>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary w-100">Sign In</button>
                            </div>
                        </form>
                        <form id="upgrade-form" class="row g-3" data-upgradable="<?php echo $existing['upgradable'] ? '1' : '0'; ?>" hidden>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="up_slugs" name="generate_slugs" checked>
                                    <label class="form-check-label" for="up_slugs">Give pastes without a short link one (like <code>/jbFzprF6</code>)</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="up_img" class="form-label">Image and Video Uploads</label>
                                <select class="form-select" id="up_img" name="img_enabled">
                                    <option value="keep" selected>Keep current setting</option>
                                    <option value="no">Turn off</option>
                                    <option value="yes">Turn on</option>
                                </select>
                            </div>
                            <div class="col-md-6 up-img-opt" hidden>
                                <label for="up_img_dir" class="form-label">Upload Storage Folder</label>
                                <input type="text" class="form-control" id="up_img_dir" name="img_dir"
                                       value="<?php echo $has_upload_dir ? '' : $h($default_upload_dir); ?>"
                                       placeholder="<?php echo $has_upload_dir ? 'Keep the folder set in config.php' : ''; ?>">
                                <small class="form-text text-muted">
                                    <?php if ($has_upload_dir): ?>config.php already sets a folder; leave this empty to keep it.<?php endif; ?>
                                    Keep it outside the web root.
                                </small>
                            </div>
                            <div class="col-12 up-img-opt" hidden>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="up_img_guests" name="img_guests" value="1" checked>
                                    <label class="form-check-label" for="up_img_guests">Allow guests to upload (the site captcha applies when it is on)</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="up_img_encrypt" name="img_encrypt" value="1">
                                    <label class="form-check-label" for="up_img_encrypt">Tick "Encrypt in my browser" by default</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <label for="up_old_key" class="form-label">Old <code>$sec_key</code> from v2.x <span class="text-muted">(optional)</span></label>
                                <input type="text" class="form-control" id="up_old_key" name="old_sec_key" placeholder="Only if some pastes are still encrypted by Paste 2.x">
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-success w-100">Upgrade to <?php echo $h(PASTE_VERSION); ?></button>
                            </div>
                            <div class="col-12 text-center">
                                <a href="#" id="show-reinstall" class="small">Reinstall or change the database settings instead</a>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
                <div class="card mb-4" id="db-card"<?php echo $installed ? ' hidden' : ''; ?>>
                    <div class="card-body">
                        <h5 class="card-title">Database and Configuration</h5>
                        <small class="text-danger">If upgrading from Paste 2.x - backup your database.</small>
                        <?php if ($existing['locked'] && !$installed): ?>
                            <div class="alert alert-warning small mt-2"><?php echo $h(installer_locked_message($existing)); ?></div>
                        <?php endif; ?>
                        <div class="alert alert-danger" id="alertfailed" role="alert">
                            Configuration failed. <span id="error-details"></span>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <form id="db-form" class="row g-3"<?php echo !$php_ok || ($existing['locked'] && !$installed) ? ' hidden' : ''; ?>>
                            <div class="col-md-6">
                                <label for="data_host" class="form-label">Database Host</label>
                                <input type="text" class="form-control" id="data_host" name="data_host" value="localhost" required>
                            </div>
                            <div class="col-md-6">
                                <label for="data_name" class="form-label">Database Name</label>
                                <input type="text" class="form-control" id="data_name" name="data_name" required>
                            </div>
                            <div class="col-md-6">
                                <label for="data_user" class="form-label">Database Username</label>
                                <input type="text" class="form-control" id="data_user" name="data_user" required>
                            </div>
                            <div class="col-md-6">
                                <label for="data_pass" class="form-label">Database Password</label>
                                <input type="password" class="form-control" id="data_pass" name="data_pass">
                            </div>
                            <div class="col-md-6">
                                <label for="enablegoog" class="form-label">Enable Google OAuth User Logins</label>
                                <select class="form-select" id="enablegoog" name="enablegoog">
                                    <option value="no" selected>No</option>
                                    <option value="yes">Yes</option>
                                </select>
                                <small class="form-text text-muted">Enabling Google OAuth requires Google Cloud Console setup. HTTPS is recommended for security.</small>
                            </div>
                            <div class="col-md-6">
                                <label for="enablesmtp" class="form-label">Enable SMTP Email (Gmail)</label>
                                <select class="form-select" id="enablesmtp" name="enablesmtp">
                                    <option value="no" selected>No</option>
                                    <option value="yes">Yes</option>
                                </select>
                                <small class="form-text text-muted">Enabling SMTP requires Gmail API setup or SMTP credentials. Configure in admin panel after installation.</small>
                            </div>
                            <div class="col-md-6">
                                <label for="img_enabled" class="form-label">Enable Image and Video Uploads</label>
                                <select class="form-select img-setting" id="img_enabled" name="img_enabled">
                                    <?php if ($config_exists): ?>
                                    <option value="keep" selected>Keep current setting</option>
                                    <?php endif; ?>
                                    <option value="no"<?php echo $config_exists ? '' : ' selected'; ?>>No</option>
                                    <option value="yes">Yes</option>
                                </select>
                                <small class="form-text text-muted">Images (10 MB) and videos (25 MB) at <code>/img</code>, stored encrypted with their link as the key. Limits and other settings are in Admin &gt; Uploads.</small>
                            </div>
                            <div class="col-md-6 img-opt" hidden>
                                <label for="img_dir" class="form-label">Upload Storage Folder</label>
                                <input type="text" class="form-control" id="img_dir" name="img_dir"
                                       value="<?php echo $has_upload_dir ? '' : htmlspecialchars($default_upload_dir, ENT_QUOTES, 'UTF-8'); ?>"
                                       placeholder="<?php echo $has_upload_dir ? 'Keep the folder set in config.php' : ''; ?>">
                                <small class="form-text text-muted">
                                    <?php if ($has_upload_dir): ?>config.php already sets a folder; leave this empty to keep it.<?php endif; ?>
                                    Keep it outside the web root. It is created if PHP is allowed to.
                                </small>
                            </div>
                            <div class="col-12 img-opt" hidden>
                                <div class="form-check">
                                    <input class="form-check-input img-setting" type="checkbox" id="img_guests" name="img_guests" value="1" checked>
                                    <label class="form-check-label" for="img_guests">Allow guests to upload (the site captcha applies when it is on)</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input img-setting" type="checkbox" id="img_encrypt" name="img_encrypt" value="1">
                                    <label class="form-check-label" for="img_encrypt">Tick "Encrypt in my browser" by default</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary w-100">Configure</button>
                            </div>
                        </form>
                        <?php if (!$php_ok): ?>
                            <div class="alert alert-warning mt-3">
                                Installation is disabled because your PHP version (<?php echo htmlspecialchars($php_version); ?>) is too low. Please upgrade to PHP 8.1 or higher.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <!-- END INSTALL PANEL -->

        <!-- CONFIGURATION PANEL -->
        <div id="configure">
            <div class="col-md-12">
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title">Configure Admin Account</h5>
                        <div class="alert alert-danger" id="admin-alertfailed" role="alert">
                            Error admin setup failed. <span id="admin-error-details"></span>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <form id="admin-form" class="row g-3">
                            <div class="col-12">
                              <hr>
                              <h6 class="mb-2">Re-key old encrypted pastes (optional)</h6>
                              <label for="old_sec_key" class="form-label">Old <code>$sec_key</code> from v2.x</label>
                              <input type="text" class="form-control" id="old_sec_key" name="old_sec_key"
                                     placeholder="Paste the old $sec_key (hex or plain string)">
                              <small class="text-muted">
                                If provided, the installer will decrypt all pastes with <code>encrypt='1'</code> using the old key and
                                re-encrypt them with your new key (secure IV+HMAC format).
                              </small>
                            </div>
                            <div class="col-12">
                              <hr>
                              <h6 class="mb-2">URL Slug Generation (v3.4+)</h6>
                              <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="generate_slugs" name="generate_slugs" checked>
                                <label class="form-check-label" for="generate_slugs">
                                  Generate random slugs for existing pastes
                                </label>
                              </div>
                              <small class="text-muted">
                                Creates random URL slugs (like <code>/jbFzprF6</code>) for all existing pastes that don't have one.
                                This enables the new URL format. Numeric IDs will still work unless you block them in admin settings.
                              </small>
                            </div>
                            <div class="col-md-6">
                                <label for="admin_user" class="form-label">Username</label>
                                <input type="text" class="form-control" id="admin_user" name="admin_user" required>
                            </div>
                            <div class="col-md-6">
                                <label for="admin_pass" class="form-label">Password</label>
                                <input type="password" class="form-control" id="admin_pass" name="admin_pass" required>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">Submit</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <!-- END CONFIGURATION PANEL -->

        <div id="pre_load">
            <div class="col-md-12">
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title">Installing database schema for Paste. Please wait...</h5>
                    </div>
                </div>
            </div>
        </div>

        <div id="logpanel" class="col-md-12">
            <div class="card mb-4">
                <div class="card-body">
                    <div id="log"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row footer">
        <div class="col-md-6 text-start">
            <a href="https://github.com/boxlabss/PASTE">Updates</a> &mdash; <a href="https://github.com/boxlabss/PASTE/issues">Bugs</a>
        </div>
        <div class="col-md-6 text-end">
            Powered by <a href="https://phpaste.sourceforge.io/" target="_blank">Paste <?php echo $h(PASTE_VERSION); ?></a>
        </div>
    </div>
</div>

<script src="//code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
<script src="//cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>
<script src="install.js"></script>
</body>
</html>
