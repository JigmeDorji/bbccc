-- Migration 004: Patron registrations (supports parent-linked and patron-only records)

CREATE TABLE IF NOT EXISTS `patrons` (
    `id`          INT NOT NULL AUTO_INCREMENT,
    `parent_id`   INT DEFAULT NULL,
    `full_name`   VARCHAR(150) NOT NULL,
    `email`       VARCHAR(150) NOT NULL,
    `phone`       VARCHAR(50)  DEFAULT NULL,
    `address`     TEXT         DEFAULT NULL,
    `patron_type` VARCHAR(50)  NOT NULL DEFAULT 'Regular',
    `status`      ENUM('Pending','Active','Inactive') NOT NULL DEFAULT 'Pending',
    `notes`       TEXT         DEFAULT NULL,
    `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_patron_email` (`email`),
    KEY `idx_patron_parent` (`parent_id`),
    KEY `idx_patron_status` (`status`),
    CONSTRAINT `fk_patron_parent`
        FOREIGN KEY (`parent_id`) REFERENCES `parents`(`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
