<?php
// includes/document_preview.php
//
// Local, offline "screenshot each page" rendering for the Case Report
// appendix (see cargo_hold/case_report.php and case_report_previews.php) -
// an attached document is embedded as an image of what it actually looks
// like, rather than reflowed plain text pulled out of it. Everything here
// runs entirely inside the app container with no network access at request
// time:
//  - .pdf goes straight through pdftoppm (poppler-utils, apt-installed).
//  - .docx and .txt are first normalized to PDF via LibreOffice headless
//    (`soffice --headless --convert-to pdf`, libreoffice-writer, also
//    apt-installed), then rendered through the same pdftoppm step - one
//    uniform pipeline for every supported type instead of a different
//    renderer per format.
// Both poppler-utils and libreoffice-writer are plain system packages
// baked into the image at build time (see Dockerfile), so nothing here
// ever needs the container to have network access at runtime.

const EMBEDDABLE_EXTENSIONS = ['txt', 'pdf', 'docx'];

// A report shouldn't be able to balloon to hundreds of pages just because
// someone ticked the appendix box on a long document - and each page is a
// full raster image, so this also bounds how slow rendering one document
// (and how large the resulting report page) can get.
const APPENDIX_MAX_PAGES = 10;
const APPENDIX_IMAGE_DPI = 110;

// Rendered page-images are cached on the persisted data volume, keyed off
// each source document's own path/size/mtime - a document only needs to be
// run through LibreOffice/pdftoppm once; every later report view (or
// checkbox toggle) for the same unchanged document is a disk read instead
// of a fresh conversion.
const PREVIEW_CACHE_DIR = '/var/www/polaris-data/report-cache';

function is_embeddable_extension(string $filename): bool
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, EMBEDDABLE_EXTENSIONS, true);
}

// Single-document convenience wrapper around render_document_page_images_batch().
// Returns ['images' => [data-uri, ...], 'total_pages' => int, 'truncated' => bool]
// on success, or null if the file type isn't supported, the file is
// missing, or rendering failed for any reason. Never throws - one bad or
// unsupported document shouldn't break the whole report.
function render_document_page_images(string $filePath, string $originalFilename): ?array
{
    $result = render_document_page_images_batch([
        '_single' => ['file_path' => $filePath, 'original_filename' => $originalFilename],
    ]);
    return $result['_single'];
}

// Renders (or reuses a cached rendering of) page-images for several
// documents at once. $items is [key => ['file_path' => ..., 'original_filename' => ...]].
// Returns [key => result-array-or-null] using the same per-item shape as
// render_document_page_images().
//
// Cache hits resolve immediately with no subprocess work at all. Cache
// misses are converted concurrently rather than one at a time - each
// document's LibreOffice/pdftoppm work is entirely independent of the
// others, so a case with several attached documents pays roughly the cost
// of its single slowest document instead of the sum of all of them.
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

        // www-data's real $HOME isn't writable in this image, which makes
        // soffice's dconf/fontconfig caches log permission warnings on
        // every call (harmless, but wasteful) - pointing HOME at this
        // item's own temp dir gives those subsystems somewhere writable.
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
    // mtime+size in the key means a re-uploaded/replaced document at the
    // same path is automatically treated as a cache miss - no explicit
    // invalidation needed.
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

// Runs several shell commands concurrently and waits for all of them.
// $commands is [key => ['cmd' => string, 'env' => array|null]]. Returns
// [key => bool success]. A single shared timeout applies to the whole
// batch (not per-command), since these are always run together as one
// logical unit of work.
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

    // proc_get_status()'s exitcode is only meaningful the first call after
    // a given process exits - it must be captured right here, since
    // proc_close() below would otherwise report -1 (already consumed)
    // instead of the real exit code.
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
