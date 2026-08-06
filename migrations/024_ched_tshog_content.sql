-- Migration 024: dedicated content table for the Ched Tshog Singye Tsewa
-- practice page, mirroring tara_content's schema and append-only pattern
-- (each save inserts a new row; the page reads the most recent one).

CREATE TABLE IF NOT EXISTS `ched_tshog_content` (
    `id`            INT           NOT NULL AUTO_INCREMENT,
    `title`         VARCHAR(180)  DEFAULT NULL,
    `subtitle`      VARCHAR(255)  DEFAULT NULL,
    `intro_text`    TEXT          DEFAULT NULL,
    `body_text`     TEXT          DEFAULT NULL,
    `schedule_text` VARCHAR(255)  DEFAULT NULL,
    `monthly_text`  VARCHAR(255)  DEFAULT NULL,
    `contact_text`  VARCHAR(255)  DEFAULT NULL,
    `imgUrl`        VARCHAR(255)  DEFAULT NULL,
    `created_at`    TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
