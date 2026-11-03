-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Feb 03, 2026 at 01:42 PM
-- Server version: 8.0.22
-- PHP Version: 8.4.17

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `paste`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin`
--

CREATE TABLE `admin` (
  `id` int NOT NULL,
  `user` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pass` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'admin@example.com',
  `reset_code` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reset_expiry` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admin_history`
--

CREATE TABLE `admin_history` (
  `id` int NOT NULL,
  `admin_id` int DEFAULT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `last_date` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ads`
--

CREATE TABLE `ads` (
  `id` int NOT NULL,
  `text_ads` text COLLATE utf8mb4_unicode_ci,
  `ads_1` text COLLATE utf8mb4_unicode_ci,
  `ads_2` text COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `api_keys`
--

CREATE TABLE `api_keys` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `api_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'Default',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ban_user`
--

CREATE TABLE `ban_user` (
  `id` int NOT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_date` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `captcha`
--

CREATE TABLE `captcha` (
  `id` int NOT NULL,
  `cap_e` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'off',
  `mode` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Normal',
  `mul` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'off',
  `allowed` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(7) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#000000',
  `recaptcha_sitekey` text COLLATE utf8mb4_unicode_ci,
  `recaptcha_secretkey` text COLLATE utf8mb4_unicode_ci,
  `recaptcha_version` enum('v2','v3') COLLATE utf8mb4_unicode_ci DEFAULT 'v2',
  `turnstile_sitekey` text COLLATE utf8mb4_unicode_ci,
  `turnstile_secretkey` text COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `interface`
--

CREATE TABLE `interface` (
  `id` int NOT NULL,
  `theme` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'default',
  `lang` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en.php'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `interface`
--

INSERT INTO `interface` (`id`, `theme`, `lang`) VALUES
(1, 'default', 'en.php');

-- --------------------------------------------------------

--
-- Table structure for table `mail`
--

CREATE TABLE `mail` (
  `id` int NOT NULL,
  `verification` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'enabled',
  `smtp_host` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `smtp_username` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `smtp_password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `smtp_port` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `protocol` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '2',
  `auth` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'true',
  `socket` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tls',
  `oauth_client_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `oauth_client_secret` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `oauth_refresh_token` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mail_log`
--

CREATE TABLE `mail_log` (
  `id` int NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('verification','reset','test') COLLATE utf8mb4_unicode_ci NOT NULL,
  `sent_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pages`
--

CREATE TABLE `pages` (
  `id` int NOT NULL,
  `page_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `page_title` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `page_content` longtext COLLATE utf8mb4_unicode_ci,
  `location` enum('','header','footer','both') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `nav_parent` int DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_date` datetime NOT NULL,
  `external_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `pages`
--

INSERT INTO `pages` (`id`, `page_name`, `page_title`, `page_content`, `location`, `nav_parent`, `sort_order`, `is_active`, `last_date`, `external_url`) VALUES
(1, 'contact', 'Contact', '<h1>Contact Us</h1><p>Email: <a href=\"mailto:admin@example.com\">admin@example.com</a></p>', 'footer', NULL, 0, 1, '2025-09-02 13:26:10', NULL),
(2, 'terms', 'Terms of Service', '<h1>Terms of Service</h1><p>Replace this with your actual terms.</p>', 'footer', NULL, 1, 1, '2025-09-02 13:26:10', NULL),
(5, 'API', 'API', '<p><br></p>', 'footer', NULL, 0, 1, '2026-02-02 10:50:30', 'https://paste.boxlabs.uk/api-docs.php');

-- --------------------------------------------------------

--
-- Table structure for table `page_view`
--

CREATE TABLE `page_view` (
  `id` int NOT NULL,
  `tpage` int UNSIGNED NOT NULL DEFAULT '0',
  `tvisit` int UNSIGNED NOT NULL DEFAULT '0',
  `date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pastes`
--

CREATE TABLE `pastes` (
  `id` int NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Untitled',
  `content` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `encrypt` varchar(1) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '0',
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'NONE',
  `now_time` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `views` mediumtext COLLATE utf8mb4_unicode_ci,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `member` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Guest',
  `expiry` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `visible` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '0',
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `slug` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date` datetime NOT NULL,
  `s_date` date NOT NULL,
  `parent_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `paste_comments`
--

CREATE TABLE `paste_comments` (
  `id` int NOT NULL,
  `paste_id` int NOT NULL,
  `parent_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `body_html_cached` text COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `paste_options`
--

CREATE TABLE `paste_options` (
  `id` int NOT NULL,
  `option_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `option_value` text COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `paste_options`
--

INSERT INTO `paste_options` (`id`, `option_name`, `option_value`) VALUES
(1, 'url_mode', 'slug'),
(2, 'slug_length', '8'),
(3, 'block_numeric_urls', '1'),
(4, 'api_enabled', '1'),
(5, 'api_keys_per_user', '5');

-- --------------------------------------------------------

--
-- Table structure for table `paste_views`
--

CREATE TABLE `paste_views` (
  `id` int NOT NULL,
  `paste_id` int NOT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `view_date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sitemap_options`
--

CREATE TABLE `sitemap_options` (
  `id` int NOT NULL,
  `priority` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '0.9',
  `changefreq` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'daily'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `site_info`
--

CREATE TABLE `site_info` (
  `id` int NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `des` mediumtext COLLATE utf8mb4_unicode_ci,
  `keyword` mediumtext COLLATE utf8mb4_unicode_ci,
  `site_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `twit` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `face` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gplus` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ga` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `additional_scripts` text COLLATE utf8mb4_unicode_ci,
  `baseurl` text COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `site_info`
--

INSERT INTO `site_info` (`id`, `title`, `des`, `keyword`, `site_name`, `email`, `twit`, `face`, `gplus`, `ga`, `additional_scripts`, `baseurl`) VALUES
(1, 'Paste', 'Paste can store text, source code or sensitive data for a set period of time.', 'paste,pastebin.com,pastebin,text,paste,online paste', 'Paste', 'mail@vanquishsolutions.co.uk', '', '', '', '', '', 'https://paste.boxlabs.uk/');

-- --------------------------------------------------------

--
-- Table structure for table `site_permissions`
--

CREATE TABLE `site_permissions` (
  `id` int NOT NULL,
  `disableguest` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'off',
  `siteprivate` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'off',
  `allowguestfork` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'off',
  `hiderecentpastes` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'off'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `site_permissions`
--

INSERT INTO `site_permissions` (`id`, `disableguest`, `siteprivate`, `allowguestfork`, `hiderecentpastes`) VALUES
(1, '', '', 'on', ''),
(2, 'off', 'off', 'on', 'off');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `oauth_uid` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `platform` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `verified` enum('0','1','2') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '0',
  `picture` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT 'NONE',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `username_locked` tinyint(1) NOT NULL DEFAULT '1',
  `last_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `refresh_token` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verification_code` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reset_code` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reset_expiry` datetime DEFAULT NULL,
  `remember_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date` datetime NOT NULL,
  `spam_exempt` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `visitor_ips`
--

CREATE TABLE `visitor_ips` (
  `id` int NOT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `visit_date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin`
--
ALTER TABLE `admin`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user` (`user`);

--
-- Indexes for table `admin_history`
--
ALTER TABLE `admin_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_admin_id` (`admin_id`);

--
-- Indexes for table `ads`
--
ALTER TABLE `ads`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `api_keys`
--
ALTER TABLE `api_keys`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_api_key` (`api_key`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `ban_user`
--
ALTER TABLE `ban_user`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `captcha`
--
ALTER TABLE `captcha`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `interface`
--
ALTER TABLE `interface`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `mail`
--
ALTER TABLE `mail`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `mail_log`
--
ALTER TABLE `mail_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pages`
--
ALTER TABLE `pages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pages_location` (`location`),
  ADD KEY `idx_pages_navparent` (`nav_parent`),
  ADD KEY `idx_pages_active` (`is_active`);

--
-- Indexes for table `page_view`
--
ALTER TABLE `page_view`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pastes`
--
ALTER TABLE `pastes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_pastes_slug` (`slug`),
  ADD KEY `idx_pastes_parent` (`parent_id`);
ALTER TABLE `pastes` ADD FULLTEXT KEY `content` (`content`);
ALTER TABLE `pastes` ADD FULLTEXT KEY `content_2` (`content`);

--
-- Indexes for table `paste_comments`
--
ALTER TABLE `paste_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_paste_time` (`paste_id`,`created_at`),
  ADD KEY `fk_comments_user` (`user_id`),
  ADD KEY `idx_parent` (`paste_id`,`parent_id`,`created_at`),
  ADD KEY `fk_comments_parent` (`parent_id`);

--
-- Indexes for table `paste_options`
--
ALTER TABLE `paste_options`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_option_name` (`option_name`);

--
-- Indexes for table `paste_views`
--
ALTER TABLE `paste_views`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_paste_ip_date` (`paste_id`,`ip`),
  ADD KEY `idx_paste_id` (`paste_id`),
  ADD KEY `idx_view_date` (`view_date`);

--
-- Indexes for table `sitemap_options`
--
ALTER TABLE `sitemap_options`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `site_info`
--
ALTER TABLE `site_info`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `site_permissions`
--
ALTER TABLE `site_permissions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `visitor_ips`
--
ALTER TABLE `visitor_ips`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_ip_date` (`ip`,`visit_date`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin`
--
ALTER TABLE `admin`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admin_history`
--
ALTER TABLE `admin_history`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ads`
--
ALTER TABLE `ads`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `api_keys`
--
ALTER TABLE `api_keys`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ban_user`
--
ALTER TABLE `ban_user`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `captcha`
--
ALTER TABLE `captcha`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `interface`
--
ALTER TABLE `interface`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `mail`
--
ALTER TABLE `mail`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mail_log`
--
ALTER TABLE `mail_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pages`
--
ALTER TABLE `pages`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `page_view`
--
ALTER TABLE `page_view`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pastes`
--
ALTER TABLE `pastes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `paste_comments`
--
ALTER TABLE `paste_comments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `paste_options`
--
ALTER TABLE `paste_options`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `paste_views`
--
ALTER TABLE `paste_views`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sitemap_options`
--
ALTER TABLE `sitemap_options`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `site_info`
--
ALTER TABLE `site_info`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `site_permissions`
--
ALTER TABLE `site_permissions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `visitor_ips`
--
ALTER TABLE `visitor_ips`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admin_history`
--
ALTER TABLE `admin_history`
  ADD CONSTRAINT `fk_admin_history_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `api_keys`
--
ALTER TABLE `api_keys`
  ADD CONSTRAINT `api_keys_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pages`
--
ALTER TABLE `pages`
  ADD CONSTRAINT `fk_pages_navparent` FOREIGN KEY (`nav_parent`) REFERENCES `pages` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `paste_comments`
--
ALTER TABLE `paste_comments`
  ADD CONSTRAINT `fk_comments_parent` FOREIGN KEY (`parent_id`) REFERENCES `paste_comments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_comments_paste` FOREIGN KEY (`paste_id`) REFERENCES `pastes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `paste_views`
--
ALTER TABLE `paste_views`
  ADD CONSTRAINT `paste_views_ibfk_1` FOREIGN KEY (`paste_id`) REFERENCES `pastes` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
