# Changelog for **[Paste](https://phpaste.sourceforge.io/)** (Updated on 12/11/2026)

3.5 - 12/11/2026
* New spam filter:
    Scores each paste in points: your own rules (keywords, regular expressions and link domains), IP reputation (DNSBL, StopForumSpam), link domains on URI blocklists (Spamhaus DBL, SURBL; off until you turn it on in Settings) and form traps (a hidden field people never see but bots fill in, and forms sent within 3 seconds). Members can be made exempt.
    A learned filter judges pastes by their words. It learns from the moderation queue (rejecting a held paste teaches it spam, approving one teaches it normal), the new Spam button in Admin > Pastes, examples you paste in, or the site's own pastes.
    It scores in both directions. A paste that looks like spam gets 60 points, which holds it for review. A paste that clearly looks normal has 30 points taken off. Once you trust it, set the 60 to 100 so spam is blocked outright.
    A held paste's author gets its link straight away (the API returns it as url), and it works once a moderator approves the paste
    For private and password-protected pastes the spam log keeps no excerpt, excerpts leave the queue once a paste is approved or rejected, and learning from the site's own pastes skips private and password-protected ones
* Image and video uploads, and FILEHOST uploads from IRC clients (text uploads become pastes, images and videos are stored like /img uploads):
    Every upload is stored encrypted. Signed-in users can also keep a copy of each link in their account. "Your uploads" shows names and previews on every device ("Keep links in my account", on by default)
    "Encrypt in my browser" encrypts a file before it is sent, so the server never sees the file or its key
    Titles: a Title field on the upload form (with several files, each can have its own). The title is shown instead of the file name and names the download. Works without JavaScript; the API and Python client take it as title
    Albums: several files uploaded together go into an album by default (one page and one link, with a title and description); switch it off for separate links, or make an album from separate uploads afterwards with "Make an album from these". The album's maker can add uploads, reorder, remove, rename and delete it (with or without its uploads). Admin > Uploads sets how many uploads an album can hold, for guests (5) and members (50); 0 turns albums off for that group
    Videos play from their direct link, and download names with accents or emoji are sent as UTF-8
    Admin > Uploads: settings and reports, find an upload or album by its link to open or delete it, and remove entries whose files are missing
    API: upload images and videos (action=upload), delete uploads (action=upload_delete), make albums (action=album), and get pastes as plain text (format=raw). Python client: uploads and --album
