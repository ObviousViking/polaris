-- Extends case_items (displayed to users as "Produced Items" - see
-- includes/migrations/014_case_items.sql for the consolidation this built
-- on) with the fields ISO17025/FSR-style tracking needs: a file count, a
-- formal book-out/book-in cycle with its own printed receipt (mirroring
-- exhibit_receipts - see includes/migrations/010_exhibit_receipts.sql and
-- 011_exhibit_return.sql), and versioned file uploads that keep every prior
-- version rather than overwriting it.
--
-- last_handed_to/last_handed_to_at (added in 014, alongside the HANDOVER
-- history action) were always a placeholder for this exact feature, so
-- they're renamed in place rather than duplicated - existing data, if any,
-- carries over as the most recent book-out.

ALTER TABLE case_items
  CHANGE COLUMN last_handed_to booked_out_to varchar(255) DEFAULT NULL,
  CHANGE COLUMN last_handed_to_at booked_out_at datetime DEFAULT NULL,
  ADD COLUMN returned_at datetime DEFAULT NULL AFTER booked_out_at,
  ADD COLUMN file_count int unsigned DEFAULT NULL AFTER notes;

ALTER TABLE case_item_history
  MODIFY COLUMN action enum('CREATE','UPDATE','HANDOVER','BOOK_OUT','BOOK_IN','FILE_UPLOAD') NOT NULL;

-- Versioned file uploads. Append-only by convention (nothing in the app
-- ever updates or deletes a row here) - the current version of a file is
-- just the highest `version` for that item_id, so every prior version stays
-- downloadable via case_item_files rather than being overwritten on disk.
CREATE TABLE IF NOT EXISTS case_item_files (
  file_id int NOT NULL AUTO_INCREMENT,
  item_id int NOT NULL,
  version int NOT NULL,
  original_filename varchar(255) NOT NULL,
  stored_filename varchar(255) NOT NULL,
  file_path text NOT NULL,
  file_size int DEFAULT NULL,
  explainer text,
  uploaded_by int NOT NULL,
  uploaded_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (file_id),
  UNIQUE KEY item_version (item_id, version),
  KEY uploaded_by (uploaded_by),
  CONSTRAINT fk_case_item_files_item FOREIGN KEY (item_id) REFERENCES case_items (item_id),
  CONSTRAINT fk_case_item_files_user FOREIGN KEY (uploaded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Persisted produced-item book-out/book-in receipts - same shape and same
-- "render once, save the exact snapshot" approach as exhibit_receipts/
-- exhibit_receipt_items (includes/exhibit_receipts.php), just against
-- case_items instead of exhibits. A junction table since one receipt can
-- cover several items booked out/in together in the same batch.
CREATE TABLE IF NOT EXISTS case_item_receipts (
  receipt_id int NOT NULL AUTO_INCREMENT,
  job_id int NOT NULL,
  receipt_type enum('out','in') NOT NULL,
  booked_out_to varchar(255) DEFAULT NULL,
  file_path text NOT NULL,
  generated_by int NOT NULL,
  generated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (receipt_id),
  KEY job_id (job_id),
  KEY generated_by (generated_by),
  CONSTRAINT fk_case_item_receipts_job FOREIGN KEY (job_id) REFERENCES jobs (job_id),
  CONSTRAINT fk_case_item_receipts_user FOREIGN KEY (generated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS case_item_receipt_items (
  receipt_id int NOT NULL,
  item_id int NOT NULL,
  PRIMARY KEY (receipt_id, item_id),
  KEY item_id (item_id),
  CONSTRAINT fk_case_item_receipt_items_receipt FOREIGN KEY (receipt_id) REFERENCES case_item_receipts (receipt_id) ON DELETE CASCADE,
  CONSTRAINT fk_case_item_receipt_items_item FOREIGN KEY (item_id) REFERENCES case_items (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
