-- Migration 023: optional photo for each service_programs card.
-- When set, the card shows this image inside the icon badge instead of
-- the Font Awesome icon.

DROP PROCEDURE IF EXISTS _bbcc_migration_023;
DELIMITER $$
CREATE PROCEDURE _bbcc_migration_023()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_programs' AND COLUMN_NAME = 'image_url'
    ) THEN
        ALTER TABLE `service_programs` ADD COLUMN `image_url` VARCHAR(255) NOT NULL DEFAULT '' AFTER `icon`;
    END IF;
END$$
DELIMITER ;
CALL _bbcc_migration_023();
DROP PROCEDURE IF EXISTS _bbcc_migration_023;
