<?php

namespace FileCarton;

/**
 * API: batch_download — Download selected files/folders as a streaming ZIP.
 *
 * POST ?fcapi=batch_download        → Validate selection, store params in session, return { token }.
 * GET  ?fcapi=batch_download&token= → Retrieve params from session (one-time), stream ZIP.
 *
 * The two-step flow lets the browser handle the download natively (no blob in memory).
 */

function api_batch_download(PathSecurity $pathSec, FileOps $fileOps): void {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['token'])) {
        batch_download_stream($pathSec, $_GET['token']);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Response::error('method_not_allowed', 405);
    }

    // CSRF is validated by the API handler for all POST requests.

    $input = json_decode(file_get_contents('php://input'), true);

    $path = $input['path'] ?? '';
    $items = $input['items'] ?? [];

    if (!is_array($items) || empty($items)) {
        Response::error('missing_fields', 400, ['fields' => 'path, items']);
    }
    $items = array_values(array_filter($items, function ($s) {
        return is_string($s) && $s !== '';
    }));
    if (empty($items)) {
        Response::error('missing_fields', 400, ['fields' => 'items']);
    }

    $dirAbs = $pathSec->resolveExistingDir($path);

    // Pre-scan: count files and total size to enforce limits
    $fileCount = 0;
    $totalSize = 0;
    $hasEntries = false;
    $skipped = [];
    foreach (enumerate_archive_entries($dirAbs, $items, $pathSec, $skipped) as $entry) {
        $hasEntries = true;
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

    if (!$hasEntries && empty($skipped)) {
        Response::error('not_found.file', 404);
    }

    // Store download params in session and return a one-time token
    $token = bin2hex(random_bytes(16));
    if (!isset($_SESSION['fc_batch_downloads'])) {
        $_SESSION['fc_batch_downloads'] = [];
    }

    // Evict tokens older than 5 minutes
    $now = time();
    foreach ($_SESSION['fc_batch_downloads'] as $k => $v) {
        if ($now - ($v['created'] ?? 0) > 300) {
            unset($_SESSION['fc_batch_downloads'][$k]);
        }
    }

    $_SESSION['fc_batch_downloads'][$token] = [
        'path'  => $path,
        'items' => $items,
        'created' => $now,
    ];

    Response::ok(['token' => $token]);
}

/**
 * GET handler: retrieve stored params by token and stream the ZIP.
 */
function batch_download_stream(PathSecurity $pathSec, string $token): void {
    if (!is_string($token) || $token === '') {
        Response::error('missing_fields', 400, ['fields' => 'token']);
    }

    $downloads = $_SESSION['fc_batch_downloads'] ?? [];
    if (!isset($downloads[$token]) || !is_array($downloads[$token])) {
        Response::error('not_found.download', 404);
    }

    $params = $downloads[$token];
    unset($_SESSION['fc_batch_downloads'][$token]);

    // Write session (consumes the token) and release the lock before streaming
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $path = $params['path'] ?? '';
    $items = $params['items'] ?? [];

    if (!is_array($items) || empty($items)) {
        Response::error('missing_fields', 400, ['fields' => 'items']);
    }

    $dirAbs = $pathSec->resolveExistingDir($path);

    // Determine archive filename
    if (count($items) === 1) {
        $baseName = preg_replace('/[\x00-\x1F\x7F"\\\\]/', '_', $items[0]) . '.zip';
    } else {
        $baseName = 'download_' . date('ymd_His') . '.zip';
    }
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $baseName);
    $encoded = rawurlencode($baseName);

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
