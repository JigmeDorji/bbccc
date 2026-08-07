-- Migration 026: standalone donations -- admin-editable bank details for
-- donations, and a submission queue so anyone can donate immediately
-- (no account required), with admin verify/reject same as fee payments.

CREATE TABLE IF NOT EXISTS `donation_settings` (
    `id`             INT           NOT NULL DEFAULT 1,
    `bank_name`      VARCHAR(150)  DEFAULT NULL,
    `account_name`   VARCHAR(150)  DEFAULT NULL,
    `bsb`            VARCHAR(20)   DEFAULT NULL,
    `account_number` VARCHAR(40)   DEFAULT NULL,
    `bank_notes`     TEXT          DEFAULT NULL,
    `updated_by`     VARCHAR(190)  DEFAULT NULL,
    `updated_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `donation_settings` (`id`) VALUES (1);

CREATE TABLE IF NOT EXISTS `donations` (
    `id`            INT            NOT NULL AUTO_INCREMENT,
    `donor_name`    VARCHAR(150)   NOT NULL,
    `donor_email`   VARCHAR(190)   DEFAULT NULL,
    `donor_phone`   VARCHAR(50)    DEFAULT NULL,
    `amount`        DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    `payment_ref`   VARCHAR(150)   DEFAULT NULL,
    `proof_path`    VARCHAR(255)   DEFAULT NULL,
    `message`       TEXT           DEFAULT NULL,
    `status`        ENUM('Pending','Verified','Rejected') NOT NULL DEFAULT 'Pending',
    `reject_reason` VARCHAR(500)   DEFAULT NULL,
    `verified_by`   VARCHAR(150)   DEFAULT NULL,
    `verified_at`   DATETIME       DEFAULT NULL,
    `submitted_at`  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_donations_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
