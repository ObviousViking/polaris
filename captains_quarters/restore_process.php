<?php
// captains_quarters/restore_process.php
//
// Restores a backup produced by backup_download.php: re-imports the whole
// database and replaces the uploaded-files subfolders. Destructive and
// irreversible from inside the app.
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once '../db.php';
require_once '../includes/audit.php';
require_once '../includes/settings.php';
require_once '../includes/backup.php';
require_once '../includes/permissions.php';
require_permission($conn, 'manage_backup');

$backupRestoreUrl = 'backup_restore.php' . (isset($_GET['embedded']) ? '?embedded=1' : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: $backupRestoreUrl");
    exit();
}

function fail(string $message): void
{
    global $backupRestoreUrl;
    $_SESSION['restore_message'] = $message;
    $_SESSION['restore_message_type'] = 'error';
    header("Location: $backupRestoreUrl");
    exit();
}

if (trim($_POST['confirm_phrase'] ?? '') !== 'RESTORE') {
    fail("Restore cancelled - you must type RESTORE exactly to confirm.");
}

if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
    $uploadError = $_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE;
    fail($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE
        ? "Backup file is larger than this server currently allows to upload."
        : "No backup file was uploaded, or the upload failed.");
}

$origName = $_FILES['backup_file']['name'];
$result = backup_restore_archive($conn, $_FILES['backup_file']['tmp_name'], $origName);
if (!$result['ok']) {
    fail($result['error']);
}

log_audit_event($conn, 'backup', null, 'RESTORE', (int) $_SESSION['user_id'], json_encode(['filename' => $origName]));

// sessions was just replaced too, so end this one cleanly and log in fresh.
session_destroy();
header("Location: ../login.php?restored=1");
exit();
