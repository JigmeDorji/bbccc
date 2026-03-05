-- Migration 001: Event Management tables
-- Originally: db_migration_events.sql

CREATE TABLE IF NOT EXISTS `events` (
    `id`          INT NOT NULL AUTO_INCREMENT,
    `title`       VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `event_date`  DATE NOT NULL,
    `start_time`  TIME NULL,
    `end_time`    TIME NULL,
    `location`    VARCHAR(255) NULL,
    `sponsors`    VARCHAR(255) NULL,
    `contacts`    VARCHAR(100) NULL,
    `image`       VARCHAR(255) DEFAULT NULL,
    `status`      ENUM('Available','Pending Approval','Booked') NOT NULL DEFAULT 'Available',
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_event_date` (`event_date`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bookings` (
    `id`         INT NOT NULL AUTO_INCREMENT,
    `event_id`   INT NOT NULL,
    `name`       VARCHAR(255) NOT NULL,
    `address`    TEXT NOT NULL,
    `phone`      VARCHAR(50) NOT NULL,
    `email`      VARCHAR(255) NOT NULL,
    `message`    TEXT NULL,
    `status`     ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_booking_event` (`event_id`),
    INDEX `idx_booking_status` (`status`),
    CONSTRAINT `fk_booking_event` FOREIGN KEY (`event_id`)
        REFERENCES `events` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
