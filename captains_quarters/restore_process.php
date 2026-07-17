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

$stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($role);
$stmt->fetch();
$stmt->close();

if ($role !== 'admin' && $role !== 'super') {
    header("Location: ../dashboard.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: system_settings.php");
    exit();
}

function fail(string $message): void
{
    $_SESSION['restore_message'] = $message;
    $_SESSION['restore_message_type'] = 'error';
    header("Location: system_settings.php");
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
if (!preg_match('/\.(tar\.gz|tgz)$/i', $origName)) {
    fail("Invalid file type - expected a .tar.gz backup archive (the format backup_download.php produces).");
}

$extractDir = sys_get_temp_dir() . '/polaris_restore_' . bin2hex(random_bytes(8));
if (!mkdir($extractDir, 0700, true)) {
    fail("Could not create a temp working directory for the restore.");
}

$extract = backup_run(['tar', 'xzf', $_FILES['backup_file']['tmp_name'], '-C', $extractDir]);
if ($extract['exit_code'] !== 0 || !is_file($extractDir . '/database.sql')) {
    error_log("restore_process: tar extract failed or database.sql missing: " . $extract['stderr']);
    backup_rrmdir($extractDir);
    fail("Restore failed - the uploaded file isn't a valid Polaris backup archive.");
}

$db = backup_db_env();
$import = backup_run(
    ['mysql', '-h', $db['host'], '-P', $db['port'], '-u', $db['user'], $db['name']],
    ['MYSQL_PWD' => $db['pass']],
    $extractDir . '/database.sql'
);
if ($import['exit_code'] !== 0) {
    error_log("restore_process: mysql import failed: " . $import['stderr']);
    backup_rrmdir($extractDir);
    fail("Restore failed while importing the database. The existing database was not modified because the import runs in a single pass - check the server log for details.");
}

// Re-read data_root_dir since the DB was just replaced. Uses `cp` instead
// of rename() since the temp dir and data root are on different filesystems.
$dataRoot = rtrim(get_data_root($conn), '/');
foreach (BACKUP_DATA_SUBFOLDERS as $sub) {
    $extractedSub = $extractDir . '/' . $sub;
    if (!is_dir($extractedSub)) {
        continue;
    }
    $liveSub = $dataRoot . '/' . $sub;
    backup_rrmdir($liveSub);
    $copy = backup_run(['cp', '-a', $extractedSub, $liveSub]);
    if ($copy['exit_code'] !== 0) {
        error_log("restore_process: cp failed for $sub: " . $copy['stderr']);
    }
}

backup_rrmdir($extractDir);

log_audit_event($conn, 'backup', null, 'RESTORE', (int) $_SESSION['user_id'], json_encode(['filename' => $origName]));

// sessions was just replaced too, so end this one cleanly and log in fresh.
session_destroy();
header("Location: ../login.php?restored=1");
exit();
