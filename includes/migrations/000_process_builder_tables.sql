-- Creates the "Process Builder" tables (process_types, process_fields,
-- exhibit_processes, exhibit_process_values, exhibit_process_history) for
-- databases provisioned before this feature existed. includes/polaris_create.sql
-- already creates these on fresh installs, but no migration ever brought
-- existing databases up to date - so migration 001, which ALTERs
-- process_fields, has been failing every request since process_fields never
-- existed here. This must sort/run before 001.
--
-- Mirrors includes/polaris_create.sql exactly (same columns, keys, FKs, and
-- the append-only hash-chain triggers on exhibit_process_history).

CREATE TABLE IF NOT EXISTS `process_types` (
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

CREATE TABLE IF NOT EXISTS `process_fields` (
  `id` int NOT NULL AUTO_INCREMENT,
  `process_type_id` int NOT NULL,
  `field_label` varchar(255) NOT NULL,
  `field_key` varchar(100) NOT NULL,
  `field_type` enum('text','textarea','number','date') NOT NULL DEFAULT 'text',
  `is_required` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `process_type_field_key` (`process_type_id`,`field_key`),
  CONSTRAINT `process_fields_ibfk_1` FOREIGN KEY (`process_type_id`) REFERENCES `process_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `exhibit_processes` (
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

CREATE TABLE IF NOT EXISTS `exhibit_process_values` (
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

CREATE TABLE IF NOT EXISTS `exhibit_process_history` (
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
