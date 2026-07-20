<?php
// includes/session_bootstrap.php
//
// Auto-prepended to every request (see docker/php-overrides.ini) so the
// DB-backed session handler is registered before any page's session_start().

// Restore the classic "check the return value" mysqli style.
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

        // Bootstrap the schema on first boot. Locked to avoid two requests
        // racing to import at once right after the container starts.
        $freshInstall = false;
        if (@$conn->query("SELECT 1 FROM settings LIMIT 1") === false) {
            $lockResult = $conn->query("SELECT GET_LOCK('polaris_schema_init', 10) AS got");
            $gotLock = $lockResult && ($lockRow = $lockResult->fetch_assoc()) && (int) $lockRow['got'] === 1;

            if ($gotLock) {
                if (@$conn->query("SELECT 1 FROM settings LIMIT 1") === false) {
                    $schema = @file_get_contents(__DIR__ . '/polaris_create.sql');
                    if ($schema !== false && $conn->multi_query($schema)) {
                        // Check every statement's result - multi_query() only
                        // reports the first failure otherwise.
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

        // Additive-only schema migrations for changes since initial install.
        require_once __DIR__ . '/migrate.php';
        run_pending_migrations($conn, $freshInstall);

        require_once __DIR__ . '/achievements.php';
        sync_achievement_catalog($conn);

        require_once __DIR__ . '/permissions.php';
        sync_permission_catalog($conn);
        seed_role_default_permissions_if_empty($conn);
        grandfather_existing_users($conn);

        require_once __DIR__ . '/DbSessionHandler.php';
        $handler = new DbSessionHandler($conn, (int) ini_get('session.gc_maxlifetime'));
        session_set_save_handler($handler, true);
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
