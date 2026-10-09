<?php

namespace FileCarton;

/**
 * API: list — List directory contents.
 * GET ?fcapi=list&path={dirPath}
 */

function api_list(PathSecurity $pathSec, FileOps $fileOps): void {
$path = $_GET['path'] ?? '';
$absPath = $pathSec->resolve($path);
$pathSec->assertNotIgnored($absPath);

if (!file_exists($absPath)) {
    Response::error('not_found.dir', 404);
}
if (!is_dir($absPath)) {
    Response::error('not_a_dir', 400);
}

$result = $fileOps->listDir($absPath);
$result['dirs'] = $pathSec->filterEntries($result['dirs'], $absPath);
$result['files'] = $pathSec->filterEntries($result['files'], $absPath);
Response::ok($result);
}
