-- 013_website_content_tables.sql
-- Create missing website content tables and patch newer columns needed by admin/public pages.

CREATE TABLE IF NOT EXISTS `school_content` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `description` LONGTEXT NULL,
    `imgUrl` VARCHAR(255) NULL,
    `students_count` VARCHAR(60) NULL,
    `teachers_count` VARCHAR(60) NULL,
    `campuses_count` VARCHAR(60) NULL,
    `year_levels` VARCHAR(120) NULL,
    `stats_heading` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tara_content` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(255) NULL,
    `subtitle` VARCHAR(255) NULL,
    `intro_text` LONGTEXT NULL,
    `body_text` LONGTEXT NULL,
    `schedule_text` TEXT NULL,
    `monthly_text` TEXT NULL,
    `contact_text` TEXT NULL,
    `imgUrl` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `download_files` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `original_name` VARCHAR(255) NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sponsor_settings` (
    `id` TINYINT UNSIGNED NOT NULL,
    `icon_one` VARCHAR(80) NULL,
    `icon_two` VARCHAR(80) NULL,
    `icon_three` VARCHAR(80) NULL,
    `image_one` VARCHAR(255) NULL,
    `image_two` VARCHAR(255) NULL,
    `image_three` VARCHAR(255) NULL,
    `intro_text` LONGTEXT NULL,
    `title_one` VARCHAR(255) NULL,
    `title_two` VARCHAR(255) NULL,
    `title_three` VARCHAR(255) NULL,
    `date_one` VARCHAR(255) NULL,
    `date_two` VARCHAR(255) NULL,
    `date_three` VARCHAR(255) NULL,
    `detail_one` LONGTEXT NULL,
    `detail_two` LONGTEXT NULL,
    `detail_three` LONGTEXT NULL,
    `detail_image_one` VARCHAR(255) NULL,
    `detail_image_two` VARCHAR(255) NULL,
    `detail_image_three` VARCHAR(255) NULL,
    `style_one` VARCHAR(30) NULL,
    `style_two` VARCHAR(30) NULL,
    `style_three` VARCHAR(30) NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure download_files.original_name exists for friendly download filenames.
SET @has_download_original_name := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'download_files'
    AND COLUMN_NAME = 'original_name'
);
SET @sql_download_original_name := IF(
  @has_download_original_name = 0,
  "ALTER TABLE `download_files` ADD COLUMN `original_name` VARCHAR(255) NULL AFTER `description`",
  "SET @bbcc_download_original_name_exists := 1"
);
PREPARE stmt_download_original_name FROM @sql_download_original_name;
EXECUTE stmt_download_original_name;
DEALLOCATE PREPARE stmt_download_original_name;

-- Ensure banner.sort_order exists for drag/drop ordering.
SET @has_banner_sort_order := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'banner'
    AND COLUMN_NAME = 'sort_order'
);
SET @sql_banner_sort_order := IF(
  @has_banner_sort_order = 0,
  "ALTER TABLE `banner` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0 AFTER `imgUrl`",
  "SET @bbcc_banner_sort_order_exists := 1"
);
PREPARE stmt_banner_sort_order FROM @sql_banner_sort_order;
EXECUTE stmt_banner_sort_order;
DEALLOCATE PREPARE stmt_banner_sort_order;

-- Ensure ourteam.member_type exists for Board/Executive split.
SET @has_ourteam_member_type := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ourteam'
    AND COLUMN_NAME = 'member_type'
);
SET @sql_ourteam_member_type := IF(
  @has_ourteam_member_type = 0,
  "ALTER TABLE `ourteam` ADD COLUMN `member_type` VARCHAR(30) NOT NULL DEFAULT 'executive' AFTER `designation`",
  "SET @bbcc_ourteam_member_type_exists := 1"
);
PREPARE stmt_ourteam_member_type FROM @sql_ourteam_member_type;
EXECUTE stmt_ourteam_member_type;
DEALLOCATE PREPARE stmt_ourteam_member_type;

-- Ensure BLCS schedule settings table exists (avoid runtime CREATE/ALTER cost).
CREATE TABLE IF NOT EXISTS `blcs_schedule_settings` (
    `id` TINYINT UNSIGNED NOT NULL,
    `intro_text` TEXT NULL,
    `terms_text` TEXT NULL,
    `sunday_dates_text` LONGTEXT NULL,
    `page_text` LONGTEXT NULL,
    `highlight_text` TEXT NULL,
    `updated_by` VARCHAR(190) NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
