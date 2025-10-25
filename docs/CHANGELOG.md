# Changelog for **[Paste](https://phpaste.sourceforge.io/)** (Updated on 25/10/2025)

Current - 3.3
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
