-- Retires case_updates_history - case update history (add/edit/delete) now
-- lives in case_history alongside job field edits, one combined timeline
-- per case, instead of a separate per-feature table. Existing
-- case_updates_history rows must be migrated into case_history via
-- bin/migrate_case_updates_history.php BEFORE this runs (it drops the
-- table outright - run that script by hand first if this deployment ever
-- had real case_updates_history data).
--
-- Also drops case_updates.deleted_at/deleted_by: soft-delete-with-restore
-- is no longer needed on case_updates itself now that a delete's full
-- content is captured as a CASE_UPDATE_DELETED entry in case_history -
-- deletes are hard deletes going forward (see delete_update.php).

DROP TRIGGER IF EXISTS `case_updates_history_hash_chain`;
DROP TRIGGER IF EXISTS `case_updates_history_no_update`;
DROP TRIGGER IF EXISTS `case_updates_history_no_delete`;
DROP TABLE IF EXISTS `case_updates_history`;

-- Any row already soft-deleted under the old model would otherwise silently
-- "come back" once deleted_at/deleted_by are dropped below (nothing left
-- to mark it deleted) - purge those rows for real first. Their content is
-- already safe: bin/migrate_case_updates_history.php copied the DELETE
-- event (including the deleted text) into case_history before this file
-- runs.
SET @col_exists_check = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'case_updates' AND COLUMN_NAME = 'deleted_at'
);
SET @ddl = IF(@col_exists_check > 0,
    'DELETE FROM case_updates WHERE deleted_at IS NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- DROP COLUMN has no IF EXISTS guard MySQL will accept unconditionally
-- (see includes/migrations/002_backfill_schema_drift.sql's comment on the
-- equivalent ADD COLUMN problem) - guarded via information_schema instead,
-- so this stays safe to run more than once.
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'case_updates' AND COLUMN_NAME = 'deleted_by'
);
SET @ddl = IF(@col_exists > 0,
    'ALTER TABLE case_updates DROP FOREIGN KEY case_updates_ibfk_4, DROP KEY deleted_by, DROP COLUMN deleted_by, DROP COLUMN deleted_at',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
