<?php
// bin/reset_password.php
//
// CLI-only password reset. Run from inside the app container:
//
//   docker exec -it polaris_app php bin/reset_password.php user@example.com 'NewPassword123!'

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("This script is CLI-only.\n");
}

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php reset_password.php <email> <new-password>\n");
    exit(1);
}

[, $email, $newPassword] = $argv;

if (strlen($newPassword) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

require_once __DIR__ . '/../db.php';

$hash = password_hash($newPassword, PASSWORD_DEFAULT);
$stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
$stmt->bind_param("ss", $hash, $email);
$stmt->execute();

if ($stmt->affected_rows === 0) {
    fwrite(STDERR, "No user found with email: $email\n");
    exit(1);
}

echo "Password updated for $email\n";
