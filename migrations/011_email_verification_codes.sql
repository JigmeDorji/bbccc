-- Email verification OTP codes table
CREATE TABLE IF NOT EXISTS `email_verification_codes` (
    `id`          INT NOT NULL AUTO_INCREMENT,
    `email`       VARCHAR(190) NOT NULL,
    `code_hash`   CHAR(64) NOT NULL COMMENT 'SHA-256 of the 6-digit code',
    `purpose`     VARCHAR(50) NOT NULL DEFAULT 'signup' COMMENT 'signup, change_email, etc.',
    `attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Wrong-code attempts',
    `verified_at` DATETIME DEFAULT NULL,
    `expires_at`  DATETIME NOT NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_evc_email_purpose` (`email`, `purpose`),
    KEY `idx_evc_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
