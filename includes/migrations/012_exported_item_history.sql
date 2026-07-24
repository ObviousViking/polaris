-- Brings exported_items up to a defensible standard for FSR Code of
-- Practice s.25.2.1(a) (records of movement of material to another party)
-- and s.25.2.8 (traceability to a uniquely identified item/exhibit):
--   - source_exhibit_id links a working copy back to the exhibit it was
--     derived from.
--   - last_handed_to/last_handed_to_at is a quick-glance summary of the
--     most recent handover; the full history of every handover (not just
--     the latest) lives in exported_item_history below.
--   - exported_item_history mirrors exhibit_history's append-only,
--     hash-chained design (see includes/integrity.php) - CREATE/UPDATE
--     events plus a distinct HANDOVER action recording who an item was
--     given to and when, each one permanently retained.

ALTER TABLE exported_items
  ADD COLUMN source_exhibit_id int DEFAULT NULL AFTER job_id,
  ADD COLUMN last_handed_to varchar(255) DEFAULT NULL,
  ADD COLUMN last_handed_to_at datetime DEFAULT NULL,
  ADD KEY source_exhibit_id (source_exhibit_id),
  ADD CONSTRAINT fk_exported_items_exhibit FOREIGN KEY (source_exhibit_id) REFERENCES exhibits (exhibit_id);

CREATE TABLE IF NOT EXISTS exported_item_history (
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
  CONSTRAINT fk_exported_item_history_item FOREIGN KEY (item_id) REFERENCES exported_items (item_id),
  CONSTRAINT fk_exported_item_history_user FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TRIGGER IF EXISTS exported_item_history_hash_chain;
CREATE TRIGGER exported_item_history_hash_chain BEFORE INSERT ON exported_item_history
FOR EACH ROW
BEGIN
    DECLARE prev CHAR(64);
    SELECT row_hash INTO prev FROM exported_item_history ORDER BY history_id DESC LIMIT 1;
    IF prev IS NULL THEN
        SET prev = REPEAT('0', 64);
    END IF;
    SET NEW.prev_hash = prev;
    SET NEW.row_hash = SHA2(CONCAT_WS('|', NEW.item_id, NEW.action, NEW.changed_by, NEW.changed_at, IFNULL(NEW.changes, ''), prev), 256);
END;

DROP TRIGGER IF EXISTS exported_item_history_no_update;
CREATE TRIGGER exported_item_history_no_update BEFORE UPDATE ON exported_item_history
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'exported_item_history is append-only and cannot be modified';
END;

DROP TRIGGER IF EXISTS exported_item_history_no_delete;
CREATE TRIGGER exported_item_history_no_delete BEFORE DELETE ON exported_item_history
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'exported_item_history is append-only and cannot be deleted from';
END;
