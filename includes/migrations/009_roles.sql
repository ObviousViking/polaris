-- Custom roles catalog, layered on top of the pre-existing free-text
-- users.role column. Built-ins (user/admin/super) are seeded via PHP
-- (sync_roles_catalog() in includes/permissions.php), same pattern as the
-- permissions catalog, to avoid a bootstrap ordering problem on fresh
-- installs. Admins can add/remove further roles via
-- manage_role_permissions.php. role_default_permissions.role has no FK
-- here - deliberately, matches its existing free-text design - the app
-- layer keeps them in sync (see create_role()/delete_role()).

CREATE TABLE IF NOT EXISTS roles (
  role_key varchar(50) NOT NULL,
  label varchar(100) NOT NULL,
  is_builtin tinyint(1) NOT NULL DEFAULT 0,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (role_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
