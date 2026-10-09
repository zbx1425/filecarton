<?php

namespace FileCarton;

/**
 * API: read — Read file content for editor/preview.
 * GET ?fcapi=read&path={filePath}
 *
 * Returns UTF-8 text content. Rejects files > max edit size or non-UTF-8.
 */

function api_read(PathSecurity $pathSec, FileOps $fileOps): void {
$path = $_GET['path'] ?? '';
$absPath = $pathSec->resolveExistingFile($path);

$size = filesize($absPath);
if ($size > FILECARTON_MAX_EDIT_SIZE) {
    Response::error('file_too_large', 413, ['maxMB' => round(FILECARTON_MAX_EDIT_SIZE / 1024 / 1024, 1)]);
}

$content = $fileOps->readFile($absPath);

if (!mb_check_encoding($content, 'UTF-8')) {
    Response::error('not_utf8', 400);
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
