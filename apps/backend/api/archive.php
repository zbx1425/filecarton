<?php
/**
 * API: archive — Create or extract an archive.
 * POST ?api=1&action=archive
 * Body: { operation: "create"|"extract", ... }
 *
 * Create: { operation, format: "zip"|"tar", path, items: string[], archiveName? }
 * Extract: { operation, path (archive file), targetPath, createSubdir? }
 */


$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['operation'])) {
    Response::error('Missing required field: operation', 400);
}

$operation = $input['operation'];

if ($operation === 'create') {
    archiveCreate($input, $pathSec, $fileOps);
} elseif ($operation === 'extract') {
    archiveExtract($input, $pathSec);
} else {
    Response::error('Operation must be "create" or "extract"', 400);
}

function archiveCreate(array $input, PathSecurity $pathSec, FileOps $fileOps): never {
    if (!isset($input['format'], $input['path'], $input['items']) || !is_array($input['items'])) {
        Response::error('Missing fields: format, path, items', 400);
    }

    $format = $input['format'];
    if ($format !== 'zip' && $format !== 'tar') {
        Response::error('Format must be "zip" or "tar"', 400);
    }

    $dirAbs = $pathSec->resolve($input['path']);
    if (!is_dir($dirAbs)) {
        Response::error('Directory not found', 404);
    }

    $archiveName = $input['archiveName'] ?? ('archive_' . date('ymd_His') . '.' . $format);
    $sanitizedName = $pathSec->sanitizeFileName($archiveName);
    $archivePath = $dirAbs . '/' . $sanitizedName;
    $pathSec->assertWithinRoot($archivePath);

    if (file_exists($archivePath)) {
        Response::error('Archive name already exists', 409);
    }

    $fileCount = 0;
    $totalSize = 0;
    countItemsForArchive($dirAbs, $input['items'], $pathSec, $fileCount, $totalSize);

    if ($fileCount > FILECARTON_ARCHIVE_MAX_FILES) {
        Response::error('Too many files to archive (limit: ' . FILECARTON_ARCHIVE_MAX_FILES . ')', 400);
    }
    if ($totalSize > FILECARTON_ARCHIVE_MAX_SIZE) {
        Response::error('Total size too large to archive (limit: ' . round(FILECARTON_ARCHIVE_MAX_SIZE / 1024 / 1024 / 1024, 1) . ' GB)', 400);
    }

    if ($format === 'zip') {
        createZip($dirAbs, $input['items'], $archivePath, $pathSec);
    } else {
        createTar($dirAbs, $input['items'], $archivePath, $pathSec);
    }

    $relativePath = substr($archivePath, strlen($pathSec->getRootPath()) + 1);

    Response::ok([
        'archivePath' => $relativePath,
        'size'        => filesize($archivePath),
    ]);
}

function archiveExtract(array $input, PathSecurity $pathSec): never {
    if (!isset($input['path'], $input['targetPath'])) {
        Response::error('Missing fields: path, targetPath', 400);
    }

    $archiveAbs = $pathSec->resolve($input['path']);
    if (!is_file($archiveAbs)) {
        Response::error('Archive file not found', 404);
    }

    $targetAbs = $pathSec->resolve($input['targetPath']);
    if (!is_dir($targetAbs)) {
        Response::error('Target directory not found', 404);
    }

    $createSubdir = !empty($input['createSubdir']);
    if ($createSubdir) {
        $baseName = $pathSec->sanitizeFileName(pathinfo($archiveAbs, PATHINFO_FILENAME));
        $targetAbs .= '/' . $baseName;
        $pathSec->assertWithinRoot($targetAbs);
        if (!is_dir($targetAbs)) {
            mkdir($targetAbs, 0755, true);
        }
    }

    $normalizedTarget = str_replace('\\', '/', $targetAbs);
    $ext = strtolower(pathinfo($archiveAbs, PATHINFO_EXTENSION));
    $extracted = 0;

    if ($ext === 'zip') {
        $extracted = extractZipSafe($archiveAbs, $targetAbs, $normalizedTarget, $pathSec);
    } elseif ($ext === 'tar' || $ext === 'gz' || $ext === 'tgz') {
        $extracted = extractTarSafe($archiveAbs, $targetAbs, $normalizedTarget, $pathSec);
    } else {
        Response::error('Unsupported archive format', 400);
    }

    $relTarget = substr(str_replace('\\', '/', $targetAbs), strlen($pathSec->getRootPath()) + 1);

    Response::ok([
        'extracted'  => $extracted,
        'targetPath' => $relTarget ?: '',
    ]);
}

