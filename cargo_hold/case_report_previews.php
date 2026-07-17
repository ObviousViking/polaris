<?php
// cargo_hold/case_report_previews.php
//
// Lazy AJAX companion to case_report.php. Rendering an embeddable document
// into page-images (LibreOffice + pdftoppm) is the slow part of building a
// report, and the appendix checkbox on that page is off by default - so
// this is only called once a user actually turns the appendix on, rather
// than blocking the report's initial page load for every visit regardless
// of whether anyone looks at the appendix. Document identity (job_id ->
// its exhibits/case documents) is re-derived server-side rather than
// trusting any client-supplied file paths.
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit();
}
require_once '../db.php';
require_once '../includes/document_preview.php';

header('Content-Type: application/json');

if (!isset($_GET['job_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Job ID not specified.']);
    exit();
}
$job_id = intval($_GET['job_id']);

$items = [];

$stmt = $conn->prepare("
    SELECT ed.original_filename, ed.file_path
    FROM exhibit_documents ed
    JOIN exhibits e ON ed.exhibit_id = e.exhibit_id
    WHERE e.job_id = ? AND e.deleted_at IS NULL
");
$stmt->bind_param("i", $job_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    if (is_embeddable_extension($row['original_filename'])) {
        $items[$row['file_path']] = [
            'file_path' => $row['file_path'],
            'original_filename' => $row['original_filename'],
        ];
    }
}
$stmt->close();

$stmt = $conn->prepare("SELECT original_filename, file_path FROM case_documents WHERE job_id = ?");
$stmt->bind_param("i", $job_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    if (is_embeddable_extension($row['original_filename'])) {
        $items[$row['file_path']] = [
            'file_path' => $row['file_path'],
            'original_filename' => $row['original_filename'],
        ];
    }
}
$stmt->close();

echo json_encode(render_document_page_images_batch($items));
