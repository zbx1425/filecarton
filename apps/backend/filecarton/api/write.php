<?php

namespace FileCarton;

/**
 * API: write — Save file content (with optional optimistic locking).
 * POST ?fcapi=write
 * Body: { path, content, expectedMtime? }
 *
 * If expectedMtime is provided and doesn't match the file's current mtime,
 * returns 409 Conflict. If omitted, last-writer-wins.
 */

function api_write(PathSecurity $pathSec, FileOps $fileOps): void {
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['path'], $input['content'])) {
    Response::error('Missing required fields: path, content', 400);
}

if (!is_string($input['content'])) {
    Response::error('Field "content" must be a string', 400);
}

if (strlen($input['content']) > FILECARTON_MAX_EDIT_SIZE) {
    Response::error('Content too large (max ' . round(FILECARTON_MAX_EDIT_SIZE / 1024 / 1024) . ' MB)', 413);
}

$absPath = $pathSec->resolve($input['path']);
$pathSec->assertCanModify($absPath);

if (!file_exists($absPath)) {
    Response::error('File not found', 404);
}
if (!is_file($absPath)) {
    Response::error('Not a file', 400);
}

if (isset($input['expectedMtime'])) {
    clearstatcache(true, $absPath);
    $currentMtime = filemtime($absPath);
    if ((int)$input['expectedMtime'] !== $currentMtime) {
        Response::error('File was modified by another user', 409);
    }
}

$bytes = $fileOps->writeFile($absPath, $input['content']);
clearstatcache(true, $absPath);

Response::ok([
    'size'  => $bytes,
    'mtime' => filemtime($absPath),
]);
}
