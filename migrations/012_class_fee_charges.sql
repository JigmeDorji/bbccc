-- Migration 012: Class-based additional fee charges (e.g., textbook charge)

DROP PROCEDURE IF EXISTS _bbcc_migration_012;
DELIMITER $$
CREATE PROCEDURE _bbcc_migration_012()
BEGIN
    -- 1) Class charge master table
    CREATE TABLE IF NOT EXISTS `pcm_class_fee_charges` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `class_id` INT NOT NULL,
        `charge_title` VARCHAR(120) NOT NULL,
        `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `description` VARCHAR(500) DEFAULT NULL,
        `due_date` DATE DEFAULT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_by` VARCHAR(100) DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_class_charge_class` (`class_id`),
        KEY `idx_class_charge_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- 2) pcm_fee_payments.plan_type must support "Additional"
    IF EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'pcm_fee_payments'
          AND COLUMN_NAME = 'plan_type'
          AND LOWER(COLUMN_TYPE) NOT LIKE '%additional%'
    ) THEN
        ALTER TABLE `pcm_fee_payments`
            MODIFY COLUMN `plan_type` ENUM('Term-wise','Half-yearly','Yearly','Additional') NOT NULL;
    END IF;

    -- 3) Extend installment label length (for custom charge names)
    IF EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'pcm_fee_payments'
          AND COLUMN_NAME = 'instalment_label'
          AND (CHARACTER_MAXIMUM_LENGTH IS NULL OR CHARACTER_MAXIMUM_LENGTH < 120)
    ) THEN
        ALTER TABLE `pcm_fee_payments`
            MODIFY COLUMN `instalment_label` VARCHAR(120) NOT NULL COMMENT 'Term / charge label';
    END IF;

    -- 4) Add reference to class charge
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'pcm_fee_payments'
          AND COLUMN_NAME = 'class_charge_id'
    ) THEN
        ALTER TABLE `pcm_fee_payments`
            ADD COLUMN `class_charge_id` INT NULL AFTER `enrolment_id`;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'pcm_fee_payments'
          AND INDEX_NAME = 'idx_fee_class_charge'
    ) THEN
        CREATE INDEX `idx_fee_class_charge` ON `pcm_fee_payments` (`class_charge_id`);
    END IF;
END$$
DELIMITER ;

CALL _bbcc_migration_012();
DROP PROCEDURE IF EXISTS _bbcc_migration_012;

