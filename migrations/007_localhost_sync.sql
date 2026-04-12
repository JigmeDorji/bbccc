-- Migration 007: Localhost schema sync for latest BBCC modules
-- Purpose: ensure a fresh local setup has all tables/columns used by current code.

DROP PROCEDURE IF EXISTS _bbcc_migration_007;
DELIMITER $$
CREATE PROCEDURE _bbcc_migration_007()
BEGIN
    -- ------------------------------------------------------------
    -- 1) Attendance compatibility columns (latest attendance flow)
    -- ------------------------------------------------------------
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'attendance'
          AND COLUMN_NAME = 'marked_at'
    ) THEN
        ALTER TABLE `attendance`
            ADD COLUMN `marked_at` DATETIME NULL AFTER `status`;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'attendance'
          AND COLUMN_NAME = 'batch_id'
    ) THEN
        ALTER TABLE `attendance`
            ADD COLUMN `batch_id` VARCHAR(48) NULL AFTER `marked_at`;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'attendance'
          AND INDEX_NAME = 'uniq_attendance_day'
    ) THEN
        BEGIN
            -- Some older installs may still have this unique index.
            -- If it cannot be dropped due to legacy foreign keys, continue safely.
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            ALTER TABLE `attendance` DROP INDEX `uniq_attendance_day`;
        END;
    END IF;

    -- ------------------------------------------------------------
    -- 2) Parent/user linking compatibility
    -- ------------------------------------------------------------
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'parents'
          AND COLUMN_NAME = 'user_id'
    ) THEN
        ALTER TABLE `parents`
            ADD COLUMN `user_id` VARCHAR(50) NULL AFTER `id`;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'parents'
          AND INDEX_NAME = 'idx_parents_user_id'
    ) THEN
        CREATE INDEX `idx_parents_user_id` ON `parents` (`user_id`);
    END IF;

    -- Backfill parents.user_id from user table where possible.
    UPDATE `parents` p
    LEFT JOIN `user` u_email
        ON LOWER(u_email.username) = LOWER(p.email)
    LEFT JOIN `user` u_username
        ON LOWER(u_username.username) = LOWER(p.username)
    SET p.user_id = COALESCE(p.user_id, u_email.userid, u_username.userid)
    WHERE p.user_id IS NULL;

    -- ------------------------------------------------------------
    -- 3) Parent profile log compatibility columns
    -- ------------------------------------------------------------
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'parent_profile_update_log'
          AND COLUMN_NAME = 'updated_by_userid'
    ) THEN
        ALTER TABLE `parent_profile_update_log`
            ADD COLUMN `updated_by_userid` VARCHAR(50) NULL AFTER `parent_id`;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'parent_profile_update_log'
          AND COLUMN_NAME = 'old_data'
    ) THEN
        ALTER TABLE `parent_profile_update_log`
            ADD COLUMN `old_data` LONGTEXT NULL AFTER `updated_at`;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'parent_profile_update_log'
          AND COLUMN_NAME = 'new_data'
    ) THEN
        ALTER TABLE `parent_profile_update_log`
            ADD COLUMN `new_data` LONGTEXT NULL AFTER `old_data`;
    END IF;

    -- ------------------------------------------------------------
    -- 4) Admin profile table
    -- ------------------------------------------------------------
    CREATE TABLE IF NOT EXISTS `admin_profiles` (
        `user_id` VARCHAR(50) NOT NULL PRIMARY KEY,
        `full_name` VARCHAR(150) NULL,
        `title` VARCHAR(120) NULL,
        `phone` VARCHAR(40) NULL,
        `address` VARCHAR(255) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    -- ------------------------------------------------------------
    -- 5) Enrolment audit table
    -- ------------------------------------------------------------
    CREATE TABLE IF NOT EXISTS `pcm_enrolment_audit` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `enrolment_id` INT NULL,
        `student_id` INT NOT NULL,
        `event_type` VARCHAR(80) NOT NULL,
        `actor` VARCHAR(150) NULL,
        `details` TEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_student` (`student_id`),
        KEY `idx_enrolment` (`enrolment_id`),
        KEY `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    -- ------------------------------------------------------------
    -- 6) Mail queue table
    -- ------------------------------------------------------------
    CREATE TABLE IF NOT EXISTS `mail_queue` (
        `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `to_email` VARCHAR(255) NOT NULL,
        `to_name` VARCHAR(255) NULL,
        `subject` VARCHAR(255) NOT NULL,
        `html_body` MEDIUMTEXT NOT NULL,
        `attempts` INT NOT NULL DEFAULT 0,
        `max_attempts` INT NOT NULL DEFAULT 5,
        `status` VARCHAR(20) NOT NULL DEFAULT 'queued',
        `last_error` TEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `sent_at` DATETIME NULL,
        KEY `idx_status_available` (`status`, `available_at`),
        KEY `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    -- ------------------------------------------------------------
    -- 7) Audit logs table
    -- ------------------------------------------------------------
    CREATE TABLE IF NOT EXISTS `audit_logs` (
        `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `occurred_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `user_id` VARCHAR(80) NULL,
        `username` VARCHAR(190) NULL,
        `role` VARCHAR(80) NULL,
        `ip_address` VARCHAR(64) NULL,
        `route` VARCHAR(190) NULL,
        `method` VARCHAR(12) NULL,
        `action_name` VARCHAR(120) NULL,
        `entity` VARCHAR(120) NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'info',
        `details_json` MEDIUMTEXT NULL,
        KEY `idx_occurred_at` (`occurred_at`),
        KEY `idx_user` (`username`, `occurred_at`),
        KEY `idx_action` (`action_name`, `occurred_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
END$$
DELIMITER ;

CALL _bbcc_migration_007();
DROP PROCEDURE IF EXISTS _bbcc_migration_007;
