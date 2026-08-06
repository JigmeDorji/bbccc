-- Migration 025: admin-editable minimum enrolment age.
-- Singleton row (id=1), same pattern as fees_settings/sponsor_settings.
-- Seeded to match the previously-hardcoded default (5 years 6 months).

CREATE TABLE IF NOT EXISTS `enrolment_settings` (
    `id`             TINYINT UNSIGNED NOT NULL,
    `min_age_years`  TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `min_age_months` TINYINT UNSIGNED NOT NULL DEFAULT 6,
    `updated_by`     VARCHAR(190) DEFAULT NULL,
    `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `enrolment_settings` (`id`, `min_age_years`, `min_age_months`) VALUES (1, 5, 6);
