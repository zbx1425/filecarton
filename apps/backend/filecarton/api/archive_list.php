<?php

namespace FileCarton;

/**
 * API: archive_list — List contents of a ZIP or TAR archive.
 * GET ?fcapi=archive_list&path={archivePath}
 */

function api_archive_list(PathSecurity $pathSec, FileOps $fileOps): void {
$path = $_GET['path'] ?? '';
$absPath = $pathSec->resolve($path);
$pathSec->assertNotIgnored($absPath);

if (!is_file($absPath)) {
    Response::error('Not a file', 404);
}

$ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
$entries = [];
$totalFiles = 0;
$totalSize = 0;
$compressedSize = filesize($absPath);

if ($ext === 'zip') {
    $zip = new \ZipArchive();
    if ($zip->open($absPath) !== true) {
        Response::error('Cannot open ZIP archive', 400);
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $entryPath = $stat['name'];
        $isDir = str_ends_with($entryPath, '/');
        $size = $stat['size'];
        $entries[] = [
            'path'  => $entryPath,
            'size'  => $size,
            'isDir' => $isDir,
        ];
        if (!$isDir) {
            $totalFiles++;
            $totalSize += $size;
        }
    }
    $zip->close();
} elseif ($ext === 'tar' || $ext === 'gz' || $ext === 'tgz') {
    try {
        $phar = new \PharData($absPath);
        $iter = new \RecursiveIteratorIterator($phar, \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iter as $item) {
            $entryPath = $item->getPathname();
            $entryPath = preg_replace('#^phar://.*?\.tar(?:\.gz)?/#', '', $entryPath);
            $entryPath = str_replace('\\', '/', $entryPath);
            $isDir = $item->isDir();
            $size = $isDir ? 0 : $item->getSize();
            $entries[] = [
                'path'  => $entryPath,
                'size'  => $size,
                'isDir' => $isDir,
            ];
            if (!$isDir) {
                $totalFiles++;
                $totalSize += $size;
            }
        }
    } catch (\Throwable $e) {
        Response::error('Cannot read TAR archive: ' . $e->getMessage(), 400);
    }
} else {
    Response::error('Unsupported archive format', 400);
}

Response::ok([
    'entries'        => $entries,
    'totalFiles'     => $totalFiles,
    'totalSize'      => $totalSize,
    'compressedSize' => $compressedSize,
]);
}
