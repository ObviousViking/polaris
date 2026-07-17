<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'db.php';

$user_id = $_SESSION['user_id'];
$current_password = $_POST['current_password'];
$new_password = $_POST['new_password'];
$confirm_password = $_POST['confirm_password'];

// Check that the new password and confirmation match.
if ($new_password !== $confirm_password) {
    $_SESSION['password_error'] = "New password and confirmation do not match.";
    header("Location: user_profile.php");
    exit();
}

// Retrieve the current password hash.
$stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($hash);
$stmt->fetch();
$stmt->close();

if (!password_verify($current_password, $hash)) {
    $_SESSION['password_error'] = "Current password is incorrect.";
    header("Location: user_profile.php");
    exit();
}

// Hash the new password and update the database.
$new_hash = password_hash($new_password, PASSWORD_DEFAULT);
$stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
$stmt->bind_param("si", $new_hash, $user_id);
$stmt->execute();
$stmt->close();

$_SESSION['password_success'] = "Password changed successfully.";
header("Location: user_profile.php");
exit();
?>