-- Paste database schema
--
-- The same tables the installer (install/) creates for Paste 3.5, with its
-- default settings. The installer or upgrader is the usual way to set up the
-- database; import this file only to set one up by hand:
--
--   mysql -u USER -p DATABASE < docs/paste.mysqlschema.sql
--
-- Then copy docs/config.example.php to config.php, set site_info.baseurl
-- and site_info.email below, and add an admin:
--
--   php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
--   INSERT INTO `admin` (`user`, `pass`, `email`) VALUES ('admin', '<hash>', 'admin@example.com');
--
-- Image and video uploads start turned off; turn them on in Admin > Uploads.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------

--
-- Table `admin`
--

CREATE TABLE `admin` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user` varchar(250) NOT NULL,
  `pass` varchar(250) NOT NULL,
  `email` varchar(255) NOT NULL DEFAULT 'admin@example.com',
  `reset_code` varchar(32) DEFAULT NULL,
  `reset_expiry` datetime DEFAULT NULL,
  `permissions` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user` (`user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table `admin_history`
--

CREATE TABLE `admin_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `admin_id` int DEFAULT NULL,
  `last_date` datetime NOT NULL,
  `ip` varchar(45) NOT NULL,
  `user_agent` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_admin_id` (`admin_id`),
  CONSTRAINT `fk_admin_history_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table `ads`
--

CREATE TABLE `ads` (
  `id` int NOT NULL AUTO_INCREMENT,
  `text_ads` text DEFAULT NULL,
  `ads_1` text DEFAULT NULL,
  `ads_2` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ads` (`id`, `text_ads`, `ads_1`, `ads_2`) VALUES
(1,'','','');

-- --------------------------------------------------------

--
-- Table `api_keys`
--

CREATE TABLE `api_keys` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `api_key` varchar(64) NOT NULL,
  `name` varchar(100) DEFAULT 'Default',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `key_prefix` varchar(16) DEFAULT NULL,
  `scopes` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_api_key` (`api_key`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `api_keys_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table `api_rate_limits`
--

CREATE TABLE `api_rate_limits` (
  `bucket` varchar(64) NOT NULL,
  `window_start` int NOT NULL,
  `hits` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`bucket`,`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table `ban_user`
--

CREATE TABLE `ban_user` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `last_date` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table `captcha`
--

CREATE TABLE `captcha` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cap_e` varchar(10) NOT NULL DEFAULT 'off',
  `mode` varchar(50) NOT NULL DEFAULT 'Normal',
  `recaptcha_version` enum('v2','v3') DEFAULT 'v2',
  `mul` varchar(10) NOT NULL DEFAULT 'off',
  `allowed` text NOT NULL,
  `color` varchar(7) NOT NULL DEFAULT '#000000',
  `recaptcha_sitekey` text DEFAULT NULL,
  `recaptcha_secretkey` text DEFAULT NULL,
  `turnstile_sitekey` text DEFAULT NULL,
  `turnstile_secretkey` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `captcha` (`id`, `cap_e`, `mode`, `recaptcha_version`, `mul`, `allowed`, `color`, `recaptcha_sitekey`, `recaptcha_secretkey`, `turnstile_sitekey`, `turnstile_secretkey`) VALUES
(1,'off','normal','v2','off','ABCDEFGHIJKLMNOPQRSTUVYXYZabcdefghijklmnopqrstuvwxyz0123456789','#000000','','','','');

-- --------------------------------------------------------

--
-- Table `filehost_files`
--

CREATE TABLE `filehost_files` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `account` varchar(64) NOT NULL,
  `network` varchar(64) NOT NULL,
  `mime` varchar(127) NOT NULL,
  `size` int unsigned NOT NULL,
  `ext` varchar(8) NOT NULL,
  `filename` varchar(255) DEFAULT NULL,
  `paste_id` int DEFAULT NULL,
  `ip` varchar(45) NOT NULL,
  `created` datetime NOT NULL,
  `expires` datetime DEFAULT NULL,
  `encrypted` tinyint(1) NOT NULL DEFAULT 0,
  `meta` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `thumb_size` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `account_created` (`account`,`created`),
  KEY `expires` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table `filehost_jti`
--

CREATE TABLE `filehost_jti` (
  `jti` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `exp` int unsigned NOT NULL,
  `account` varchar(64) NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`jti`),
  KEY `exp` (`exp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table `img_reports`
--

CREATE TABLE `img_reports` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `reason` varchar(500) NOT NULL,
  `pass` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `ip` varchar(45) NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `slug` (`slug`),
  KEY `ip_created` (`ip`,`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table `interface`
--

CREATE TABLE `interface` (
  `id` int NOT NULL AUTO_INCREMENT,
  `theme` varchar(50) NOT NULL DEFAULT 'default',
  `lang` varchar(50) NOT NULL DEFAULT 'en.php',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `interface` (`id`, `theme`, `lang`) VALUES
(1,'default','en.php');

-- --------------------------------------------------------

--
-- Table `mail`
--

CREATE TABLE `mail` (
  `id` int NOT NULL AUTO_INCREMENT,
  `verification` varchar(20) NOT NULL DEFAULT 'enabled',
  `smtp_host` varchar(255) DEFAULT '',
  `smtp_username` varchar(255) DEFAULT '',
  `smtp_password` varchar(255) DEFAULT '',
  `smtp_port` varchar(10) DEFAULT '',
  `protocol` varchar(20) NOT NULL DEFAULT '2',
  `auth` varchar(20) NOT NULL DEFAULT 'true',
  `socket` varchar(20) NOT NULL DEFAULT 'tls',
  `oauth_client_id` varchar(255) DEFAULT NULL,
  `oauth_client_secret` varchar(255) DEFAULT NULL,
  `oauth_refresh_token` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `mail` (`id`, `verification`, `smtp_host`, `smtp_username`, `smtp_password`, `smtp_port`, `protocol`, `auth`, `socket`, `oauth_client_id`, `oauth_client_secret`, `oauth_refresh_token`) VALUES
(1,'enabled','smtp.gmail.com','','','587','2','true','tls',NULL,NULL,NULL);

-- --------------------------------------------------------

--
-- Table `mail_log`
--

CREATE TABLE `mail_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `sent_at` datetime NOT NULL,
  `type` enum('verification','reset','test') NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table `pages`
--

CREATE TABLE `pages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `last_date` datetime NOT NULL,
  `page_name` varchar(255) NOT NULL,
  `page_title` mediumtext NOT NULL,
  `page_content` longtext DEFAULT NULL,
  `location` enum('','header','footer','both') NOT NULL DEFAULT '',
  `nav_parent` int DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `external_url` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pages_location` (`location`),
  KEY `idx_pages_navparent` (`nav_parent`),
  KEY `idx_pages_active` (`is_active`),
  CONSTRAINT `fk_pages_navparent` FOREIGN KEY (`nav_parent`) REFERENCES `pages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `pages` (`id`, `last_date`, `page_name`, `page_title`, `page_content`, `location`, `nav_parent`, `sort_order`, `is_active`, `external_url`) VALUES
(1,'2026-11-05 09:59:43','contact','Contact','<h1>Contact Us</h1><p>Email: <a href=\"mailto:admin@example.com\">admin@example.com</a></p>','footer',NULL,0,1,NULL),
(2,'2026-11-05 09:59:43','terms','Terms of Service','<h1>Terms of Service</h1><p>Replace this with your actual terms.</p>','footer',NULL,1,1,NULL),
(3,'2026-11-05 09:59:43','api','API Documentation',NULL,'footer',NULL,2,1,'api-docs.php');

-- --------------------------------------------------------

--
-- Table `page_view`
--

CREATE TABLE `page_view` (
  `id` int NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `tpage` int unsigned NOT NULL DEFAULT 0,
  `tvisit` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table `pastes`
--

CREATE TABLE `pastes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `slug` varchar(32) DEFAULT NULL,
  `parent_id` int DEFAULT NULL,
  `title` varchar(255) NOT NULL DEFAULT 'Untitled',
  `content` longtext NOT NULL,
  `visible` varchar(10) NOT NULL DEFAULT '0',
  `code` varchar(50) NOT NULL DEFAULT 'text',
  `expiry` varchar(50) DEFAULT NULL,
  `password` varchar(255) NOT NULL DEFAULT 'NONE',
  `encrypt` varchar(1) NOT NULL DEFAULT '0',
  `member` varchar(255) NOT NULL DEFAULT 'Guest',
  `date` datetime NOT NULL,
  `ip` varchar(45) NOT NULL,
  `now_time` varchar(50) DEFAULT NULL,
  `s_date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_pastes_slug` (`slug`),
  KEY `idx_pastes_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table `paste_comments`
--

CREATE TABLE `paste_comments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `paste_id` int NOT NULL,
  `parent_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `body` text NOT NULL,
  `body_html_cached` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip` varchar(45) NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_paste_time` (`paste_id`,`created_at`),
  KEY `idx_parent` (`paste_id`,`parent_id`,`created_at`),
  KEY `fk_comments_parent` (`parent_id`),
  KEY `fk_comments_user` (`user_id`),
  CONSTRAINT `fk_comments_parent` FOREIGN KEY (`parent_id`) REFERENCES `paste_comments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comments_paste` FOREIGN KEY (`paste_id`) REFERENCES `pastes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table `paste_options`
--

CREATE TABLE `paste_options` (
  `id` int NOT NULL AUTO_INCREMENT,
  `option_name` varchar(100) NOT NULL,
  `option_value` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_option_name` (`option_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `paste_options` (`id`, `option_name`, `option_value`) VALUES
(1,'url_mode','slug'),
(2,'slug_length','8'),
(3,'img_enabled','0'),
(4,'api_enabled','1'),
(5,'api_keys_per_user','5'),
(6,'api_rate_limit','60');

-- --------------------------------------------------------

--
-- Table `paste_views`
--

CREATE TABLE `paste_views` (
  `id` int NOT NULL AUTO_INCREMENT,
  `paste_id` int NOT NULL,
  `ip` varchar(45) NOT NULL,
  `view_date` date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_paste_ip_date` (`paste_id`,`ip`,`view_date`),
  KEY `idx_paste_id` (`paste_id`),
  KEY `idx_view_date` (`view_date`),
  CONSTRAINT `paste_views_ibfk_1` FOREIGN KEY (`paste_id`) REFERENCES `pastes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table `sitemap_options`
--

CREATE TABLE `sitemap_options` (
  `id` int NOT NULL AUTO_INCREMENT,
  `priority` varchar(10) NOT NULL DEFAULT '0.9',
  `changefreq` varchar(20) NOT NULL DEFAULT 'daily',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sitemap_options` (`id`, `priority`, `changefreq`) VALUES
(1,'0.9','daily');

-- --------------------------------------------------------

--
-- Table `site_info`
--

CREATE TABLE `site_info` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `des` mediumtext DEFAULT NULL,
  `keyword` mediumtext DEFAULT NULL,
  `site_name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `twit` varchar(255) DEFAULT NULL,
  `face` varchar(255) DEFAULT NULL,
  `gplus` varchar(255) DEFAULT NULL,
  `ga` varchar(255) DEFAULT NULL,
  `additional_scripts` text DEFAULT NULL,
  `baseurl` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `site_info` (`id`, `title`, `des`, `keyword`, `site_name`, `email`, `twit`, `face`, `gplus`, `ga`, `additional_scripts`, `baseurl`) VALUES
(1,'Paste','Paste can store text, source code, or sensitive data for a set period of time.','paste,pastebin.com,pastebin,text,paste,online paste','Paste','admin@example.com','https://x.com/','https://www.facebook.com/','','','','https://paste.example.com/');

-- --------------------------------------------------------

--
-- Table `site_permissions`
--

CREATE TABLE `site_permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `disableguest` varchar(10) NOT NULL DEFAULT 'off',
  `siteprivate` varchar(10) NOT NULL DEFAULT 'off',
  `hiderecentpastes` varchar(10) NOT NULL DEFAULT 'off',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `site_permissions` (`id`, `disableguest`, `siteprivate`, `hiderecentpastes`) VALUES
(1,'off','off','off');

-- --------------------------------------------------------

--
-- Table `spam_ip_cache`
--

CREATE TABLE `spam_ip_cache` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `score` int NOT NULL DEFAULT 0,
  `sources` varchar(255) DEFAULT NULL,
  `checked_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_spam_ip` (`ip`),
  KEY `idx_spam_ip_exp` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table `spam_learned`
--

CREATE TABLE `spam_learned` (
  `hash` char(64) NOT NULL,
  `label` enum('spam','ham') NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`hash`),
  KEY `idx_spam_learned_label` (`label`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table `spam_log`
--

CREATE TABLE `spam_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `created` datetime NOT NULL,
  `ip` varchar(45) NOT NULL,
  `member` varchar(255) NOT NULL DEFAULT 'Guest',
  `score` int NOT NULL DEFAULT 0,
  `action` varchar(10) NOT NULL DEFAULT 'allow',
  `reasons` text DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `excerpt` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_spam_log_created` (`created`),
  KEY `idx_spam_log_ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table `spam_queue`
--

CREATE TABLE `spam_queue` (
  `id` int NOT NULL AUTO_INCREMENT,
  `created` datetime NOT NULL,
  `ip` varchar(45) NOT NULL,
  `member` varchar(255) NOT NULL DEFAULT 'Guest',
  `score` int NOT NULL DEFAULT 0,
  `reasons` text DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `excerpt` varchar(500) DEFAULT NULL,
  `payload` longtext NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  PRIMARY KEY (`id`),
  KEY `idx_spam_queue_status` (`status`),
  KEY `idx_spam_queue_created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table `spam_rules`
--

CREATE TABLE `spam_rules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `rule_type` enum('keyword','regex','domain') NOT NULL DEFAULT 'keyword',
  `pattern` varchar(255) NOT NULL,
  `weight` int NOT NULL DEFAULT 50,
  `target` enum('content','title','both') NOT NULL DEFAULT 'both',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `note` varchar(255) DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_spam_rules_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table `spam_settings`
--

CREATE TABLE `spam_settings` (
  `id` tinyint NOT NULL DEFAULT 1,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `block_threshold` int NOT NULL DEFAULT 100,
  `review_threshold` int NOT NULL DEFAULT 50,
  `max_links` int NOT NULL DEFAULT 4,
  `link_weight` int NOT NULL DEFAULT 12,
  `link_ratio_pct` int NOT NULL DEFAULT 35,
  `link_ratio_weight` int NOT NULL DEFAULT 30,
  `min_length` int NOT NULL DEFAULT 24,
  `short_weight` int NOT NULL DEFAULT 15,
  `caps_weight` int NOT NULL DEFAULT 10,
  `guest_weight` int NOT NULL DEFAULT 10,
  `dnsbl_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `dnsbl_zones` text DEFAULT NULL,
  `dnsbl_weight` int NOT NULL DEFAULT 40,
  `sfs_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `sfs_weight` int NOT NULL DEFAULT 50,
  `sfs_confidence` int NOT NULL DEFAULT 30,
  `ip_cache_ttl` int NOT NULL DEFAULT 3600,
  `log_allowed` tinyint(1) NOT NULL DEFAULT 0,
  `uribl_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `uribl_zones` text DEFAULT NULL,
  `uribl_weight` int NOT NULL DEFAULT 50,
  `bayes_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `bayes_min_docs` int NOT NULL DEFAULT 5,
  `bayes_spam_pct` int NOT NULL DEFAULT 90,
  `bayes_spam_weight` int NOT NULL DEFAULT 60,
  `bayes_ham_pct` int NOT NULL DEFAULT 10,
  `bayes_ham_weight` int NOT NULL DEFAULT 30,
  `trap_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `trap_weight` int NOT NULL DEFAULT 150,
  `trap_min_seconds` int NOT NULL DEFAULT 3,
  `trap_fast_weight` int NOT NULL DEFAULT 30,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `spam_settings` (`id`, `enabled`, `block_threshold`, `review_threshold`, `max_links`, `link_weight`, `link_ratio_pct`, `link_ratio_weight`, `min_length`, `short_weight`, `caps_weight`, `guest_weight`, `dnsbl_enabled`, `dnsbl_zones`, `dnsbl_weight`, `sfs_enabled`, `sfs_weight`, `sfs_confidence`, `ip_cache_ttl`, `log_allowed`, `uribl_enabled`, `uribl_zones`, `uribl_weight`, `bayes_enabled`, `bayes_min_docs`, `bayes_spam_pct`, `bayes_spam_weight`, `bayes_ham_pct`, `bayes_ham_weight`, `trap_enabled`, `trap_weight`, `trap_min_seconds`, `trap_fast_weight`) VALUES
(1,0,100,50,4,12,35,30,24,15,10,10,0,'zen.spamhaus.org\nbl.spamcop.net\ndnsbl.dronebl.org',40,0,50,30,3600,0,0,'dbl.spamhaus.org\nmulti.surbl.org',50,1,5,90,60,10,30,1,150,3,30);

-- --------------------------------------------------------

--
-- Table `spam_tokens`
--

CREATE TABLE `spam_tokens` (
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `spam` int unsigned NOT NULL DEFAULT 0,
  `ham` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `oauth_uid` varchar(255) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `username_locked` tinyint(1) NOT NULL DEFAULT 1,
  `email_id` varchar(255) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `platform` varchar(50) NOT NULL,
  `password` varchar(255) DEFAULT '',
  `verified` enum('0','1','2') NOT NULL DEFAULT '0',
  `picture` varchar(255) DEFAULT 'NONE',
  `date` datetime NOT NULL,
  `ip` varchar(45) NOT NULL,
  `last_ip` varchar(45) DEFAULT NULL,
  `refresh_token` varchar(255) DEFAULT NULL,
  `token` varchar(512) DEFAULT NULL,
  `verification_code` varchar(32) DEFAULT NULL,
  `reset_code` varchar(32) DEFAULT NULL,
  `reset_expiry` datetime DEFAULT NULL,
  `remember_token` varchar(64) DEFAULT NULL,
  `spam_exempt` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table `visitor_ips`
--

CREATE TABLE `visitor_ips` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `visit_date` date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_ip_date` (`ip`,`visit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
