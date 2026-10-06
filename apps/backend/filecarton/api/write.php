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
    Response::error('Request body too large', 413);
}

$path = $_POST['path'] ?? '';
if (!is_string($path) || $path === '') {
    Response::error('Missing required fields: path, content', 400);
}

$file = $_FILES['content'] ?? null;
if (!is_array($file)) {
    Response::error('Missing required fields: path, content', 400);
}

$error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($error !== UPLOAD_ERR_OK) {
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        Response::error('Content too large (max ' . round(FILECARTON_MAX_EDIT_SIZE / 1024 / 1024) . ' MB)', 413);
    }
    Response::error('Missing required fields: path, content', 400);
}

$size = (int)$file['size'];
if ($size > FILECARTON_MAX_EDIT_SIZE) {
    Response::error('Content too large (max ' . round(FILECARTON_MAX_EDIT_SIZE / 1024 / 1024) . ' MB)', 413);
}

$tmp = $file['tmp_name'] ?? '';
if (!is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp)) {
    Response::error('Invalid upload', 400);
}

$content = file_get_contents($tmp);
if ($content === false) {
    throw new \RuntimeException('Failed to read uploaded content');
}

$absPath = $pathSec->resolve($path);
$pathSec->assertCanModify($absPath);

if (!file_exists($absPath)) {
    Response::error('File not found', 404);
}
if (!is_file($absPath)) {
    Response::error('Not a file', 400);
}

if (isset($_POST['expectedMtime']) && $_POST['expectedMtime'] !== '') {
    clearstatcache(true, $absPath);
    $currentMtime = filemtime($absPath);
    if ((int)$_POST['expectedMtime'] !== $currentMtime) {
        Response::error('File was modified by another user', 409);
    }
}

$bytes = $fileOps->writeFile($absPath, $content);
clearstatcache(true, $absPath);

Response::ok([
    'size'  => $bytes,
    'mtime' => filemtime($absPath),
]);
}
