<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    die("Not authenticated");
}

require_once '../db.php';
require_once '../includes/permissions.php';
if (!user_can($conn, (int) $_SESSION['user_id'], 'document_manage')) {
    die("You do not have permission to download photos.");
}

$exhibit_id = isset($_GET['exhibit_id']) ? intval($_GET['exhibit_id']) : 0;
if ($exhibit_id <= 0) die("Invalid exhibit ID");

$stmt = $conn->prepare("SELECT file_name, file_path FROM exhibit_photos WHERE exhibit_id = ?");
$stmt->bind_param("i", $exhibit_id);
$stmt->execute();
$result = $stmt->get_result();

$files = [];
while ($row = $result->fetch_assoc()) {
    $files[] = $row['file_path'];
}

if (empty($files)) {
    die("No photos found.");
}

$tmpZip = tempnam(sys_get_temp_dir(), 'photos_') . '.zip';
$zip = new ZipArchive();

if ($zip->open($tmpZip, ZipArchive::CREATE) !== true) {
    die("Could not create zip file.");
}

foreach ($files as $file) {
    if (file_exists($file)) {
        $zip->addFile($file, basename($file));
    }
}
$zip->close();

header('Content-Type: application/zip');
header('Content-disposition: attachment; filename=exhibit_photos_' . $exhibit_id . '.zip');
header('Content-Length: ' . filesize($tmpZip));
readfile($tmpZip);
unlink($tmpZip);
exit;