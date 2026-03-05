-- Migration 002: Parent Class Management system
-- Originally: db_migration_parent_class_mgmt.sql

-- 1) Extend `parents` table: add kiosk PIN + active flag
DROP PROCEDURE IF EXISTS _pcm_extend_parents;
DELIMITER $$
CREATE PROCEDURE _pcm_extend_parents()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parents' AND COLUMN_NAME = 'pin_hash'
    ) THEN
        ALTER TABLE `parents` ADD COLUMN `pin_hash` VARCHAR(255) DEFAULT NULL AFTER `password`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parents' AND COLUMN_NAME = 'status'
    ) THEN
        ALTER TABLE `parents` ADD COLUMN `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active' AFTER `pin_hash`;
    END IF;
END$$
DELIMITER ;
CALL _pcm_extend_parents();
DROP PROCEDURE IF EXISTS _pcm_extend_parents;

-- 2) Bank accounts
CREATE TABLE IF NOT EXISTS `pcm_bank_accounts` (
    `id`             INT          NOT NULL AUTO_INCREMENT,
    `bank_name`      VARCHAR(120) NOT NULL,
    `account_name`   VARCHAR(120) NOT NULL,
    `bsb`            VARCHAR(20)  NOT NULL,
    `account_number` VARCHAR(40)  NOT NULL,
    `reference_hint` VARCHAR(255) DEFAULT NULL,
    `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 3) Enrolment requests
CREATE TABLE IF NOT EXISTS `pcm_enrolments` (
    `id`              INT           NOT NULL AUTO_INCREMENT,
    `student_id`      INT           NOT NULL COMMENT 'FK students.id',
    `parent_id`       INT           NOT NULL COMMENT 'FK parents.id',
    `fee_plan`        ENUM('Term-wise','Half-yearly','Yearly') NOT NULL DEFAULT 'Term-wise',
    `fee_amount`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `payment_ref`     VARCHAR(150)  DEFAULT NULL,
    `proof_path`      VARCHAR(255)  DEFAULT NULL,
    `status`          ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    `admin_note`      VARCHAR(500)  DEFAULT NULL,
    `reviewed_by`     VARCHAR(100)  DEFAULT NULL,
    `reviewed_at`     DATETIME      DEFAULT NULL,
    `submitted_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_enrol_student` (`student_id`),
    KEY `idx_enrol_parent` (`parent_id`),
    KEY `idx_enrol_status` (`status`),
    CONSTRAINT `fk_enrol_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_enrol_parent`  FOREIGN KEY (`parent_id`)  REFERENCES `parents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 4) Fee payments
CREATE TABLE IF NOT EXISTS `pcm_fee_payments` (
    `id`               INT           NOT NULL AUTO_INCREMENT,
    `enrolment_id`     INT           NOT NULL COMMENT 'FK pcm_enrolments.id',
    `student_id`       INT           NOT NULL,
    `parent_id`        INT           NOT NULL,
    `plan_type`        ENUM('Term-wise','Half-yearly','Yearly') NOT NULL,
    `instalment_label` VARCHAR(20)   NOT NULL COMMENT 'Term 1, Half 1, Yearly, etc.',
    `due_amount`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `paid_amount`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `payment_ref`      VARCHAR(150)  DEFAULT NULL,
    `proof_path`       VARCHAR(255)  DEFAULT NULL,
    `status`           ENUM('Unpaid','Pending','Verified','Rejected') NOT NULL DEFAULT 'Unpaid',
    `reject_reason`    VARCHAR(500)  DEFAULT NULL,
    `verified_by`      VARCHAR(100)  DEFAULT NULL,
    `verified_at`      DATETIME      DEFAULT NULL,
    `due_date`         DATE          DEFAULT NULL,
    `submitted_at`     DATETIME      DEFAULT NULL,
    `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_fee_instalment` (`enrolment_id`, `instalment_label`),
    KEY `idx_fee_student` (`student_id`),
    KEY `idx_fee_parent`  (`parent_id`),
    KEY `idx_fee_status`  (`status`),
    CONSTRAINT `fk_fee_enrolment` FOREIGN KEY (`enrolment_id`) REFERENCES `pcm_enrolments`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_fee_student2`  FOREIGN KEY (`student_id`)   REFERENCES `students`(`id`)       ON DELETE CASCADE,
    CONSTRAINT `fk_fee_parent2`   FOREIGN KEY (`parent_id`)    REFERENCES `parents`(`id`)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 5) Kiosk sign-in / sign-out log
