-- Adds a 'lookup' field type to Process Builder fields, so a field can be a
-- dropdown sourced from another table (e.g. "Software Used" pulled from
-- Assets) instead of free text. See includes/process_lookups.php for the
-- fixed, hardcoded set of allowed sources - lookup_source is validated
-- against that list in PHP, never interpolated into SQL, so this stays safe
-- even though it's admin-editable.
ALTER TABLE process_fields
  MODIFY COLUMN field_type enum('text','textarea','number','date','lookup') NOT NULL DEFAULT 'text',
  ADD COLUMN lookup_source varchar(50) DEFAULT NULL AFTER field_type;
