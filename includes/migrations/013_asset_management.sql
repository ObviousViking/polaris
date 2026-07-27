-- Brings Asset Management up to the same evidentiary standard as Exhibits,
-- and toward ISO/IEC 17025 6.4 (equipment: unique identification, checks,
-- maintenance/calibration records) - asset_history mirrors exhibit_history's
-- append-only hash+HMAC chain exactly (see includes/integrity.php).

-- assets: swap denormalized text (asset_type/location) for real FKs, add
-- soft-delete (can't hard-delete once an asset has history, same reasoning
-- as exhibits.deleted_at), and creation/update tracking.
ALTER TABLE assets
  ADD COLUMN asset_type_id int DEFAULT NULL AFTER asset_type,
  ADD COLUMN location_id int DEFAULT NULL AFTER location,
  ADD COLUMN created_by int DEFAULT NULL AFTER created_at,
  ADD COLUMN updated_at timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_by,
  ADD COLUMN deleted_at datetime DEFAULT NULL AFTER updated_at,
  ADD COLUMN deleted_by int DEFAULT NULL AFTER deleted_at;

UPDATE assets a
  JOIN asset_types t ON t.type_name = a.asset_type
  SET a.asset_type_id = t.id
  WHERE a.asset_type_id IS NULL;

UPDATE assets a
  JOIN asset_locations l ON l.location_name = a.location
  SET a.location_id = l.id
  WHERE a.location_id IS NULL AND a.location IS NOT NULL;

ALTER TABLE assets
  MODIFY COLUMN asset_type_id int NOT NULL,
  ADD KEY asset_type_id (asset_type_id),
  ADD KEY location_id (location_id),
  ADD KEY created_by (created_by),
  ADD KEY deleted_by (deleted_by),
  ADD CONSTRAINT fk_assets_type FOREIGN KEY (asset_type_id) REFERENCES asset_types (id),
  ADD CONSTRAINT fk_assets_location FOREIGN KEY (location_id) REFERENCES asset_locations (id),
  ADD CONSTRAINT fk_assets_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  ADD CONSTRAINT fk_assets_deleted_by FOREIGN KEY (deleted_by) REFERENCES users (id),
  DROP COLUMN asset_type,
  DROP COLUMN location;

