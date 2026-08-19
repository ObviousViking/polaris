<?php
// upload_case_item_file.php
//
// Adds a new version of a file to a produced item. Never overwrites a
// previous version - each upload is a new row/new file on disk, numbered
// sequentially per item_id, so every prior version stays downloadable (see
// includes/migrations/022_produced_items.sql).
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/settings.php';
require_once '../includes/integrity.php';
require_once '../includes/permissions.php';
require_permission($conn, 'exhibit_edit');

$item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
$job_id = isset($_POST['job_id']) ? intval($_POST['job_id']) : 0;

if ($item_id <= 0) {
    die("Item ID not specified.");
}

$stmt = $conn->prepare("SELECT item_ref FROM case_items WHERE item_id = ?");
$stmt->bind_param("i", $item_id);
$stmt->execute();
$stmt->bind_result($item_ref);
if (!$stmt->fetch()) {
    die("Produced item not found.");
}
$stmt->close();

$redirect = "edit_case_item.php?item_id=$item_id&job_id=$job_id";

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['item_file']) || $_FILES['item_file']['error'] === UPLOAD_ERR_NO_FILE) {
    header("Location: $redirect");
    exit();
}

if ($_FILES['item_file']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['case_item_message'] = "File upload failed (error code {$_FILES['item_file']['error']}). Please try a smaller file.";
    header("Location: $redirect");
    exit();
}

$allowed_extensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'jpg', 'jpeg', 'png', 'zip'];
$original_name = basename($_FILES['item_file']['name']);
$extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

if (!in_array($extension, $allowed_extensions, true)) {
    $_SESSION['case_item_message'] = "File type not allowed. Permitted types: " . implode(', ', $allowed_extensions) . ".";
    header("Location: $redirect");
    exit();
}

$explainer = trim($_POST['explainer'] ?? '');

// Next version number for this item - append-only, so this is always
// MAX(version) + 1, never reused even if a row were ever removed.
$verStmt = $conn->prepare("SELECT COALESCE(MAX(version), 0) + 1 FROM case_item_files WHERE item_id = ?");
$verStmt->bind_param("i", $item_id);
$verStmt->execute();
$verStmt->bind_result($nextVersion);
$verStmt->fetch();
$verStmt->close();

$config = get_storage_settings($conn);
$item_ref_safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $item_ref);
$upload_dir = $config['paths']['produced_item_dir_fs'] . "$item_id-$item_ref_safe/";
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$stored_filename = "v{$nextVersion}_" . date('Ymd_His') . "_" . bin2hex(random_bytes(4)) . "." . $extension;
$target_path = $upload_dir . $stored_filename;

if (!move_uploaded_file($_FILES['item_file']['tmp_name'], $target_path)) {
    $_SESSION['case_item_message'] = "Upload failed. Please check file permissions or try a smaller file.";
    header("Location: $redirect");
    exit();
}

$file_size = filesize($target_path) ?: null;
$uploaded_by = (int) $_SESSION['user_id'];

$insertStmt = $conn->prepare("
    INSERT INTO case_item_files (item_id, version, original_filename, stored_filename, file_path, file_size, explainer, uploaded_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");
$insertStmt->bind_param("iisssisi", $item_id, $nextVersion, $original_name, $stored_filename, $target_path, $file_size, $explainer, $uploaded_by);

if ($insertStmt->execute()) {
    insert_history_row($conn, 'case_item_history', $item_id, 'FILE_UPLOAD', $uploaded_by, json_encode([
        'filename'  => $original_name,
        'version'   => $nextVersion,
        'explainer' => $explainer,
    ]));
    $_SESSION['case_item_message'] = "File uploaded as version $nextVersion.";
} else {
    $_SESSION['case_item_message'] = "Error saving file record: " . $conn->error;
}
$insertStmt->close();

header("Location: $redirect");
exit();
