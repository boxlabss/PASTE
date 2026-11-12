[![Download PASTE](https://a.fsdn.com/con/app/sf-download-button)](https://sourceforge.net/projects/phpaste/files/latest/download)

[![Download PASTE](https://img.shields.io/sourceforge/dw/phpaste.svg)](https://sourceforge.net/projects/phpaste/files/latest/download)
[![Download PASTE](https://img.shields.io/sourceforge/dt/phpaste.svg)](https://sourceforge.net/projects/phpaste/files/latest/download)

Paste is forked from the original source pastebin.com used before it was bought.
The original source is available from the previous owner's **[GitHub repository](https://github.com/lordelph/pastebin)**

A public version can be found **[here](https://paste.boxlabs.uk/)**

<table>
  <tr>
    <td><img src="https://paste.boxlabs.uk/demoimg/editor.png" alt="editormobile" width = 279px height = auto></td>
    <td><img src="https://paste.boxlabs.uk/demoimg/editor2.png" alt="editor" width = 288px height = auto></td>  
    <td><img src="https://paste.boxlabs.uk/demoimg/diff.png" alt="diff" width = 279px height = auto></td>
    <td><img src="https://paste.boxlabs.uk/demoimg/login.png" alt="login" width = 279px height = auto></td>
    <td><img src="https://paste.boxlabs.uk/demoimg/settings.png" alt="settings" width = 279px height = auto></td>
    <td><img src="https://paste.boxlabs.uk/demoimg/admin.png" alt="admin panel" width = 279px height = auto></td>
  </tr>
</table>

IRC: If you would like support or want to contribute to Paste connect to irc.afternet.org in channel #PASTE

Any bugs can be reported at:
https://github.com/boxlabss/PASTE/issues/new

Requirements
===
 - PHP 8.1 or higher with `pdo_mysql`, `openssl`, `curl` and `fileinfo` extensions (`fileinfo` checks the type of uploads). `GD` for the internal CAPTCHA and for turning rotated photos upright.
  - MySQL or MariaDB
  - Composer for dependency management
  - Web server (e.g., Apache/Nginx) with HTTPS enabled (if OAuth enabled as below)

See docs/CHANGELOG.md
Paste 3.5
====
* Spam filter: points based, with regex, keywords, a filter that learns from moderation, link domain blocklists, form traps, IP reputation (DNSBL, StopForumSpam) and per-user exemptions
* Image and video uploads at /img, and FILEHOST text and image uploads from IRC
* Uploads are stored encrypted with each file's link as the key
* Per-admin permissions for sections of the admin panel
* The installer upgrades existing installations in place
* API uploads, hashed API keys with scopes, and a per-key rate limit
* Security and bug fixes (see docs/CHANGELOG.md)

Paste 3.4
====
* Fixed editing of Pastes
* Added pastebin.com-like slug support for URLs (default 8 chars)
* Show a differences link for forked Pastes
* Show a list of all forks on an original paste
* Added a json API (see docs/API.md/https://paste.boxlabs.uk/api-docs.php) and a python client.
* Updated example nginx conf (for URLs)

Paste 3.3
====
* Improved autodetection for syntax highlighting for both GeSHi and Highlight.php
	Fix sluggish behaviour with Highlight.php's autodetection of very large plain text especially.
	Added more heuristic detection handling, improved efficiency of Paste's autodetect
* Added Client side encryption with AES-256-GCM
* Admin panel improvements
	Add new email field for admins
	Implemented Password reset in the admin panel (Forgot?)
	Active tabs fixed in configuration.php/admin.php
	Add option to "Reset token" for SMTP OAuth2h
* Fixed an issue with Turnstile not working on the login/signup modals
* Fixed an issue with reCAPTCHA v3 expiring tokens too soon

Previous version 3.2
* diff viewer reintegration
	?diff.php?a=thispaste&b=anotherpaste

	Supports downloadable .diff files after generation

* Comments integration
```php
// Comments
$comments_enabled          = true;   // on/off
$comments_require_login    = true;   // if false, guests can comment
$comments_on_protected     = false;  // allow/show comments on password-protected pastes
````
* Highlight.php integration - or continue using GeSHi
* theme switcher if highlight is enabled
```php
// Code highlighting engine for non-Markdown pastes: 'highlight' (highlight.php) or 'geshi' (default)
$highlighter = $highlighter ?? 'geshi';

// Style theme for highlighter.php (see includes/Highlight/styles)
$hl_style = 'hybrid.css';
```
* Page navs in header/footer
* "Cookies" footer

Previous version 3.1
* Account deletion
* reCAPTCHA v3 with server side integration and token handling (and v2 support)
* 	Select reCAPTCHA in admin/configuration.php
*	Select v2 or v3 depending on your keys
* 	Default score can be set in /includes/recaptcha.php but 0.8 will catch 99% of bots, balancing false negatives.
* 	Pastes and user account login/register are gated, with v3 users are no longer required to enter a captcha.
* If signed up with OAuth2, ability to change username once in /profile.php - Support more platforms in future.
* Search feature, archive/pagination
* Improved admin panel with Bootstrap 5
* Ability to add/remove admins
* Fixed SMTP for user account emails/verification - Plain SMTP server or use OAuth2 for Google Mail
* CSRF session tokens, improve security, stay logged in for 30 days with "Remember Me"
* PHP version must be 8.1 or above - time to drag Paste into the future. 
* Clean up the codebase, remove obsolete functions and added more comments
* /tmp folder has gone bye bye - improved admin panel statistics, daily unique paste views

Previous version - 3.0
* PHP 8.4> compatibility
* Replace mysqli with pdo
* New default theme, upgrade paste2 theme from bootstrap 3 to 5
* Dark mode
* Admin panel changes
* Google OAuth2 SMTP/User accounts
* Security and bug fixes 
* Improved installer, checks for existing database and updates schema as appropriate.
* Improved database schema
* Update Parsedown for Markdown
* All pastes encrypted in the database with AES-256 by default

---
Install
===
* Create a database for PASTE.
* Upload all files to a webfolder
* Point your browser to http(s)://example.com/install
* Input some settings, DELETE the install folder and you're ready to go.
* To configure OAuth, first you need to use composer to install phpmailer and google api/oauth2 client
  - Install Composer dependencies:
    ```bash
    cd /oauth
    composer require google/apiclient:^2.12 league/oauth2-client:^2.7
    cd /mail
    composer require phpmailer/phpmailer:^6.9
    ```
   - Enter database details (host, name, user, password) and OAuth settings (enable or disable Google sign-in).
   - This generates `config.php` with dynamic `G_REDIRECT_URI` based on your domain.
   
 **Set Up Google OAuth for User Logins**:
   - Go to [Google Cloud Console](https://console.developers.google.com).
   - Create a project and enable the Google+ API.
   - Create OAuth 2.0 credentials (Web application).
   - Set the Authorized Redirect URI to: `<baseurl>oauth/google.php` (e.g., `https://yourdomain.com/oauth/google.php`), where `<baseurl>` is from `site_info.baseurl`.
   - Update `config.php` with:
     ```php
     define('G_CLIENT_ID', 'your_client_id');
     define('G_CLIENT_SECRET', 'your_client_secret');
     ```
   - Ensure `enablegoog` is set to `yes` in `config.php`.
 **Set Up Gmail SMTP with OAuth2**:
   - In [Google Cloud Console](https://console.developers.google.com), enable the Gmail API.
   - Create or reuse OAuth 2.0 credentials.
   - Set the Authorized Redirect URI to: `<baseurl>oauth/google_smtp.php` (e.g., `https://yourdomain.com/oauth/google_smtp.php`), where `<baseurl>` is from `site_info.baseurl`.
   - Log in to `/admin/configuration.php` as an admin.
   - Enter the Client ID and Client Secret under "Google OAuth 2.0 Setup for Gmail SMTP".
   - Click "Authorize Gmail SMTP" to authenticate and save the refresh token in the `mail` table.
   - Configure SMTP settings (host: `smtp.gmail.com`, port: `587`, socket: `tls`, auth: `true`, protocol: `2`).

Development setup
===
* Set up git
* Fork this repository
* Create a database for PASTE.
* Check out the current master branch of your fork
* Point your browser to http(s)://example.com/install and follow the instructions on screen or import docs/paste.mysqlschema.sql into your database and copy docs/config.example.php to config.php and edit

Now you can start coding and send in pull requests.

---

Upgrading
===
From 3.x

1. Back up the database (`mysqldump -u user -p databasename > paste-backup.sql`).
2. Upload the new files over the old ones. Keep your `config.php`.
3. Open http(s)://example.com/install, sign in with an admin account and press **Upgrade**,
   or run `php install/upgrade.php` from a shell (`--no-slugs` skips giving old pastes short links).
4. Delete the `install` folder.

The upgrade adds any missing tables and columns, fixes columns whose definition has changed,
and sets the version in `config.php`. Pastes, users, admin accounts, settings, pages and the
rest of `config.php` are kept, and a copy of the old `config.php` is saved next to it as
`config.backup-<date>.php`. It is safe to run again; a second run reports that nothing changed.

Read the notes at the end of the upgrade. They name any account called Guest, or called the
same as `FILEHOST_MEMBER` ("irc" by default). Those names label guest and IRC content, so new
accounts cannot use them and an account named Guest can no longer sign in. Delete such an
account in Admin > Users unless you created it yourself; the content under its name is kept.

From 2.x: open the installer, sign in with an admin account, and choose
**Reinstall or change the database settings**. Enter the old `$sec_key` on the second step
to re-encrypt pastes encrypted by 2.x.


---
Admin access
===
In **Admin Account > Manage Admins**, each admin gets either **Full access** or only the
sections ticked for them: Configuration, Interface, Pastes, Website uploads, IRC uploads,
Users and personal data, IP bans, Spam filter, Statistics, Ads, Pages, Sitemap and Tasks.

* Every admin can open the dashboard and change their own account. The sidebar only lists
  the sections an admin can open, and any other section answers "No access".
* **Users and personal data** covers the Users page and the email and IP addresses shown in
  other sections (Pastes, Uploads, Spam filter, the dashboard). Without it they show as "hidden".
* **IP bans** is needed for "Ban IP + Delete" in Pastes.
* **Website uploads** and **IRC uploads** split Admin > Uploads: each admin sees, finds and
  deletes only the kinds of upload they have, and only website-upload admins see the /img
  settings and reports.
* **Configuration** (additional scripts and analytics), **Ads** and **Pages** put HTML and
  scripts on the site, which run for every visitor, including admins with full access. Give
  them only to admins you would trust with full access.
* Only admins with full access can manage admins, see the admin login history or sign in to
  the installer. The primary admin (ID 1) always has full access, and nobody can change
  their own access.

Existing admins keep full access. The access list is stored in the `admin.permissions`
column, which the upgrader adds.

---
Spam filter
===
Admin > Spam Filter adds up points for each paste and holds it for moderation or blocks
it past the thresholds you set. Turn it on under Settings.

Advertising spam often carries one link or none, so counting links misses it. The
learned filter judges a paste by its words and shape instead:

* In the **Learn** tab, paste a few spam examples (separated by a line of dashes) and
  press **These are spam**, then **Learn this site's pastes as normal**. It starts scoring
  once it knows 5 of each.
* After that it keeps learning: rejecting a held paste or pressing **Spam** in Admin > Pastes
  teaches it spam, approving a held paste teaches it normal.
* A paste that looks like spam gets 60 points (held); one that clearly looks normal has
  30 taken off, so a normal paste with a link is not held by a link rule or the guest weight.

Also under Settings: **Link domains** looks up linked domains on Spamhaus DBL and SURBL,
and **Form traps** score bots that fill in a hidden field or send the form within seconds.
Spamhaus and SURBL refuse lookups made through public DNS (8.8.8.8, 1.1.1.1), so the
blocklist checks need your host's resolver or a local one.

---
Clean URLs
===
Set mod_rewrite in config.php to 1

For Apache, just use .htaccess

For Nginx, use the example config in **[docs/nginx.example.conf](https://github.com/boxlabss/PASTE/blob/HEAD/docs/nginx.example.conf)**

---
Changelog
===
See **[docs/CHANGELOG.md](https://github.com/boxlabss/PASTE/blob/HEAD/docs/CHANGELOG.md)**

---
Paste now supports pastes of upto 4GB in size, and this is configurable in config.php

However, this relies on the value of post_max_size in your PHP configuration file.

```php
// Max paste size in MB. This value should always be below the value of
// post_max_size in your PHP configuration settings (php.ini) or empty errors will occur.
// The value we got on installation of Paste was: post_max_size = 1G
// Otherwise, the maximum value that can be set is 4000 (4GB)
$pastelimit = "1"; // 0.5 = 512 kilobytes, 1 = 1MB
```

Everything else can be configured using the admin panel.

---

Credits
===

* Paul Dixon for developing **[the original pastebin.com](https://github.com/lordelph/pastebin)**
* **[Pat O'Brien](https://github.com/poblabs)** for numerous contributions to the project.
* **[Viktoria Rei Bauer](https://github.com/ToeiRei)** for her contributions to the project.
* Roberto Rodriguez (roberto.rodriguez.pino[AT]gmail.com) for PostgreSQL support on v1.9.

The Paste theme was built using Bootstrap 5
