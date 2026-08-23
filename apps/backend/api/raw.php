<?php
/**
 * API: raw — Serve file inline with correct MIME type.
 * GET ?api=1&action=raw&path={filePath}
 *
 * Used by frontend for <img src> and <audio src> preview.
 * Frontend gates on file size before requesting.
 * Supports ETag/304 caching via Response::stream().
 */

$path = $_GET['path'] ?? '';
$absPath = $pathSec->resolve($path);

if (!is_file($absPath)) {
    Response::error('Not a file', 404);
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