CREATE TABLE IF NOT EXISTS `pcm_kiosk_log` (
    `id`         INT  NOT NULL AUTO_INCREMENT,
    `child_id`   INT  NOT NULL COMMENT 'FK students.id',
    `parent_id`  INT  NOT NULL COMMENT 'FK parents.id',
    `log_date`   DATE NOT NULL,
    `time_in`    TIME DEFAULT NULL,
    `time_out`   TIME DEFAULT NULL,
    `method`     ENUM('KIOSK','MANUAL') NOT NULL DEFAULT 'KIOSK',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_kiosk_child_date` (`child_id`, `log_date`),
    KEY `idx_kiosk_parent` (`parent_id`),
    KEY `idx_kiosk_date`   (`log_date`),
    CONSTRAINT `fk_kiosk_child2`  FOREIGN KEY (`child_id`)  REFERENCES `students`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_kiosk_parent2` FOREIGN KEY (`parent_id`) REFERENCES `parents`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 6) Absence requests
CREATE TABLE IF NOT EXISTS `pcm_absence_requests` (
    `id`           INT  NOT NULL AUTO_INCREMENT,
    `child_id`     INT  NOT NULL,
    `parent_id`    INT  NOT NULL,
    `absence_date` DATE NOT NULL,
    `reason`       TEXT NOT NULL,
    `status`       ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    `admin_note`   VARCHAR(500) DEFAULT NULL,
    `decided_by`   VARCHAR(100) DEFAULT NULL,
    `decided_at`   DATETIME     DEFAULT NULL,
    `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_abs_child`  (`child_id`),
    KEY `idx_abs_parent` (`parent_id`),
    KEY `idx_abs_date`   (`absence_date`),
    CONSTRAINT `fk_abs_child`  FOREIGN KEY (`child_id`)  REFERENCES `students`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_abs_parent` FOREIGN KEY (`parent_id`) REFERENCES `parents`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 7) Kiosk brute-force protection
CREATE TABLE IF NOT EXISTS `pcm_kiosk_failed` (
    `id`           INT         NOT NULL AUTO_INCREMENT,
    `phone`        VARCHAR(20) NOT NULL,
    `ip_address`   VARCHAR(45) NOT NULL,
    `attempted_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_fail_phone` (`phone`),
    KEY `idx_fail_ip`    (`ip_address`),
    KEY `idx_fail_time`  (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 8) Seed bank account from existing fees_settings (optional)
DROP PROCEDURE IF EXISTS _pcm_seed_bank;
DELIMITER $$
CREATE PROCEDURE _pcm_seed_bank()
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fees_settings'
    ) THEN
        INSERT INTO `pcm_bank_accounts` (`bank_name`,`account_name`,`bsb`,`account_number`,`reference_hint`)
        SELECT COALESCE(fs.bank_name,'TBC'), COALESCE(fs.account_name,'TBC'),
               COALESCE(fs.bsb,'TBC'), COALESCE(fs.account_number,'TBC'), fs.bank_notes
        FROM `fees_settings` fs WHERE fs.id = 1
        AND NOT EXISTS (SELECT 1 FROM `pcm_bank_accounts` LIMIT 1)
        LIMIT 1;
    END IF;
END$$
DELIMITER ;
CALL _pcm_seed_bank();
DROP PROCEDURE IF EXISTS _pcm_seed_bank;
