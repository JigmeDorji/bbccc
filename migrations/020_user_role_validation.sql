-- Migration 020: roles reference table + validation on user.role
--
-- user.role was a free-text VARCHAR with no lookup table or constraint,
-- which allowed a malformed value ('Administrator ' with a trailing space)
-- to exist undetected in production. This adds a documented canonical role
-- list and a CHECK constraint that mirrors the case/separator tolerance
-- already implemented at runtime by bbcc_normalize_role_key() in
-- include/module_access.php, so it rejects genuine typos/garbage without
-- rejecting anything the app already treats as valid.

CREATE TABLE IF NOT EXISTS `roles` (
    `role_key` VARCHAR(50) NOT NULL,
    `display_name` VARCHAR(100) NOT NULL,
    `is_superadmin` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`role_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `roles` (`role_key`, `display_name`, `is_superadmin`) VALUES
('Administrator', 'Administrator', 1),
('System_owner', 'System Owner', 1),
('Admin', 'Admin', 0),
('Company Admin', 'Company Admin', 0),
('Staff', 'Staff', 0),
('Website Admin', 'Website Admin', 0),
('teacher', 'Teacher', 0),
('parent', 'Parent', 0),
('patron', 'Patron', 0);

DROP PROCEDURE IF EXISTS _bbcc_migration_020;
DELIMITER $$
CREATE PROCEDURE _bbcc_migration_020()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'user'
          AND CONSTRAINT_NAME = 'chk_user_role'
    ) THEN
        ALTER TABLE `user` ADD CONSTRAINT `chk_user_role` CHECK (
            REPLACE(REPLACE(LOWER(TRIM(`role`)), '-', ' '), '_', ' ') IN (
                'administrator', 'system owner', 'admin', 'company admin', 'staff',
                'website admin', 'teacher', 'parent', 'patron'
            )
        );
    END IF;
END$$
DELIMITER ;
CALL _bbcc_migration_020();
DROP PROCEDURE IF EXISTS _bbcc_migration_020;
