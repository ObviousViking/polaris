CREATE DATABASE  IF NOT EXISTS `polaris` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;
USE `polaris`;
-- MySQL dump 10.13  Distrib 8.0.38, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: polaris
-- ------------------------------------------------------
-- Server version	8.3.0

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `asset_locations`
--

DROP TABLE IF EXISTS `asset_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_locations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `location_name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_types`
--

DROP TABLE IF EXISTS `asset_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type_name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `assets`
--

DROP TABLE IF EXISTS `assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `assets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `asset_number` varchar(20) NOT NULL,
  `friendly_name` varchar(255) NOT NULL,
  `asset_type` varchar(100) NOT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `availability` enum('Deployed','Not Deployed','In Maintenance','Out Of Service','Destroyed') DEFAULT 'Deployed',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `asset_number` (`asset_number`)
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `case_history`
--

DROP TABLE IF EXISTS `case_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `case_history` (
  `history_id` int NOT NULL AUTO_INCREMENT,
  `job_id` int NOT NULL,
  `action` varchar(50) NOT NULL,
  `changed_by` int NOT NULL,
  `changes` text,
  `changed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `prev_hash` char(64) NOT NULL,
  `row_hash` char(64) NOT NULL,
  `prev_hmac` char(64) NOT NULL,
  `hmac_hash` char(64) NOT NULL,
  PRIMARY KEY (`history_id`),
  KEY `job_id` (`job_id`),
  CONSTRAINT `case_history_ibfk_1` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`job_id`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- Tamper-evident hash chain, layer 1: each row's hash covers its own fields
-- plus the previous row's hash, so editing any historical row (or deleting
-- one, which breaks the sequence) is detectable by re-walking the chain -
-- see includes/integrity.php. Enforced as a trigger, not in application
-- code, so it applies no matter which code path (or a raw SQL client)
-- inserts a row. (Layer 2, prev_hmac/hmac_hash, is computed in PHP - see
-- includes/integrity.php - since a trigger cannot access the HISTORY_HMAC_KEY
-- env var; only the application container can see that.)
DROP TRIGGER IF EXISTS `case_history_hash_chain`;
CREATE TRIGGER `case_history_hash_chain` BEFORE INSERT ON `case_history`
FOR EACH ROW
BEGIN
    DECLARE prev CHAR(64);
    SELECT row_hash INTO prev FROM case_history ORDER BY history_id DESC LIMIT 1;
    IF prev IS NULL THEN
        SET prev = REPEAT('0', 64);
    END IF;
    SET NEW.prev_hash = prev;
    SET NEW.row_hash = SHA2(CONCAT_WS('|', NEW.job_id, NEW.action, NEW.changed_by, NEW.changed_at, IFNULL(NEW.changes, ''), prev), 256);
END;

-- This table is append-only - nothing in the app ever UPDATEs or DELETEs a
-- history row. Reject both outright instead of relying on the hash chain to
-- merely detect it after the fact.
DROP TRIGGER IF EXISTS `case_history_no_update`;
CREATE TRIGGER `case_history_no_update` BEFORE UPDATE ON `case_history`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'case_history is append-only and cannot be modified';
END;

DROP TRIGGER IF EXISTS `case_history_no_delete`;
CREATE TRIGGER `case_history_no_delete` BEFORE DELETE ON `case_history`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'case_history is append-only and cannot be deleted from';
END;

--
-- Table structure for table `audit_log`
--

-- "Who changed what, when" log for admin/config mutations (lookup tables,
-- users, tasks, assets, system settings) - see includes/audit.php. Uses the
-- same two-layer tamper-evident chain as case_history/exhibit_history below
-- (plain hash chain via trigger, HMAC chain via includes/audit.php) - those
-- exist for legal chain-of-custody over cases/exhibits specifically, this
-- covers everything else that used to have no trail at all.
DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int DEFAULT NULL,
  `action` varchar(20) NOT NULL,
  `changed_by` int DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `details` text,
  `prev_hash` char(64) NOT NULL,
  `row_hash` char(64) NOT NULL,
  `prev_hmac` char(64) NOT NULL,
  `hmac_hash` char(64) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `entity_type` (`entity_type`),
  KEY `changed_by` (`changed_by`),
  CONSTRAINT `audit_log_ibfk_1` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- Same tamper-evident hash chain (layer 1) as case_history/exhibit_history -
