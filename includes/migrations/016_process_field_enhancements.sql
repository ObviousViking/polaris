-- Process Builder field improvements:
--   - 'checkbox' field type (a simple tick box).
--   - lookup_asset_type_id lets a lookup field sourced from Assets be
--     restricted to one asset type (e.g. a "Software Used" field should
--     only offer assets typed "Software", not every deployed asset) - see
--     includes/process_lookups.php, which builds a parameterized query from
--     this rather than any admin-supplied SQL.
ALTER TABLE process_fields
  MODIFY COLUMN field_type ENUM('text','textarea','number','date','lookup','checkbox') NOT NULL DEFAULT 'text',
  ADD COLUMN lookup_asset_type_id INT NULL AFTER lookup_source,
  ADD CONSTRAINT fk_process_fields_lookup_asset_type FOREIGN KEY (lookup_asset_type_id) REFERENCES asset_types (id);