* More than one domain: list the extra domains in $alias_domains in config.php (see docs/config.example.php). Pages opened through one of them link and post to that domain.
* Per-admin permissions: limit an admin to sections of the admin panel, such as pastes, website uploads, IRC uploads or user data. Manage Admins notes that Configuration, Ads and Pages can put scripts on the site, so they amount to full access
* The installer upgrades existing installations in place (or php install/upgrade.php), keeping config.php, settings and data
* API keys are stored hashed and can be limited to scopes (read, pastes, uploads), with a per-key rate limit set in Admin > Configuration > API
* Python CLI client: uploads, paste passwords and view-once expiry
* Fixed stored XSS through the client-side encryption field (content is validated and escaped)
* Signed-in paste create/edit/fork requires the CSRF token; session cookie is SameSite=Lax
* Editing a password-protected paste with the password box left empty keeps the password
* View-once pastes can no longer be read repeatedly through raw/download, and are not embeddable
* View-once and expired pastes are left out of Recent Pastes, the archive, profile pages, fork lists and the sitemap, so a crawler or a passing visitor cannot use up a view-once paste
* Embeds: escaped title/content, line breaks preserved, links use the slug and respect mod_rewrite
* IP bans with wildcards (49.37.117.*)
* Client-side encryption: large pastes no longer fail to encrypt, errors are shown instead of a redirect, and edit/fork buttons are passed through
* Installer: database details containing quotes, &, $ or <> are saved correctly, and an existing site's installer needs a full-access admin sign-in
* Diff viewer improvements, plus fixed the built-in Myers diff (used when the xdiff extension is not installed), which produced wrong diffs
* A database outage now shows the themed error page instead of a fatal "undefined function paste_error()"
* Added .hex (https://hexdroid.org) scripting to GeSHi
* Moved language autodetection to includes/langdetect.php. Each language is scored on its own patterns (77 languages), and one is only chosen when it clearly beats every other; otherwise the paste is shown as plain text and "Why this language?" says what came closest
    "# comment" lines no longer make Tcl, Bash, Python, Perl, Ruby, PowerShell, YAML, nginx and other code render as Markdown, and commented config files (.gitignore, SSH config) stay plain text
    Newly recognised: Haskell, Scala, Elixir, Erlang, Clojure, Lisp, Scheme, Dart, Objective-C, Groovy, VB.NET, BASIC, Pascal, Fortran, MATLAB, Julia, OCaml, F#, Nim, CoffeeScript, D, AutoHotkey, Smalltalk, Prolog, Awk, x86, ARM, RISC-V and MIPS assembly, Ada, Haxe, Mathematica, CMake, Protocol Buffers, TeX, GLSL, Verilog, VHDL, SCSS, Less, Puppet, Nix and NSIS. Zig, COBOL and a few others are recognised and shown as plain text rather than mislabelled
    Eggdrop scripts use GeSHi's Eggdrop Tcl highlighting, and GeSHi no longer falls back to plain text for languages it has
    Diffs of PHP files are shown as diffs, IRC and chat logs as plain text, and only a shebang on the first line naming a known interpreter counts
    RISC-V assembly is no longer detected as ARM
* RISC-V assembly highlighting for both highlighters, and MIPS assembly for GeSHi (includes/geshi/riscv.php, includes/geshi/mips.php, includes/Highlight/languages/riscv.json)
* docs/convert-hljs-to-json.mjs keeps a grammar's keyword groups (keyword, built_in, literal, meta), so each is coloured separately
* Theme CSS and JavaScript links carry a version, so browsers load the new files after an update instead of a cached copy
* Scripts from CDNs carry integrity hashes, so a changed file is refused. highlight.js and zxcvbn now load from jsDelivr.
* Sign-in:
    "Keep me signed in" works for 30 days
    Signing in on a second device no longer signs the first one out; changing your password signs out your other devices
    Changing your username once (Google sign-ins) moves your uploads and albums with it
    Google sign-in only links to an account by an email address Google has verified. The first Google sign-in to an account that has a password removes the password and ends the account's other sessions and API keys, so someone who signed up with another person's address loses the account to its real owner
* Security fixes:
    A name whose account was deleted cannot be taken again while pastes or uploads still belong to it
    Deleting an account, from your profile or in Admin > Users, also deletes its pastes, comments, uploads, albums and API keys (uploads and albums stayed behind, and Admin > Users kept the pastes)
    Admin > Interface only accepts a language file from /langs and a theme folder from /theme
    Deleting a paste from your profile or the sidebar needs the form token, and the profile list asks before deleting
    Signing out returns you to the page you came from only if it is on this site
    A password reset signs the account out everywhere
    Wrong paste passwords and failed sign-ins (site and admin) are limited to 10 per IP every 10 minutes
    Raw, download and embed links of a password-protected paste carry a token instead of the password (raw and download links last a day, the embed link until the password changes), entering the password unlocks the paste for the session for a day, and the password box hides what is typed. ?password= still works for scripts

Previous version - 3.4 - 06/02/2026
* Fixed editing of Pastes
* Added pastebin.com-like slug support for URLs (default 8 chars)
* Show a "View differences" link for forked Pastes
* Show a list of all forks on an original paste
* Added a json API (see API.md/https://paste.boxlabs.uk/api-docs.php) and a python CLI client.
* Updated example nginx conf (for URLs)
* Added option to disable "Recent Pastes" in sidebar
* Bug fixes

Previous version - 3.3
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

Previous version - 3.2
* improvements
* integration of https://github.com/scrivo/highlight.php
* theme picker if enabled (see example config)
* improved the layout for paste views, fixed some line number css bugs
* added a cookie footer/just comment it out in /theme/default/footer.php if not required
* Page navs in header/footer
* Comments integration
* Improve "My Pastes" - paginate user pastes - list comments
* Added a small tool to help client side password generation for user registration
* Fixed the installer to migrate encrypted pastes from 2.x
* readded a .diff feature (might still be bugs at this time) but supports ?diff.php?a=oldpasteID&b=newpasteID and generates a usable .diff
* ability to load files, drag and drop
* internal captcha improved and now works

Previous version - 3.1
* Account deletion
* reCAPTCHA v3 with server side integration and token handling (and v2 support)
* 	Select reCAPTCHA in admin/configuration.php
*	Select v2 or v3 depending on your keys
* If signed up with OAuth2, ability to change username once in /profile.php
* Search feature, archive/pagination
* Improved admin panel with Bootstrap 5
* Ability to add/remove admins
* Fixed SMTP for user emails - Plain SMTP server or use OAuth2 for Google Mail
* PHP version must be 8.1 or above
* Clean up the codebase, remove obsolete functions and add more comments

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


Previous version - 2.2
-

Frontend changes
* add french translations
* set markdown as default paste language

Backend changes
* secure email verifications against SQL injections

Other changes
* Fix php7 compatibility problems
* Code cleanup

Previous version - 2.1
-
Frontend changes
* User pages has been added and 'My Pastes' have been streamlined into this
* Ability to Fork or Edit pastes
* Raw view added
* Ability to embed pastes on websites
* Pastes can now be submitted and parsed as Markdown using **[Parsedown](http://parsedown.org/)**
* Added reCAPTCHA 2 support

Backend changes
* New options in the Admin panel in Configuration > Permissions

  Option to only allow registered users to paste
  
  Option to make site private, ie by disabling Recent Pastes and Archives
  
* New theme added: clean --- A white/grey version of the default theme
* New option in the Admin Panel in Configuration > Mail Settings to disable or enable email verification
* New option in the Admin panel in Configuration > Site Info to add javascript to the footer
* Added functionality in the Admin panel in >Pastes to ban IPs directly from the list
* Added functionality in the Admin panel in >Dashboard to compare the current installed version with the latest version

Other changes
* Code cleanup and elimination of errors

Previous version - 2.0
-

* New theme
* An installer
* User accounts added

  Ability to login and register with email verification
  
  'My Pastes' page with options to view and delete pastes

* Admin panel added

  Dashboard (front page) with a header to display some statistics of the day: overall views, unique views, pastes & users and lists to display recent pastes, users and admin logins
  
  Configuration page to apply Site name, title, description and keywords metatags, with sublinks to other configuration options such as Captcha settings (set the captcha type: easy, normal & tough and colour) and Mail settings for email verification (set Mail Protocol to either PHP Mail or SMTP and SMTP options)
  
  Interface page to set language with the new translations system, see /langs/ --- and also set the theme
  
  Admin account page to reset admin login details
  
  'Pastes' page to show a list of all pastes with options to delete and see more details
  
  'Users' page to show a list of all registered users with options to show if user registered with email or OAUTH and options to ban or delete
  
  'IP Bans' page to add and list IP bans
  
  'Statistics' page to show overall amount of pastes, expired pastes, users, banned users, page views & unique page views
  
  'Ads' page to add functionality to add ads to sidebar and footer sections
  
  'Pages' page to add new pages using a WYSIWIG editor, and also an option to view a list of pages with delete and edit functionality
  
  'Sitemap' page to control the frequency that the new sitemap system is updated
  
  'Tasks' page for some database optimization and common tasks, delete all expired pastes, clear admin history, delete unverified accounts 

* Archives added
* Captcha added

Other changes
* Overall code overhaul