-- see the comment on case_history_hash_chain below. entity_id and details
-- are nullable (not every audit_log row is about a single numbered entity,
-- e.g. system settings changes), so they're wrapped in IFNULL to keep the
-- pipe-delimited payload stable - matching how verify_history_chain() in
-- includes/integrity.php treats a missing field as an empty string.
DROP TRIGGER IF EXISTS `audit_log_hash_chain`;
CREATE TRIGGER `audit_log_hash_chain` BEFORE INSERT ON `audit_log`
FOR EACH ROW
BEGIN
    DECLARE prev CHAR(64);
    SELECT row_hash INTO prev FROM audit_log ORDER BY id DESC LIMIT 1;
    IF prev IS NULL THEN
        SET prev = REPEAT('0', 64);
    END IF;
    SET NEW.prev_hash = prev;
    SET NEW.row_hash = SHA2(CONCAT_WS('|', NEW.entity_type, IFNULL(NEW.entity_id, ''), NEW.action, NEW.changed_by, NEW.changed_at, IFNULL(NEW.details, ''), prev), 256);
END;

DROP TRIGGER IF EXISTS `audit_log_no_update`;
CREATE TRIGGER `audit_log_no_update` BEFORE UPDATE ON `audit_log`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only and cannot be modified';
END;

DROP TRIGGER IF EXISTS `audit_log_no_delete`;
CREATE TRIGGER `audit_log_no_delete` BEFORE DELETE ON `audit_log`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only and cannot be deleted from';
END;

--
-- Table structure for table `case_documents`
--

DROP TABLE IF EXISTS `case_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `case_documents` (
  `id` int NOT NULL AUTO_INCREMENT,
  `job_id` int NOT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `stored_filename` varchar(255) DEFAULT NULL,
  `file_path` text,
  `uploaded_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `uploaded_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `job_id` (`job_id`),
  KEY `uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `case_types`
--

DROP TABLE IF EXISTS `case_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `case_types` (
  `case_type_id` int NOT NULL AUTO_INCREMENT,
  `type_name` varchar(255) NOT NULL,
  PRIMARY KEY (`case_type_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `case_updates`
--

