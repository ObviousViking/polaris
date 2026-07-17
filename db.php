<?php
// db.php
//
// DB connection from environment variables. Reuses session_bootstrap.php's
// connection if one is already open, instead of opening a second one.
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
