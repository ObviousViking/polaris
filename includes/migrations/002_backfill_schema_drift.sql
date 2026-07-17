-- Backfills three pieces of schema that made it into includes/polaris_create.sql
-- (so fresh installs have always had them) but were never given a migration
-- for already-provisioned databases:
--
--  * audit_log table entirely missing - log_audit_event() (includes/audit.php)
--    has been silently swallowing every audit write.
--  * exhibits.deleted_at / deleted_by missing - the exhibit soft-delete
--    feature (delete_exhibit.php / restore_exhibit.php / exported_items.php,
--    and the WHERE e.deleted_at IS NULL filter in job.php) fatals wherever it
--    touches these columns, since the columns themselves don't exist.
--  * exhibit_locations.is_active missing - manage_locations.php has been
--    throwing "Undefined array key" warnings reading it.
--
-- Mirrors includes/polaris_create.sql exactly for all three.

CREATE TABLE IF NOT EXISTS `audit_log` (
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

-- Plain `ADD COLUMN` would fail with "Duplicate column" on any database that
-- (like this one) already picked these up via a hand-run ALTER before this
-- migration existed - and MySQL has no `ADD COLUMN IF NOT EXISTS` to guard
-- against that (unlike `CREATE TABLE IF NOT EXISTS`). information_schema +
-- dynamic SQL is the standard portable way to make an ALTER conditional.
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exhibit_locations' AND COLUMN_NAME = 'is_active'
);
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE exhibit_locations ADD COLUMN is_active tinyint(1) NOT NULL DEFAULT 1',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exhibits' AND COLUMN_NAME = 'deleted_at'
);
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE exhibits ADD COLUMN deleted_at datetime DEFAULT NULL, ADD COLUMN deleted_by int DEFAULT NULL, ADD KEY deleted_by (deleted_by), ADD CONSTRAINT exhibits_ibfk_deleted_by FOREIGN KEY (deleted_by) REFERENCES users (id)',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
