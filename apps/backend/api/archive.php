<?php
/**
 * API: archive — Create or extract an archive.
 * POST ?api=1&action=archive
 * Body: { operation: "create"|"extract", ... }
 *
 * Create: { operation, format: "zip"|"tar", path, items: string[], archiveName? }
 * Extract: { operation, path (archive file), targetPath, createSubdir? }
 *
 * Archive name conflict returns 409.
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
        $baseName = pathinfo($archiveAbs, PATHINFO_FILENAME);
        $targetAbs .= '/' . $baseName;
        if (!is_dir($targetAbs)) {
            mkdir($targetAbs, 0755, true);
        }
        $pathSec->assertWithinRoot($targetAbs);
    }

    $ext = strtolower(pathinfo($archiveAbs, PATHINFO_EXTENSION));
    $extracted = 0;

    if ($ext === 'zip') {
        $zip = new ZipArchive();
        if ($zip->open($archiveAbs) !== true) {
            Response::error('Cannot open ZIP archive', 400);
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            $entryPath = str_replace('\\', '/', $entryName);
            $destPath = $targetAbs . '/' . $entryPath;

            $realDest = realpath(dirname($destPath));
            if ($realDest !== false) {
                $normalizedDest = str_replace('\\', '/', $realDest);
                $normalizedTarget = str_replace('\\', '/', $targetAbs);
                if ($normalizedDest !== $normalizedTarget && !str_starts_with($normalizedDest, $normalizedTarget . '/')) {
                    continue;
                }
            }

            if (str_ends_with($entryPath, '/')) {
                if (!is_dir($destPath)) mkdir($destPath, 0755, true);
            } else {
                $parentDir = dirname($destPath);
                if (!is_dir($parentDir)) mkdir($parentDir, 0755, true);
                $zip->extractTo($targetAbs, $entryName);
                $extracted++;
            }
        }
        $zip->close();
    } elseif ($ext === 'tar' || $ext === 'gz' || $ext === 'tgz') {
        try {
            $phar = new PharData($archiveAbs);
            $phar->extractTo($targetAbs, null, true);
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($targetAbs, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iter as $item) {
                if ($item->isFile()) $extracted++;
            }
        } catch (\Throwable $e) {
            Response::error('Extract failed: ' . $e->getMessage(), 500);
        }
    } else {
        Response::error('Unsupported archive format', 400);
    }

    $relTarget = substr(str_replace('\\', '/', $targetAbs), strlen($pathSec->getRootPath()) + 1);

    Response::ok([
        'extracted'  => $extracted,
        'targetPath' => $relTarget ?: '',
    ]);
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
