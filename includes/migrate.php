<?php
// includes/migrate.php
//
// Additive-only migration runner. Each schema change ships as a numbered
// file in includes/migrations/, applied once and tracked in
// schema_migrations. Fresh installs mark every existing migration file as
// already-applied instead of replaying them, since polaris_create.sql
// already creates the current shape directly.

function run_pending_migrations(mysqli $conn, bool $markOnly = false): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS schema_migrations (
            id int NOT NULL AUTO_INCREMENT,
            migration_name varchar(255) NOT NULL,
            applied_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY migration_name (migration_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");

    $migrationsDir = __DIR__ . '/migrations';
    if (!is_dir($migrationsDir)) {
        return;
    }
    $files = glob($migrationsDir . '/*.sql');
    if ($files === false || empty($files)) {
        return;
    }
    sort($files); // numeric prefixes sort in run order

    $applied = [];
    $res = $conn->query("SELECT migration_name FROM schema_migrations");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $applied[$row['migration_name']] = true;
        }
    }

    $pending = array_filter($files, fn($f) => !isset($applied[basename($f)]));
    if (empty($pending)) {
        return;
    }

    if ($markOnly) {
        $stmt = $conn->prepare("INSERT IGNORE INTO schema_migrations (migration_name) VALUES (?)");
        foreach ($pending as $file) {
            $name = basename($file);
            $stmt->bind_param("s", $name);
            $stmt->execute();
        }
        $stmt->close();
        return;
    }

    $lockResult = $conn->query("SELECT GET_LOCK('polaris_schema_migrations', 10) AS got");
    $gotLock = $lockResult && ($lockRow = $lockResult->fetch_assoc()) && (int) $lockRow['got'] === 1;
    if (!$gotLock) {
        error_log("run_pending_migrations: could not acquire lock, skipping this request");
        return;
    }

    try {
        // Re-check under the lock.
        $applied = [];
        $res = $conn->query("SELECT migration_name FROM schema_migrations");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $applied[$row['migration_name']] = true;
            }
        }

        foreach ($files as $file) {
            $name = basename($file);
            if (isset($applied[$name])) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false || trim($sql) === '') {
                continue;
            }

            $ok = true;
            if ($conn->multi_query($sql)) {
                do {
                    if ($result = $conn->store_result()) {
                        $result->free();
                    }
                    if ($conn->errno) {
                        $ok = false;
                        error_log("Migration $name failed: [{$conn->errno}] {$conn->error}");
                    }
                } while ($conn->more_results() && $conn->next_result());
            } else {
                $ok = false;
                error_log("Migration $name multi_query() failed: [{$conn->errno}] {$conn->error}");
            }

            if ($ok) {
                $stmt = $conn->prepare("INSERT INTO schema_migrations (migration_name) VALUES (?)");
                $stmt->bind_param("s", $name);
                $stmt->execute();
                $stmt->close();
            } else {
                // Stop here - later files may depend on this one; retries next request.
                break;
            }
        }
    } finally {
        $conn->query("SELECT RELEASE_LOCK('polaris_schema_migrations')");
    }
}
