<?php

namespace FileCarton;

/**
 * API: search — Recursive filename search.
 * GET ?fcapi=search&path={basePath}&q={query}&limit=200
 */

function api_search(PathSecurity $pathSec, FileOps $fileOps): void {
$basePath = api_query_string('path');
$query = api_query_string('q');
$limit = max(1, min(FILECARTON_SEARCH_MAX_LIMIT, (int)($_GET['limit'] ?? FILECARTON_SEARCH_DEFAULT_LIMIT)));

if ($query === '') {
    Response::error('missing_fields', 400, ['fields' => 'q']);
}

$absBase = $pathSec->resolveExistingDir($basePath);
$absBaseNorm = rtrim(str_replace('\\', '/', $absBase), '/');
$logicalBase = ltrim(str_replace('\\', '/', $basePath), '/');

$results = [];
$truncated = false;

$filteredDir = new \RecursiveCallbackFilterIterator(
    new \RecursiveDirectoryIterator($absBase, \RecursiveDirectoryIterator::SKIP_DOTS),
    function ($item) use ($pathSec) {
        $fullPath = str_replace('\\', '/', $item->getPathname());
        return !$pathSec->isIgnored($fullPath);
    }
);

$iterator = new \RecursiveIteratorIterator(
    $filteredDir,
    \RecursiveIteratorIterator::SELF_FIRST,
    \RecursiveIteratorIterator::CATCH_GET_CHILD
);

$scanned = 0;
$scanLimit = FILECARTON_SEARCH_MAX_SCAN;
$scanLimitReached = false;

foreach ($iterator as $item) {
    if (++$scanned > $scanLimit) {
        $truncated = true;
        $scanLimitReached = true;
        break;
    }

    $name = $item->getFilename();

    if (mb_stripos($name, $query) === false) {
        continue;
    }

    $dirPath = str_replace('\\', '/', $item->getPath());

    if ($dirPath === $absBaseNorm) {
        $relativeDirPath = $logicalBase;
    } else {
        $suffix = substr($dirPath, strlen($absBaseNorm) + 1);
        $relativeDirPath = $logicalBase !== '' ? $logicalBase . '/' . $suffix : $suffix;
    }

    $entry = [
        'name' => $name,
        'path' => $relativeDirPath,
        'type' => $item->isDir() ? 'dir' : 'file',
    ];
    if (!$item->isDir()) {
        $entry['size'] = $item->getSize();
    }

    $results[] = $entry;

    if (count($results) >= $limit) {
        $truncated = true;
        break;
    }
}

Response::ok([
    'results'          => $results,
    'truncated'        => $truncated,
    'scanLimitReached' => $scanLimitReached,
]);
}
