-- Exhibit "metadata pool": a shared catalog of exhibit-identity attributes
-- (make, model, serial, IMEI, etc.) that live on the exhibit itself rather
-- than on any one process. A process can bind one of its fields to a pool
-- field (process_fields.field_type = 'metapool'); saving that field both
-- records a snapshot on the process instance (exhibit_process_values, as
-- always) and writes through to the exhibit's current pool value below.
-- This lets a process template's field TYPE evolve later (e.g. text -> hash)
-- without touching already-recorded process history, since the pool value
-- and the process snapshot are independent - unlike editing a process_fields
-- row directly, which was blocking on already-recorded exhibit_process_values.
CREATE TABLE IF NOT EXISTS exhibit_metadata_fields (
  id int NOT NULL AUTO_INCREMENT,
  field_label varchar(255) NOT NULL,
  field_key varchar(100) NOT NULL,
  field_type enum('text','number','date','checkbox','hash') NOT NULL DEFAULT 'text',
  hash_algorithm enum('MD5','SHA1','SHA256') DEFAULT NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  sort_order int NOT NULL DEFAULT 0,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY field_key (field_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Current value per exhibit per pool field - lots of NULLs expected (a
-- mobile won't have a hard drive's fields, a hard drive won't have an IMEI),
-- one row is only created once a value is actually entered.
CREATE TABLE IF NOT EXISTS exhibit_metadata_values (
  id int NOT NULL AUTO_INCREMENT,
  exhibit_id int NOT NULL,
  metadata_field_id int NOT NULL,
  value text,
  updated_by int DEFAULT NULL,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_exhibit_field (exhibit_id, metadata_field_id),
  KEY metadata_field_id (metadata_field_id),
  KEY updated_by (updated_by),
  CONSTRAINT fk_exhibit_metadata_values_exhibit FOREIGN KEY (exhibit_id) REFERENCES exhibits (exhibit_id),
  CONSTRAINT fk_exhibit_metadata_values_field FOREIGN KEY (metadata_field_id) REFERENCES exhibit_metadata_fields (id),
  CONSTRAINT fk_exhibit_metadata_values_user FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Append-only change log, same hash+HMAC chain shape as exhibit_history
-- (see includes/integrity.php) - ref_col is exhibit_id, `changes` carries
-- the field key/label/old/new value as JSON since one exhibit has many pool
-- fields, same pattern exhibit_process_history uses for its per-field diffs.
CREATE TABLE IF NOT EXISTS exhibit_metadata_history (
  history_id int NOT NULL AUTO_INCREMENT,
  exhibit_id int NOT NULL,
  action enum('CREATE','UPDATE') NOT NULL,
  changed_by int NOT NULL,
  changed_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  changes text,
  prev_hash char(64) NOT NULL,
  row_hash char(64) NOT NULL,
  prev_hmac char(64) NOT NULL,
  hmac_hash char(64) NOT NULL,
  PRIMARY KEY (history_id),
  KEY exhibit_id (exhibit_id),
  KEY changed_by (changed_by),
  CONSTRAINT fk_exhibit_metadata_history_exhibit FOREIGN KEY (exhibit_id) REFERENCES exhibits (exhibit_id),
  CONSTRAINT fk_exhibit_metadata_history_user FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TRIGGER IF EXISTS exhibit_metadata_history_hash_chain;
CREATE TRIGGER exhibit_metadata_history_hash_chain BEFORE INSERT ON exhibit_metadata_history
FOR EACH ROW
BEGIN
    DECLARE prev CHAR(64);
    SELECT row_hash INTO prev FROM exhibit_metadata_history ORDER BY history_id DESC LIMIT 1;
    IF prev IS NULL THEN
        SET prev = REPEAT('0', 64);
    END IF;
    SET NEW.prev_hash = prev;
    SET NEW.row_hash = SHA2(CONCAT_WS('|', NEW.exhibit_id, NEW.action, NEW.changed_by, NEW.changed_at, IFNULL(NEW.changes, ''), prev), 256);
END;

DROP TRIGGER IF EXISTS exhibit_metadata_history_no_update;
CREATE TRIGGER exhibit_metadata_history_no_update BEFORE UPDATE ON exhibit_metadata_history
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'exhibit_metadata_history is append-only and cannot be modified';
END;

DROP TRIGGER IF EXISTS exhibit_metadata_history_no_delete;
CREATE TRIGGER exhibit_metadata_history_no_delete BEFORE DELETE ON exhibit_metadata_history
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'exhibit_metadata_history is append-only and cannot be deleted from';
END;

-- A process field can bind to a pool field instead of owning its own type -
-- rendering/validation then follow the pool field's type, so an admin can
-- add "Serial Number" to three different processes and it behaves the same
-- (and stays the same shared value) in all three.
ALTER TABLE process_fields
  MODIFY COLUMN field_type ENUM('text','textarea','number','date','lookup','checkbox','hash','metapool') NOT NULL DEFAULT 'text',
  ADD COLUMN metadata_field_id int DEFAULT NULL AFTER hash_algorithm,
  ADD CONSTRAINT fk_process_fields_metadata_field FOREIGN KEY (metadata_field_id) REFERENCES exhibit_metadata_fields (id);

INSERT IGNORE INTO permissions (permission_key, label, description, category, sort_order) VALUES
  ('manage_metadata_pool', 'Manage Metadata Pool', 'Define the shared exhibit metadata fields (make, model, serial, IMEI, etc.) available to Process Builder.', 'System Administration', 87);

INSERT IGNORE INTO role_default_permissions (role, permission_key) VALUES ('admin', 'manage_metadata_pool');

INSERT IGNORE INTO user_permissions (user_id, permission_key)
SELECT u.id, 'manage_metadata_pool' FROM users u WHERE u.role = 'admin';
