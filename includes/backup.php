<?php
// includes/backup.php
//
// Shared plumbing for full system backup/restore. A backup is a single
// .tar.gz with database.sql (mysqldump) plus the uploaded-file folders.
// Shells out to mysqldump/mysql/tar via proc_open's array form (no shell,
// no injection surface); DB credentials go through MYSQL_PWD so they don't
// show up in `ps`.

const BACKUP_DATA_SUBFOLDERS = ['avatars', 'exhibit-photos', 'exhibit-documents', 'case-documents', 'produced-items', 'produced-item-receipts'];

// Runs a command with no shell involved. Returns exit code + captured stderr.
function backup_run(array $cmd, array $env = [], ?string $stdinFile = null, ?string $stdoutFile = null): array
{
    $descriptors = [
        0 => $stdinFile !== null ? ['file', $stdinFile, 'r'] : ['pipe', 'r'],
        1 => $stdoutFile !== null ? ['file', $stdoutFile, 'w'] : ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $fullEnv = array_merge($_ENV, $env);
    $process = proc_open($cmd, $descriptors, $pipes, null, $fullEnv);
    if (!is_resource($process)) {
        return ['exit_code' => -1, 'stderr' => 'proc_open failed'];
    }

    if ($stdinFile === null) {
        fclose($pipes[0]);
    }
    $stdout = $stdoutFile === null ? stream_get_contents($pipes[1]) : '';
    if ($stdoutFile === null) {
        fclose($pipes[1]);
    }
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    return ['exit_code' => $exitCode, 'stderr' => $stderr, 'stdout' => $stdout];
}

function backup_db_env(): array
{
    return [
        'host' => getenv('DB_HOST'),
        'port' => getenv('DB_PORT') ?: '3306',
        'user' => getenv('DB_USER'),
        'pass' => getenv('DB_PASS'),
        'name' => getenv('DB_NAME'),
    ];
}

// Shared by captains_quarters/restore_process.php (authenticated) and
// setup.php (first-run, no user exists yet to authenticate as) - the actual
// extract/import/copy steps are identical either way.
function backup_restore_archive(mysqli $conn, string $uploadedTmpPath, string $origName): array
{
    if (!preg_match('/\.(tar\.gz|tgz)$/i', $origName)) {
        return ['ok' => false, 'error' => "Invalid file type - expected a .tar.gz backup archive (the format backup_download.php produces)."];
    }

    $extractDir = sys_get_temp_dir() . '/polaris_restore_' . bin2hex(random_bytes(8));
    if (!mkdir($extractDir, 0700, true)) {
        return ['ok' => false, 'error' => "Could not create a temp working directory for the restore."];
    }

    $extract = backup_run(['tar', 'xzf', $uploadedTmpPath, '-C', $extractDir]);
    if ($extract['exit_code'] !== 0 || !is_file($extractDir . '/database.sql')) {
        error_log("backup_restore_archive: tar extract failed or database.sql missing: " . $extract['stderr']);
        backup_rrmdir($extractDir);
        return ['ok' => false, 'error' => "Restore failed - the uploaded file isn't a valid Polaris backup archive."];
    }

    $db = backup_db_env();
    $import = backup_run(
        ['mysql', '-h', $db['host'], '-P', $db['port'], '-u', $db['user'], $db['name']],
        ['MYSQL_PWD' => $db['pass']],
        $extractDir . '/database.sql'
    );
    if ($import['exit_code'] !== 0) {
        error_log("backup_restore_archive: mysql import failed: " . $import['stderr']);
        backup_rrmdir($extractDir);
        return ['ok' => false, 'error' => "Restore failed while importing the database. Check the server log for details."];
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
            error_log("backup_restore_archive: cp failed for $sub: " . $copy['stderr']);
        }
    }

    backup_rrmdir($extractDir);
    return ['ok' => true, 'error' => null];
}

// Recursively deletes a directory (temp backup/restore working folders).
function backup_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            backup_rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}
