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

changes

