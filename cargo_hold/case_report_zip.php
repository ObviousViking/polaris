<?php
// cargo_hold/case_report_zip.php
//
// Downloads the original attachments for whatever's selected in the Case
// Report builder, as a ZIP. Exhibit/document IDs are re-validated against
// job_id server-side rather than trusted as given.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';

if (!isset($_GET['job_id'])) {
    die("Job ID not specified.");
}
$job_id = intval($_GET['job_id']);

$jobStmt = $conn->prepare("SELECT custom_ref FROM jobs WHERE job_id = ?");
$jobStmt->bind_param("i", $job_id);
$jobStmt->execute();
$jobStmt->bind_result($custom_ref);
if (!$jobStmt->fetch()) {
    die("Case not found.");
}
$jobStmt->close();

$requestedIds = array_filter(array_map('intval', explode(',', $_GET['exhibit_ids'] ?? '')));
$includeSub = ($_GET['include_sub'] ?? '0') === '1';
$includeCaseDocuments = ($_GET['include_case_documents'] ?? '0') === '1';

if (empty($requestedIds)) {
    die("No exhibits selected.");
}

// Confirm every requested id actually belongs to this job.
$placeholders = implode(',', array_fill(0, count($requestedIds), '?'));
$stmt = $conn->prepare("SELECT exhibit_id, exhibit_ref FROM exhibits WHERE job_id = ? AND deleted_at IS NULL AND exhibit_id IN ($placeholders)");
$types = 'i' . str_repeat('i', count($requestedIds));
$stmt->bind_param($types, $job_id, ...$requestedIds);
$stmt->execute();
$res = $stmt->get_result();
$exhibitRefs = [];
while ($row = $res->fetch_assoc()) {
    $exhibitRefs[$row['exhibit_id']] = $row['exhibit_ref'];
}
$stmt->close();

if (empty($exhibitRefs)) {
    die("No valid exhibits selected.");
}

$exhibitIds = array_keys($exhibitRefs);

// Expands the set one generation of sub-exhibits at a time.
if ($includeSub) {
    $frontier = $exhibitIds;
    while (!empty($frontier)) {
        $fPlaceholders = implode(',', array_fill(0, count($frontier), '?'));
        $stmt = $conn->prepare("SELECT exhibit_id, exhibit_ref FROM exhibits WHERE job_id = ? AND deleted_at IS NULL AND parent_id IN ($fPlaceholders)");
        $types = 'i' . str_repeat('i', count($frontier));
        $stmt->bind_param($types, $job_id, ...$frontier);
        $stmt->execute();
        $res = $stmt->get_result();
        $frontier = [];
        while ($row = $res->fetch_assoc()) {
            if (!isset($exhibitRefs[$row['exhibit_id']])) {
                $exhibitRefs[$row['exhibit_id']] = $row['exhibit_ref'];
                $frontier[] = $row['exhibit_id'];
            }
        }
        $stmt->close();
    }
    $exhibitIds = array_keys($exhibitRefs);
}

$tmpZip = tempnam(sys_get_temp_dir(), 'case_zip_') . '.zip';
$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE) !== true) {
    die("Could not create zip file.");
}

$usedNames = [];
function zip_add_unique(ZipArchive $zip, string $filePath, string $folder, string $filename, array &$usedNames): void
{
    if (!is_file($filePath)) {
        return;
    }
    $internalPath = $folder . '/' . $filename;
    $suffix = 1;
    while (isset($usedNames[$internalPath])) {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $internalPath = $folder . '/' . $base . ' (' . (++$suffix) . ')' . ($ext !== '' ? '.' . $ext : '');
    }
    $usedNames[$internalPath] = true;
    $zip->addFile($filePath, $internalPath);
}

$placeholders = implode(',', array_fill(0, count($exhibitIds), '?'));
$types = str_repeat('i', count($exhibitIds));

$stmt = $conn->prepare("SELECT exhibit_id, original_filename, file_path FROM exhibit_documents WHERE exhibit_id IN ($placeholders)");
$stmt->bind_param($types, ...$exhibitIds);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $folder = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $exhibitRefs[$row['exhibit_id']]) . '/Documents';
    zip_add_unique($zip, $row['file_path'], $folder, $row['original_filename'], $usedNames);
}
$stmt->close();

$stmt = $conn->prepare("SELECT exhibit_id, file_name, file_path FROM exhibit_photos WHERE exhibit_id IN ($placeholders)");
$stmt->bind_param($types, ...$exhibitIds);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $folder = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $exhibitRefs[$row['exhibit_id']]) . '/Photos';
    zip_add_unique($zip, $row['file_path'], $folder, $row['file_name'], $usedNames);
}
$stmt->close();

if ($includeCaseDocuments) {
    $stmt = $conn->prepare("SELECT original_filename, file_path FROM case_documents WHERE job_id = ?");
    $stmt->bind_param("i", $job_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        zip_add_unique($zip, $row['file_path'], 'Case Documents', $row['original_filename'], $usedNames);
    }
    $stmt->close();
}

$zip->close();

$safeRef = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $custom_ref);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename=' . $safeRef . '_attachments.zip');
header('Content-Length: ' . filesize($tmpZip));
readfile($tmpZip);
unlink($tmpZip);
exit;
