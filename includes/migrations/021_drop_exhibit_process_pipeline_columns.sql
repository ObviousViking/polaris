-- Rolls back the "core pipeline checklist" concept added in
-- 020_process_exhibit_type_scoping.sql. The examination page no longer
-- splits processes into a pipeline checklist vs. Case Events - it's a flat
-- "Processes" list instead, and exhibit progress is shown via a 3-stage
-- stepper driven by exhibits.status (see captains_log/examination.php).
-- process_type_exhibit_types reverts to a pure many-to-many join, used only
-- to scope which process types appear in the "Add Process" picker for a
-- given exhibit type (captains_quarters/manage_processes.php).
ALTER TABLE process_type_exhibit_types
  DROP COLUMN is_core,
  DROP COLUMN sort_order;
