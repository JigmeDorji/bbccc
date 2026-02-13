-- ============================================================
-- BBCC Event Management Module – Database Migration
-- Run this SQL against the restWeb_db database
-- Safe to re-run: drops and recreates tables each time
-- ============================================================

-- Drop tables in correct order (bookings first due to FK)
DROP TABLE IF EXISTS `bookings`;
DROP TABLE IF EXISTS `events`;

-- 1. Events table
CREATE TABLE `events` (
    `id`          INT NOT NULL AUTO_INCREMENT,
    `title`       VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `event_date`  DATE NOT NULL,
    `start_time`  TIME NULL,
    `end_time`    TIME NULL,
    `location`    VARCHAR(255) NULL,
    `sponsors`    VARCHAR(255) NULL,
    `contacts`    VARCHAR(100) NULL,
    `status`      ENUM('Available','Pending Approval','Booked') NOT NULL DEFAULT 'Available',
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_event_date` (`event_date`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Bookings table
CREATE TABLE `bookings` (
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

-- ============================================================
-- Seed: BBCC Events Calendar 2026 (from the official flyer)
-- Events WITH sponsors = 'Booked', events WITHOUT sponsors = 'Available'
-- ============================================================
INSERT INTO `events` (`title`, `description`, `event_date`, `start_time`, `end_time`, `location`, `sponsors`, `contacts`, `status`) VALUES
-- Row 1: Jan 3, Tuesday – Ekazati Tshokor → Booked
('Ekazati Tshokor',          'Regular prayer session',              '2026-01-03', '09:00:00', '12:00:00', 'BBCC Hall', 'ག་གདང་འབའར་ཞོན།', NULL, 'Booked'),
-- Row 2: Jan 17, Saturday – Tara and Menlha Dungdrup → Booked
('Tara and Menlha Dungdrup', 'Tara and Medicine Buddha prayer',     '2026-01-17', '09:00:00', '12:00:00', 'BBCC Hall', 'བོ་རྡོ་རྗེ་འོས་སྐུར་རྗེ་རྟོན།', '0402 096 551', 'Booked'),
-- Row 3: Feb 1, Sunday – Ekazati Tshokor → Booked
('Ekazati Tshokor',          'Regular prayer session',              '2026-02-01', '09:00:00', '12:00:00', 'BBCC Hall', 'རྨས་གླིད་པའང་གདང་དའརོག་གཤ།', '0422 403 307', 'Booked'),
-- Row 4: Feb 14, Saturday – Tara and Menlha Dungdrup → Booked
('Tara and Menlha Dungdrup', 'Tara and Medicine Buddha prayer',     '2026-02-14', '09:00:00', '12:00:00', 'BBCC Hall', 'རོད་དའཆང་གོད་གའགམའམའརྨའཆའའརའདའའརའན།', NULL, 'Booked'),
-- Row 5: Mar 3, Tuesday – Ekazati Tshokor → Booked
('Ekazati Tshokor',          'Regular prayer session',              '2026-03-03', '09:00:00', '12:00:00', 'BBCC Hall', 'རྫས་རྩའམའདོད་ཀྱི་རའདའརོག་གཤ།', '0466 039 282', 'Booked'),
-- Row 6: Mar 14, Saturday – Tara and Menlha Dungdrup → Booked
('Tara and Menlha Dungdrup', 'Tara and Medicine Buddha prayer',     '2026-03-14', '09:00:00', '12:00:00', 'BBCC Hall', 'འམས་གརའ་འོསའགཤའ་གདང་དའའབའའརའན།', '0406 701 355', 'Booked'),
-- Row 7: Apr 1, Wednesday – Ekazati Tshokor → Booked
('Ekazati Tshokor',          'Regular prayer session',              '2026-04-01', '09:00:00', '12:00:00', 'BBCC Hall', 'འོང་གའགམའམའརྩའམའདོད་ཀྱི་རའདའརོག་གཤ།', '0403 934 564', 'Booked'),
-- Row 8: Apr 11, Saturday – Tara and Menlha Dungdrup → Booked
('Tara and Menlha Dungdrup', 'Tara and Medicine Buddha prayer',     '2026-04-11', '09:00:00', '12:00:00', 'BBCC Hall', 'འོང་གའགམའམའརོད་མའ་གདགའརའདའརའགའརྨའན།', '0479 084 976', 'Booked'),
-- Row 9: Apr 26, Sunday – Zhabdrung Kuchoe → Booked
('Zhabdrung Kuchoe',         'Zhabdrung Kuchoe commemoration',      '2026-04-26', '09:00:00', '12:00:00', 'BBCC Hall', 'རྩགའརའདོད་གའརའདའརའན།', '0424 567 774', 'Booked'),
-- Row 10: May 1, Friday – Ekazati Tshokor → Booked
('Ekazati Tshokor',          'Regular prayer session',              '2026-05-01', '09:00:00', '12:00:00', 'BBCC Hall', 'འབའརའརྩའམའརྟའའགའརའདའདའགཤའའན།', '0499 783 244', 'Booked'),
-- Row 11: May 16, Saturday – Tara and Menlha Dungdrup → Booked
('Tara and Menlha Dungdrup', 'Tara and Medicine Buddha prayer',     '2026-05-16', '09:00:00', '12:00:00', 'BBCC Hall', 'རྩོན་རའདོད་གའརའདའརའདའའན།', '0466 196 214', 'Booked'),
-- Row 12: May 31, Sunday – Lord Buddha's Parinirvana/Ekazati Tshokor → Booked
('Lord Buddha''s Parinirvana / Ekazati Tshokor', 'Lord Buddha''s Parinirvana commemoration and Ekazati Tshokor', '2026-05-31', '09:00:00', '12:00:00', 'BBCC Hall', 'རྩགའམའརོད་མའ་གདགའམརའདའརའན།', '0422 331 106', 'Booked'),
-- Row 13: Jun 13, Saturday – Tara and Menlha Dungdrup → Booked
('Tara and Menlha Dungdrup', 'Tara and Medicine Buddha prayer',     '2026-06-13', '09:00:00', '12:00:00', 'BBCC Hall', 'ནའམའརོད་ཀྱི་བུད་མའམའགའདའརོག་གཤ།', '0452 144 279', 'Booked'),
-- Row 14: Jun 24, Wednesday – Birth Anniversary of Guru Rinpoche → Available (no sponsor)
('Birth Anniversary of Guru Rinpoche', 'Birth Anniversary of Guru Rinpoche celebration', '2026-06-24', '09:00:00', '12:00:00', 'BBCC Hall', NULL, NULL, 'Available'),
-- Row 15: Jun 29, Monday – Ekazati Tshokor → Booked
('Ekazati Tshokor',          'Regular prayer session',              '2026-06-29', '09:00:00', '12:00:00', 'BBCC Hall', 'འོང་གའགམའམའརྩོན་འོན་གའམའདའརོག་གཤ།', '0466 659 687', 'Booked'),
-- Row 16: Jul 11, Saturday – Tara and Menlha Dungdrup → Available (no sponsor)
('Tara and Menlha Dungdrup', 'Tara and Medicine Buddha prayer',     '2026-07-11', '09:00:00', '12:00:00', 'BBCC Hall', NULL, NULL, 'Available'),
-- Row 17: Jul 18, Saturday – First Sermon of Lord Buddha → Available (no sponsor)
('First Sermon of Lord Buddha', 'First Sermon of Lord Buddha commemoration', '2026-07-18', '09:00:00', '12:00:00', 'BBCC Hall', NULL, NULL, 'Available'),
-- Row 18: Jul 29, Wednesday – Ekazati Tshokor → Booked
('Ekazati Tshokor',          'Regular prayer session',              '2026-07-29', '09:00:00', '12:00:00', 'BBCC Hall', 'དམས་རེམའར་འདོག་རའདའརོག་གཤ།', NULL, 'Booked'),
-- Row 19: Aug 8, Saturday – Tara and Menlha Dungdrup → Booked
('Tara and Menlha Dungdrup', 'Tara and Medicine Buddha prayer',     '2026-08-08', '09:00:00', '12:00:00', 'BBCC Hall', 'རོད་དའངའརྨའརྟའའན།', '0451 762 917', 'Booked'),
-- Row 20: Aug 28, Friday – Ekazati Tshokor → Available (no sponsor)
('Ekazati Tshokor',          'Regular prayer session',              '2026-08-28', '09:00:00', '12:00:00', 'BBCC Hall', NULL, NULL, 'Available'),
-- Row 21: Sep 5, Saturday – Tara and Menlha Dungdrup → Available (no sponsor)
('Tara and Menlha Dungdrup', 'Tara and Medicine Buddha prayer',     '2026-09-05', '09:00:00', '12:00:00', 'BBCC Hall', NULL, NULL, 'Available'),
-- Row 22: Sep 26, Saturday – Ekazati Tshokor → Available (no sponsor)
('Ekazati Tshokor',          'Regular prayer session',              '2026-09-26', '09:00:00', '12:00:00', 'BBCC Hall', NULL, NULL, 'Available'),
-- Row 23: Oct 10, Monday – Tara and Menlha Dungdrup → Available (no sponsor)
('Tara and Menlha Dungdrup', 'Tara and Medicine Buddha prayer',     '2026-10-10', '09:00:00', '12:00:00', 'BBCC Hall', NULL, NULL, 'Available'),
-- Row 24: Oct 26, Monday – Ekazati Tshokor → Available (no sponsor)
('Ekazati Tshokor',          'Regular prayer session',              '2026-10-26', '09:00:00', '12:00:00', 'BBCC Hall', NULL, NULL, 'Available'),
-- Row 25: Nov 1, Sunday – Descending Day of Lord Buddha → Booked
('Descending Day of Lord Buddha', 'Descending Day of Lord Buddha celebration', '2026-11-01', '09:00:00', '12:00:00', 'BBCC Hall', 'འོང་རྩམའདོད་གའདའདའརའདོད།', '0435 008 955', 'Booked'),
-- Row 26: Nov 5, Saturday – Tara and Menlha Dungdrup → Available (no sponsor)
('Tara and Menlha Dungdrup', 'Tara and Medicine Buddha prayer',     '2026-11-05', '09:00:00', '12:00:00', 'BBCC Hall', NULL, NULL, 'Available'),
-- Row 27: Nov 24, Tuesday – Ekazati Tshokor → Available (no sponsor)
('Ekazati Tshokor',          'Regular prayer session',              '2026-11-24', '09:00:00', '12:00:00', 'BBCC Hall', NULL, NULL, 'Available'),
-- Row 28: Dec 24, Thursday – Ekazati Tshokor → Available (no sponsor)
('Ekazati Tshokor',          'Regular prayer session',              '2026-12-24', '09:00:00', '12:00:00', 'BBCC Hall', NULL, NULL, 'Available'),
-- Row 29: Dec 27, Saturday – Tara and Menlha Dungdrup → Available (no sponsor)
('Tara and Menlha Dungdrup', 'Tara and Medicine Buddha prayer',     '2026-12-27', '09:00:00', '12:00:00', 'BBCC Hall', NULL, NULL, 'Available');
