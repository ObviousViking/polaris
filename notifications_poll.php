<?php
// notifications_poll.php
//
// JSON endpoint polled by header.php's toast notifications - returns this
// user's currently-unread notifications.
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([]);
    exit();
}

require_once __DIR__ . '/db.php';

$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT id, type, message, created_at FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 20");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}

echo json_encode($notifications);
