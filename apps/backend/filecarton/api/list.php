<?php

namespace FileCarton;

/**
 * API: list — List directory contents.
 * GET ?fcapi=list&path={dirPath}
 */

function api_list(PathSecurity $pathSec, FileOps $fileOps): void {
$path = $_GET['path'] ?? '';
$absPath = $pathSec->resolve($path);

if (!is_dir($absPath)) {
    Response::error('Not a directory', 404);
}

$result = $fileOps->listDir($absPath);
Response::ok($result);
}
