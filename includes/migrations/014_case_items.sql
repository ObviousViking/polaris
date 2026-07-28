-- Consolidates exported_items and produced_exhibits into one generic,
-- admin-configurable "case items" system - different labs use different
-- terminology/workflows for "things produced or derived during a case
-- that aren't formal exhibits" (working copies, produced exhibits,
-- exported items, reports, etc.), so a fixed two-category split was the
-- wrong shape. case_item_types is an admin-editable lookup (see
-- manage_case_item_types.php); every item gets the same baseline
-- treatment regardless of type.
--
-- Schema only - deliberately no data migration here. case_item_history's
-- prev_hmac/hmac_hash columns are only ever computed by insert_history_row()
-- in includes/integrity.php using HISTORY_HMAC_KEY, a secret plain SQL/
-- triggers never see - a raw INSERT...SELECT here cannot produce a valid
-- HMAC chain. Existing exported_items/produced_exhibits data is backfilled
-- by bin/migrate_case_items_data.php, run once after this migration
-- applies (see that script's header comment for the exact command).
--
-- exported_items/produced_exhibits/exported_item_history are NOT dropped -
-- they stay in place, unreferenced by the app, as a safety net.

CREATE TABLE IF NOT EXISTS case_item_types (
  type_id int NOT NULL AUTO_INCREMENT,
  type_name varchar(100) NOT NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (type_id),
  UNIQUE KEY type_name (type_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO case_item_types (type_name, is_active) VALUES ('EXPORTED ITEM', 1), ('PRODUCED EXHIBIT', 1);

CREATE TABLE IF NOT EXISTS case_items (
  item_id int NOT NULL AUTO_INCREMENT,
  job_id int NOT NULL,
  type_id int NOT NULL,
  source_exhibit_id int DEFAULT NULL,
  item_ref varchar(50) NOT NULL,
  description varchar(255) DEFAULT NULL,
  status enum('Awaiting Review','Being Reviewed','Reviewed','Not Reviewed') DEFAULT 'Awaiting Review',
  notes text,
  created_on date DEFAULT NULL,
  created_by int DEFAULT NULL,
  assigned_to int DEFAULT NULL,
  last_handed_to varchar(255) DEFAULT NULL,
  last_handed_to_at datetime DEFAULT NULL,
  PRIMARY KEY (item_id),
  UNIQUE KEY job_item_ref (job_id, item_ref),
  KEY type_id (type_id),
  KEY source_exhibit_id (source_exhibit_id),
  KEY created_by (created_by),
  KEY assigned_to (assigned_to),
  CONSTRAINT fk_case_items_job FOREIGN KEY (job_id) REFERENCES jobs (job_id),
  CONSTRAINT fk_case_items_type FOREIGN KEY (type_id) REFERENCES case_item_types (type_id),
  CONSTRAINT fk_case_items_exhibit FOREIGN KEY (source_exhibit_id) REFERENCES exhibits (exhibit_id),
  CONSTRAINT fk_case_items_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_case_items_assigned_to FOREIGN KEY (assigned_to) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Append-only, hash-chained history - identical shape/triggers to
-- exported_item_history (see includes/integrity.php).
CREATE TABLE IF NOT EXISTS case_item_history (
  history_id int NOT NULL AUTO_INCREMENT,
  item_id int NOT NULL,
  action enum('CREATE','UPDATE','HANDOVER') NOT NULL,
  changed_by int NOT NULL,
  changed_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  changes text,
  prev_hash char(64) NOT NULL,
  row_hash char(64) NOT NULL,
  prev_hmac char(64) NOT NULL,
  hmac_hash char(64) NOT NULL,
  PRIMARY KEY (history_id),
  KEY item_id (item_id),
  KEY changed_by (changed_by),
  CONSTRAINT fk_case_item_history_item FOREIGN KEY (item_id) REFERENCES case_items (item_id),
  CONSTRAINT fk_case_item_history_user FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TRIGGER IF EXISTS case_item_history_hash_chain;
CREATE TRIGGER case_item_history_hash_chain BEFORE INSERT ON case_item_history
FOR EACH ROW
BEGIN
    DECLARE prev CHAR(64);
    SELECT row_hash INTO prev FROM case_item_history ORDER BY history_id DESC LIMIT 1;
    IF prev IS NULL THEN
        SET prev = REPEAT('0', 64);
    END IF;
    SET NEW.prev_hash = prev;
    SET NEW.row_hash = SHA2(CONCAT_WS('|', NEW.item_id, NEW.action, NEW.changed_by, NEW.changed_at, IFNULL(NEW.changes, ''), prev), 256);
END;

DROP TRIGGER IF EXISTS case_item_history_no_update;
CREATE TRIGGER case_item_history_no_update BEFORE UPDATE ON case_item_history
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'case_item_history is append-only and cannot be modified';
END;

DROP TRIGGER IF EXISTS case_item_history_no_delete;
CREATE TRIGGER case_item_history_no_delete BEFORE DELETE ON case_item_history
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'case_item_history is append-only and cannot be deleted from';
END;
