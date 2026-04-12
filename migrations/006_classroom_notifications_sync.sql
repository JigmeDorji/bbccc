-- Migration 006: Classroom + notifications + attendance compatibility sync
-- Safe/idempotent migration for existing installs (local/cPanel)

DROP PROCEDURE IF EXISTS _bbcc_migration_006;
DELIMITER $$
CREATE PROCEDURE _bbcc_migration_006()
BEGIN
    -- ------------------------------------------------------------
    -- 1) Dzongkha Classroom tables
    -- ------------------------------------------------------------
    CREATE TABLE IF NOT EXISTS `classroom_announcements` (
        `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `title` VARCHAR(200) NOT NULL,
        `message` TEXT NOT NULL,
        `category` VARCHAR(50) NOT NULL DEFAULT 'Announcement',
        `scope_type` VARCHAR(30) NOT NULL DEFAULT 'selected_classes',
        `posted_by_user_id` VARCHAR(80) NULL,
        `posted_by_username` VARCHAR(190) NULL,
        `posted_by_name` VARCHAR(190) NULL,
        `posted_by_role` VARCHAR(40) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_created_at` (`created_at`),
        KEY `idx_scope_type` (`scope_type`),
        KEY `idx_posted_by_user_id` (`posted_by_user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `classroom_announcement_classes` (
        `announcement_id` BIGINT UNSIGNED NOT NULL,
        `class_id` INT NOT NULL,
        PRIMARY KEY (`announcement_id`, `class_id`),
        KEY `idx_class_id` (`class_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `classroom_reports` (
        `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `class_id` INT NOT NULL,
        `student_id` INT NOT NULL,
        `teacher_id` INT NULL,
        `report_title` VARCHAR(200) NOT NULL,
        `report_type` VARCHAR(50) NOT NULL DEFAULT 'Progress',
        `feedback_text` TEXT NOT NULL,
        `created_by_user_id` VARCHAR(80) NULL,
        `created_by_name` VARCHAR(190) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_student_id` (`student_id`),
        KEY `idx_class_id` (`class_id`),
        KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `classroom_report_comments` (
        `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `report_id` BIGINT UNSIGNED NOT NULL,
        `parent_id` INT NOT NULL,
        `commenter_name` VARCHAR(190) NULL,
        `comment_text` TEXT NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_report_id` (`report_id`),
        KEY `idx_parent_id` (`parent_id`),
        KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'classroom_report_comments' AND COLUMN_NAME = 'read_by_teacher_at'
    ) THEN
        ALTER TABLE `classroom_report_comments`
            ADD COLUMN `read_by_teacher_at` DATETIME NULL AFTER `created_at`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'classroom_report_comments' AND COLUMN_NAME = 'read_by_admin_at'
    ) THEN
        ALTER TABLE `classroom_report_comments`
            ADD COLUMN `read_by_admin_at` DATETIME NULL AFTER `read_by_teacher_at`;
    END IF;

    -- ------------------------------------------------------------
    -- 2) In-app notifications table
    -- ------------------------------------------------------------
    CREATE TABLE IF NOT EXISTS `app_notifications` (
        `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `target_username` VARCHAR(255) NULL,
        `target_role` VARCHAR(40) NULL,
        `title` VARCHAR(255) NOT NULL,
        `body` TEXT NULL,
        `level` VARCHAR(20) NOT NULL DEFAULT 'info',
        `link_url` VARCHAR(255) NULL,
        `is_read` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `read_at` DATETIME NULL,
        KEY `idx_target_user` (`target_username`),
        KEY `idx_target_role` (`target_role`),
        KEY `idx_read_created` (`is_read`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    -- ------------------------------------------------------------
    -- 3) Attendance compatibility (batch + multi-mark support)
    -- ------------------------------------------------------------
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'batch_id'
    ) THEN
        ALTER TABLE `attendance`
            ADD COLUMN `batch_id` VARCHAR(48) NULL AFTER `marked_at`;
    END IF;

    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND INDEX_NAME = 'uniq_attendance_day'
    ) THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            ALTER TABLE `attendance` DROP INDEX `uniq_attendance_day`;
        END;
    END IF;

    -- ------------------------------------------------------------
    -- 4) Class/enrolment/fees helper columns used by latest code
    -- ------------------------------------------------------------
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'classes' AND COLUMN_NAME = 'campus_key'
    ) THEN
        ALTER TABLE `classes`
            ADD COLUMN `campus_key` VARCHAR(20) NOT NULL DEFAULT 'c1' AFTER `class_name`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pcm_enrolments' AND COLUMN_NAME = 'campus_preference'
    ) THEN
        ALTER TABLE `pcm_enrolments`
            ADD COLUMN `campus_preference` VARCHAR(20) NOT NULL DEFAULT 'any' AFTER `fee_plan`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fees_settings' AND COLUMN_NAME = 'campus_one_name'
    ) THEN
        ALTER TABLE `fees_settings`
            ADD COLUMN `campus_one_name` VARCHAR(150) NULL AFTER `amount_yearly`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fees_settings' AND COLUMN_NAME = 'campus_two_name'
    ) THEN
        ALTER TABLE `fees_settings`
            ADD COLUMN `campus_two_name` VARCHAR(150) NULL AFTER `campus_one_name`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fees_settings' AND COLUMN_NAME = 'term1_total_classes'
    ) THEN
        ALTER TABLE `fees_settings`
            ADD COLUMN `term1_total_classes` INT NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fees_settings' AND COLUMN_NAME = 'term2_total_classes'
    ) THEN
        ALTER TABLE `fees_settings`
            ADD COLUMN `term2_total_classes` INT NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fees_settings' AND COLUMN_NAME = 'term3_total_classes'
    ) THEN
        ALTER TABLE `fees_settings`
            ADD COLUMN `term3_total_classes` INT NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fees_settings' AND COLUMN_NAME = 'term4_total_classes'
    ) THEN
        ALTER TABLE `fees_settings`
            ADD COLUMN `term4_total_classes` INT NULL;
    END IF;
END$$
DELIMITER ;

CALL _bbcc_migration_006();
DROP PROCEDURE IF EXISTS _bbcc_migration_006;

