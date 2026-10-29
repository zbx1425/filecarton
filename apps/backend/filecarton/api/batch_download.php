<?php

namespace FileCarton;

/**
 * API: batch_download — Download selected files/folders as a streaming ZIP.
 * GET ?fcapi=batch_download&path={dirPath}&items=name1,name2,...
 *
 * Streams a ZIP archive directly to the client without creating a temporary file.
 * Does not use X-Sendfile / X-Accel-Redirect.
 */

function api_batch_download(PathSecurity $pathSec, FileOps $fileOps): void {
    $path = $_GET['path'] ?? '';
    $itemsParam = $_GET['items'] ?? '';

    if ($itemsParam === '') {
        Response::error('missing_fields', 400, ['fields' => 'items']);
    }

    $dirAbs = $pathSec->resolveExistingDir($path);
    $items = explode(',', $itemsParam);
    $items = array_filter(array_map('trim', $items), function ($s) { return $s !== ''; });

    if (empty($items)) {
        Response::error('missing_fields', 400, ['fields' => 'items']);
    }

    // Pre-scan: count files and total size to enforce limits
    $fileCount = 0;
    $totalSize = 0;
    $skipped = [];
    foreach (enumerate_archive_entries($dirAbs, $items, $pathSec, $skipped) as $entry) {
        if (!$entry['isDir']) {
            $fileCount++;
            $totalSize += (int)@filesize($entry['fullPath']);
        }
        if ($fileCount > FILECARTON_ARCHIVE_MAX_FILES) {
            Response::error('archive.too_many_to_create', 400, ['limit' => FILECARTON_ARCHIVE_MAX_FILES]);
        }
        if ($totalSize > FILECARTON_ARCHIVE_MAX_SIZE) {
            Response::error('archive.too_large_to_create', 400, ['limitMB' => round(FILECARTON_ARCHIVE_MAX_SIZE / 1024 / 1024, 1)]);
        }
    }

    if ($fileCount === 0 && empty($skipped)) {
        Response::error('not_found.file', 404);
    }

    // Determine archive filename
    if (count($items) === 1) {
        $archiveName = preg_replace('/[^\w\-.]/', '_', $items[0]) . '.zip';
    } else {
        $archiveName = 'download_' . date('ymd_His') . '.zip';
    }
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $archiveName);
    $encoded = rawurlencode($archiveName);

    // Release session lock before streaming
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Send headers — no Content-Length (streaming)
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    @ini_set('zlib.output_compression', 'Off');
    @set_time_limit(0);

    header('Content-Type: application/zip');
    header("Content-Disposition: attachment; filename=\"" . addcslashes($ascii, '"\\') . "\"; filename*=UTF-8''" . $encoded);
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    http_response_code(200);

    $out = fopen('php://output', 'wb');
    $zip = new StreamingZipWriter($out);

    $skipped = [];
    foreach (enumerate_archive_entries($dirAbs, $items, $pathSec, $skipped) as $entry) {
        if (connection_aborted()) break;
        if ($entry['isDir']) {
            $zip->addDirectory($entry['entryName']);
        } else {
            $zip->addFileFromPath($entry['entryName'], $entry['fullPath']);
        }
    }

    $zip->finish();
    fclose($out);
    exit;
}
