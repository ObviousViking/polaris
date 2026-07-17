<?php
// includes/document_preview.php
//
// Renders attached documents as page images for the Case Report appendix.
// PDFs go through pdftoppm; DOCX/TXT are converted to PDF via LibreOffice
// headless first, then through the same pdftoppm step. Everything runs
// locally with no network access at request time.

const EMBEDDABLE_EXTENSIONS = ['txt', 'pdf', 'docx'];

// Caps how many pages get rendered per document.
const APPENDIX_MAX_PAGES = 10;
const APPENDIX_IMAGE_DPI = 110;

// Rendered page images are cached here, keyed by source file path/size/mtime.
const PREVIEW_CACHE_DIR = '/var/www/polaris-data/report-cache';

function is_embeddable_extension(string $filename): bool
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, EMBEDDABLE_EXTENSIONS, true);
}

// Single-document wrapper around render_document_page_images_batch(). Returns
// ['images' => [...], 'total_pages' => int, 'truncated' => bool] or null.
function render_document_page_images(string $filePath, string $originalFilename): ?array
{
    $result = render_document_page_images_batch([
        '_single' => ['file_path' => $filePath, 'original_filename' => $originalFilename],
    ]);
    return $result['_single'];
}

// Renders page images for several documents at once (or reuses cached
// ones). Cache misses convert concurrently instead of one at a time.
function render_document_page_images_batch(array $items): array
{
    $results = [];
    $pending = [];

    foreach ($items as $key => $item) {
        $filePath = $item['file_path'];
        $originalFilename = $item['original_filename'];
        if (!is_embeddable_extension($originalFilename) || !is_file($filePath) || !is_readable($filePath)) {
            $results[$key] = null;
            continue;
        }
        $cached = read_preview_cache($filePath);
        if ($cached !== null) {
            $results[$key] = $cached;
            continue;
        }
        $pending[$key] = $item;
    }

    if (empty($pending)) {
        return $results;
    }

    $workDirs = [];
    $pdfPaths = [];
    $sofficeCommands = [];

    foreach ($pending as $key => $item) {
        $ext = strtolower(pathinfo($item['original_filename'], PATHINFO_EXTENSION));
        $workDir = sys_get_temp_dir() . '/polaris_report_' . uniqid('', true);
        @mkdir($workDir, 0700, true);
        $workDirs[$key] = $workDir;

        if ($ext === 'pdf') {
            $pdfPaths[$key] = $item['file_path'];
            continue;
        }

        $profileDir = $workDir . '/lo_profile';
        $cacheDir = $workDir . '/cache';
        @mkdir($profileDir, 0700, true);
        @mkdir($cacheDir, 0700, true);

        // Give soffice a writable HOME to avoid dconf/fontconfig cache warnings.
        $env = array_merge(getenv() ?: [], [
            'HOME' => $cacheDir,
            'XDG_CACHE_HOME' => $cacheDir,
        ]);

        $sofficeCommands[$key] = [
            'cmd' => 'soffice --headless --nologo --norestore '
                . '-env:UserInstallation=file://' . $profileDir . ' '
                . '--convert-to pdf --outdir ' . escapeshellarg($workDir) . ' '
                . escapeshellarg($item['file_path']),
            'env' => $env,
        ];
    }

    if (!empty($sofficeCommands)) {
        $sofficeResults = run_processes_parallel($sofficeCommands, 30);
        foreach ($sofficeCommands as $key => $spec) {
            if (!$sofficeResults[$key]) {
                continue; // conversion failed - stays out of $pdfPaths, resolves to null below
            }
            $expected = $workDirs[$key] . '/' . pathinfo($pending[$key]['file_path'], PATHINFO_FILENAME) . '.pdf';
            if (is_file($expected)) {
                $pdfPaths[$key] = $expected;
            }
        }
    }

    $pdftoppmCommands = [];
    foreach ($pdfPaths as $key => $pdfPath) {
        $pdftoppmCommands[$key] = [
            'cmd' => 'pdftoppm -png -r ' . APPENDIX_IMAGE_DPI . ' -l ' . APPENDIX_MAX_PAGES . ' '
                . escapeshellarg($pdfPath) . ' ' . escapeshellarg($workDirs[$key] . '/page'),
            'env' => null,
        ];
    }
    $pdftoppmResults = !empty($pdftoppmCommands) ? run_processes_parallel($pdftoppmCommands, 30) : [];

    foreach ($pending as $key => $item) {
        $result = null;
        if (isset($pdfPaths[$key]) && ($pdftoppmResults[$key] ?? false)) {
            $pageFiles = glob($workDirs[$key] . '/page-*.png') ?: [];
            natsort($pageFiles);
            if (!empty($pageFiles)) {
                $totalPages = pdf_page_count($pdfPaths[$key]) ?? count($pageFiles);
                $images = [];
                foreach ($pageFiles as $pageFile) {
                    $images[] = 'data:image/png;base64,' . base64_encode(file_get_contents($pageFile));
                }
                $result = [
                    'images' => $images,
                    'total_pages' => $totalPages,
                    'truncated' => $totalPages > count($images),
                ];
                write_preview_cache($item['file_path'], $result);
            }
        }
        $results[$key] = $result;
    }

    foreach ($workDirs as $workDir) {
        rrmdir($workDir);
    }

    return $results;
}

