-- Lets Process Builder (captains_quarters/manage_processes.php) control the
-- order processes are listed in - both in its own table and in the "Add
-- Process" picker on captains_log/examination.php.
ALTER TABLE process_types ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER description;

UPDATE process_types pt
JOIN (
    SELECT id, ROW_NUMBER() OVER (ORDER BY name) AS rn FROM process_types
) ranked ON ranked.id = pt.id
SET pt.sort_order = ranked.rn;
