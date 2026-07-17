<?php
session_start();
require_once 'db.php';

// Process only POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: login.php");
    exit();
}

// --- Rate limiting: track failed login attempts in the session. ---
if (!isset($_SESSION['failed_login_attempts'])) {
    $_SESSION['failed_login_attempts'] = 0;
}
if (!isset($_SESSION['last_failed_login'])) {
    $_SESSION['last_failed_login'] = time();
}

$max_attempts = 5;    // Maximum allowed failed attempts.
$lockout_time = 300;  // Lockout period in seconds (e.g., 300 seconds = 5 minutes).

if ($_SESSION['failed_login_attempts'] >= $max_attempts && (time() - $_SESSION['last_failed_login']) < $lockout_time) {
    $error = "Too many failed login attempts. Please try again in a few minutes.";
    include 'login.php';
    exit();
}

// --- Input Handling ---
$email = trim($_POST['email']);
$password = $_POST['password'];

// Validate email format.
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = "Invalid email format.";
    include 'login.php';
    exit();
}

// --- Use Prepared Statements to Prevent SQL Injection ---
$stmt = $conn->prepare("SELECT id, password FROM users WHERE email = ? LIMIT 1");
if ($stmt === false) {
    error_log("Prepare failed: " . $conn->error);
    $error = "Internal server error.";
    include 'login.php';
    exit();
}
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    // No user found with that email.
    $_SESSION['failed_login_attempts']++;
    $_SESSION['last_failed_login'] = time();
    $error = "No user found with that email address.";
    include 'login.php';
    exit();
}

$stmt->bind_result($user_id, $hash);
$stmt->fetch();

// --- Password Verification ---
if (password_verify($password, $hash)) {
    // Successful login.
    session_regenerate_id(true); // Regenerate session ID to prevent session fixation.
    $_SESSION['user_id'] = $user_id;

    // Reset failed login attempts on success.
    $_SESSION['failed_login_attempts'] = 0;
    $_SESSION['last_failed_login'] = time();

    require_once 'includes/achievements.php';
    check_and_unlock_achievements($conn, (int) $user_id, 'first_login');
    check_and_unlock_achievements($conn, (int) $user_id, 'tenure_days');

    header("Location: dashboard.php");
    exit();
} else {
    // Incorrect password.
    $_SESSION['failed_login_attempts']++;
    $_SESSION['last_failed_login'] = time();
    $error = "Invalid password.";
    include 'login.php';
    exit();
}
?>