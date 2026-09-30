-- Migration 029: Index menu event dates for the homepage upcoming-events query

DROP PROCEDURE IF EXISTS _bbcc_migration_029;
DELIMITER $$
CREATE PROCEDURE _bbcc_migration_029()
BEGIN
    IF EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'menu'
          AND COLUMN_NAME = 'eventStartDateTime'
    ) AND NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'menu'
          AND INDEX_NAME = 'idx_menu_event_start_datetime'
    ) THEN
        CREATE INDEX `idx_menu_event_start_datetime`
            ON `menu` (`eventStartDateTime`);
    END IF;
END$$
DELIMITER ;

CALL _bbcc_migration_029();
DROP PROCEDURE IF EXISTS _bbcc_migration_029;