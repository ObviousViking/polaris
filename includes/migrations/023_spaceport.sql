-- Spaceport: the submissions portal. Officers file a full case request with
-- no job_id yet; a reviewer approves (creates the real job), rejects (reason
-- required), or requests more info (officer amends and resubmits). Declared
-- exhibits are reconciled against reality at book-in, not trusted outright.
-- See spaceport design reference for the full rationale.

CREATE TABLE IF NOT EXISTS external_contacts (
  contact_id int NOT NULL AUTO_INCREMENT,
  name varchar(255) NOT NULL,
  role_title varchar(255) DEFAULT NULL,
  phone varchar(50) DEFAULT NULL,
  email varchar(255) DEFAULT NULL,
  force_id int DEFAULT NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (contact_id),
  KEY force_id (force_id),
  CONSTRAINT fk_external_contacts_force FOREIGN KEY (force_id) REFERENCES forces (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS submissions (
  submission_id int NOT NULL AUTO_INCREMENT,
  status enum('Pending','More Info Requested','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  case_type_id int DEFAULT NULL,
  initial_summary text,
  oic varchar(255) DEFAULT NULL,
  operation int DEFAULT NULL,
  customer_id int DEFAULT NULL,
  lead_force_id int DEFAULT NULL,
  suspect varchar(255) DEFAULT NULL,
  fingerprints tinyint(1) DEFAULT 0,
  dna tinyint(1) DEFAULT 0,
  malware tinyint(1) DEFAULT 0,
  incident_number varchar(100) DEFAULT NULL,
  external_reference varchar(100) DEFAULT NULL,
  contact_primary_id int DEFAULT NULL,
  contact_secondary_id int DEFAULT NULL,
  submitted_by int NOT NULL,
  submitted_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by int DEFAULT NULL,
  reviewed_at datetime DEFAULT NULL,
  decision_reason text,
  info_requested_note text,
  job_id int DEFAULT NULL,
  PRIMARY KEY (submission_id),
  KEY case_type_id (case_type_id),
  KEY customer_id (customer_id),
  KEY lead_force_id (lead_force_id),
  KEY contact_primary_id (contact_primary_id),
  KEY contact_secondary_id (contact_secondary_id),
  KEY submitted_by (submitted_by),
  KEY reviewed_by (reviewed_by),
  KEY job_id (job_id),
  CONSTRAINT fk_submissions_case_type FOREIGN KEY (case_type_id) REFERENCES case_types (case_type_id),
  CONSTRAINT fk_submissions_customer FOREIGN KEY (customer_id) REFERENCES customers (customer_id),
  CONSTRAINT fk_submissions_force FOREIGN KEY (lead_force_id) REFERENCES forces (id),
  CONSTRAINT fk_submissions_contact_primary FOREIGN KEY (contact_primary_id) REFERENCES external_contacts (contact_id),
  CONSTRAINT fk_submissions_contact_secondary FOREIGN KEY (contact_secondary_id) REFERENCES external_contacts (contact_id),
  CONSTRAINT fk_submissions_submitted_by FOREIGN KEY (submitted_by) REFERENCES users (id),
  CONSTRAINT fk_submissions_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users (id),
  CONSTRAINT fk_submissions_job FOREIGN KEY (job_id) REFERENCES jobs (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Append-only, hash-chained - identical pattern to case_item_history.
CREATE TABLE IF NOT EXISTS submission_history (
  history_id int NOT NULL AUTO_INCREMENT,
  submission_id int NOT NULL,
  action enum('CREATE','UPDATE','MORE_INFO_REQUESTED','RESUBMITTED','APPROVED','REJECTED') NOT NULL,
  changed_by int NOT NULL,
  changed_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  changes text,
  prev_hash char(64) NOT NULL,
  row_hash char(64) NOT NULL,
  prev_hmac char(64) NOT NULL,
  hmac_hash char(64) NOT NULL,
  PRIMARY KEY (history_id),
  KEY submission_id (submission_id),
  KEY changed_by (changed_by),
  CONSTRAINT fk_submission_history_submission FOREIGN KEY (submission_id) REFERENCES submissions (submission_id),
  CONSTRAINT fk_submission_history_user FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TRIGGER IF EXISTS submission_history_hash_chain;
CREATE TRIGGER submission_history_hash_chain BEFORE INSERT ON submission_history
FOR EACH ROW
BEGIN
    DECLARE prev CHAR(64);
    SELECT row_hash INTO prev FROM submission_history ORDER BY history_id DESC LIMIT 1;
    IF prev IS NULL THEN
        SET prev = REPEAT('0', 64);
    END IF;
    SET NEW.prev_hash = prev;
    SET NEW.row_hash = SHA2(CONCAT_WS('|', NEW.submission_id, NEW.action, NEW.changed_by, NEW.changed_at, IFNULL(NEW.changes, ''), prev), 256);
END;

DROP TRIGGER IF EXISTS submission_history_no_update;
CREATE TRIGGER submission_history_no_update BEFORE UPDATE ON submission_history
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'submission_history is append-only and cannot be modified';
END;

DROP TRIGGER IF EXISTS submission_history_no_delete;
CREATE TRIGGER submission_history_no_delete BEFORE DELETE ON submission_history
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'submission_history is append-only and cannot be deleted from';
END;

-- One row per declared physical item ("Mobile Phone: 2" -> two rows).
-- Reconciled against real exhibits at book-in - matched_exhibit_id is set
-- once staff confirm receipt, reconcile_status tracks the outcome.
CREATE TABLE IF NOT EXISTS submitted_exhibits (
  submitted_exhibit_id int NOT NULL AUTO_INCREMENT,
  submission_id int NOT NULL,
  exhibit_type_id int DEFAULT NULL,
  description varchar(255) DEFAULT NULL,
  bag_number varchar(50) DEFAULT NULL,
  seizing_officer varchar(255) DEFAULT NULL,
  seizing_location varchar(255) DEFAULT NULL,
  reconcile_status enum('Declared','Received','Missing') NOT NULL DEFAULT 'Declared',
  matched_exhibit_id int DEFAULT NULL,
  PRIMARY KEY (submitted_exhibit_id),
  KEY submission_id (submission_id),
  KEY exhibit_type_id (exhibit_type_id),
  KEY matched_exhibit_id (matched_exhibit_id),
  CONSTRAINT fk_submitted_exhibits_submission FOREIGN KEY (submission_id) REFERENCES submissions (submission_id),
  CONSTRAINT fk_submitted_exhibits_type FOREIGN KEY (exhibit_type_id) REFERENCES exhibit_types (exhibit_type_id),
  CONSTRAINT fk_submitted_exhibits_exhibit FOREIGN KEY (matched_exhibit_id) REFERENCES exhibits (exhibit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Subject photo, versioned like case_item_files - old photos stay visible
-- rather than being overwritten. Kept keyed to submission_id only (never
-- job_id): submissions rows are permanent, so a job's photo history is just
-- "subject_photos where submission_id = (select submission_id from
-- submissions where job_id = ?)".
CREATE TABLE IF NOT EXISTS subject_photos (
  photo_id int NOT NULL AUTO_INCREMENT,
  submission_id int NOT NULL,
  version int NOT NULL,
  original_filename varchar(255) NOT NULL,
  stored_filename varchar(255) NOT NULL,
  file_path text NOT NULL,
  uploaded_by int NOT NULL,
  uploaded_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (photo_id),
  UNIQUE KEY submission_version (submission_id, version),
  KEY uploaded_by (uploaded_by),
  CONSTRAINT fk_subject_photos_submission FOREIGN KEY (submission_id) REFERENCES submissions (submission_id),
  CONSTRAINT fk_subject_photos_user FOREIGN KEY (uploaded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Repeatable date + free-text label pairs, declared at submission time.
CREATE TABLE IF NOT EXISTS submission_key_dates (
  key_date_id int NOT NULL AUTO_INCREMENT,
  submission_id int NOT NULL,
  event_date date NOT NULL,
  label varchar(255) NOT NULL,
  PRIMARY KEY (key_date_id),
  KEY submission_id (submission_id),
  CONSTRAINT fk_submission_key_dates_submission FOREIGN KEY (submission_id) REFERENCES submissions (submission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Same shape, but generic to any job - not only ones that arrived via
-- Spaceport. Copied across from submission_key_dates on approval; staff can
-- also add more directly on a job that never went through submission.
CREATE TABLE IF NOT EXISTS case_key_dates (
  key_date_id int NOT NULL AUTO_INCREMENT,
  job_id int NOT NULL,
  event_date date NOT NULL,
  label varchar(255) NOT NULL,
  created_by int DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (key_date_id),
  KEY job_id (job_id),
  KEY created_by (created_by),
  CONSTRAINT fk_case_key_dates_job FOREIGN KEY (job_id) REFERENCES jobs (job_id),
  CONSTRAINT fk_case_key_dates_user FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Admin-defined extra questions applied to every submission - same spirit as
-- the exhibit metadata pool, but its own tables since the domain doesn't
-- match what process_fields is wired to.
CREATE TABLE IF NOT EXISTS submission_question_fields (
  field_id int NOT NULL AUTO_INCREMENT,
  field_label varchar(255) NOT NULL,
  field_key varchar(100) NOT NULL,
  field_type enum('text','textarea','number','date','checkbox') NOT NULL DEFAULT 'text',
  is_required tinyint(1) NOT NULL DEFAULT 0,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  sort_order int NOT NULL DEFAULT 0,
  PRIMARY KEY (field_id),
  UNIQUE KEY field_key (field_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS submission_question_values (
  value_id int NOT NULL AUTO_INCREMENT,
  submission_id int NOT NULL,
  field_id int NOT NULL,
  value text,
  PRIMARY KEY (value_id),
  UNIQUE KEY submission_field (submission_id, field_id),
  KEY field_id (field_id),
  CONSTRAINT fk_submission_question_values_submission FOREIGN KEY (submission_id) REFERENCES submissions (submission_id),
  CONSTRAINT fk_submission_question_values_field FOREIGN KEY (field_id) REFERENCES submission_question_fields (field_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Mirrors submissions' new optional fields onto the real job once approved.
ALTER TABLE jobs
  ADD COLUMN incident_number varchar(100) DEFAULT NULL,
  ADD COLUMN external_reference varchar(100) DEFAULT NULL;

INSERT IGNORE INTO permissions (permission_key, label, description, category, sort_order) VALUES
  ('submission_create', 'Submit Cases', 'File a new case submission via Spaceport.', 'Spaceport', 90),
  ('submission_view_own', 'View Own Submissions', 'Check the status/history of submissions filed by this user.', 'Spaceport', 91),
  ('submission_review', 'Review Submissions', 'Approve, reject, or request more information on a submission.', 'Spaceport', 92),
  ('manage_submission_questions', 'Manage Submission Questions', 'Define the admin-configurable extra questions shown on the submission form.', 'Spaceport', 93);

INSERT IGNORE INTO role_default_permissions (role, permission_key)
  SELECT 'admin', permission_key FROM permissions WHERE category = 'Spaceport';

INSERT IGNORE INTO user_permissions (user_id, permission_key)
  SELECT u.id, p.permission_key FROM users u JOIN permissions p ON p.category = 'Spaceport' WHERE u.role = 'admin';

INSERT IGNORE INTO roles (role_key, label, is_builtin) VALUES ('submitting_officer', 'Submitting Officer', 0);
INSERT IGNORE INTO role_default_permissions (role, permission_key) VALUES
  ('submitting_officer', 'submission_create'),
  ('submitting_officer', 'submission_view_own');
