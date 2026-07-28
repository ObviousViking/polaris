-- Adds a 'hash' Process Builder field type - a text value that's validated
-- against a specific algorithm's expected hex length (MD5/SHA1/SHA256),
-- e.g. "Source Hash" / "Image Hash" fields on an imaging process.
ALTER TABLE process_fields
  MODIFY COLUMN field_type ENUM('text','textarea','number','date','lookup','checkbox','hash') NOT NULL DEFAULT 'text',
  ADD COLUMN hash_algorithm ENUM('MD5','SHA1','SHA256') NULL AFTER lookup_asset_type_id;
