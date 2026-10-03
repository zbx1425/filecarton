<?php

namespace FileCarton;

/**
 * API: download — Force-download a file.
 * GET ?api=1&action=download&path={filePath}
 */

function api_download(PathSecurity $pathSec, FileOps $fileOps): void {
$path = $_GET['path'] ?? '';
$absPath = $pathSec->resolve($path);

if (!is_file($absPath)) {
    Response::error('Not a file', 404);
}

Response::file($absPath);
}
