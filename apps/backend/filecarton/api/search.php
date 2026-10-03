<?php

namespace FileCarton;

/**
 * API: search — Recursive filename search.
 * GET ?api=1&action=search&path={basePath}&q={query}&limit=200
 */

function api_search(PathSecurity $pathSec, FileOps $fileOps): void {
$basePath = $_GET['path'] ?? '';
$query = $_GET['q'] ?? '';
$limit = max(1, min(FILECARTON_SEARCH_MAX_LIMIT, (int)($_GET['limit'] ?? FILECARTON_SEARCH_DEFAULT_LIMIT)));

if ($query === '') {
    Response::error('Search query required', 400);
}

$absBase = $pathSec->resolve($basePath);

if (!is_dir($absBase)) {
    Response::error('Not a directory', 404);
}

$rootPath = $pathSec->getRootPath();
$results = [];
$truncated = false;

$iterator = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($absBase, \RecursiveDirectoryIterator::SKIP_DOTS),
    \RecursiveIteratorIterator::SELF_FIRST
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

    $fullPath = str_replace('\\', '/', $item->getPathname());
    $dirPath = str_replace('\\', '/', $item->getPath());

    $relativeDirPath = ($dirPath === $rootPath)
        ? ''
        : substr($dirPath, strlen($rootPath) + 1);

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
