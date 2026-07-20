-- Adds communication detail fields to case_updates: when update_type is
-- 'Communication', the user records how contact was made (comm_type) and
-- who it was with (comm_person). Both stay NULL for 'Case Update' rows.
--
-- Guarded via information_schema, same pattern as migration 005/002, since
-- MySQL has no ADD COLUMN IF NOT EXISTS.

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'case_updates' AND COLUMN_NAME = 'comm_type'
);
SET @ddl = IF(@col_exists = 0,
    "ALTER TABLE case_updates ADD COLUMN comm_type enum('Email','Phone','In Person','Other') DEFAULT NULL AFTER update_type",
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'case_updates' AND COLUMN_NAME = 'comm_person'
);
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE case_updates ADD COLUMN comm_person varchar(150) DEFAULT NULL AFTER comm_type',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
