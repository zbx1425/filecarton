<?php

namespace FileCarton;

/**
 * API: list — List directory contents.
 * GET ?fcapi=list&path={dirPath}
 */

function api_list(PathSecurity $pathSec, FileOps $fileOps): void {
$path = api_query_string('path');
$absPath = $pathSec->resolveExistingDir($path);

$result = $fileOps->listDir($absPath);
$result['dirs'] = $pathSec->filterEntries($result['dirs'], $absPath);
$result['files'] = $pathSec->filterEntries($result['files'], $absPath);

foreach ($result['dirs'] as &$dir) {
    $childAbs = $absPath . '/' . $dir['name'];
    $sub = @scandir($childAbs);
    $hasVisible = false;
    if ($sub !== false) {
        foreach ($sub as $s) {
            if ($s === '.' || $s === '..') continue;
            if (!$pathSec->isIgnored($childAbs . '/' . $s)) {
                $hasVisible = true;
                break;
            }
        }
    }
    $dir['hasChildren'] = $hasVisible;
}
unset($dir);

$tempDir = RepoSetting::current()->resolvedTempDir();
if ($tempDir !== '') {
    (new TempStorage($tempDir))->cleanup((int)FILECARTON_CHUNK_EXPIRY);
}

Response::ok($result);
}
