<?php

namespace FileCarton;

/**
 * API: write — Save file content (with optional optimistic locking).
 * POST ?fcapi=write
 * multipart/form-data: path, content (file), expectedMtime? (field)
 *
 * If expectedMtime is provided and doesn't match the file's current mtime,
 * returns 409 Conflict. If omitted, last-writer-wins.
 */

function api_write(PathSecurity $pathSec, FileOps $fileOps): void {
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    Response::error('upload.body_too_large', 413);
}

$path = $_POST['path'] ?? '';
if (!is_string($path) || $path === '') {
    Response::error('missing_fields', 400, ['fields' => 'path, content']);
}

$file = $_FILES['content'] ?? null;
if (!is_array($file)) {
    Response::error('missing_fields', 400, ['fields' => 'path, content']);
}

$error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($error !== UPLOAD_ERR_OK) {
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        Response::error('upload.content_too_large', 413, ['maxMB' => round(FILECARTON_MAX_EDIT_SIZE / 1024 / 1024)]);
    }
    Response::error('missing_fields', 400, ['fields' => 'path, content']);
}

$size = (int)$file['size'];
if ($size > FILECARTON_MAX_EDIT_SIZE) {
    Response::error('upload.content_too_large', 413, ['maxMB' => round(FILECARTON_MAX_EDIT_SIZE / 1024 / 1024)]);
}

$tmp = $file['tmp_name'] ?? '';
if (!is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp)) {
    Response::error('upload.invalid', 400);
}

$content = file_get_contents($tmp);
if ($content === false) {
    throw new \RuntimeException('Failed to read uploaded content');
}

$absPath = $pathSec->resolve($path);
$pathSec->assertCanModify($absPath);

if (!file_exists($absPath)) {
    Response::error('not_found.file', 404);
}
if (!is_file($absPath)) {
    Response::error('not_a_file', 400);
}

if (isset($_POST['expectedMtime']) && $_POST['expectedMtime'] !== '') {
    clearstatcache(true, $absPath);
    $currentMtime = filemtime($absPath);
    if ((int)$_POST['expectedMtime'] !== $currentMtime) {
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
