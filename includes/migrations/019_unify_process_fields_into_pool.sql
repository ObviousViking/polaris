-- Unifies Process Builder field definitions into the Metadata Pool
-- (exhibit_metadata_fields), so a field is defined exactly once regardless
-- of how many processes ask for it. Previously a "one-off" process field
-- (anything except field_type = 'metapool') was both defined and validated
-- entirely within process_fields, and could never be changed or removed
-- once any exhibit had a recorded value against it - the only escape was
-- retiring the whole process and building a new one. The Metadata Pool
-- already didn't have that problem (editing a pool field's type/label was
-- always allowed regardless of usage - see 018_metadata_pool.sql), so this
-- migration makes the pool the *only* place a field is ever defined:
-- process_fields becomes a pure selection table (process_type_id,
-- metadata_field_id, is_required, sort_order), and exhibit_process_values
-- points directly at the pool field rather than at the selection row, so a
-- process's field selection can be freely added/removed at any time with no
-- usage guard - no recorded value ever references it.
--
-- Adds a per-field is_shared_value toggle: true (the default, matching
-- every existing pool field's current behavior) means one current value per
-- exhibit synced across every process that includes it; false means the
-- value is recorded independently on each process instance and never
-- touches the shared current-value table - for things like an acquisition
-- hash or an examination timestamp that legitimately differ between
-- separate runs of the same process.
--
-- Existing one-off fields are promoted into their own private (not shared)
-- pool entries rather than discarded, since this app is public now and some
-- installs may already have real examination data recorded against them.
-- Each keeps its own catalog entry (field_key suffixed with the source
-- process_fields.id, so this can never collide even if several processes
-- happened to use the same label) rather than trying to cleverly collapse
-- identical-looking one-offs into a shared entry - an admin can always
-- consolidate obvious duplicates by hand afterwards via the Metadata Pool
-- if they want to; that's a tidiness choice, not a correctness requirement.

ALTER TABLE exhibit_metadata_fields
  MODIFY COLUMN field_type ENUM('text','textarea','number','date','checkbox','hash','lookup') NOT NULL DEFAULT 'text',
  ADD COLUMN lookup_source VARCHAR(50) DEFAULT NULL AFTER field_type,
  ADD COLUMN lookup_asset_type_id INT DEFAULT NULL AFTER lookup_source,
  ADD COLUMN is_shared_value TINYINT(1) NOT NULL DEFAULT 1 AFTER hash_algorithm,
  ADD CONSTRAINT fk_exhibit_metadata_fields_lookup_asset_type FOREIGN KEY (lookup_asset_type_id) REFERENCES asset_types (id);

INSERT INTO exhibit_metadata_fields
  (field_label, field_key, field_type, hash_algorithm, lookup_source, lookup_asset_type_id, is_shared_value, sort_order)
SELECT
  pf.field_label,
  CONCAT(pf.field_key, '_pf', pf.id),
  pf.field_type,
  pf.hash_algorithm,
  pf.lookup_source,
  pf.lookup_asset_type_id,
  0,
  pf.sort_order
FROM process_fields pf
WHERE pf.metadata_field_id IS NULL;

UPDATE process_fields pf
JOIN exhibit_metadata_fields mf ON mf.field_key = CONCAT(pf.field_key, '_pf', pf.id)
SET pf.metadata_field_id = mf.id
WHERE pf.metadata_field_id IS NULL;

ALTER TABLE exhibit_process_values ADD COLUMN metadata_field_id INT DEFAULT NULL AFTER process_field_id;

UPDATE exhibit_process_values epv
JOIN process_fields pf ON pf.id = epv.process_field_id
SET epv.metadata_field_id = pf.metadata_field_id;

ALTER TABLE exhibit_process_values
  DROP FOREIGN KEY exhibit_process_values_ibfk_2,
  DROP KEY exhibit_process_field,
  DROP KEY process_field_id,
  MODIFY COLUMN metadata_field_id INT NOT NULL,
  DROP COLUMN process_field_id,
  ADD CONSTRAINT fk_exhibit_process_values_metadata_field FOREIGN KEY (metadata_field_id) REFERENCES exhibit_metadata_fields (id),
  ADD UNIQUE KEY exhibit_process_metadata_field (exhibit_process_id, metadata_field_id);

ALTER TABLE process_fields
  DROP FOREIGN KEY fk_process_fields_lookup_asset_type,
  DROP KEY process_type_field_key,
  MODIFY COLUMN metadata_field_id INT NOT NULL,
  DROP COLUMN field_label,
  DROP COLUMN field_key,
  DROP COLUMN field_type,
  DROP COLUMN lookup_source,
  DROP COLUMN lookup_asset_type_id,
  DROP COLUMN hash_algorithm,
  ADD UNIQUE KEY process_type_metadata_field (process_type_id, metadata_field_id);
