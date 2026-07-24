-- Adds support for booking exhibits back in after a checkout (see
-- cargo_hold/book_in_exhibits.php): a new exhibit_history action distinct
-- from the original booking-in event, and a third exhibit_receipts type
-- alongside the existing 'in'/'out'. Plain ALTER TABLE, not DML, so
-- exhibit_history's append-only triggers (which only fire on
-- INSERT/UPDATE/DELETE) don't get in the way.

ALTER TABLE exhibit_history
  MODIFY COLUMN action enum('BOOK_IN','BOOK_OUT','UPDATE','DELETE','RESTORE','RETURN') NOT NULL;

ALTER TABLE exhibit_receipts
  MODIFY COLUMN receipt_type enum('in','out','return') NOT NULL;
