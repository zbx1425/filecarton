<?php

namespace FileCarton;

/**
 * API: raw — Serve file inline with correct MIME type.
 * GET ?fcapi=raw&path={filePath}
 */

function api_raw(PathSecurity $pathSec, FileOps $fileOps): void {
$path = $_GET['path'] ?? '';
$absPath = $pathSec->resolveExistingFile($path);

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
