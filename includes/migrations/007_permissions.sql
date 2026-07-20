-- Granular per-user permissions (see includes/permissions.php).
-- Schema only - the permissions catalog, role default bundles, and
-- grandfathering of existing users are all populated in PHP on boot,
-- same as achievements.php's sync pattern, to avoid a foreign-key
-- ordering problem (role_default_permissions can't reference rows in
-- permissions until that table has been synced from PHP).

CREATE TABLE IF NOT EXISTS permissions (
  permission_key varchar(50) NOT NULL,
  label varchar(150) NOT NULL,
  description varchar(255) DEFAULT NULL,
  category varchar(50) NOT NULL,
  sort_order int NOT NULL DEFAULT 0,
  PRIMARY KEY (permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS role_default_permissions (
  role varchar(50) NOT NULL,
  permission_key varchar(50) NOT NULL,
  PRIMARY KEY (role, permission_key),
  FOREIGN KEY (permission_key) REFERENCES permissions(permission_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS user_permissions (
  user_id int NOT NULL,
  permission_key varchar(50) NOT NULL,
  PRIMARY KEY (user_id, permission_key),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (permission_key) REFERENCES permissions(permission_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
