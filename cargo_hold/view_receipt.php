<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../db.php';
require_once '../includes/permissions.php';
require_permission($conn, 'exhibit_view');

$receipt_id = isset($_GET['receipt_id']) ? intval($_GET['receipt_id']) : 0;
$stmt = $conn->prepare("SELECT file_path FROM exhibit_receipts WHERE receipt_id = ?");
$stmt->bind_param("i", $receipt_id);
$stmt->execute();
$result = $stmt->get_result();
$receipt = $result->fetch_assoc();
$stmt->close();

if ($receipt && file_exists($receipt['file_path'])) {
    header('Content-Type: text/html; charset=UTF-8');
    readfile($receipt['file_path']);
    exit;
} else {
    http_response_code(404);
    echo "Receipt not found.";
}
