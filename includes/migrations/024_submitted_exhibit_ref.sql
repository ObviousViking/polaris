-- The exhibit ref is assigned by the seizing officer and doesn't change
-- once set, same as the bag number - so it belongs on the declaration
-- itself, not invented later at book-in. book_in_exhibits.php now prefills
-- (but still allows correcting) this value rather than asking staff to
-- assign a fresh one from scratch.

ALTER TABLE submitted_exhibits
  ADD COLUMN exhibit_ref varchar(50) DEFAULT NULL AFTER exhibit_type_id;
