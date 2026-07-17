<?php
// includes/backup.php
//
// Shared plumbing for the full system backup/restore feature (System
// Management -> System Settings - see captains_quarters/backup_download.php
// and restore_process.php). A "backup" is a single .tar.gz containing:
//
//   database.sql       - a full mysqldump of the polaris database
//   avatars/            (only if present)
//   exhibit-photos/      "
//   exhibit-documents/   "
//   case-documents/      "
//
// Shells out to mysqldump/mysql/tar (via proc_open with an array command -
// no shell involved, so there's no quoting/injection surface) rather than
// reimplementing a SQL dumper or archive format in PHP. Credentials are
// passed via the MYSQL_PWD environment variable, not a command-line flag,
// so they never show up in `ps`.

const BACKUP_DATA_SUBFOLDERS = ['avatars', 'exhibit-photos', 'exhibit-documents', 'case-documents'];

// Runs a command with no shell involved (array form - each element is a
// literal argv entry, not shell-interpreted), optionally redirecting stdin
// from a file and stdout to a file. Returns the exit code; stderr is
// captured and returned for logging (never shown to the browser).
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

// Recursively deletes a directory - used to clean up temp backup/restore
// working directories, which may contain a full copy of every uploaded file.
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
