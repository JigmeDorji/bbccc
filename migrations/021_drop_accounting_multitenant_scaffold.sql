-- Migration 021: remove unused accounting / multi-tenant scaffold
--
-- company, project, account_head, account_head_sub, account_head_type,
-- and journal_entry were leftover from a generic multi-tenant starter
-- template. The school runs as a single tenant in practice: only
-- generateStatement.php (also removed) ever read the accounting tables,
-- only 1 of 227 user rows had companyID/projectID populated, and
-- $_SESSION['companyID']/['projectID'] were dead-read in every other
-- page that referenced them. Confirmed no cross-cutting foreign keys
-- into these tables from anything else in the schema before dropping.

DROP TABLE IF EXISTS `journal_entry`;
DROP TABLE IF EXISTS `account_head_sub`;
DROP TABLE IF EXISTS `account_head`;
DROP TABLE IF EXISTS `account_head_type`;
DROP TABLE IF EXISTS `company`;
DROP TABLE IF EXISTS `project`;

DROP PROCEDURE IF EXISTS _bbcc_migration_021;
DELIMITER $$
CREATE PROCEDURE _bbcc_migration_021()
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user' AND COLUMN_NAME = 'companyID'
    ) THEN
        ALTER TABLE `user` DROP COLUMN `companyID`;
    END IF;

    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user' AND COLUMN_NAME = 'projectID'
    ) THEN
        ALTER TABLE `user` DROP COLUMN `projectID`;
    END IF;
END$$
DELIMITER ;
CALL _bbcc_migration_021();
DROP PROCEDURE IF EXISTS _bbcc_migration_021;
