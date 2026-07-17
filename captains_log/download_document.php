<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../db.php';

$doc_id = isset($_GET['doc_id']) ? intval($_GET['doc_id']) : 0;
$stmt = $conn->prepare("SELECT file_path, original_filename FROM exhibit_documents WHERE id = ?");
$stmt->bind_param("i", $doc_id);
$stmt->execute();
$result = $stmt->get_result();
$doc = $result->fetch_assoc();

if ($doc && file_exists($doc['file_path'])) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $doc['original_filename'] . '"');
    header('Content-Length: ' . filesize($doc['file_path']));
    readfile($doc['file_path']);
    exit;
} else {
    http_response_code(404);
    error_log("Download failed: doc_id=$doc_id, doc=" . ($doc ? 'found' : 'not found') . ", file_exists=" . ($doc && file_exists($doc['file_path']) ? 'true' : 'false'));
    echo "File not found.";
}
?>