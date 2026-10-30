<?php

namespace FileCarton;

/**
 * API: write — Save file content (with optional optimistic locking).
 * POST ?fcapi=write&path={filePath}[&expectedMtime={mtime}]
 * Body: raw binary file content via php://input.
 *
 * If expectedMtime is provided and doesn't match the file's current mtime,
 * returns 409 Conflict. If omitted, last-writer-wins.
 */

function api_write(PathSecurity $pathSec, FileOps $fileOps): void {
    $path = $_GET['path'] ?? '';
    if (!is_string($path) || $path === '') {
        Response::error('missing_fields', 400, ['fields' => 'path']);
    }

    $content = file_get_contents('php://input');
    if ($content === false) {
        throw new ApiException('server_error', 500);
    }

    $size = strlen($content);
    if ($size > FILECARTON_MAX_EDIT_SIZE) {
        Response::error('upload.content_too_large', 413, ['maxMB' => round(FILECARTON_MAX_EDIT_SIZE / 1024 / 1024)]);
    }

    $absPath = $pathSec->resolveExistingFile($path);
    $pathSec->assertCanModify($absPath);

    $expectedMtime = $_GET['expectedMtime'] ?? '';
    if ($expectedMtime !== '') {
        clearstatcache(true, $absPath);
        $currentMtime = filemtime($absPath);
        if ((int)$expectedMtime !== $currentMtime) {
            Response::error('conflict.mtime', 409);
        }
    }

    $bytes = $fileOps->writeFile($absPath, $content);
    clearstatcache(true, $absPath);

    Response::ok([
        'size'  => $bytes,
        'mtime' => filemtime($absPath),
    ]);
}
