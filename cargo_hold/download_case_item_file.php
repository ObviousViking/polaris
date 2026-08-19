<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../db.php';
require_once '../includes/permissions.php';
require_permission($conn, 'exhibit_view');

$file_id = isset($_GET['file_id']) ? intval($_GET['file_id']) : 0;
$stmt = $conn->prepare("SELECT file_path, original_filename FROM case_item_files WHERE file_id = ?");
$stmt->bind_param("i", $file_id);
$stmt->execute();
$result = $stmt->get_result();
$file = $result->fetch_assoc();
$stmt->close();

if ($file && file_exists($file['file_path'])) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file['original_filename'] . '"');
    header('Content-Length: ' . filesize($file['file_path']));
    readfile($file['file_path']);
    exit;
} else {
    http_response_code(404);
    echo "File not found.";
}
