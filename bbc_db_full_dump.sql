-- ==========================================================
-- Full Database Dump: bbc_db
-- Generated: 2026-02-22 14:41:14
-- Server: MySQL 8.1.0
-- ==========================================================

CREATE DATABASE IF NOT EXISTS `bbc_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `bbc_db`;

-- MySQL dump 10.13  Distrib 9.3.0, for macos14.7 (arm64)
--
-- Host: localhost    Database: bbc_db
-- ------------------------------------------------------
-- Server version	8.1.0

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `about`
--

DROP TABLE IF EXISTS `about`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `about` (
  `id` int NOT NULL AUTO_INCREMENT,
  `description` longtext,
  `imgUrl` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `about`
--

LOCK TABLES `about` WRITE;
/*!40000 ALTER TABLE `about` DISABLE KEYS */;
INSERT INTO `about` VALUES (18,'Welcome to Zhe Mai Gaa, a haven where culinary artistry meets heartfelt hospitality. Our menu is a symphony of diverse and exquisite flavors, crafted with precision and care by our dedicated chefs. Step into our restaurant and embark on a gastronomic journey that promises to delight your palate and elevate your dining experience. Join us for an unforgettable meal that celebrates the essence of fine dining, offering a harmonious blend of taste, quality, and warmth in every dish. This is the new version Changes','uploads/abt-01.png'),(19,'Welcome to Tobay Tech Strategy, where innovation meets intelligent execution. We are a forward-thinking technology consultancy dedicated to transforming visions into impactful digital solutions. At the heart of our approach lies a deep understanding of business goals, powered by cutting-edge technologies and data-driven strategies. Whether it\'s streamlining operations, enhancing customer experiences, or driving sustainable growth, Tobay Tech Strategy offers a clear path to digital excellence. Step into a future shaped by strategic insight, technical mastery, and a passion for progress.\r\n\r\n','uploads/abt-01.png'),(20,'Welcome to Tobay Tech Strategy, where innovation meets intelligent execution. We are a forward-thinking technology consultancy dedicated to transforming visions into impactful digital solutions. At the heart of our approach lies a deep understanding of business goals, powered by cutting-edge technologies and data-driven strategies. Whether it\'s streamlining operations, enhancing customer experiences, or driving sustainable growth, Tobay Tech Strategy offers a clear path to digital excellence. Step into a future shaped by strategic insight, technical mastery, and a passion for progress. jasbdbasdjbasjdhb asjdajsd ajsbdjbas ajsdjasd jabhsdjasd iasbda  Test\r\n\r\n','uploads/abt-01.png'),(21,'The Bhutanese Buddhist and Cultural Centre (BBCC) is dedicated to providing spiritual guidance, cultural preservation, and pastoral support to Bhutanese residents in Canberra and nearby NSW towns. Our focus is on fostering unity, harmony, and the preservation of Bhutanese identity within a diverse community.\r\n\r\nBBCC offers weekly language and cultural classes for children, regular Dharma teachings, meditation sessions, and special programs such as TARA practice and Doenchoe. These services nurture spiritual growth, provide support during life’s challenges, and build a vibrant Bhutanese community in the ACT.','uploads/Gemini_Generated_Image_eenj50eenj50eenj.png'),(22,'The Bhutanese Buddhist and Cultural Centre (BBCC) is dedicated to providing spiritual guidance, cultural preservation, and pastoral support to Bhutanese residents in Canberra and nearby NSW towns. Our focus is on fostering unity, harmony, and the preservation of Bhutanese identity within a diverse community.\r\n\r\nBBCC offers weekly language and cultural classes for children, regular Dharma teachings, meditation sessions, and special programs such as TARA practice and Doenchoe. These services nurture spiritual growth, provide support during life’s challenges, and build a vibrant Bhutanese community in the ACT.','uploads/Gemini_Generated_Image_eenj50eenj50eenj.png'),(23,'The Bhutanese Buddhist and Cultural Centre (BBCC) is dedicated to providing spiritual guidance, cultural preservation, and pastoral support to Bhutanese residents in Canberra and nearby NSW towns. Our focus is on fostering unity, harmony, and the preservation of Bhutanese identity within a diverse community.\r\n\r\nBBCC offers weekly language and cultural classes for children, regular Dharma teachings, meditation sessions, and special programs such as TARA practice and Doenchoe. These services nurture spiritual growth, provide support during life’s challenges, and build a vibrant Bhutanese community in the ACT.','uploads/Gemini_Generated_Image_eenj50eenj50eenj.png'),(24,'The Bhutanese Buddhist and Cultural Centre (BBCC) is dedicated to providing spiritual guidance, cultural preservation, and pastoral support to Bhutanese residents in Canberra and nearby NSW towns. Our focus is on fostering unity, harmony, and the preservation of Bhutanese identity within a diverse community. <br>\r\n\r\nBBCC offers weekly language and cultural classes for children, regular Dharma teachings, meditation sessions, and special programs such as TARA practice and Doenchoe. These services nurture spiritual growth, provide support during life’s challenges, and build a vibrant Bhutanese community in the ACT.','uploads/Gemini_Generated_Image_eenj50eenj50eenj.png'),(25,'The Bhutanese Buddhist and Cultural Centre (BBCC) is dedicated to providing spiritual guidance, cultural preservation, and pastoral support to Bhutanese residents in Canberra and nearby NSW towns. Our focus is on fostering unity, harmony, and the preservation of Bhutanese identity within a diverse community. <br><br>\r\n\r\nBBCC offers weekly language and cultural classes for children, regular Dharma teachings, meditation sessions, and special programs such as TARA practice and Doenchoe. These services nurture spiritual growth, provide support during life’s challenges, and build a vibrant Bhutanese community in the ACT.','uploads/Gemini_Generated_Image_eenj50eenj50eenj.png'),(26,'The Bhutanese Buddhist and Cultural Centre (BBCC) is dedicated to providing spiritual guidance, cultural preservation, and pastoral support to Bhutanese residents in Canberra and nearby NSW towns. Our focus is on fostering unity, harmony, and the preservation of Bhutanese identity within a diverse community. <br><br>\r\n\r\nBBCC offers weekly language and cultural classes for children, regular Dharma teachings, meditation sessions, and special programs such as TARA practice and Doenchoe. These services nurture spiritual growth, provide support during life’s challenges, and build a vibrant Bhutanese community in the ACTR.','uploads/Gemini_Generated_Image_eenj50eenj50eenj.png');
/*!40000 ALTER TABLE `about` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `absence_requests`
--

DROP TABLE IF EXISTS `absence_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `absence_requests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `child_id` int NOT NULL COMMENT 'FK → students.id',
  `parent_id` int NOT NULL COMMENT 'FK → parents.id',
  `date` date NOT NULL,
  `reason` text NOT NULL,
  `status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `admin_note` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_absence_child` (`child_id`),
  KEY `idx_absence_parent` (`parent_id`),
  KEY `idx_absence_date` (`date`),
  CONSTRAINT `fk_absence_child` FOREIGN KEY (`child_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_absence_parent` FOREIGN KEY (`parent_id`) REFERENCES `parents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `absence_requests`
