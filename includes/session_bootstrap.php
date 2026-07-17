<?php
// includes/session_bootstrap.php
//
// Auto-prepended to every request (see docker/php-overrides.ini ->
// auto_prepend_file), so this runs, and registers the DB-backed session
// handler, BEFORE any page's own session_start() call. Registering a
// custom handler after session_start() has already fired has no effect,
// hence the auto-prepend rather than a plain require somewhere mid-page.

// PHP 8.1+ defaults mysqli to throwing exceptions on error. This file (and
// the rest of the app) expects the older "check the return value" style
// (===false, ->connect_error, etc.), so restore that globally, before any
// mysqli connection is made.
mysqli_report(MYSQLI_REPORT_OFF);

$db_host = getenv('DB_HOST');
$db_port = getenv('DB_PORT') ?: '3306';
$db_user = getenv('DB_USER');
$db_pass = getenv('DB_PASS');
$db_name = getenv('DB_NAME');

if (!empty($db_host) && !empty($db_name)) {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name, (int) $db_port);

    if ($conn && !$conn->connect_error) {
        $GLOBALS['conn'] = $conn;

        // Bootstrap the schema on first boot. The schema ships inside the
        // image itself (includes/polaris_create.sql) rather than relying on
        // a host-mounted MySQL init file - that hit real bind-mount
        // permission issues in practice. GET_LOCK + a double-checked
        // re-query guards against two requests racing to import at once
        // right after the container starts.
        $freshInstall = false;
        if (@$conn->query("SELECT 1 FROM settings LIMIT 1") === false) {
            $lockResult = $conn->query("SELECT GET_LOCK('polaris_schema_init', 10) AS got");
            $gotLock = $lockResult && ($lockRow = $lockResult->fetch_assoc()) && (int) $lockRow['got'] === 1;

            if ($gotLock) {
                if (@$conn->query("SELECT 1 FROM settings LIMIT 1") === false) {
                    $schema = @file_get_contents(__DIR__ . '/polaris_create.sql');
                    if ($schema !== false && $conn->multi_query($schema)) {
                        // multi_query() only reports the FIRST statement's
                        // failure - a later one in the batch can fail
                        // silently unless each iteration's error is checked
                        // explicitly, which is exactly what happened here
                        // once (trigger creation blocked by binlog/SUPER
                        // privilege - see docker-compose.yml). Logged loudly
                        // rather than silently leaving a half-built schema.
                        do {
                            if ($result = $conn->store_result()) {
                                $result->free();
                            }
                            if ($conn->errno) {
                                error_log("Schema bootstrap statement failed: [{$conn->errno}] {$conn->error}");
                            }
                        } while ($conn->more_results() && $conn->next_result());
                        $freshInstall = true;
                    } elseif ($schema !== false) {
                        error_log("Schema bootstrap multi_query() failed: [{$conn->errno}] {$conn->error}");
                    }
                }
                $conn->query("SELECT RELEASE_LOCK('polaris_schema_init')");
            }
        }

        // Additive-only schema migrations for changes shipped after initial
        // install - see includes/migrate.php. A fresh install already has
        // the current shape from polaris_create.sql above, so its migration
        // history is just marked as applied rather than replayed.
        require_once __DIR__ . '/migrate.php';
        run_pending_migrations($conn, $freshInstall);

        // Keeps the achievements catalog (a fixed list defined in code) in
        // sync with the achievements table - cheap once caught up, same
        // philosophy as run_pending_migrations() above.
        require_once __DIR__ . '/achievements.php';
        sync_achievement_catalog($conn);

        require_once __DIR__ . '/DbSessionHandler.php';
        $handler = new DbSessionHandler($conn, (int) ini_get('session.gc_maxlifetime'));
        session_set_save_handler($handler, true);
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
