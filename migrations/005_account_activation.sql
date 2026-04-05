-- Migration 005: Account activation via email link

DROP PROCEDURE IF EXISTS _bbcc_add_user_activation_columns;
DELIMITER $$
CREATE PROCEDURE _bbcc_add_user_activation_columns()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'user'
          AND COLUMN_NAME = 'is_active'
    ) THEN
        ALTER TABLE `user`
            ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `role`;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'user'
          AND COLUMN_NAME = 'activated_at'
    ) THEN
        ALTER TABLE `user`
            ADD COLUMN `activated_at` DATETIME NULL AFTER `is_active`;
    END IF;
END$$
DELIMITER ;
CALL _bbcc_add_user_activation_columns();
DROP PROCEDURE IF EXISTS _bbcc_add_user_activation_columns;

CREATE TABLE IF NOT EXISTS `account_activation_tokens` (
    `id`         INT NOT NULL AUTO_INCREMENT,
    `user_id`    VARCHAR(50)  NOT NULL,
    `user_email` VARCHAR(150) NOT NULL,
    `token_hash` CHAR(64)     NOT NULL,
    `expires_at` DATETIME     NOT NULL,
    `used_at`    DATETIME     DEFAULT NULL,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_activation_token_hash` (`token_hash`),
    KEY `idx_activation_user_id` (`user_id`),
    KEY `idx_activation_user_email` (`user_email`),
    KEY `idx_activation_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