-- A type/location with any historical asset reference can't be
-- hard-deleted - manage_asset_types.php/manage_asset_locations.php
-- deactivate instead, same pattern as exhibit_locations.
ALTER TABLE asset_types ADD COLUMN is_active tinyint(1) NOT NULL DEFAULT 1;
ALTER TABLE asset_locations ADD COLUMN is_active tinyint(1) NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS asset_history (
  history_id int NOT NULL AUTO_INCREMENT,
  asset_id int NOT NULL,
  action enum('CREATE','UPDATE','CHECKOUT','CHECKIN','MAINTENANCE','DELETE','RESTORE') NOT NULL,
  changed_by int NOT NULL,
  changed_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  changes text,
  prev_hash char(64) NOT NULL,
  row_hash char(64) NOT NULL,
  prev_hmac char(64) NOT NULL,
  hmac_hash char(64) NOT NULL,
  PRIMARY KEY (history_id),
  KEY asset_id (asset_id),
  KEY changed_by (changed_by),
  CONSTRAINT fk_asset_history_asset FOREIGN KEY (asset_id) REFERENCES assets (id),
  CONSTRAINT fk_asset_history_user FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TRIGGER IF EXISTS asset_history_hash_chain;
CREATE TRIGGER asset_history_hash_chain BEFORE INSERT ON asset_history
FOR EACH ROW
BEGIN
    DECLARE prev CHAR(64);
    SELECT row_hash INTO prev FROM asset_history ORDER BY history_id DESC LIMIT 1;
    IF prev IS NULL THEN
        SET prev = REPEAT('0', 64);
    END IF;
    SET NEW.prev_hash = prev;
    SET NEW.row_hash = SHA2(CONCAT_WS('|', NEW.asset_id, NEW.action, NEW.changed_by, NEW.changed_at, IFNULL(NEW.changes, ''), prev), 256);
END;

DROP TRIGGER IF EXISTS asset_history_no_update;
CREATE TRIGGER asset_history_no_update BEFORE UPDATE ON asset_history
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'asset_history is append-only and cannot be modified';
END;

DROP TRIGGER IF EXISTS asset_history_no_delete;
CREATE TRIGGER asset_history_no_delete BEFORE DELETE ON asset_history
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'asset_history is append-only and cannot be deleted from';
END;

-- Checkout continuity: who has an asset, when it's due back, condition at
-- each end. One open row (checked_in_at IS NULL) per asset at a time.
CREATE TABLE IF NOT EXISTS asset_checkouts (
  checkout_id int NOT NULL AUTO_INCREMENT,
  asset_id int NOT NULL,
  checked_out_to int NOT NULL,
  checked_out_by int NOT NULL,
  checked_out_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  due_back_at datetime DEFAULT NULL,
  condition_out text,
  checked_in_at datetime DEFAULT NULL,
  checked_in_by int DEFAULT NULL,
  condition_in text,
  notes text,
  PRIMARY KEY (checkout_id),
  KEY asset_id (asset_id),
  KEY checked_out_to (checked_out_to),
  CONSTRAINT fk_asset_checkouts_asset FOREIGN KEY (asset_id) REFERENCES assets (id),
  CONSTRAINT fk_asset_checkouts_to FOREIGN KEY (checked_out_to) REFERENCES users (id),
  CONSTRAINT fk_asset_checkouts_by FOREIGN KEY (checked_out_by) REFERENCES users (id),
  CONSTRAINT fk_asset_checkouts_in_by FOREIGN KEY (checked_in_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Maintenance/calibration/verification event log with a due-date field so
-- overdue equipment can be surfaced (see logisticshub/maintenance.php).
CREATE TABLE IF NOT EXISTS asset_maintenance (
  maintenance_id int NOT NULL AUTO_INCREMENT,
  asset_id int NOT NULL,
  event_type enum('Maintenance','Calibration','Verification','Repair','Inspection') NOT NULL,
  performed_at date NOT NULL,
  performed_by varchar(255) DEFAULT NULL,
  result enum('Pass','Fail','N/A') DEFAULT NULL,
  next_due_at date DEFAULT NULL,
  certificate_reference varchar(255) DEFAULT NULL,
  notes text,
  logged_by int NOT NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (maintenance_id),
  KEY asset_id (asset_id),
  KEY logged_by (logged_by),
  CONSTRAINT fk_asset_maintenance_asset FOREIGN KEY (asset_id) REFERENCES assets (id),
  CONSTRAINT fk_asset_maintenance_logged_by FOREIGN KEY (logged_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- New permission keys (asset_checkout/asset_maintenance/asset_delete).
-- sync_permission_catalog() (run at request time, after migrations) would
-- normally populate the `permissions` catalog row for a new key, but
-- role_default_permissions/user_permissions both FK permission_key back to
-- `permissions` - inserting into them here, before that sync has run,
-- needs the catalog rows to already exist or the FK silently drops the
-- INSERT IGNORE. Insert the catalog rows directly (sync_permission_catalog()
-- will just no-op/refresh them afterward).
INSERT IGNORE INTO permissions (permission_key, label, description, category, sort_order) VALUES
  ('asset_checkout', 'Check Assets In/Out', 'Check assets out to a user and back in again.', 'Asset Management', 52),
  ('asset_maintenance', 'Log Asset Maintenance', 'Log maintenance, calibration, verification, and repair records, and view items with checks due.', 'Asset Management', 53),
  ('asset_delete', 'Delete Assets', 'Delete and restore asset records.', 'Asset Management', 54);

-- role_default_permissions and already-granted user_permissions are
-- one-time snapshots, not a live binding (see apply_role_default_permissions()),
-- so a brand new permission key doesn't retroactively reach anyone -
-- back-fill it directly here, same idea as grandfather_existing_users().
INSERT IGNORE INTO role_default_permissions (role, permission_key)
SELECT 'admin', k FROM (
    SELECT 'asset_checkout' AS k UNION ALL SELECT 'asset_maintenance' UNION ALL SELECT 'asset_delete'
) new_perms;

INSERT IGNORE INTO user_permissions (user_id, permission_key)
SELECT u.id, k FROM users u
JOIN (
    SELECT 'asset_checkout' AS k UNION ALL SELECT 'asset_maintenance' UNION ALL SELECT 'asset_delete'
) new_perms
WHERE u.role = 'admin';

INSERT IGNORE INTO role_default_permissions (role, permission_key) VALUES ('user', 'asset_checkout');

INSERT IGNORE INTO user_permissions (user_id, permission_key)
SELECT u.id, 'asset_checkout' FROM users u WHERE u.role = 'user';
