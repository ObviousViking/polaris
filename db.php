<?php
// db.php
//
// DB connection comes entirely from environment variables - this app only
// runs in Docker (see docker-compose.yml).

// includes/session_bootstrap.php (auto-prepended, see docker/php-overrides.ini)
// has already opened a connection for the session handler - reuse it
// instead of opening a second one.
if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
    $conn = $GLOBALS['conn'];
} else {
    // See includes/session_bootstrap.php for why this is needed on PHP 8.1+.
    mysqli_report(MYSQLI_REPORT_OFF);

    $db_host = getenv('DB_HOST');
    $db_port = getenv('DB_PORT') ?: '3306';
    $db_user = getenv('DB_USER');
    $db_pass = getenv('DB_PASS');
    $db_name = getenv('DB_NAME');

    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name, (int) $db_port);

    if ($conn->connect_error) {
        die("Database connection failed: " . $conn->connect_error);
    }

    $GLOBALS['conn'] = $conn;
}
?>
