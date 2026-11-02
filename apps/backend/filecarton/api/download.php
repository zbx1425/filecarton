<?php

namespace FileCarton;

/**
 * API: download — Force-download a file.
 * GET ?fcapi=download&path={filePath}
 */

function api_download(PathSecurity $pathSec, FileOps $fileOps): void {
$path = api_query_string('path');
$absPath = $pathSec->resolveExistingFile($path);

Response::file($absPath);
}