DROP TABLE IF EXISTS `case_updates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
-- Case update history (add/edit/delete) is logged into case_history
-- alongside job field edits - one combined timeline per case - rather than
-- a separate table (see add_update.php/edit_update.php/delete_update.php).
-- Deletes are hard deletes: the deleted content is preserved as a
-- CASE_UPDATE_DELETED entry in case_history, so there's no need for a
-- soft-delete/restore flag here.
CREATE TABLE `case_updates` (
  `update_id` int NOT NULL AUTO_INCREMENT,
  `job_id` int NOT NULL,
  `user_id` int NOT NULL,
  `update_type` enum('Case Update','Communication') NOT NULL DEFAULT 'Case Update',
  `update_text` text NOT NULL,
  `update_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  PRIMARY KEY (`update_id`),
  KEY `job_id` (`job_id`),
  KEY `user_id` (`user_id`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `case_updates_ibfk_1` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`job_id`),
  CONSTRAINT `case_updates_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `case_updates_ibfk_3` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `customers`
--

DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customers` (
  `customer_id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `organisation` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone_number` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`customer_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `exhibit_audit`
--

DROP TABLE IF EXISTS `exhibit_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exhibit_audit` (
  `audit_id` int NOT NULL AUTO_INCREMENT,
  `exhibit_id` int NOT NULL,
  `change_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `changed_by` int NOT NULL,
  `action` enum('BOOK_IN','BOOK_OUT','UPDATE') NOT NULL,
  `field_changed` varchar(50) NOT NULL,
  `old_value` text,
  `new_value` text,
  PRIMARY KEY (`audit_id`),
  KEY `exhibit_id` (`exhibit_id`),
  KEY `changed_by` (`changed_by`),
  CONSTRAINT `fk_exhibit_audit_exhibit` FOREIGN KEY (`exhibit_id`) REFERENCES `exhibits` (`exhibit_id`),
  CONSTRAINT `fk_exhibit_audit_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `exhibit_documents`
--

DROP TABLE IF EXISTS `exhibit_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exhibit_documents` (
  `id` int NOT NULL AUTO_INCREMENT,
  `exhibit_id` int NOT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `stored_filename` varchar(255) DEFAULT NULL,
  `file_path` text,
  `uploaded_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `uploaded_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `exhibit_id` (`exhibit_id`),
  KEY `uploaded_by` (`uploaded_by`)
) ENGINE=MyISAM AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `exhibit_history`
--

DROP TABLE IF EXISTS `exhibit_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exhibit_history` (
  `history_id` int NOT NULL AUTO_INCREMENT,
  `exhibit_id` int NOT NULL,
  -- DELETE/RESTORE back the soft-delete flow in delete_exhibit.php /
  -- restore_exhibit.php - exhibits are never hard-deleted (see the
  -- deleted_at/deleted_by columns on `exhibits` below), so these record the
  -- delete/undo events themselves rather than removing rows.
  `action` enum('BOOK_IN','BOOK_OUT','UPDATE','DELETE','RESTORE') NOT NULL,
  `changed_by` int NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `changes` text,
  `prev_hash` char(64) NOT NULL,
  `row_hash` char(64) NOT NULL,
  `prev_hmac` char(64) NOT NULL,
  `hmac_hash` char(64) NOT NULL,
  PRIMARY KEY (`history_id`),
  KEY `exhibit_id` (`exhibit_id`),
  KEY `changed_by` (`changed_by`),
  CONSTRAINT `fk_exhibit_history_exhibit` FOREIGN KEY (`exhibit_id`) REFERENCES `exhibits` (`exhibit_id`),
  CONSTRAINT `fk_exhibit_history_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- Same tamper-evident hash chain (layer 1) as case_history - see the
-- comment there.
DROP TRIGGER IF EXISTS `exhibit_history_hash_chain`;
CREATE TRIGGER `exhibit_history_hash_chain` BEFORE INSERT ON `exhibit_history`
FOR EACH ROW
BEGIN
    DECLARE prev CHAR(64);
    SELECT row_hash INTO prev FROM exhibit_history ORDER BY history_id DESC LIMIT 1;
    IF prev IS NULL THEN
        SET prev = REPEAT('0', 64);
    END IF;
    SET NEW.prev_hash = prev;
    SET NEW.row_hash = SHA2(CONCAT_WS('|', NEW.exhibit_id, NEW.action, NEW.changed_by, NEW.changed_at, IFNULL(NEW.changes, ''), prev), 256);
END;

DROP TRIGGER IF EXISTS `exhibit_history_no_update`;
CREATE TRIGGER `exhibit_history_no_update` BEFORE UPDATE ON `exhibit_history`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'exhibit_history is append-only and cannot be modified';
END;

DROP TRIGGER IF EXISTS `exhibit_history_no_delete`;
CREATE TRIGGER `exhibit_history_no_delete` BEFORE DELETE ON `exhibit_history`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'exhibit_history is append-only and cannot be deleted from';
END;

--
-- Table structure for table `exhibit_locations`
--

DROP TABLE IF EXISTS `exhibit_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exhibit_locations` (
  `location_id` int NOT NULL AUTO_INCREMENT,
  `location_name` varchar(255) NOT NULL,
  -- A location with any historical exhibit reference can't be hard-deleted
  -- (exhibits.location_id has a FK back here, and exhibit history is
  -- append-only) - see manage_locations.php, which deactivates instead of
  -- deleting in that case. Only a never-used location can be hard-deleted.
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`location_id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `exhibit_photos`
--

DROP TABLE IF EXISTS `exhibit_photos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exhibit_photos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `exhibit_id` int NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` text NOT NULL,
  `uploaded_by` int NOT NULL,
  `uploaded_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `exhibit_id` (`exhibit_id`),
  KEY `uploaded_by` (`uploaded_by`)
) ENGINE=MyISAM AUTO_INCREMENT=108 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `exhibit_types`
--

DROP TABLE IF EXISTS `exhibit_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exhibit_types` (
  `exhibit_type_id` int NOT NULL AUTO_INCREMENT,
  `type_name` varchar(100) NOT NULL,
  PRIMARY KEY (`exhibit_type_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `exhibits`
--

DROP TABLE IF EXISTS `exhibits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exhibits` (
  `exhibit_id` int NOT NULL AUTO_INCREMENT,
  `job_id` int NOT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `time_in` datetime DEFAULT CURRENT_TIMESTAMP,
  `time_out` datetime DEFAULT NULL,
  `exhibit_type_id` int NOT NULL,
  `urgency` enum('Low','Medium','High') DEFAULT 'Medium',
  `bag_number` varchar(50) DEFAULT NULL,
  `exhibit_ref` varchar(50) NOT NULL,
  `location_id` int NOT NULL,
  `delivered_by` varchar(255) NOT NULL,
  `status` enum('Not Yet Started','Imaging','Imaged','Being Analysed','On Hold','Complete') DEFAULT 'Not Yet Started',
  `item_description` varchar(255) DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `allocated_to` int DEFAULT NULL,
  `summary_of_findings` text,
  `parent_id` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  -- Soft-delete only - see delete_exhibit.php. An exhibit can't be
  -- hard-deleted once it has history (always true after booking in), since
  -- exhibit_history is append-only and foreign-keyed back to this table.
  -- NULL deleted_at = active; active views filter on that.
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  PRIMARY KEY (`exhibit_id`),
  KEY `job_id` (`job_id`),
  KEY `exhibit_type_id` (`exhibit_type_id`),
  KEY `location_id` (`location_id`),
  KEY `deleted_by` (`deleted_by`),
  CONSTRAINT `exhibits_ibfk_1` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`job_id`),
  CONSTRAINT `exhibits_ibfk_2` FOREIGN KEY (`exhibit_type_id`) REFERENCES `exhibit_types` (`exhibit_type_id`),
  CONSTRAINT `exhibits_ibfk_3` FOREIGN KEY (`location_id`) REFERENCES `exhibit_locations` (`location_id`),
  CONSTRAINT `exhibits_ibfk_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Examination process/metadata tables - see captains_quarters/manage_processes.php
-- (the "Process Builder") for defining process_types/process_fields, and
-- captains_log/manage_exhibit_process.php for filling one in against an
-- exhibit. exhibit_processes is the current/live record; every create or
-- edit is also written to exhibit_process_history (full snapshot, not a
-- diff, since the field set is dynamic per process type) with the same
-- tamper-evident hash/HMAC chain as case_history/exhibit_history/audit_log.
--

DROP TABLE IF EXISTS `process_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `process_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `process_types_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `process_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `process_fields` (
  `id` int NOT NULL AUTO_INCREMENT,
  `process_type_id` int NOT NULL,
  `field_label` varchar(255) NOT NULL,
  `field_key` varchar(100) NOT NULL,
  `field_type` enum('text','textarea','number','date','lookup') NOT NULL DEFAULT 'text',
  -- Only meaningful when field_type = 'lookup' - a key into the fixed
  -- source list in includes/process_lookups.php (e.g. 'assets'), never a
  -- raw table/column name, so this is safe even though it's admin-editable.
  `lookup_source` varchar(50) DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `process_type_field_key` (`process_type_id`,`field_key`),
  CONSTRAINT `process_fields_ibfk_1` FOREIGN KEY (`process_type_id`) REFERENCES `process_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `exhibit_processes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exhibit_processes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `exhibit_id` int NOT NULL,
  `process_type_id` int NOT NULL,
  `free_text` text,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `exhibit_id` (`exhibit_id`),
  KEY `process_type_id` (`process_type_id`),
  KEY `created_by` (`created_by`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `exhibit_processes_ibfk_1` FOREIGN KEY (`exhibit_id`) REFERENCES `exhibits` (`exhibit_id`),
  CONSTRAINT `exhibit_processes_ibfk_2` FOREIGN KEY (`process_type_id`) REFERENCES `process_types` (`id`),
  CONSTRAINT `exhibit_processes_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `exhibit_processes_ibfk_4` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `exhibit_process_values`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exhibit_process_values` (
  `id` int NOT NULL AUTO_INCREMENT,
  `exhibit_process_id` int NOT NULL,
  `process_field_id` int NOT NULL,
  `value` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exhibit_process_field` (`exhibit_process_id`,`process_field_id`),
  KEY `process_field_id` (`process_field_id`),
  CONSTRAINT `exhibit_process_values_ibfk_1` FOREIGN KEY (`exhibit_process_id`) REFERENCES `exhibit_processes` (`id`),
  CONSTRAINT `exhibit_process_values_ibfk_2` FOREIGN KEY (`process_field_id`) REFERENCES `process_fields` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `exhibit_process_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exhibit_process_history` (
  `history_id` int NOT NULL AUTO_INCREMENT,
  `exhibit_process_id` int NOT NULL,
  `action` varchar(20) NOT NULL,
  `changed_by` int NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `changes` text,
  `prev_hash` char(64) NOT NULL,
  `row_hash` char(64) NOT NULL,
  `prev_hmac` char(64) NOT NULL,
  `hmac_hash` char(64) NOT NULL,
  PRIMARY KEY (`history_id`),
  KEY `exhibit_process_id` (`exhibit_process_id`),
  KEY `changed_by` (`changed_by`),
  CONSTRAINT `exhibit_process_history_ibfk_1` FOREIGN KEY (`exhibit_process_id`) REFERENCES `exhibit_processes` (`id`),
  CONSTRAINT `exhibit_process_history_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TRIGGER IF EXISTS `exhibit_process_history_hash_chain`;
CREATE TRIGGER `exhibit_process_history_hash_chain` BEFORE INSERT ON `exhibit_process_history`
FOR EACH ROW
BEGIN
    DECLARE prev CHAR(64);
    SELECT row_hash INTO prev FROM exhibit_process_history ORDER BY history_id DESC LIMIT 1;
    IF prev IS NULL THEN
        SET prev = REPEAT('0', 64);
    END IF;
    SET NEW.prev_hash = prev;
    SET NEW.row_hash = SHA2(CONCAT_WS('|', NEW.exhibit_process_id, NEW.action, NEW.changed_by, NEW.changed_at, IFNULL(NEW.changes, ''), prev), 256);
END;

DROP TRIGGER IF EXISTS `exhibit_process_history_no_update`;
CREATE TRIGGER `exhibit_process_history_no_update` BEFORE UPDATE ON `exhibit_process_history`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'exhibit_process_history is append-only and cannot be modified';
END;

DROP TRIGGER IF EXISTS `exhibit_process_history_no_delete`;
CREATE TRIGGER `exhibit_process_history_no_delete` BEFORE DELETE ON `exhibit_process_history`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'exhibit_process_history is append-only and cannot be deleted from';
END;

--
-- Table structure for table `exported_items`
--

DROP TABLE IF EXISTS `exported_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exported_items` (
  `item_id` int NOT NULL AUTO_INCREMENT,
  `job_id` int NOT NULL,
  `extraction_ref` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` enum('Awaiting Review','Being Reviewed','Reviewed','Not Reviewed') DEFAULT 'Awaiting Review',
  `extracted_on` date DEFAULT NULL,
  `extracted_by` int DEFAULT NULL,
  `assigned_to` int DEFAULT NULL,
  PRIMARY KEY (`item_id`),
  KEY `job_id` (`job_id`),
  KEY `extracted_by` (`extracted_by`),
  KEY `assigned_to` (`assigned_to`),
  CONSTRAINT `exported_items_ibfk_job` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`job_id`),
  CONSTRAINT `exported_items_ibfk_extracted` FOREIGN KEY (`extracted_by`) REFERENCES `users` (`id`),
  CONSTRAINT `exported_items_ibfk_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `produced_exhibits`
--

DROP TABLE IF EXISTS `produced_exhibits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `produced_exhibits` (
  `exhibit_id` int NOT NULL AUTO_INCREMENT,
  `job_id` int NOT NULL,
  `exhibit_ref` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `produced_date` date DEFAULT NULL,
  `extracted_by` int DEFAULT NULL,
  PRIMARY KEY (`exhibit_id`),
  KEY `job_id` (`job_id`),
  KEY `extracted_by` (`extracted_by`),
  CONSTRAINT `produced_exhibits_ibfk_job` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`job_id`),
  CONSTRAINT `produced_exhibits_ibfk_extracted` FOREIGN KEY (`extracted_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `forces`
--

DROP TABLE IF EXISTS `forces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `forces` (
  `id` int NOT NULL AUTO_INCREMENT,
  `force_name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `job_priorities`
--

DROP TABLE IF EXISTS `job_priorities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_priorities` (
  `priority_id` int NOT NULL AUTO_INCREMENT,
  `priority_name` varchar(50) NOT NULL,
  PRIMARY KEY (`priority_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `job_status`
--

DROP TABLE IF EXISTS `job_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_status` (
  `status_id` int NOT NULL AUTO_INCREMENT,
  `status_name` varchar(255) NOT NULL,
  PRIMARY KEY (`status_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `job_id` int NOT NULL AUTO_INCREMENT,
  `date_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `case_type_id` int DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `oic` varchar(255) DEFAULT NULL,
  `operation` int DEFAULT NULL,
  `customer_id` int DEFAULT NULL,
  `initial_summary` text,
  `lead_force_id` int DEFAULT NULL,
  `suspect` varchar(255) DEFAULT NULL,
  `fingerprints` tinyint(1) DEFAULT NULL,
  `dna` tinyint(1) DEFAULT NULL,
  `status_id` int DEFAULT NULL,
  `strategy_set` datetime DEFAULT NULL,
  `strategy_due` datetime DEFAULT NULL,
  `strategy_complete` datetime DEFAULT NULL,
  `fmt_number` varchar(255) DEFAULT NULL,
  `malware` tinyint(1) DEFAULT NULL,
  `custom_ref` varchar(7) DEFAULT '',
  PRIMARY KEY (`job_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `type` varchar(50) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `operations`
--

DROP TABLE IF EXISTS `operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `operations` (
  `operation_id` int NOT NULL AUTO_INCREMENT,
  `operation_name` varchar(255) NOT NULL,
  PRIMARY KEY (`operation_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(128) NOT NULL,
  `data` mediumtext,
  `last_access` int unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- Defaults match the paths the Docker image mounts its data volume at
-- (see docker-compose.yml / Dockerfile). A bare-metal install can change
-- these during first-run setup or later from Captain's Quarters.
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('avatar_dir_fs', '/var/www/polaris-data/avatars/'),
  ('avatar_dir_url', '/polaris_uploads/avatars/'),
  ('photo_dir_fs', '/var/www/polaris-data/exhibit-photos/'),
  ('photo_dir_url', '/polaris_uploads/exhibit-photos/'),
  ('document_dir_fs', '/var/www/polaris-data/exhibit-documents/'),
  ('document_dir_url', '/polaris_uploads/exhibit-documents/');

--
-- Table structure for table `tasks`
--

DROP TABLE IF EXISTS `tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tasks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `task_ref` varchar(10) NOT NULL,
  `custom_ref` varchar(255) NOT NULL,
  `job_id` int NOT NULL,
  `assigned_to` int NOT NULL,
  `description` text NOT NULL,
  `status` enum('not_started','in_progress','completed') DEFAULT 'not_started',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `completion_comment` text,
  `is_active` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(50) DEFAULT 'user',
  `avatar` varchar(255) DEFAULT NULL,
  `active` int NOT NULL DEFAULT '1',
  `is_active` tinyint(1) DEFAULT '1',
  `theme` enum('dark','light') NOT NULL DEFAULT 'dark',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `achievements`
--
-- User Profile achievements panel - see includes/achievements.php. This is
-- the fixed catalog (definitions); user_achievements below records who
-- unlocked what, and when.

DROP TABLE IF EXISTS `achievements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `achievements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `achievement_key` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) NOT NULL,
  `icon` varchar(16) NOT NULL,
  `metric` varchar(50) NOT NULL,
  `threshold` int NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `achievement_key` (`achievement_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_achievements`
--

DROP TABLE IF EXISTS `user_achievements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_achievements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `achievement_id` int NOT NULL,
  `unlocked_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_achievement` (`user_id`,`achievement_id`),
  KEY `achievement_id` (`achievement_id`),
  CONSTRAINT `user_achievements_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `user_achievements_ibfk_2` FOREIGN KEY (`achievement_id`) REFERENCES `achievements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `permission_key` varchar(50) NOT NULL,
  `label` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `category` varchar(50) NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`permission_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `role_default_permissions`
--

DROP TABLE IF EXISTS `role_default_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_default_permissions` (
  `role` varchar(50) NOT NULL,
  `permission_key` varchar(50) NOT NULL,
  PRIMARY KEY (`role`,`permission_key`),
  CONSTRAINT `role_default_permissions_ibfk_1` FOREIGN KEY (`permission_key`) REFERENCES `permissions` (`permission_key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_permissions`
--

DROP TABLE IF EXISTS `user_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_permissions` (
  `user_id` int NOT NULL,
  `permission_key` varchar(50) NOT NULL,
  PRIMARY KEY (`user_id`,`permission_key`),
  KEY `permission_key` (`permission_key`),
  CONSTRAINT `user_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_permissions_ibfk_2` FOREIGN KEY (`permission_key`) REFERENCES `permissions` (`permission_key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping events for database 'polaris'
--

--
-- Dumping routines for database 'polaris'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-07-07 10:40:08
