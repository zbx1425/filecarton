<?php

namespace FileCarton;

/**
 * API: raw — Serve file inline with correct MIME type.
 * GET ?fcapi=raw&path={filePath}
 *
 * Used by frontend for <img src> and <audio src> preview.
 * Frontend gates on file size before requesting.
 * Supports ETag/304 caching via Response::stream().
 */

function api_raw(PathSecurity $pathSec, FileOps $fileOps): void {
$path = $_GET['path'] ?? '';
$absPath = $pathSec->resolve($path);
$pathSec->assertNotIgnored($absPath);

if (!file_exists($absPath)) {
    Response::error('File not found', 404);
}
if (!is_file($absPath)) {
    Response::error('Not a file', 400);
}

$mime = MimeType::detect($absPath);

if ($mime === 'image/svg+xml') {
    header('Content-Security-Policy: sandbox');
    Response::stream($absPath, $mime);
} elseif (MimeType::isSafeForInline($mime)) {
    Response::stream($absPath, $mime);
} else {
    Response::file($absPath);
}
}
