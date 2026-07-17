<?php
// includes/migrate.php
//
// Small, additive-only migration runner. Called from session_bootstrap.php
// on every request (cheap once caught up - see the early-return below), so
// a fresh `docker compose up` on a NEW image version picks up any schema
// change automatically instead of needing someone to hand-run SQL against
// the running container.
//
// Deliberately does NOT try to diff the live schema against some "expected"
// shape and auto-add/alter/drop columns to match - that's how you silently
// lose data the day a column gets renamed or removed. Instead: each schema
// change ships as its own numbered file in includes/migrations/, applied at
// most once (tracked in schema_migrations) and only ever in the forward
// direction. Nothing in this file will ever DROP or MODIFY existing data -
// that discipline lives in what gets written into each migration file, not
// in code here, so always write new migrations as CREATE/ADD, never
// DROP/RENAME an existing column outright (add a new one and migrate data
// deliberately instead, if that's ever needed).
//
// Fresh installs don't need to replay this history - includes/polaris_create.sql
// already creates the current shape directly - so run_pending_migrations()
// marks every migration file that exists at first-boot time as already
// applied right after the fresh schema import (see session_bootstrap.php).

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
    sort($files); // numeric filename prefixes (001_, 002_, ...) sort in run order

    $applied = [];
    $res = $conn->query("SELECT migration_name FROM schema_migrations");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $applied[$row['migration_name']] = true;
        }
    }

    $pending = array_filter($files, fn($f) => !isset($applied[basename($f)]));
    if (empty($pending)) {
        return; // fast path - the common case on every normal request
    }

    // A fresh install's schema already reflects every migration that
    // existed at that point (see includes/polaris_create.sql) - record them
    // as applied rather than re-running their SQL against a schema that
    // already has it.
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

    // Concurrent requests during/right after a deploy shouldn't race to
    // apply the same migration twice.
    $lockResult = $conn->query("SELECT GET_LOCK('polaris_schema_migrations', 10) AS got");
    $gotLock = $lockResult && ($lockRow = $lockResult->fetch_assoc()) && (int) $lockRow['got'] === 1;
    if (!$gotLock) {
        error_log("run_pending_migrations: could not acquire lock, skipping this request");
        return;
    }

    try {
        // Re-check under the lock in case another request just finished.
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
                // Don't mark it applied, and don't attempt later files this
                // boot - they may depend on this one. It'll retry (and stop
                // at the same place) on the next request until fixed.
                break;
            }
        }
    } finally {
        $conn->query("SELECT RELEASE_LOCK('polaris_schema_migrations')");
    }
}
