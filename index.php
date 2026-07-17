<?php
// index.php
session_start();

// DB connection comes entirely from environment variables (see
// docker-compose.yml) - this app only runs in Docker.
if (!getenv('DB_HOST') || !getenv('DB_USER') || getenv('DB_PASS') === false || !getenv('DB_NAME')) {
    die("Database environment variables (DB_HOST/DB_USER/DB_PASS/DB_NAME) are not set - check docker-compose.yml.");
}

require_once 'db.php';

// Check if there are any users in the users table
$query = "SELECT COUNT(*) as total FROM users";
$result = $conn->query($query);
if (!$result) {
    die("Error checking users table: " . $conn->error);
}
$row = $result->fetch_assoc();

if ($row['total'] == 0) {
    // No users exist; redirect to the setup page to create the super user.
    header("Location: setup.php");
    exit();
} else {
    // Users already exist; send to the login page.
    header("Location: login.php");
    exit();
}
?>
