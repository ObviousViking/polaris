-- Persisted exhibit book-in/book-out receipts (see includes/exhibit_receipts.php).
-- A receipt is generated and saved to disk once, at the moment exhibits are
-- booked in/out, so it can be viewed later exactly as it looked at that
-- point in time - rather than exhibit_receipt.php's older behaviour of
-- recomputing the receipt live from current exhibit data on every view.
-- exhibit_receipt_items is a junction, since one receipt can cover several
-- exhibits booked in/out together in the same batch.

CREATE TABLE IF NOT EXISTS exhibit_receipts (
  receipt_id int NOT NULL AUTO_INCREMENT,
  job_id int NOT NULL,
  receipt_type enum('in','out') NOT NULL,
  booked_out_to varchar(255) DEFAULT NULL,
  file_path text NOT NULL,
  generated_by int NOT NULL,
  generated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (receipt_id),
  KEY job_id (job_id),
  KEY generated_by (generated_by),
  CONSTRAINT fk_exhibit_receipts_job FOREIGN KEY (job_id) REFERENCES jobs (job_id),
  CONSTRAINT fk_exhibit_receipts_user FOREIGN KEY (generated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS exhibit_receipt_items (
  receipt_id int NOT NULL,
  exhibit_id int NOT NULL,
  PRIMARY KEY (receipt_id, exhibit_id),
  KEY exhibit_id (exhibit_id),
  CONSTRAINT fk_exhibit_receipt_items_receipt FOREIGN KEY (receipt_id) REFERENCES exhibit_receipts (receipt_id) ON DELETE CASCADE,
  CONSTRAINT fk_exhibit_receipt_items_exhibit FOREIGN KEY (exhibit_id) REFERENCES exhibits (exhibit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
