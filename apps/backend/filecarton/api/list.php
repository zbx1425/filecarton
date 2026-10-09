<?php

namespace FileCarton;

/**
 * API: list — List directory contents.
 * GET ?fcapi=list&path={dirPath}
 */

function api_list(PathSecurity $pathSec, FileOps $fileOps): void {
$path = $_GET['path'] ?? '';
$absPath = $pathSec->resolveExistingDir($path);

$result = $fileOps->listDir($absPath);
$result['dirs'] = $pathSec->filterEntries($result['dirs'], $absPath);
$result['files'] = $pathSec->filterEntries($result['files'], $absPath);
Response::ok($result);
}
