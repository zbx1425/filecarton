<?php
/**
 * API: list — List directory contents.
 * GET ?api=1&action=list&path={dirPath}
 */

$path = $_GET['path'] ?? '';
$absPath = $pathSec->resolve($path);

if (!is_dir($absPath)) {
    Response::error('Not a directory', 404);
}

$result = $fileOps->listDir($absPath);
Response::ok($result);
