-- FILEHOST (IRCv3 draft/FILEHOST + draft/authtoken uploads).  Apply once:
--   mysql -uuser -p databasename < upgrade/2.1-to-2.2-filehost.sql

CREATE TABLE IF NOT EXISTS `filehost_jti` (
  `jti` char(64) COLLATE utf8mb4_bin NOT NULL,
  `exp` int unsigned NOT NULL,
  `account` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`jti`),
  KEY `exp` (`exp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `filehost_files` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(32) COLLATE utf8mb4_bin NOT NULL,
  `account` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `network` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime` varchar(127) COLLATE utf8mb4_unicode_ci NOT NULL,
  `size` int unsigned NOT NULL,
  `ext` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `filename` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paste_id` int DEFAULT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created` datetime NOT NULL,
  `expires` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `account_created` (`account`,`created`),
  KEY `expires` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
