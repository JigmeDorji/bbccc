-- Migration 022: editable icon/title/description for the "Classes and
-- Practices" cards on services.php (School, Ched Tshog Singye Tsewa,
-- Droenchoe). Previously hardcoded directly in services.php's HTML.

CREATE TABLE IF NOT EXISTS `service_programs` (
    `id`          INT           NOT NULL AUTO_INCREMENT,
    `slug`        VARCHAR(100)  NOT NULL,
    `sort_order`  INT           NOT NULL DEFAULT 0,
    `icon`        VARCHAR(60)   NOT NULL DEFAULT 'fa-hands-praying',
    `title`       VARCHAR(150)  NOT NULL,
    `description` VARCHAR(500)  NOT NULL DEFAULT '',
    `link_url`    VARCHAR(255)  NOT NULL,
    `updated_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_service_programs_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `service_programs` (`slug`, `sort_order`, `icon`, `title`, `description`, `link_url`) VALUES
('school', 1, 'fa-chalkboard-user', 'Bhutanese Language and Culture School',
 'Comprehensive language and culture learning program covering Dzongkha reading, writing, speaking, Bhutanese traditions, and values.',
 'bhutanese-language-and-culture-school'),
('ched-tshog-singye-tsewa', 2, 'fa-om', 'Ched Tshog Singye Tsewa',
 'A community practice of offering and blessing, open to new and experienced practitioners.',
 'ched-tshog-singye-tsewa'),
('droenchoe-tara-practice', 3, 'fa-om', 'Droenchoe (Tara) Practice',
 'Weekly Saturday practice under guidance, welcoming new and experienced practitioners on a spiritual learning journey.',
 'droenchoe-tara-practice');
