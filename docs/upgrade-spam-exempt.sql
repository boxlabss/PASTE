-- Adds the per-user spam filter exemption used by admin/users.php and admin/spamfilter.php.
-- The admin pages also add this column automatically the first time they load.
ALTER TABLE `users` ADD COLUMN `spam_exempt` TINYINT(1) NOT NULL DEFAULT 0;
