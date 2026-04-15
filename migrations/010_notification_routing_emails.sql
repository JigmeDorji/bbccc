-- Migration 010: Notification routing emails (website vs class workflows)

DROP PROCEDURE IF EXISTS _bbcc_migration_010;
DELIMITER $$
CREATE PROCEDURE _bbcc_migration_010()
BEGIN
    IF EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'fees_settings'
    ) THEN
        IF NOT EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'fees_settings'
              AND COLUMN_NAME = 'website_notify_email'
        ) THEN
            ALTER TABLE `fees_settings`
                ADD COLUMN `website_notify_email` VARCHAR(190) NULL AFTER `campus_two_name`;
        END IF;

        IF NOT EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'fees_settings'
              AND COLUMN_NAME = 'class_notify_email'
        ) THEN
            ALTER TABLE `fees_settings`
                ADD COLUMN `class_notify_email` VARCHAR(190) NULL AFTER `website_notify_email`;
        END IF;
    END IF;
END$$
DELIMITER ;

CALL _bbcc_migration_010();
DROP PROCEDURE IF EXISTS _bbcc_migration_010;
