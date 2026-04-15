CREATE TABLE IF NOT EXISTS `user_module_access_overrides` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` VARCHAR(64) NOT NULL DEFAULT '',
  `username` VARCHAR(190) NOT NULL DEFAULT '',
  `module_key` VARCHAR(64) NOT NULL,
  `action_key` VARCHAR(64) NOT NULL,
  `effect` ENUM('grant','revoke') NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` VARCHAR(190) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_module_action` (`user_id`, `username`, `module_key`, `action_key`),
  KEY `idx_override_user_id` (`user_id`),
  KEY `idx_override_username` (`username`),
  KEY `idx_override_module` (`module_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

