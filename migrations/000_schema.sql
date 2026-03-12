-- ============================================================
-- 000_schema.sql — Core BBCCC Database Schema
-- ============================================================

-- ------------------------------------------------------------
-- company
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `company` (
    `companyID`      VARCHAR(50)  NOT NULL,
    `companyName`    VARCHAR(150) NOT NULL,
    `address`        TEXT         DEFAULT NULL,
    `contactEmail`   VARCHAR(150) DEFAULT NULL,
    `contactPhone`   VARCHAR(50)  DEFAULT NULL,
    `contact_person` VARCHAR(150) DEFAULT NULL,
    `gst`            VARCHAR(50)  DEFAULT NULL,
    `createdAt`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`companyID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- project
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `project` (
    `projectID`      VARCHAR(50)  NOT NULL,
    `companyID`      VARCHAR(50)  DEFAULT NULL,
    `projectName`    VARCHAR(150) NOT NULL,
    `projectAddress` TEXT         DEFAULT NULL,
    `deadline`       DATE         DEFAULT NULL,
    `remarks`        TEXT         DEFAULT NULL,
    `createdAt`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`projectID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- user
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user` (
    `userid`      VARCHAR(50)   NOT NULL,
    `username`    VARCHAR(150)  NOT NULL,
    `password`    VARCHAR(255)  NOT NULL,
    `companyID`   VARCHAR(50)   DEFAULT NULL,
    `projectID`   VARCHAR(50)   DEFAULT NULL,
    `role`        VARCHAR(50)   DEFAULT NULL,
    `createdDate` DATETIME      DEFAULT NULL,
    PRIMARY KEY (`userid`),
    UNIQUE KEY `uq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- parents
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `parents` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    `full_name`  VARCHAR(150) NOT NULL,
    `gender`     VARCHAR(20)  DEFAULT NULL,
    `email`      VARCHAR(150) DEFAULT NULL,
    `phone`      VARCHAR(50)  DEFAULT NULL,
    `address`    TEXT         DEFAULT NULL,
    `username`   VARCHAR(150) DEFAULT NULL,
    `password`   VARCHAR(255) DEFAULT NULL,
    `pin_hash`   VARCHAR(255) DEFAULT NULL,
    `status`     ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_parent_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- parent_profile_update_log
CREATE TABLE IF NOT EXISTS `parent_profile_update_log` (
    `id`         INT NOT NULL AUTO_INCREMENT,
    `parent_id`  INT DEFAULT NULL,
    `field`      VARCHAR(100) DEFAULT NULL,
    `old_value`  TEXT DEFAULT NULL,
    `new_value`  TEXT DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- students
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `students` (
    `id`                 INT          NOT NULL AUTO_INCREMENT,
    `student_id`         VARCHAR(50)  DEFAULT NULL,
    `student_name`       VARCHAR(150) NOT NULL,
    `dob`                DATE         DEFAULT NULL,
    `gender`             VARCHAR(20)  DEFAULT NULL,
    `medical_issue`      TEXT         DEFAULT NULL,
    `class_option`       VARCHAR(100) DEFAULT NULL,
    `registration_date`  DATE         DEFAULT NULL,
    `approval_status`    ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    `parentId`           INT          DEFAULT NULL,
    `parent_id`          INT          DEFAULT NULL,
    `status`             VARCHAR(20)  DEFAULT 'Active',
    `created_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- teachers
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `teachers` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    `user_id`    VARCHAR(50)  DEFAULT NULL,
    `full_name`  VARCHAR(150) NOT NULL,
    `email`      VARCHAR(150) DEFAULT NULL,
    `phone`      VARCHAR(50)  DEFAULT NULL,
    `active`     TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- classes
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `classes` (
    `id`            INT          NOT NULL AUTO_INCREMENT,
    `class_name`    VARCHAR(150) NOT NULL,
    `description`   TEXT         DEFAULT NULL,
    `capacity`      INT          DEFAULT NULL,
    `schedule_text` VARCHAR(255) DEFAULT NULL,
    `teacher_id`    INT          DEFAULT NULL,
    `active`        TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- class_assignments
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `class_assignments` (
    `id`          INT       NOT NULL AUTO_INCREMENT,
    `class_id`    INT       NOT NULL,
    `student_id`  INT       NOT NULL,
    `assigned_by` VARCHAR(50) DEFAULT NULL,
    `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- attendance
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance` (
    `id`              INT          NOT NULL AUTO_INCREMENT,
    `class_id`        INT          DEFAULT NULL,
    `student_id`      INT          DEFAULT NULL,
    `teacher_id`      INT          DEFAULT NULL,
    `attendance_date` DATE         DEFAULT NULL,
    `status`          ENUM('Present','Absent','Late','Excused') NOT NULL DEFAULT 'Present',
    `notes`           TEXT         DEFAULT NULL,
    `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- fees_settings
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `fees_settings` (
    `id`                 INT            NOT NULL DEFAULT 1,
    `bank_name`          VARCHAR(150)   DEFAULT NULL,
    `account_name`       VARCHAR(150)   DEFAULT NULL,
    `bsb`                VARCHAR(20)    DEFAULT NULL,
    `account_number`     VARCHAR(40)    DEFAULT NULL,
    `bank_notes`         TEXT           DEFAULT NULL,
    `due_term1`          DATE           DEFAULT NULL,
    `due_term2`          DATE           DEFAULT NULL,
    `due_term3`          DATE           DEFAULT NULL,
    `due_term4`          DATE           DEFAULT NULL,
    `amount_termwise`    DECIMAL(10,2)  DEFAULT 0.00,
    `amount_halfyearly`  DECIMAL(10,2)  DEFAULT 0.00,
    `amount_yearly`      DECIMAL(10,2)  DEFAULT 0.00,
    `updated_at`         TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed a default fees_settings row
INSERT IGNORE INTO `fees_settings` (`id`) VALUES (1);

-- ------------------------------------------------------------
-- fees_payments
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `fees_payments` (
    `id`                 INT            NOT NULL AUTO_INCREMENT,
    `student_id`         INT            DEFAULT NULL,
    `plan_type`          VARCHAR(50)    DEFAULT NULL,
    `installment_code`   VARCHAR(50)    DEFAULT NULL,
    `due_amount`         DECIMAL(10,2)  DEFAULT 0.00,
    `status`             ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    `proof_path`         VARCHAR(255)   DEFAULT NULL,
    `payment_reference`  VARCHAR(150)   DEFAULT NULL,
    `created_at`         TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- payments  (manual proof uploads)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
    `id`          INT           NOT NULL AUTO_INCREMENT,
    `student_id`  INT           DEFAULT NULL,
    `parent_id`   INT           DEFAULT NULL,
    `amount`      DECIMAL(10,2) DEFAULT NULL,
    `proof_path`  VARCHAR(255)  DEFAULT NULL,
    `status`      ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    `uploaded_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- account_head_type
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `account_head_type` (
    `id`       INT          NOT NULL AUTO_INCREMENT,
    `typeName` VARCHAR(100) NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `account_head_type` (`id`, `typeName`) VALUES
(1,'Assets'), (2,'Liabilities'), (3,'Equity'), (4,'Revenue'), (5,'Expenses');

-- ------------------------------------------------------------
-- account_head
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `account_head` (
    `id`                INT          NOT NULL AUTO_INCREMENT,
    `accountHeadName`   VARCHAR(150) NOT NULL,
    `accountHeadTypeID` INT          DEFAULT NULL,
    `companyID`         VARCHAR(50)  DEFAULT NULL,
    `projectID`         VARCHAR(50)  DEFAULT NULL,
    `createdAt`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- account_head_sub
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `account_head_sub` (
    `id`              INT          NOT NULL AUTO_INCREMENT,
    `subAccountName`  VARCHAR(150) NOT NULL,
    `accountHeadID`   INT          DEFAULT NULL,
    `companyID`       VARCHAR(50)  DEFAULT NULL,
    `projectID`       VARCHAR(50)  DEFAULT NULL,
    `createdAt`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- journal_entry
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `journal_entry` (
    `id`               INT            NOT NULL AUTO_INCREMENT,
    `date`             DATE           DEFAULT NULL,
    `accountHeadID`    INT            DEFAULT NULL,
    `subAccountHeadID` INT            DEFAULT NULL,
    `description`      TEXT           DEFAULT NULL,
    `refNo`            VARCHAR(100)   DEFAULT NULL,
    `amount`           DECIMAL(12,2)  DEFAULT 0.00,
    `remarks`          TEXT           DEFAULT NULL,
    `companyID`        VARCHAR(50)    DEFAULT NULL,
    `projectID`        VARCHAR(50)    DEFAULT NULL,
    `createdAt`        TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- banner
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `banner` (
    `id`       INT          NOT NULL AUTO_INCREMENT,
    `title`    VARCHAR(255) DEFAULT NULL,
    `subtitle` VARCHAR(255) DEFAULT NULL,
    `imgUrl`   VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- menu  (services / events shown on public site)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `menu` (
    `id`                 INT            NOT NULL AUTO_INCREMENT,
    `menuName`           VARCHAR(150)   NOT NULL,
    `menuDetail`         TEXT           DEFAULT NULL,
    `menuImgUrl`         VARCHAR(255)   DEFAULT NULL,
    `price`              DECIMAL(10,2)  DEFAULT NULL,
    `eventStartDateTime` DATETIME       DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- ourteam
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ourteam` (
    `id`          INT          NOT NULL AUTO_INCREMENT,
    `Name`        VARCHAR(150) NOT NULL,
    `designation` VARCHAR(150) DEFAULT NULL,
    `imgUrl`      VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- about
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `about` (
    `id`          INT  NOT NULL AUTO_INCREMENT,
    `description` TEXT DEFAULT NULL,
    `imgUrl`      VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- contact
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `contact` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(150) DEFAULT NULL,
    `email`      VARCHAR(150) DEFAULT NULL,
    `subject`    VARCHAR(255) DEFAULT NULL,
    `message`    TEXT         DEFAULT NULL,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- sign_in_out
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sign_in_out` (
    `id`            INT       NOT NULL AUTO_INCREMENT,
    `class_id`      INT       DEFAULT NULL,
    `student_id`    INT       DEFAULT NULL,
    `parent_id`     INT       DEFAULT NULL,
    `signed_in_at`  DATETIME  DEFAULT NULL,
    `signed_out_at` DATETIME  DEFAULT NULL,
    `note`          TEXT      DEFAULT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- password_resets
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    `user_email` VARCHAR(150) NOT NULL,
    `token_hash` VARCHAR(255) NOT NULL,
    `expires_at` DATETIME     DEFAULT NULL,
    `used_at`    DATETIME     DEFAULT NULL,
    `created_at` DATETIME     DEFAULT NULL,
    `request_ip` VARCHAR(50)  DEFAULT NULL,
    `user_agent` TEXT         DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Seed default admin user
-- username: admin@bbccc.com  password: Admin@1234
-- (bcrypt hash of Admin@1234)
-- ============================================================
INSERT IGNORE INTO `user` (`userid`, `username`, `password`, `role`, `createdDate`)
VALUES ('1', 'admin@bbccc.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin', NOW());
