-- Keep authentication credentials exclusively in `user`.
--
-- This migration intentionally uses plain SQL only. Shared cPanel database
-- users commonly do not have CREATE ROUTINE permission, so a stored procedure
-- would leave the migration pending.
--
-- Migration 007 has already added parents.user_id and its index. The
-- transitional parents.password column is retained in 000_schema until this
-- migration runs, which keeps fresh installations compatible as well.

-- Provision a login identity for imported parent profiles that do not have
-- one yet. Existing hashes are preserved. A profile without a prior password
-- receives an unusable value and must use Forgot Password to establish one.
INSERT INTO `user` (userid, username, password, role, createdDate)
SELECT
    CONCAT('PM', LPAD(p.id, 12, '0')),
    LOWER(TRIM(p.email)),
    COALESCE(
        NULLIF(p.password, ''),
        CONCAT('$disabled$', SHA2(CONCAT(UUID(), p.id, p.email), 256))
    ),
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

-- Prefer an existing explicit link; otherwise match the login by parent email
-- or legacy parent username.
UPDATE `parents` p
LEFT JOIN `user` u_email
    ON LOWER(u_email.username) = LOWER(p.email)
LEFT JOIN `user` u_username
    ON LOWER(u_username.username) = LOWER(p.username)
SET p.user_id = COALESCE(NULLIF(p.user_id, ''), u_email.userid, u_username.userid)
WHERE p.user_id IS NULL OR p.user_id = '';

-- Credentials now live only in `user`.
ALTER TABLE `parents` DROP COLUMN `password`;
