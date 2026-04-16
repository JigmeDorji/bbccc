-- 008_team_member_types.sql
-- Add Board/Executive type support for About Us members

-- 1) Add member_type column if missing
SET @has_member_type := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ourteam'
    AND COLUMN_NAME = 'member_type'
);

SET @sql_add_member_type := IF(
  @has_member_type = 0,
  "ALTER TABLE `ourteam` ADD COLUMN `member_type` VARCHAR(30) NOT NULL DEFAULT 'executive' AFTER `designation`",
  "SET @bbcc_member_type_exists := 1"
);
PREPARE stmt_add_member_type FROM @sql_add_member_type;
EXECUTE stmt_add_member_type;
DEALLOCATE PREPARE stmt_add_member_type;

-- 2) Normalize existing rows (defensive)
UPDATE `ourteam`
SET `member_type` = 'executive'
WHERE `member_type` IS NULL OR TRIM(`member_type`) = '';

UPDATE `ourteam`
SET `member_type` = LOWER(TRIM(`member_type`));

UPDATE `ourteam`
SET `member_type` = 'executive'
WHERE `member_type` NOT IN ('board', 'executive');
