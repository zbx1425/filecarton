<?php

namespace FileCarton;

/**
 * API: download — Force-download a file.
 * GET ?fcapi=download&path={filePath}
 */

function api_download(PathSecurity $pathSec, FileOps $fileOps): void {
$path = $_GET['path'] ?? '';
$absPath = $pathSec->resolve($path);
$pathSec->assertNotIgnored($absPath);

if (!is_file($absPath)) {
    Response::error('not_found.file', 404);
}

Response::file($absPath);
}