--

LOCK TABLES `absence_requests` WRITE;
/*!40000 ALTER TABLE `absence_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `absence_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `account_head`
--

DROP TABLE IF EXISTS `account_head`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_head` (
  `id` int NOT NULL AUTO_INCREMENT,
  `accountHeadName` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `accountHeadTypeID` int NOT NULL,
  `companyID` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `projectID` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parentID` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `account_head`
--

LOCK TABLES `account_head` WRITE;
/*!40000 ALTER TABLE `account_head` DISABLE KEYS */;
INSERT INTO `account_head` VALUES (1,'Advance from Client',1,'1','1',NULL),(2,'Running Account Bill',1,'1','1',NULL),(3,'Other Receipts',1,'1','1',NULL),(4,'Supplimentary Fund',1,'1','1',NULL),(5,'Material & Tools Purchase',2,'1','1',NULL),(6,'Labor Wages',2,'1','1',NULL),(7,'Subcontractor Payments',2,'1','1',NULL),(8,'Machinery Hire/Rents',2,'1','1',NULL),(9,'Fuel & Lubricants',2,'1','1',NULL),(10,'Carriage/Transport Charges',2,'1','1',NULL),(11,'House/Office Rent',3,'1','1',NULL),(12,'House/Office Supplies',3,'1','1',NULL),(13,'Admin Staff Salary & Allowance',3,'1','1',NULL),(14,'Site Utilities',3,'1','1',NULL),(15,'Taxes/Royalties/Permit Fees',3,'1','1',NULL),(16,'Telephone/Internet',3,'1','1',NULL),(17,'Site Stationery & Printing',3,'1','1',NULL),(18,'Food & Refreshment',3,'1','1',NULL),(19,'Repair & Maintenance',3,'1','1',NULL),(20,'Financing Costs',3,'1','1',NULL),(21,'Misc Site Expenses',3,'1','1',NULL),(30,'Reallotment Fund',3,'1','1',NULL),(31,'Staff Salary',2,'4','5',NULL),(32,'Test Head',2,'1','1',NULL),(33,'Salary',3,'1','6',NULL);
/*!40000 ALTER TABLE `account_head` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `account_head_sub`
--

DROP TABLE IF EXISTS `account_head_sub`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_head_sub` (
  `id` int NOT NULL AUTO_INCREMENT,
  `subAccountName` varchar(255) NOT NULL,
  `accountHeadID` int NOT NULL,
  `companyID` int NOT NULL,
  `projectID` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `accountHeadID` (`accountHeadID`),
  CONSTRAINT `account_head_sub_ibfk_1` FOREIGN KEY (`accountHeadID`) REFERENCES `account_head` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `account_head_sub`
--

LOCK TABLES `account_head_sub` WRITE;
/*!40000 ALTER TABLE `account_head_sub` DISABLE KEYS */;
INSERT INTO `account_head_sub` VALUES (4,'Sonam Dorji',6,1,1),(5,'Tashi Dhendup',6,1,1);
/*!40000 ALTER TABLE `account_head_sub` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `account_head_type`
--

DROP TABLE IF EXISTS `account_head_type`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_head_type` (
  `id` int NOT NULL AUTO_INCREMENT,
  `typeName` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `account_head_type`
--

LOCK TABLES `account_head_type` WRITE;
/*!40000 ALTER TABLE `account_head_type` DISABLE KEYS */;
INSERT INTO `account_head_type` VALUES (1,'INCOME/RECEIPTS'),(2,'DIRECT EXPENSES'),(3,'INDIRECT EXPENSES');
/*!40000 ALTER TABLE `account_head_type` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance`
--

DROP TABLE IF EXISTS `attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `class_id` int NOT NULL,
  `student_id` int NOT NULL,
  `teacher_id` int NOT NULL,
  `attendance_date` date NOT NULL,
  `status` varchar(20) NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `marked_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_attendance_day` (`class_id`,`student_id`,`attendance_date`),
  KEY `idx_attendance_teacher` (`teacher_id`),
  KEY `fk_attendance_student` (`student_id`),
  CONSTRAINT `fk_attendance_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attendance_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attendance_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance`
--

LOCK TABLES `attendance` WRITE;
/*!40000 ALTER TABLE `attendance` DISABLE KEYS */;
INSERT INTO `attendance` VALUES (2,5,10,3,'2026-02-19','Present',NULL,'2026-02-21 22:54:31'),(3,5,9,3,'2026-02-19','Present',NULL,'2026-02-21 22:54:31'),(4,5,10,3,'2026-02-20','Present',NULL,'2026-02-21 22:54:59'),(5,5,9,3,'2026-02-20','Absent',NULL,'2026-02-21 22:54:59');
/*!40000 ALTER TABLE `attendance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance_kiosk`
--

DROP TABLE IF EXISTS `attendance_kiosk`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_kiosk` (
  `id` int NOT NULL AUTO_INCREMENT,
  `child_id` int NOT NULL COMMENT 'FK → students.id',
  `parent_id` int NOT NULL COMMENT 'FK → parents.id',
  `date` date NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `method` enum('KIOSK','ADMIN') NOT NULL DEFAULT 'KIOSK',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_kiosk_child_date` (`child_id`,`date`),
  KEY `idx_kiosk_child` (`child_id`),
  KEY `idx_kiosk_parent` (`parent_id`),
  KEY `idx_kiosk_date` (`date`),
  CONSTRAINT `fk_kiosk_child` FOREIGN KEY (`child_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_kiosk_parent` FOREIGN KEY (`parent_id`) REFERENCES `parents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_kiosk`
--

LOCK TABLES `attendance_kiosk` WRITE;
/*!40000 ALTER TABLE `attendance_kiosk` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance_kiosk` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bank_details`
--

DROP TABLE IF EXISTS `bank_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `bank_name` varchar(120) NOT NULL,
  `account_name` varchar(120) NOT NULL,
  `account_number` varchar(40) NOT NULL,
  `bsb` varchar(20) NOT NULL,
  `reference_text` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bank_details`
--

LOCK TABLES `bank_details` WRITE;
/*!40000 ALTER TABLE `bank_details` DISABLE KEYS */;
INSERT INTO `bank_details` VALUES (1,'Comm Bank','BBCC','2342342344','34234','Fee',1,'2026-02-19 11:52:48');
/*!40000 ALTER TABLE `bank_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `banner`
--

DROP TABLE IF EXISTS `banner`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `banner` (
  `id` int NOT NULL AUTO_INCREMENT,
  `imgUrl` varchar(200) DEFAULT NULL,
  `title` varchar(200) DEFAULT NULL,
  `subtitle` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `banner`
--

LOCK TABLES `banner` WRITE;
/*!40000 ALTER TABLE `banner` DISABLE KEYS */;
INSERT INTO `banner` VALUES (1,'uploads/banner/Gemini_Generated_Image_eenj50eenj50eenj.png','A Spiritual and Cultural Home for the Bhutanese Community','Offering regular rituals, teachings, and pastoral support while nurturing language, culture, and heritage for a thriving Bhutanese community in the ACT.'),(2,'uploads/banner/Monk.png','Preserving Bhutanese Identity & Culture','BBCC offers weekly Bhutanese language and cultural classes for children, regular\r\n                                    Dharma teachings, TARA practice, and Doenchoe sessions to preserve our unique\r\n                                    heritage within the ACT’s diverse community.');
/*!40000 ALTER TABLE `banner` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bookings`
--

DROP TABLE IF EXISTS `bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bookings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `event_id` int NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci,
  `status` enum('Pending','Approved','Rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_booking_event` (`event_id`),
  KEY `idx_booking_status` (`status`),
  CONSTRAINT `fk_booking_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bookings`
--

LOCK TABLES `bookings` WRITE;
/*!40000 ALTER TABLE `bookings` DISABLE KEYS */;
/*!40000 ALTER TABLE `bookings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `class_assignments`
--

DROP TABLE IF EXISTS `class_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `class_assignments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `class_id` int NOT NULL,
  `student_id` int NOT NULL,
  `assigned_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `assigned_by` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_assignment_student` (`student_id`),
  KEY `idx_assignment_class` (`class_id`),
  CONSTRAINT `fk_assignments_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assignments_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `class_assignments`
--

LOCK TABLES `class_assignments` WRITE;
/*!40000 ALTER TABLE `class_assignments` DISABLE KEYS */;
INSERT INTO `class_assignments` VALUES (4,5,10,'2026-02-21 22:53:58','1'),(5,5,9,'2026-02-21 22:54:06','1');
/*!40000 ALTER TABLE `class_assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `classes`
--

DROP TABLE IF EXISTS `classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `classes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `class_name` varchar(200) NOT NULL,
  `description` text,
  `capacity` int DEFAULT '0',
  `schedule_text` varchar(255) DEFAULT NULL,
  `teacher_id` int DEFAULT NULL,
  `active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_classes_teacher` (`teacher_id`),
  CONSTRAINT `fk_classes_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `classes`
--

LOCK TABLES `classes` WRITE;
/*!40000 ALTER TABLE `classes` DISABLE KEYS */;
INSERT INTO `classes` VALUES (5,'Dekin','Dekimn',150,'10-12',3,1,'2026-02-21 00:33:47'),(6,'Holt Belco','Belco',150,'10-12',4,1,'2026-02-21 00:34:17');
/*!40000 ALTER TABLE `classes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `company`
--

DROP TABLE IF EXISTS `company`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `company`
--

LOCK TABLES `company` WRITE;
/*!40000 ALTER TABLE `company` DISABLE KEYS */;
INSERT INTO `company` VALUES ('1','ZNK CONSTRUCTION PRIVATE LIMITED','123123','dorjijigme32@gmail.com','0404 902 044','Jigme Dorji','2323','2025-07-21 10:19:19'),('2','Z&K Construction Pvt. Ltd.','asdasd','wangmo.choki12@gmail.com','asdads','asdas','asdas','2025-07-21 10:25:48'),('3','Eassy Skill Pvt. Ltd.','Chubabchu','wangmo.choki12@gmail.com','0404902044','Choki Wangmo','2323','2025-07-25 03:06:04'),('4','Tobgay Tech Start Pvt Ltd','Babesa','dorjijigme32@gmail.com','0404902 044','Jigme Dorji','1234','2025-07-28 06:19:44');
/*!40000 ALTER TABLE `company` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contact`
--

DROP TABLE IF EXISTS `contact`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contact`
--

LOCK TABLES `contact` WRITE;
/*!40000 ALTER TABLE `contact` DISABLE KEYS */;
/*!40000 ALTER TABLE `contact` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `events`
--

DROP TABLE IF EXISTS `events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `event_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sponsors` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contacts` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Available','Pending Approval','Booked') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Available',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_event_date` (`event_date`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `events`
--

LOCK TABLES `events` WRITE;
/*!40000 ALTER TABLE `events` DISABLE KEYS */;
INSERT INTO `events` VALUES (1,'Ekazati Tshokor','Regular prayer session','2026-01-03','09:00:00','12:00:00','BBCC Hall','ག་གདང་འབའར་ཞོན།',NULL,'Booked','2026-02-13 13:39:11','2026-02-13 13:39:11'),(2,'Tara and Menlha Dungdrup','Tara and Medicine Buddha prayer','2026-01-17','09:00:00','12:00:00','BBCC Hall','བོ་རྡོ་རྗེ་འོས་སྐུར་རྗེ་རྟོན།','0402 096 551','Booked','2026-02-13 13:39:11','2026-02-13 13:55:27'),(3,'Ekazati Tshokor','Regular prayer session','2026-02-01','09:00:00','12:00:00','BBCC Hall','རྨས་གླིད་པའང་གདང་དའརོག་གཤ།','0422 403 307','Booked','2026-02-13 13:39:11','2026-02-13 13:39:11'),(4,'Tara and Menlha Dungdrup','Tara and Medicine Buddha prayer','2026-02-14','09:00:00','12:00:00','BBCC Hall','རོད་དའཆང་གོད་གའགམའམའརྨའཆའའརའདའའརའན།',NULL,'Booked','2026-02-13 13:39:11','2026-02-13 13:39:11'),(5,'Ekazati Tshokor','Regular prayer session','2026-03-03','09:00:00','12:00:00','BBCC Hall','རྫས་རྩའམའདོད་ཀྱི་རའདའརོག་གཤ།','0466 039 282','Booked','2026-02-13 13:39:11','2026-02-13 13:39:11'),(6,'Tara and Menlha Dungdrup','Tara and Medicine Buddha prayer','2026-03-14','09:00:00','12:00:00','BBCC Hall','འམས་གརའ་འོསའགཤའ་གདང་དའའབའའརའན།','0406 701 355','Booked','2026-02-13 13:39:11','2026-02-13 13:39:11'),(7,'Ekazati Tshokor','Regular prayer session','2026-04-01','09:00:00','12:00:00','BBCC Hall','འོང་གའགམའམའརྩའམའདོད་ཀྱི་རའདའརོག་གཤ།','0403 934 564','Booked','2026-02-13 13:39:11','2026-02-13 13:39:11'),(8,'Tara and Menlha Dungdrup','Tara and Medicine Buddha prayer','2026-04-11','09:00:00','12:00:00','BBCC Hall','འོང་གའགམའམའརོད་མའ་གདགའརའདའརའགའརྨའན།','0479 084 976','Booked','2026-02-13 13:39:11','2026-02-13 13:39:11'),(9,'Zhabdrung Kuchoe','Zhabdrung Kuchoe commemoration','2026-04-26','09:00:00','12:00:00','BBCC Hall','རྩགའརའདོད་གའརའདའརའན།','0424 567 774','Booked','2026-02-13 13:39:11','2026-02-13 13:39:11'),(10,'Ekazati Tshokor','Regular prayer session','2026-05-01','09:00:00','12:00:00','BBCC Hall','འབའརའརྩའམའརྟའའགའརའདའདའགཤའའན།','0499 783 244','Booked','2026-02-13 13:39:11','2026-02-13 13:39:11'),(11,'Tara and Menlha Dungdrup','Tara and Medicine Buddha prayer','2026-05-16','09:00:00','12:00:00','BBCC Hall','རྩོན་རའདོད་གའརའདའརའདའའན།','0466 196 214','Booked','2026-02-13 13:39:11','2026-02-13 13:39:11'),(12,'Lord Buddha\'s Parinirvana / Ekazati Tshokor','Lord Buddha\'s Parinirvana commemoration and Ekazati Tshokor','2026-05-31','09:00:00','12:00:00','BBCC Hall','རྩགའམའརོད་མའ་གདགའམརའདའརའན།','0422 331 106','Booked','2026-02-13 13:39:11','2026-02-13 13:39:11'),(13,'Tara and Menlha Dungdrup','Tara and Medicine Buddha prayer','2026-06-13','09:00:00','12:00:00','BBCC Hall','ནའམའརོད་ཀྱི་བུད་མའམའགའདའརོག་གཤ།','0452 144 279','Booked','2026-02-13 13:39:11','2026-02-13 13:39:11'),(14,'Birth Anniversary of Guru Rinpoche','Birth Anniversary of Guru Rinpoche celebration','2026-06-24','09:00:00','12:00:00','BBCC Hall',NULL,NULL,'Available','2026-02-13 13:39:11','2026-02-13 13:39:11'),(15,'Ekazati Tshokor','Regular prayer session','2026-06-29','09:00:00','12:00:00','BBCC Hall','འོང་གའགམའམའརྩོན་འོན་གའམའདའརོག་གཤ།','0466 659 687','Booked','2026-02-13 13:39:11','2026-02-13 13:39:11'),(16,'Tara and Menlha Dungdrup','Tara and Medicine Buddha prayer','2026-07-11','09:00:00','12:00:00','BBCC Hall',NULL,NULL,'Available','2026-02-13 13:39:11','2026-02-13 13:39:11'),(17,'First Sermon of Lord Buddha','First Sermon of Lord Buddha commemoration','2026-07-18','09:00:00','12:00:00','BBCC Hall',NULL,NULL,'Available','2026-02-13 13:39:11','2026-02-13 13:39:11'),(18,'Ekazati Tshokor','Regular prayer session','2026-07-29','09:00:00','12:00:00','BBCC Hall','དམས་རེམའར་འདོག་རའདའརོག་གཤ།',NULL,'Booked','2026-02-13 13:39:11','2026-02-13 13:39:11'),(19,'Tara and Menlha Dungdrup','Tara and Medicine Buddha prayer','2026-08-08','09:00:00','12:00:00','BBCC Hall','རོད་དའངའརྨའརྟའའན།','0451 762 917','Booked','2026-02-13 13:39:11','2026-02-13 13:39:11'),(20,'Ekazati Tshokor','Regular prayer session','2026-08-28','09:00:00','12:00:00','BBCC Hall',NULL,NULL,'Available','2026-02-13 13:39:11','2026-02-13 13:39:11'),(21,'Tara and Menlha Dungdrup','Tara and Medicine Buddha prayer','2026-09-05','09:00:00','12:00:00','BBCC Hall',NULL,NULL,'Available','2026-02-13 13:39:11','2026-02-13 13:39:11'),(22,'Ekazati Tshokor','Regular prayer session','2026-09-26','09:00:00','12:00:00','BBCC Hall',NULL,NULL,'Available','2026-02-13 13:39:11','2026-02-13 13:39:11'),(23,'Tara and Menlha Dungdrup','Tara and Medicine Buddha prayer','2026-10-10','09:00:00','12:00:00','BBCC Hall',NULL,NULL,'Available','2026-02-13 13:39:11','2026-02-13 13:39:11'),(24,'Ekazati Tshokor','Regular prayer session','2026-10-26','09:00:00','12:00:00','BBCC Hall',NULL,NULL,'Available','2026-02-13 13:39:11','2026-02-13 13:39:11'),(25,'Descending Day of Lord Buddha','Descending Day of Lord Buddha celebration','2026-11-01','09:00:00','12:00:00','BBCC Hall','འོང་རྩམའདོད་གའདའདའརའདོད།','0435 008 955','Booked','2026-02-13 13:39:11','2026-02-13 13:39:11'),(26,'Tara and Menlha Dungdrup','Tara and Medicine Buddha prayer','2026-11-05','09:00:00','12:00:00','BBCC Hall',NULL,NULL,'Available','2026-02-13 13:39:11','2026-02-13 13:39:11'),(27,'Ekazati Tshokor','Regular prayer session','2026-11-24','09:00:00','12:00:00','BBCC Hall',NULL,NULL,'Available','2026-02-13 13:39:11','2026-02-13 13:39:11'),(28,'Ekazati Tshokor','Regular prayer session','2026-12-24','09:00:00','12:00:00','BBCC Hall',NULL,NULL,'Available','2026-02-13 13:39:11','2026-02-13 13:39:11'),(29,'Tara and Menlha Dungdrup','Tara and Medicine Buddha prayer','2026-12-27','09:00:00','12:00:00','BBCC Hall',NULL,NULL,'Available','2026-02-13 13:39:11','2026-02-13 13:39:11');
/*!40000 ALTER TABLE `events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fees_payments`
--

DROP TABLE IF EXISTS `fees_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fees_payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` int NOT NULL,
  `parent_id` int NOT NULL,
  `plan_type` enum('TERM','HALF','YEAR') NOT NULL,
  `period_no` int NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `reference` varchar(255) NOT NULL,
  `proof_path` varchar(255) NOT NULL,
  `status` enum('Pending','Verified','Rejected') NOT NULL DEFAULT 'Pending',
  `submitted_at` datetime NOT NULL,
  `verified_at` datetime DEFAULT NULL,
  `verified_by` varchar(50) DEFAULT NULL,
  `reject_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_payment_period` (`student_id`,`plan_type`,`period_no`),
  KEY `idx_student` (`student_id`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_fee_parent` FOREIGN KEY (`parent_id`) REFERENCES `parents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fee_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fees_payments`
--

LOCK TABLES `fees_payments` WRITE;
/*!40000 ALTER TABLE `fees_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `fees_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fees_settings`
--

DROP TABLE IF EXISTS `fees_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fees_settings` (
  `id` int NOT NULL DEFAULT '1',
  `bank_name` varchar(120) DEFAULT NULL,
  `account_name` varchar(120) DEFAULT NULL,
  `bsb` varchar(20) DEFAULT NULL,
  `account_number` varchar(40) DEFAULT NULL,
  `bank_notes` varchar(255) DEFAULT NULL,
  `amount_termwise` decimal(10,2) NOT NULL DEFAULT '65.00',
  `amount_halfyearly` decimal(10,2) NOT NULL DEFAULT '125.00',
  `amount_yearly` decimal(10,2) NOT NULL DEFAULT '250.00',
  `due_term1` date DEFAULT NULL,
  `due_term2` date DEFAULT NULL,
  `due_term3` date DEFAULT NULL,
  `due_term4` date DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fees_settings`
--

LOCK TABLES `fees_settings` WRITE;
/*!40000 ALTER TABLE `fees_settings` DISABLE KEYS */;
INSERT INTO `fees_settings` VALUES (1,'Comm Bank','BBCC','34234','2342342344','Use reference exactly',65.00,125.00,250.00,'2026-02-11','2026-02-26','2026-03-19','2026-04-30','2026-02-21 12:49:22');
/*!40000 ALTER TABLE `fees_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `journal_entry`
--

DROP TABLE IF EXISTS `journal_entry`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `journal_entry`
--

LOCK TABLES `journal_entry` WRITE;
/*!40000 ALTER TABLE `journal_entry` DISABLE KEYS */;
INSERT INTO `journal_entry` VALUES (1,'2025-07-06',1,'Impress Fund from Tashi','aq',500000.00,'asda','1','1',NULL),(2,'2025-07-07',2,'PG Bank Charges','qwe',0.00,'aSD','1','1',NULL),(3,'2025-07-07',2,'MA Guarantee Bank Charges','qwe',0.00,'aSD','1','1',NULL),(4,'2025-07-14',3,'HSD Creta- Samtse-Phobsoo','44240',2270.00,'asd','1','1',NULL),(5,'2025-02-14',4,'Round seal','280',900.00,'asd','1','1',NULL),(6,'2025-03-14',5,'Refreshment for meet at Sheychatmhang','295',3865.00,'asD','1','1',NULL),(7,'2025-04-14',6,'Cement Loading Fee','Vr. 102',200.00,'aSD','1','1',NULL),(8,'2025-04-14',3,'HSD for BP-2-A8930 , Pling-Paro-Wangdue','13822',14013.00,'Tripper Lease Start Date','1','1',NULL),(9,'2025-04-14',7,'DA for Driver ( BP-2-A8930) Pling-Paro-Sarpang','Vr. 103',1500.00,'asd','1','1',NULL),(10,'2025-06-14',8,'Clearing Fee','599',570.00,'aSD','1','1',NULL),(11,'2025-06-14',6,'Loading Charges for Camp Material','Vr. 104',3300.00,'asd','2','1',NULL),(12,'2025-07-14',3,'HSD for BP-2-A8930 , Wangdue- Sarpang','7856',6346.00,'asD','2','1',NULL),(13,'2025-08-14',4,'Power Bank for Office','1519',4300.00,'','2','1',NULL),(14,'2025-08-14',9,'Gloves, broom, Lock & key','1511',1460.00,'','2','1',NULL),(15,'2025-08-14',5,'Groceries','7457',2755.00,'','2','1',NULL),(16,'2025-07-23',18,'ASDASD','asdas',2300.00,'123','2','1',NULL),(17,'2025-07-24',11,'asdasd','123123',1029.89,'asdads','1','1',NULL),(18,'2025-07-24',1,'asdasdasd','asdfasd',2300.00,'eeeee','1','1',NULL),(19,'2025-07-26',13,'sdasdv','asdvasdv',2300.00,'2000','3','3',NULL),(20,'2025-07-28',31,'Staff','RT23',200.00,'Test','4','5',NULL),(21,'2025-08-01',32,'M2','2323',250.00,'Test','1','1',NULL),(23,'2025-08-04',6,'Test Data','AD123',2300.00,'sdsdf','1','1',4),(24,'2025-08-04',6,'Wages','W1234',2300.00,'Test','1','1',5),(25,'2025-08-06',6,'ascas','ascas',2300.00,'asasd','1','1',4),(26,'2025-08-06',6,'asdasd','asdasd',2300.00,'asd','1','1',4),(27,'2025-08-06',1,'zxczxc','zxcxc',2300.00,'zxczxc','1','1',NULL),(28,'2025-08-06',10,'ascasca','asdfasd',1231.00,'1212','1','1',NULL),(29,'2025-08-06',6,'zxczxc','zxczx',1231.00,'casca','1','1',5),(30,'2025-08-05',10,'ascasc','asc',213.00,'a','1','1',NULL),(31,'2025-08-22',11,'asad','asdfasd',2300.00,'asdasd','1','1',NULL);
/*!40000 ALTER TABLE `journal_entry` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `kiosk_failed_attempts`
--

DROP TABLE IF EXISTS `kiosk_failed_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `kiosk_failed_attempts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `phone` varchar(20) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_kiosk_fail_phone` (`phone`),
  KEY `idx_kiosk_fail_ip` (`ip_address`),
  KEY `idx_kiosk_fail_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `kiosk_failed_attempts`
--

LOCK TABLES `kiosk_failed_attempts` WRITE;
/*!40000 ALTER TABLE `kiosk_failed_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `kiosk_failed_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `menu`
--

DROP TABLE IF EXISTS `menu`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `menu` (
  `id` int NOT NULL AUTO_INCREMENT,
  `menuName` varchar(200) DEFAULT NULL,
  `menuDetail` longtext,
  `menuImgUrl` varchar(200) DEFAULT NULL,
  `price` decimal(18,2) DEFAULT NULL,
  `eventStartDateTime` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `menu`
--

LOCK TABLES `menu` WRITE;
/*!40000 ALTER TABLE `menu` DISABLE KEYS */;
INSERT INTO `menu` VALUES (8,'Bhutanese Heritage Concert','The Bhutanese Centre (BBCC) is joyous to share that the first ever Bhutanese Heritage Concert by BLCS was a great success. It was a wonderful opportunity for our children to grow up groomed in our culture and heritage, and to help keep it alive through their voices, talents, and participation.','uploads/menu/Gemini_Generated_Image_eenj50eenj50eenj.png',NULL,'2025-11-03 09:30:00'),(9,'Multi-Project Switching','Lorem ipsum dolor sit amet, consectetur adip elit, sed do eiusmod tempor incididunt loren labore et dolore magna aliqua. Ut enim asan minim veniam, quis nostrud','uploads/menu/Gemini_Generated_Image_ta89u6ta89u6ta89.png',NULL,'2025-11-25 09:09:00');
/*!40000 ALTER TABLE `menu` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `order_items`
--

DROP TABLE IF EXISTS `order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_id` int NOT NULL,
  `menu_name` varchar(255) NOT NULL,
  `quantity` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `order_items`
--

LOCK TABLES `order_items` WRITE;
/*!40000 ALTER TABLE `order_items` DISABLE KEYS */;
INSERT INTO `order_items` VALUES (15,9,'Shu Kam Dhatse',1);
/*!40000 ALTER TABLE `order_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `orders`
--

DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_name` varchar(255) NOT NULL,
  `order_date` date NOT NULL,
  `viewed` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `orders`
--

LOCK TABLES `orders` WRITE;
/*!40000 ALTER TABLE `orders` DISABLE KEYS */;
INSERT INTO `orders` VALUES (9,'Jigme','2024-05-30',1),(10,'sdfsf','2025-08-06',1);
/*!40000 ALTER TABLE `orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ourteam`
--

DROP TABLE IF EXISTS `ourteam`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ourteam` (
  `id` int NOT NULL AUTO_INCREMENT,
  `Name` varchar(200) DEFAULT NULL,
  `designation` varchar(200) DEFAULT NULL,
  `imgUrl` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ourteam`
--

LOCK TABLES `ourteam` WRITE;
/*!40000 ALTER TABLE `ourteam` DISABLE KEYS */;
INSERT INTO `ourteam` VALUES (2,'Khenpo','Khenpo','uploads/ourteam/khenpo.jpg'),(3,'Tshering','Administration','uploads/ourteam/tshering.jpg'),(6,'Jigme Dorji','IT Support and Technical','uploads/ourteam/1771680264_WhatsAppImage2026-02-16at13.05.331.jpeg');
/*!40000 ALTER TABLE `ourteam` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `parents`
--

DROP TABLE IF EXISTS `parents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `parents` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` varchar(10) DEFAULT NULL,
  `full_name` varchar(200) NOT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `email` varchar(200) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `occupation` varchar(120) DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `pin_hash` varchar(255) DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_parent_username` (`username`),
  KEY `idx_parent_user` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `parents`
--

LOCK TABLES `parents` WRITE;
/*!40000 ALTER TABLE `parents` DISABLE KEYS */;
INSERT INTO `parents` VALUES (1,NULL,'Jigme Dorji','Male','dorjijigme32@gmail.com','0404 902 044','10 lamb PL, chiefly ACT',NULL,'dorjijigme32@gmail.com','$2y$12$UPaQco7j4Xngb6qqDedwuON6OGo/2hBpBX1DxoxNRY80D8UzWwQLG','$2y$12$BA8JucoJukyzQclDHuAIhe3E7R.1WVsZq/3jq0pkccAaZqzpsp3ja','Active','2026-02-19 12:19:03',NULL),(2,NULL,'Jigme Dorji','Female','dorji@gmail.com','+61411670179','71/116 Easty ST',NULL,'dorji@gmail.com','$2y$12$Zlmd4JLWH7Kqb/sFMP5K2u1sWLHIib3XtF7Gzw0G1DFG8cVFaQC1q',NULL,'Active','2026-02-20 12:21:38',NULL);
/*!40000 ALTER TABLE `parents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` int NOT NULL,
  `parent_id` int NOT NULL,
  `amount` decimal(10,2) DEFAULT '0.00',
  `currency` varchar(10) DEFAULT 'AUD',
  `proof_path` varchar(255) NOT NULL,
  `status` varchar(20) DEFAULT 'Pending',
  `notes` varchar(255) DEFAULT NULL,
  `uploaded_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `reviewed_by` varchar(10) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_payments_student` (`student_id`),
  KEY `idx_payments_parent` (`parent_id`),
  CONSTRAINT `fk_payments_parent` FOREIGN KEY (`parent_id`) REFERENCES `parents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payments_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pcm_absence_requests`
--

DROP TABLE IF EXISTS `pcm_absence_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pcm_absence_requests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `child_id` int NOT NULL,
  `parent_id` int NOT NULL,
  `absence_date` date NOT NULL,
  `reason` text NOT NULL,
  `status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `admin_note` varchar(500) DEFAULT NULL,
  `decided_by` varchar(100) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_abs_child` (`child_id`),
  KEY `idx_abs_parent` (`parent_id`),
  KEY `idx_abs_date` (`absence_date`),
  CONSTRAINT `fk_abs_child` FOREIGN KEY (`child_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_abs_parent` FOREIGN KEY (`parent_id`) REFERENCES `parents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pcm_absence_requests`
--

LOCK TABLES `pcm_absence_requests` WRITE;
/*!40000 ALTER TABLE `pcm_absence_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `pcm_absence_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pcm_bank_accounts`
--

DROP TABLE IF EXISTS `pcm_bank_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pcm_bank_accounts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `bank_name` varchar(120) NOT NULL,
  `account_name` varchar(120) NOT NULL,
  `bsb` varchar(20) NOT NULL,
  `account_number` varchar(40) NOT NULL,
  `reference_hint` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pcm_bank_accounts`
--

LOCK TABLES `pcm_bank_accounts` WRITE;
/*!40000 ALTER TABLE `pcm_bank_accounts` DISABLE KEYS */;
INSERT INTO `pcm_bank_accounts` VALUES (1,'Comm Bank','BBCC','34234','2342342344','qwqe',1,'2026-02-19 12:30:09','2026-02-19 12:30:09');
/*!40000 ALTER TABLE `pcm_bank_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pcm_enrolments`
--

DROP TABLE IF EXISTS `pcm_enrolments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pcm_enrolments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` int NOT NULL COMMENT 'FK students.id',
  `parent_id` int NOT NULL COMMENT 'FK parents.id',
  `class_id` int DEFAULT NULL,
  `fee_plan` enum('Term-wise','Half-yearly','Yearly') NOT NULL DEFAULT 'Term-wise',
  `fee_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `payment_ref` varchar(150) DEFAULT NULL,
  `proof_path` varchar(255) DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `admin_note` varchar(500) DEFAULT NULL,
  `reviewed_by` varchar(100) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_enrol_student` (`student_id`),
  KEY `idx_enrol_parent` (`parent_id`),
  KEY `idx_enrol_status` (`status`),
  KEY `idx_enrol_class` (`class_id`),
  CONSTRAINT `fk_enrol_parent` FOREIGN KEY (`parent_id`) REFERENCES `parents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_enrol_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pcm_enrolments`
--

LOCK TABLES `pcm_enrolments` WRITE;
/*!40000 ALTER TABLE `pcm_enrolments` DISABLE KEYS */;
INSERT INTO `pcm_enrolments` VALUES (5,9,1,5,'Term-wise',65.00,NULL,'uploads/enrolments/enrol_9_1771674505.png','Approved',NULL,'owner@gmail.com','2026-02-21 22:52:46','2026-02-21 11:48:25'),(6,10,1,5,'Half-yearly',125.00,'asdasd','uploads/enrolments/enrol_10_1771674571.png','Approved',NULL,'owner@gmail.com','2026-02-21 22:52:29','2026-02-21 11:49:31');
/*!40000 ALTER TABLE `pcm_enrolments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pcm_fee_payments`
--

DROP TABLE IF EXISTS `pcm_fee_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pcm_fee_payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `enrolment_id` int NOT NULL COMMENT 'FK pcm_enrolments.id',
  `student_id` int NOT NULL,
  `parent_id` int NOT NULL,
  `plan_type` enum('Term-wise','Half-yearly','Yearly') NOT NULL,
  `instalment_label` varchar(20) NOT NULL COMMENT 'Term 1, Half 1, Yearly, etc.',
  `due_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `paid_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `payment_ref` varchar(150) DEFAULT NULL,
  `proof_path` varchar(255) DEFAULT NULL,
  `status` enum('Unpaid','Pending','Verified','Rejected') NOT NULL DEFAULT 'Unpaid',
  `reject_reason` varchar(500) DEFAULT NULL,
  `verified_by` varchar(100) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fee_instalment` (`enrolment_id`,`instalment_label`),
  KEY `idx_fee_student` (`student_id`),
  KEY `idx_fee_parent` (`parent_id`),
  KEY `idx_fee_status` (`status`),
  CONSTRAINT `fk_fee_enrolment` FOREIGN KEY (`enrolment_id`) REFERENCES `pcm_enrolments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fee_parent2` FOREIGN KEY (`parent_id`) REFERENCES `parents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fee_student2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pcm_fee_payments`
--

LOCK TABLES `pcm_fee_payments` WRITE;
/*!40000 ALTER TABLE `pcm_fee_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `pcm_fee_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pcm_kiosk_failed`
--

DROP TABLE IF EXISTS `pcm_kiosk_failed`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pcm_kiosk_failed` (
  `id` int NOT NULL AUTO_INCREMENT,
  `phone` varchar(20) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fail_phone` (`phone`),
  KEY `idx_fail_ip` (`ip_address`),
  KEY `idx_fail_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pcm_kiosk_failed`
--

LOCK TABLES `pcm_kiosk_failed` WRITE;
/*!40000 ALTER TABLE `pcm_kiosk_failed` DISABLE KEYS */;
/*!40000 ALTER TABLE `pcm_kiosk_failed` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pcm_kiosk_log`
--

DROP TABLE IF EXISTS `pcm_kiosk_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pcm_kiosk_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `child_id` int NOT NULL COMMENT 'FK students.id',
  `parent_id` int NOT NULL COMMENT 'FK parents.id',
  `log_date` date NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `method` enum('KIOSK','MANUAL') NOT NULL DEFAULT 'KIOSK',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_kiosk_child_date` (`child_id`,`log_date`),
  KEY `idx_kiosk_parent` (`parent_id`),
  KEY `idx_kiosk_date` (`log_date`),
  CONSTRAINT `fk_kiosk_child2` FOREIGN KEY (`child_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_kiosk_parent2` FOREIGN KEY (`parent_id`) REFERENCES `parents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pcm_kiosk_log`
--

LOCK TABLES `pcm_kiosk_log` WRITE;
/*!40000 ALTER TABLE `pcm_kiosk_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `pcm_kiosk_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `project`
--

DROP TABLE IF EXISTS `project`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project` (
  `projectID` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `companyID` varchar(50) NOT NULL,
  `projectName` varchar(255) NOT NULL,
  `projectAddress` text,
  `deadline` date DEFAULT NULL,
  `remarks` text,
  PRIMARY KEY (`projectID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `project`
--

LOCK TABLES `project` WRITE;
/*!40000 ALTER TABLE `project` DISABLE KEYS */;
INSERT INTO `project` VALUES ('1','1','NHDCL Building Construction face ','Thimphu','2026-06-30','Test'),('3','2','School Student E-Learning Project ','Thimphu','2025-07-18','Test'),('4','2','NHDCL Building Construction face 2','Phuentsholing','2025-07-24','Test'),('5','4','System Management','Thimphu','2029-09-12','Construction project related data management '),('6','1','Bridge Construction ','asasd','2025-08-07','asdasd');
/*!40000 ALTER TABLE `project` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sign_in_out`
--

DROP TABLE IF EXISTS `sign_in_out`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sign_in_out` (
  `id` int NOT NULL AUTO_INCREMENT,
  `class_id` int NOT NULL,
  `student_id` int NOT NULL,
  `parent_id` int NOT NULL,
  `signed_in_at` datetime NOT NULL,
  `signed_out_at` datetime DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sign_class` (`class_id`),
  KEY `idx_sign_student` (`student_id`),
  KEY `idx_sign_parent` (`parent_id`),
  CONSTRAINT `fk_sign_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sign_parent` FOREIGN KEY (`parent_id`) REFERENCES `parents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sign_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sign_in_out`
--

LOCK TABLES `sign_in_out` WRITE;
/*!40000 ALTER TABLE `sign_in_out` DISABLE KEYS */;
/*!40000 ALTER TABLE `sign_in_out` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `students`
--

DROP TABLE IF EXISTS `students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `students` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` varchar(20) NOT NULL,
  `parentId` int NOT NULL,
  `student_name` varchar(200) NOT NULL,
  `dob` date DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `medical_issue` text,
  `class_option` varchar(255) DEFAULT NULL,
  `payment_plan` varchar(30) DEFAULT NULL,
  `payment_amount` decimal(10,2) DEFAULT NULL,
  `payment_reference` varchar(150) DEFAULT NULL,
  `payment_proof` varchar(255) DEFAULT NULL,
  `registration_date` date DEFAULT NULL,
  `approval_status` varchar(20) DEFAULT 'Pending',
  `status` varchar(20) DEFAULT 'Pending',
  `approved_by` varchar(10) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approval_note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_student_id` (`student_id`),
  KEY `idx_student_parent` (`parentId`),
  CONSTRAINT `fk_students_parent` FOREIGN KEY (`parentId`) REFERENCES `parents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `students`
--

LOCK TABLES `students` WRITE;
/*!40000 ALTER TABLE `students` DISABLE KEYS */;
INSERT INTO `students` VALUES (9,'BLCS0001',1,'Yangki Dema','2026-02-20','Female','None',NULL,NULL,NULL,NULL,NULL,'2026-02-21','Approved','Pending',NULL,NULL,NULL,'2026-02-21 11:45:20'),(10,'BLCS0002',1,'Thinlay','2026-02-14','Male','None',NULL,NULL,NULL,NULL,NULL,'2026-02-21','Approved','Pending',NULL,NULL,NULL,'2026-02-21 11:49:05');
/*!40000 ALTER TABLE `students` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `teachers`
--

DROP TABLE IF EXISTS `teachers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `teachers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` varchar(10) NOT NULL,
  `full_name` varchar(200) NOT NULL,
  `email` varchar(200) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `bio` text,
  `active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_teacher_user` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `teachers`
--

LOCK TABLES `teachers` WRITE;
/*!40000 ALTER TABLE `teachers` DISABLE KEYS */;
INSERT INTO `teachers` VALUES (3,'8','Tshering','t@gmail.com','0404 902 044',NULL,1,'2026-02-21 00:35:19'),(4,'9','Sonam Dorji','dorjijigme32@gmail.com','0404 902 044',NULL,1,'2026-02-21 00:37:55');
/*!40000 ALTER TABLE `teachers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user`
--

DROP TABLE IF EXISTS `user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user` (
  `userid` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `companyID` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `projectID` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `createdDate` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user`
--

LOCK TABLES `user` WRITE;
/*!40000 ALTER TABLE `user` DISABLE KEYS */;
INSERT INTO `user` VALUES ('1','owner@gmail.com','$2y$12$INkIANLsUlItw45nxll2t.dAWV.QGNLqbaRQUKBGH.cUjt.VaPvNq','1','1','Administrator ','2025-07-21 10:58:51'),('2','jigme','$2y$12$BkfL3DQppxrU46c6zS5tD.0sCeChzJ2domBZ4tmHx..mwEIQblUu2','2','4','Admin','2025-07-25 05:50:13'),('3','sonam','$2y$12$i.q0uRm0PMLJ3IBVm95EdOissq14I7qhwjI/FkAajMJkWNByRV8oC','3','3','Company Admin','2025-07-25 08:20:24'),('5','znk','$2y$12$7KTIYfKqIAWRCihi50Cf0.nl.S0.EIs4DmPV6wqZ2RlGoeGgfiEdC','1','1','Staff','2025-07-29 02:46:27'),('8','tshering','$2y$12$N0z.DBmBFpGtJXHM8.0VbOm9TOT9j1UuGXvnEzgA9ZlWUEmtIJLZu',NULL,NULL,'teacher','2026-02-21 00:35:19'),('9','sonam@gmail.com','$2y$12$SjGFUWu26hXzMtxmW61Gj.LdAgfXP9NMs2nx5.tVXa6WUpsEAGyHe',NULL,NULL,'teacher','2026-02-21 00:37:55'),('P43ad12c1e','Parent','$2y$12$GmkMBFbs.NcgndXu4ZKh6uONL1c0b0i..KcG9Q4qeUmo4OM.F1bgO',NULL,NULL,'parent','2026-01-07 11:29:49'),('P51d2eb692','dorji@gmail.com','$2y$12$Zlmd4JLWH7Kqb/sFMP5K2u1sWLHIib3XtF7Gzw0G1DFG8cVFaQC1q',NULL,NULL,'parent','2026-02-20 12:21:38'),('Pffb76540f','dorjijigme32@gmail.com','$2y$12$UPaQco7j4Xngb6qqDedwuON6OGo/2hBpBX1DxoxNRY80D8UzWwQLG',NULL,NULL,'parent','2026-02-19 12:19:03');
/*!40000 ALTER TABLE `user` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'bbc_db'
--