function extractZipSafe(string $archiveAbs, string $targetAbs, string $normalizedTarget, PathSecurity $pathSec): int {
    $zip = new ZipArchive();
    if ($zip->open($archiveAbs) !== true) {
        Response::error('Cannot open ZIP archive', 400);
    }

    $extracted = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = $zip->getNameIndex($i);
        $entryPath = str_replace('\\', '/', $entryName);

        $normalized = $pathSec->normalizePath($entryPath);
        if ($normalized === '') continue;

        $destPath = $normalizedTarget . '/' . $normalized;

        if (!str_starts_with($destPath, $normalizedTarget . '/')) {
            continue;
        }

        if (str_ends_with($entryPath, '/')) {
            if (!is_dir($destPath)) mkdir($destPath, 0755, true);
        } else {
            $parentDir = dirname($destPath);
            if (!is_dir($parentDir)) mkdir($parentDir, 0755, true);

            $stream = $zip->getStream($entryName);
            if ($stream === false) continue;
            $outFile = fopen($destPath, 'wb');
            if ($outFile === false) { fclose($stream); continue; }
            stream_copy_to_stream($stream, $outFile);
            fclose($stream);
            fclose($outFile);
            $extracted++;
        }
    }
    $zip->close();
    return $extracted;
}

function extractTarSafe(string $archiveAbs, string $targetAbs, string $normalizedTarget, PathSecurity $pathSec): int {
    try {
        $phar = new PharData($archiveAbs);
    } catch (\Throwable $e) {
        Response::error('Cannot open TAR archive: ' . $e->getMessage(), 400);
    }

    $extracted = 0;
    $iter = new RecursiveIteratorIterator($phar, RecursiveIteratorIterator::SELF_FIRST);

    foreach ($iter as $item) {
        $entryPath = $item->getPathname();
        $entryPath = preg_replace('#^phar://.*?\.tar(?:\.gz)?/#', '', $entryPath);
        $entryPath = str_replace('\\', '/', $entryPath);

        $normalized = $pathSec->normalizePath($entryPath);
        if ($normalized === '') continue;

        $destPath = $normalizedTarget . '/' . $normalized;

        if (!str_starts_with($destPath, $normalizedTarget . '/')) {
            continue;
        }

        if ($item->isDir()) {
            if (!is_dir($destPath)) mkdir($destPath, 0755, true);
        } else {
            $parentDir = dirname($destPath);
            if (!is_dir($parentDir)) mkdir($parentDir, 0755, true);

            $content = $item->getContent();
            file_put_contents($destPath, $content);
            $extracted++;
        }
    }

    return $extracted;
}

function countItemsForArchive(string $baseDir, array $items, PathSecurity $pathSec, int &$fileCount, int &$totalSize): void {
    foreach ($items as $name) {
        $sanitized = $pathSec->sanitizeFileName($name);
        $itemPath = $baseDir . '/' . $sanitized;
        if (!file_exists($itemPath)) continue;

        if (is_file($itemPath)) {
            $fileCount++;
            $totalSize += filesize($itemPath);
        } elseif (is_dir($itemPath)) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($itemPath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iter as $item) {
                if ($item->isFile()) {
                    $fileCount++;
                    $totalSize += $item->getSize();
                }
                if ($fileCount > FILECARTON_ARCHIVE_MAX_FILES || $totalSize > FILECARTON_ARCHIVE_MAX_SIZE) return;
            }
        }
    }
}

function createZip(string $baseDir, array $items, string $archivePath, PathSecurity $pathSec): void {
    $zip = new ZipArchive();
    if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new \RuntimeException('Cannot create ZIP file');
    }

    foreach ($items as $name) {
        $sanitized = $pathSec->sanitizeFileName($name);
        $itemPath = $baseDir . '/' . $sanitized;
        $pathSec->assertWithinRoot($itemPath);

        if (!file_exists($itemPath)) continue;

        if (is_file($itemPath)) {
            $zip->addFile($itemPath, $sanitized);
        } elseif (is_dir($itemPath)) {
            addDirToZip($zip, $itemPath, $sanitized);
        }
    }

    $zip->close();
}

function addDirToZip(ZipArchive $zip, string $dirPath, string $prefix): void {
    $zip->addEmptyDir($prefix);
    $entries = scandir($dirPath);
    if ($entries === false) return;

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $dirPath . '/' . $entry;
        $zipPath = $prefix . '/' . $entry;

        if (is_file($full)) {
            $zip->addFile($full, $zipPath);
        } elseif (is_dir($full)) {
            addDirToZip($zip, $full, $zipPath);
        }
    }
}

function createTar(string $baseDir, array $items, string $archivePath, PathSecurity $pathSec): void {
    $phar = new PharData($archivePath);

    foreach ($items as $name) {
        $sanitized = $pathSec->sanitizeFileName($name);
        $itemPath = $baseDir . '/' . $sanitized;
        $pathSec->assertWithinRoot($itemPath);

        if (!file_exists($itemPath)) continue;

        if (is_file($itemPath)) {
            $phar->addFile($itemPath, $sanitized);
        } elseif (is_dir($itemPath)) {
            addDirToTar($phar, $itemPath, $sanitized);
        }
    }
}

function addDirToTar(PharData $phar, string $dirPath, string $prefix): void {
    $phar->addEmptyDir($prefix);
    $entries = scandir($dirPath);
    if ($entries === false) return;

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $dirPath . '/' . $entry;
        $tarPath = $prefix . '/' . $entry;

        if (is_file($full)) {
            $phar->addFile($full, $tarPath);
        } elseif (is_dir($full)) {
            addDirToTar($phar, $full, $tarPath);
        }
    }
}
