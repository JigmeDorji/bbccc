-- Migration 027: optional cover image for each download file.
-- When set, downloads.php shows this image instead of the generic
-- file icon, and downloadFileSetup.php can manage it per row.

DROP PROCEDURE IF EXISTS _bbcc_migration_027;
DELIMITER $$
CREATE PROCEDURE _bbcc_migration_027()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'download_files' AND COLUMN_NAME = 'image_path'
    ) THEN
        ALTER TABLE `download_files` ADD COLUMN `image_path` VARCHAR(255) NOT NULL DEFAULT '' AFTER `file_path`;
    END IF;
END$$
DELIMITER ;
CALL _bbcc_migration_027();
DROP PROCEDURE IF EXISTS _bbcc_migration_027;
