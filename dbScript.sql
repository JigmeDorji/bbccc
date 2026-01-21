CREATE TABLE `about` (
                         `id` int NOT NULL AUTO_INCREMENT,
                         `description` longtext,
                         `imgUrl` varchar(200) DEFAULT NULL,
                         PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `account_head` (
                                `id` int NOT NULL AUTO_INCREMENT,
                                `accountHeadName` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                                `accountHeadTypeID` int NOT NULL,
                                `companyID` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
                                `projectID` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                                `parentID` int DEFAULT NULL,
                                PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `account_head_sub` (
                                    `id` int NOT NULL AUTO_INCREMENT,
                                    `subAccountName` varchar(255) NOT NULL,
                                    `accountHeadID` int NOT NULL,
                                    `companyID` int NOT NULL,
                                    `projectID` int NOT NULL,
                                    PRIMARY KEY (`id`),
                                    KEY `accountHeadID` (`accountHeadID`),
                                    CONSTRAINT `account_head_sub_ibfk_1` FOREIGN KEY (`accountHeadID`) REFERENCES `account_head` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `account_head_type` (
                                     `id` int NOT NULL AUTO_INCREMENT,
                                     `typeName` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
                                     PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `banner` (
                          `id` int NOT NULL AUTO_INCREMENT,
                          `imgUrl` varchar(200) DEFAULT NULL,
                          `title` varchar(200) DEFAULT NULL,
                          `subtitle` varchar(500) DEFAULT NULL,
                          PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `company` (
                           `companyID` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                           `companyName` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                           `address` text COLLATE utf8mb4_unicode_ci,
                           `contactEmail` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                           `contactPhone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                           `contact_person` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                           `gst` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                           `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                           PRIMARY KEY (`companyID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `contact` (
                           `id` int NOT NULL AUTO_INCREMENT,
                           `name` varchar(255) NOT NULL,
                           `email` varchar(255) NOT NULL,
                           `subject` varchar(255) NOT NULL,
                           `message` text NOT NULL,
                           PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `journal_entry` (
                                 `id` int NOT NULL AUTO_INCREMENT,
                                 `date` date NOT NULL,
                                 `accountHeadID` int NOT NULL,
                                 `description` varchar(255) NOT NULL,
                                 `refNo` varchar(100) NOT NULL,
                                 `amount` decimal(15,2) NOT NULL,
                                 `remarks` varchar(255) DEFAULT NULL,
                                 `companyID` varchar(50) NOT NULL,
                                 `projectID` varchar(100) DEFAULT NULL,
                                 `subAccountHeadID` int DEFAULT NULL,
                                 PRIMARY KEY (`id`),
                                 KEY `journal_entry_ibfk_sub` (`subAccountHeadID`),
                                 CONSTRAINT `journal_entry_ibfk_sub` FOREIGN KEY (`subAccountHeadID`) REFERENCES `account_head_sub` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `menu` (
                        `id` int NOT NULL AUTO_INCREMENT,
                        `menuName` varchar(200) DEFAULT NULL,
                        `menuDetail` longtext,
                        `menuImgUrl` varchar(200) DEFAULT NULL,
                        `price` decimal(18,2) DEFAULT NULL,
                        PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `order_items` (
                               `id` int NOT NULL AUTO_INCREMENT,
                               `order_id` int NOT NULL,
                               `menu_name` varchar(255) NOT NULL,
                               `quantity` int NOT NULL,
                               PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `orders` (
                          `id` int NOT NULL AUTO_INCREMENT,
                          `order_name` varchar(255) NOT NULL,
                          `order_date` date NOT NULL,
                          `viewed` tinyint(1) DEFAULT '0',
                          PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `ourteam` (
                           `id` int NOT NULL AUTO_INCREMENT,
                           `Name` varchar(200) DEFAULT NULL,
                           `designation` varchar(200) DEFAULT NULL,
                           `imgUrl` varchar(500) DEFAULT NULL,
                           PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `project` (
                           `projectID` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                           `companyID` varchar(50) NOT NULL,
                           `projectName` varchar(255) NOT NULL,
                           `projectAddress` text,
                           `deadline` date DEFAULT NULL,
                           `remarks` text,
                           PRIMARY KEY (`projectID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `user` (
                        `userid` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                        `username` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                        `password` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                        `companyID` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                        `projectID` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                        `role` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                        `createdDate` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                        PRIMARY KEY (`userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Create parents table

CREATE TABLE `parents` (
  `id` int NOT NULL AUTO_INCREMENT,
  `full_name` varchar(150) NOT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text,
  `occupation` varchar(100) DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Create students table

CREATE TABLE `students` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) NOT NULL,
  `student_name` varchar(150) NOT NULL,
  `dob` date DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `medical_issue` text,
  `class_option` varchar(30) DEFAULT NULL,
  `payment_plan` varchar(30) DEFAULT NULL,
  `payment_amount` decimal(10,2) DEFAULT NULL,
  `payment_reference` varchar(150) DEFAULT NULL,
  `payment_proof` varchar(255) DEFAULT NULL,
  `registration_date` date DEFAULT NULL,
  `approval_status` varchar(30) DEFAULT 'Pending',
  `parentId` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_id` (`student_id`),
  KEY `fk_students_parent` (`parentId`),
  CONSTRAINT `fk_students_parent` FOREIGN KEY (`parentId`) REFERENCES `parents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


-- Create password_reset table

CREATE TABLE `password_resets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_email` varchar(190) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `request_ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`user_email`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


-- Create parent_profile_update_log table

CREATE TABLE `parent_profile_update_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `parent_id` int NOT NULL,
  `updated_by_userid` varchar(10) NOT NULL,
  `updated_at` datetime NOT NULL,
  `old_data` json DEFAULT NULL,
  `new_data` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `parent_id` (`parent_id`),
  KEY `updated_by_userid` (`updated_by_userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SELECT * FROM bbcc_db.parent_profile_update_log;

-- Create parent_profile_audit table

CREATE TABLE `parent_profile_audit` (
  `id` int NOT NULL AUTO_INCREMENT,
  `parentId` int NOT NULL,
  `userId` varchar(10) DEFAULT NULL,
  `action` varchar(50) NOT NULL DEFAULT 'UPDATE_PROFILE',
  `changed_fields` text,
  `old_data` json DEFAULT NULL,
  `new_data` json DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;




-- Insert values here

INSERT INTO users
(userid, username, password, companyID, projectID, role, createdDate)
VALUES
(1, 'Admin', '$2y$12$INkIANLsUlItw45nxll2t.dAWV.QGNLqbaRQUKBGH.cUjt.VaPvNq', 1, 1, 'Administrator', '2025-07-21 10:58:51'),

(2, 'jigme', '$2y$12$BkfL3DQppxrU46c6zS5tD.0sCeChzJ2domBZ4tmHx..mwEIQblUu2', 2, 4, 'Admin', '2025-07-25 05:50:13'),

(3, 'sonam', '$2y$12$i.q0uRm0PMLJ3IBVm95EdOissq14I7qhwjI/FkAajMJkWNByRV8oC', 3, 3, 'Company Admin', '2025-07-25 08:20:24'),

(5, 'znk', '$2y$12$7KTIYfKqIAWRCihi50Cf0.nl.S0.EIs4DmPV6wqZ2RlGoeGgfiEdC', 1, 1, 'Staff', '2025-07-29 02:46:27');



INSERT INTO banners
(id, imgUrl, title, subtitle)
VALUES
(1,
 'uploads/banner/Gemini_Generated_Image_eenj50eenj50eenj.png',
 'A Spiritual and Cultural Home for the Bhutanese Communityy',
 'Offering regular rituals, teachings, and pastoral support while nurturing language, culture, and heritage for a thriving Bhutanese community in the ACT.y'
),

(2,
 'uploads/banner/Monk.png',
 'Preserving Bhutanese Identity & Culture',
 'BBCC offers weekly Bhutanese language and cultural classes for children, regular Dharma teachings, TARA practice, and Doenchoe sessions to preserve our unique heritage within the ACT’s diverse community.'
),

(5,
 'uploads/banner/Screenshot 2025-03-24 at 6.06.40 PM.png',
 'A Bhutanese Temple in Canberra',
 'BBCC plans to establish a Bhutanese Temple in Canberra as a vibrant centre for ceremonies, meditation, counselling, and cultural activities, promoting wellbeing and spiritual guidance for the community.'
);

-- Add any changes here




