-- Migration: Rotating QR tokens for kiosk mobile sign-in
-- Ensures parents must physically scan the QR code at the door

CREATE TABLE IF NOT EXISTS `pcm_kiosk_tokens` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `token`      VARCHAR(64) NOT NULL UNIQUE,
    `expires_at` DATETIME NOT NULL,
    `used`       TINYINT(1) NOT NULL DEFAULT 0,
    `used_by_ip` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_token (`token`),
    INDEX idx_expires (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
