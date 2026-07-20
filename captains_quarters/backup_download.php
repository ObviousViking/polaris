<?php
// captains_quarters/backup_download.php
//
// Builds a full backup (DB dump + uploaded files) into a temp .tar.gz and
// streams it to the browser - never written under a web-servable path.
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

$dataRoot = rtrim(get_data_root($conn), '/');
$db = backup_db_env();

$tmpDir = sys_get_temp_dir() . '/polaris_backup_' . bin2hex(random_bytes(8));
if (!mkdir($tmpDir, 0700, true)) {
    http_response_code(500);
    die("Could not create a temp working directory for the backup.");
}

$sqlPath = $tmpDir . '/database.sql';
$dump = backup_run(
    ['mysqldump', '--single-transaction', '--skip-lock-tables', '--routines', '--triggers',
        '-h', $db['host'], '-P', $db['port'], '-u', $db['user'], $db['name']],
    ['MYSQL_PWD' => $db['pass']],
    null,
    $sqlPath
);
if ($dump['exit_code'] !== 0) {
    error_log("backup_download: mysqldump failed: " . $dump['stderr']);
    backup_rrmdir($tmpDir);
    http_response_code(500);
    die("Backup failed while dumping the database. Check the server log for details.");
}

$archivePath = $tmpDir . '/backup.tar.gz';
$tarCmd = ['tar', 'czf', $archivePath, '-C', $tmpDir, 'database.sql'];
foreach (BACKUP_DATA_SUBFOLDERS as $sub) {
    if (is_dir($dataRoot . '/' . $sub)) {
        $tarCmd[] = '-C';
        $tarCmd[] = $dataRoot;
        $tarCmd[] = $sub;
    }
}
$tar = backup_run($tarCmd);
if ($tar['exit_code'] !== 0 || !is_file($archivePath)) {
    error_log("backup_download: tar failed: " . $tar['stderr']);
    backup_rrmdir($tmpDir);
    http_response_code(500);
    die("Backup failed while archiving files. Check the server log for details.");
}

log_audit_event($conn, 'backup', null, 'EXPORT', (int) $_SESSION['user_id'], null);

$filename = 'polaris-backup-' . date('Y-m-d-His') . '.tar.gz';
header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($archivePath));
header('Cache-Control: no-store');
readfile($archivePath);

backup_rrmdir($tmpDir);
