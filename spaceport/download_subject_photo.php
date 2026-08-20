<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/permissions.php';
require_once '../includes/spaceport.php';

$user_id = (int) $_SESSION['user_id'];
$photo_id = isset($_GET['photo_id']) ? intval($_GET['photo_id']) : 0;

$stmt = $conn->prepare("
    SELECT sp.file_path, sp.original_filename, s.submitted_by
    FROM subject_photos sp JOIN submissions s ON sp.submission_id = s.submission_id
    WHERE sp.photo_id = ?
");
$stmt->bind_param("i", $photo_id);
$stmt->execute();
$photo = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Reviewers and general case staff can see it, same as the submitting officer.
$canReview = user_can($conn, $user_id, 'submission_review') || user_can($conn, $user_id, 'case_view');
if (!$photo || !($canReview || (int) $photo['submitted_by'] === $user_id)) {
    http_response_code(404);
    echo "Not found.";
    exit();
}

if (file_exists($photo['file_path'])) {
    // A generic application/octet-stream Content-Type made every browser
    // treat this as a download regardless of the inline disposition below -
    // send the image's real MIME type so it renders directly, whether
    // requested as an <img src>, opened in a popup, or hit directly.
    $imageInfo = @getimagesize($photo['file_path']);
    $mimeType = $imageInfo['mime'] ?? 'application/octet-stream';
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: inline; filename="' . $photo['original_filename'] . '"');
    header('Content-Length: ' . filesize($photo['file_path']));
    readfile($photo['file_path']);
    exit;
}
http_response_code(404);
echo "File not found.";
