-- Scopes process types to exhibit types, and distinguishes the "core"
-- expected pipeline for an exhibit type (Pre-Imaging, Imaging, Reseal...)
-- from everything else (Case Events - repeat runs, ad hoc processes).
--
-- Opt-in restriction, same philosophy as the metapool/process-fields work:
-- a process type with zero rows here is unscoped - it keeps today's
-- behavior of being offered on every exhibit type and always counting as a
-- Case Event, never a pipeline checklist item. Only once an admin
-- explicitly assigns it to one or more exhibit types (via
-- captains_quarters/manage_exhibit_type_processes.php) does it become
-- scoped, and only then can it be marked "core" for that type. This means
-- shipping this table with zero rows changes nothing for any existing
-- process on any existing exhibit.
--
-- is_core + sort_order together define the pipeline checklist shown on
-- captains_log/examination.php for a given exhibit type - see that file for
-- how only the *first* recorded instance of a core process type counts
-- toward the checklist; any additional instance (a re-image with a
-- different tool, say) falls through to Case Events like any non-core
-- process does.
CREATE TABLE IF NOT EXISTS process_type_exhibit_types (
  id int NOT NULL AUTO_INCREMENT,
  process_type_id int NOT NULL,
  exhibit_type_id int NOT NULL,
  is_core tinyint(1) NOT NULL DEFAULT 0,
  sort_order int NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY process_type_exhibit_type (process_type_id, exhibit_type_id),
  KEY exhibit_type_id (exhibit_type_id),
  CONSTRAINT fk_ptet_process_type FOREIGN KEY (process_type_id) REFERENCES process_types (id),
  CONSTRAINT fk_ptet_exhibit_type FOREIGN KEY (exhibit_type_id) REFERENCES exhibit_types (exhibit_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
