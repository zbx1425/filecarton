<?php

namespace FileCarton;

/**
 * API: read — Read file content for editor/preview.
 * GET ?api=1&action=read&path={filePath}
 *
 * Returns UTF-8 text content. Rejects files > 5MB or non-UTF-8.
 */

function api_read(PathSecurity $pathSec, FileOps $fileOps): void {
$path = $_GET['path'] ?? '';
$absPath = $pathSec->resolve($path);

if (!is_file($absPath)) {
    Response::error('Not a file', 404);
}

$size = filesize($absPath);
if ($size > FILECARTON_MAX_EDIT_SIZE) {
    Response::error('File too large to edit (' . round($size / 1024 / 1024, 1) . ' MB). Please download instead.', 413);
}

$content = $fileOps->readFile($absPath);

if (!mb_check_encoding($content, 'UTF-8')) {
    Response::error('File is not valid UTF-8. Please download to view.', 400);
}

$mime = MimeType::detect($absPath);

Response::ok([
    'content'  => $content,
    'size'     => $size,
    'mtime'    => filemtime($absPath),
    'mime'     => $mime,
    'encoding' => 'utf-8',
]);
}
