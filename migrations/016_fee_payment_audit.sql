CREATE TABLE IF NOT EXISTS `pcm_fee_payment_audit` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `payment_id` INT NOT NULL,
    `action` VARCHAR(60) NOT NULL,
    `old_status` VARCHAR(30) DEFAULT NULL,
    `new_status` VARCHAR(30) DEFAULT NULL,
    `old_due_amount` DECIMAL(10,2) DEFAULT NULL,
    `new_due_amount` DECIMAL(10,2) DEFAULT NULL,
    `old_paid_amount` DECIMAL(10,2) DEFAULT NULL,
    `new_paid_amount` DECIMAL(10,2) DEFAULT NULL,
    `reason` VARCHAR(500) DEFAULT NULL,
    `changed_by` VARCHAR(150) DEFAULT NULL,
    `changed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_fee_audit_payment` (`payment_id`),
    KEY `idx_fee_audit_changed_at` (`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
