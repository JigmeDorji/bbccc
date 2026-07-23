-- Keep authentication credentials exclusively in `user`.
-- Backfill the stable parent/account link before removing the duplicate hash.

DROP PROCEDURE IF EXISTS bbcc_migrate_parent_credentials;

DELIMITER $$
CREATE PROCEDURE bbcc_migrate_parent_credentials()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'parents'
          AND COLUMN_NAME = 'user_id'
    ) THEN
        ALTER TABLE `parents`
            ADD COLUMN `user_id` VARCHAR(50) NULL AFTER `id`;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'parents'
          AND INDEX_NAME = 'idx_parents_user_id'
    ) THEN
        CREATE INDEX `idx_parents_user_id` ON `parents` (`user_id`);
    END IF;

    -- Provision a login identity for imported parent profiles that do not have
    -- one yet. Existing parent hashes are preserved during the transition.
    -- Rows without a prior password receive an unusable value and must use
    -- Forgot Password to establish their first password.
    IF EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'parents'
          AND COLUMN_NAME = 'password'
    ) THEN
        SET @bbcc_parent_user_sql = '
            INSERT INTO `user` (userid, username, password, role, createdDate)
            SELECT
                CONCAT(''PM'', LPAD(p.id, 12, ''0'')),
                LOWER(TRIM(p.email)),
                COALESCE(
                    NULLIF(p.password, ''''),
                    CONCAT(''$disabled$'', SHA2(CONCAT(UUID(), p.id, p.email), 256))
                ),
                ''parent'',
                NOW()
            FROM `parents` p
            LEFT JOIN `user` u_email
                ON LOWER(u_email.username) = LOWER(TRIM(p.email))
            LEFT JOIN `user` u_id
                ON u_id.userid = CONCAT(''PM'', LPAD(p.id, 12, ''0''))
            WHERE p.email IS NOT NULL
              AND TRIM(p.email) <> ''''
              AND u_email.userid IS NULL
              AND u_id.userid IS NULL
        ';
        PREPARE bbcc_parent_user_stmt FROM @bbcc_parent_user_sql;
        EXECUTE bbcc_parent_user_stmt;
        DEALLOCATE PREPARE bbcc_parent_user_stmt;
    ELSE
        INSERT INTO `user` (userid, username, password, role, createdDate)
        SELECT
            CONCAT('PM', LPAD(p.id, 12, '0')),
            LOWER(TRIM(p.email)),
            CONCAT('$disabled$', SHA2(CONCAT(UUID(), p.id, p.email), 256)),
            'parent',
            NOW()
        FROM `parents` p
        LEFT JOIN `user` u_email
            ON LOWER(u_email.username) = LOWER(TRIM(p.email))
        LEFT JOIN `user` u_id
            ON u_id.userid = CONCAT('PM', LPAD(p.id, 12, '0'))
        WHERE p.email IS NOT NULL
          AND TRIM(p.email) <> ''
          AND u_email.userid IS NULL
          AND u_id.userid IS NULL;
    END IF;

    UPDATE `parents` p
    LEFT JOIN `user` u_email
        ON LOWER(u_email.username) = LOWER(p.email)
    LEFT JOIN `user` u_username
        ON LOWER(u_username.username) = LOWER(p.username)
    SET p.user_id = COALESCE(NULLIF(p.user_id, ''), u_email.userid, u_username.userid)
    WHERE p.user_id IS NULL OR p.user_id = '';

    IF EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'parents'
          AND COLUMN_NAME = 'password'
    ) THEN
        ALTER TABLE `parents` DROP COLUMN `password`;
    END IF;
END$$
DELIMITER ;

CALL bbcc_migrate_parent_credentials();
DROP PROCEDURE IF EXISTS bbcc_migrate_parent_credentials;