function preview_cache_key(string $filePath): ?string
{
    $stat = @stat($filePath);
    if ($stat === false) {
        return null;
    }
    return sha1($filePath . '|' . $stat['mtime'] . '|' . $stat['size']);
}

function read_preview_cache(string $filePath): ?array
{
    $key = preview_cache_key($filePath);
    if ($key === null) {
        return null;
    }
    $cacheFile = PREVIEW_CACHE_DIR . '/' . $key . '.json';
    if (!is_file($cacheFile)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($cacheFile), true);
    return is_array($data) ? $data : null;
}

function write_preview_cache(string $filePath, array $result): void
{
    $key = preview_cache_key($filePath);
    if ($key === null) {
        return;
    }
    @mkdir(PREVIEW_CACHE_DIR, 0755, true);
    @file_put_contents(PREVIEW_CACHE_DIR . '/' . $key . '.json', json_encode($result));
}

function pdf_page_count(string $pdfPath): ?int
{
    $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open('pdfinfo ' . escapeshellarg($pdfPath), $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        return null;
    }
    $output = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return preg_match('/Pages:\s*(\d+)/', $output, $m) ? (int) $m[1] : null;
}

function run_process(string $cmd, int $timeoutSeconds = 20, ?array $env = null): bool
{
    $results = run_processes_parallel(['_single' => ['cmd' => $cmd, 'env' => $env]], $timeoutSeconds);
    return $results['_single'];
}

// Runs several shell commands concurrently. $commands is [key => ['cmd' =>
// string, 'env' => array|null]]. Returns [key => bool success].
function run_processes_parallel(array $commands, int $timeoutSeconds): array
{
    $procs = [];
    $pipes = [];
    foreach ($commands as $key => $spec) {
        $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($spec['cmd'], $descriptorSpec, $p, null, $spec['env'] ?? null);
        if (is_resource($proc)) {
            stream_set_blocking($p[1], false);
            stream_set_blocking($p[2], false);
            $procs[$key] = $proc;
            $pipes[$key] = $p;
        }
    }

    // exitcode is only valid on the first proc_get_status() call after exit.
    $results = [];
    $start = time();
    while (count($results) < count($procs) && (time() - $start) < $timeoutSeconds) {
        foreach ($procs as $key => $proc) {
            if (isset($results[$key])) {
                continue;
            }
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $results[$key] = $status['exitcode'] === 0;
            }
        }
        if (count($results) < count($procs)) {
            usleep(100000);
        }
    }

    foreach ($procs as $key => $proc) {
        if (!isset($results[$key])) {
            proc_terminate($proc);
            $results[$key] = false;
        }
        fclose($pipes[$key][1]);
        fclose($pipes[$key][2]);
        proc_close($proc);
    }

    foreach ($commands as $key => $spec) {
        if (!isset($results[$key])) {
            $results[$key] = false; // failed to even start
        }
    }

    return $results;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        is_dir($path) ? rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}
