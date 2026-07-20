<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode([]);
    exit();
}
require_once '../db.php';
require_once '../includes/permissions.php';
if (!user_can($conn, (int) $_SESSION['user_id'], 'case_view')) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode([]);
    exit();
}

if (!isset($_GET['job_id'])) {
    echo json_encode([]);
    exit();
}

$job_id = intval($_GET['job_id']);

// Deletes are hard deletes (see delete_update.php) - no deleted_at flag to filter here.
$stmt = $conn->prepare("SELECT cu.update_id, u.first_name, u.last_name, cu.update_type, cu.comm_type, cu.comm_person, cu.update_date,
                                cu.update_text, cu.updated_at,
                                CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by_name
                        FROM case_updates cu
                        JOIN users u ON cu.user_id = u.id
                        LEFT JOIN users uu ON cu.updated_by = uu.id
                        WHERE cu.job_id = ?
                        ORDER BY cu.update_date DESC");
$stmt->bind_param("i", $job_id);
$stmt->execute();
$result = $stmt->get_result();
$updates = [];
while ($row = $result->fetch_assoc()) {
    $updates[] = $row;
}
$stmt->close();

header('Content-Type: application/json');
echo json_encode($updates);
