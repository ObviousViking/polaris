-- Adds update_type categorization (Case Update / Communication), edit
-- tracking, and soft-delete to case_updates, plus a case_updates_history
-- table mirroring case_history's tamper-evident hash/HMAC chain - so case
-- updates can be edited/deleted with a full audit trail, same as exhibits
-- and case field edits. Mirrors includes/polaris_create.sql exactly.
--
-- Plain ALTER TABLE ADD COLUMN would fail with "Duplicate column" if this
-- ever ran twice (MySQL has no ADD COLUMN IF NOT EXISTS) - guarded via
-- information_schema + dynamic SQL, same pattern as migration 002.

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'case_updates' AND COLUMN_NAME = 'update_type'
);
SET @ddl = IF(@col_exists = 0,
    "ALTER TABLE case_updates ADD COLUMN update_type enum('Case Update','Communication') NOT NULL DEFAULT 'Case Update' AFTER user_id",
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'case_updates' AND COLUMN_NAME = 'updated_at'
);
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE case_updates ADD COLUMN updated_at datetime DEFAULT NULL, ADD COLUMN updated_by int DEFAULT NULL, ADD KEY updated_by (updated_by), ADD CONSTRAINT case_updates_ibfk_3 FOREIGN KEY (updated_by) REFERENCES users (id)',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'case_updates' AND COLUMN_NAME = 'deleted_at'
);
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE case_updates ADD COLUMN deleted_at datetime DEFAULT NULL, ADD COLUMN deleted_by int DEFAULT NULL, ADD KEY deleted_by (deleted_by), ADD CONSTRAINT case_updates_ibfk_4 FOREIGN KEY (deleted_by) REFERENCES users (id)',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `case_updates_history` (
  `history_id` int NOT NULL AUTO_INCREMENT,
  `update_id` int NOT NULL,
  `action` varchar(50) NOT NULL,
  `changed_by` int NOT NULL,
  `changes` text,
  `changed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `prev_hash` char(64) NOT NULL,
  `row_hash` char(64) NOT NULL,
  `prev_hmac` char(64) NOT NULL,
  `hmac_hash` char(64) NOT NULL,
  PRIMARY KEY (`history_id`),
  KEY `update_id` (`update_id`),
  CONSTRAINT `case_updates_history_ibfk_1` FOREIGN KEY (`update_id`) REFERENCES `case_updates` (`update_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TRIGGER IF EXISTS `case_updates_history_hash_chain`;
CREATE TRIGGER `case_updates_history_hash_chain` BEFORE INSERT ON `case_updates_history`
FOR EACH ROW
BEGIN
    DECLARE prev CHAR(64);
    SELECT row_hash INTO prev FROM case_updates_history ORDER BY history_id DESC LIMIT 1;
    IF prev IS NULL THEN
        SET prev = REPEAT('0', 64);
    END IF;
    SET NEW.prev_hash = prev;
    SET NEW.row_hash = SHA2(CONCAT_WS('|', NEW.update_id, NEW.action, NEW.changed_by, NEW.changed_at, IFNULL(NEW.changes, ''), prev), 256);
END;

DROP TRIGGER IF EXISTS `case_updates_history_no_update`;
CREATE TRIGGER `case_updates_history_no_update` BEFORE UPDATE ON `case_updates_history`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'case_updates_history is append-only and cannot be modified';
END;

DROP TRIGGER IF EXISTS `case_updates_history_no_delete`;
CREATE TRIGGER `case_updates_history_no_delete` BEFORE DELETE ON `case_updates_history`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'case_updates_history is append-only and cannot be deleted from';
END;
