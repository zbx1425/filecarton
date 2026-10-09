<?php

namespace FileCarton;

/**
 * API: archive (create) — Create a ZIP or TAR archive.
 * POST ?fcapi=archive
 * Body: { operation: "create", format: "zip"|"tar", path, items: string[], archiveName? }
 */

function api_archive_create(PathSecurity $pathSec, FileOps $fileOps): void {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['format'], $input['path'], $input['items']) || !is_array($input['items'])) {
        Response::error('missing_fields', 400, ['fields' => 'format, path, items']);
    }

    $format = $input['format'];
    if ($format !== 'zip' && $format !== 'tar') {
        Response::error('invalid_input', 400, ['field' => 'format']);
    }

    $dirAbs = $pathSec->resolveExistingDir($input['path']);

    $archiveName = $input['archiveName'] ?? ('archive_' . date('ymd_His') . '.' . $format);
    $sanitizedName = $pathSec->sanitizeFileName($archiveName);

    $archivePath = $dirAbs . '/' . $sanitizedName;
    $pathSec->assertWithinRoot($archivePath);
    $pathSec->assertCanCreateAt($archivePath, false);

    if (file_exists($archivePath)) {
        Response::error('already_exists', 409);
    }

    $fileCount = 0;
    $totalSize = 0;
    count_items_for_archive($dirAbs, $input['items'], $pathSec, $fileCount, $totalSize);

    if ($fileCount > FILECARTON_ARCHIVE_MAX_FILES) {
        Response::error('archive.too_many_files', 400, ['limit' => FILECARTON_ARCHIVE_MAX_FILES]);
    }
    if ($totalSize > FILECARTON_ARCHIVE_MAX_SIZE) {
        Response::error('archive.too_large', 400, ['limitGB' => round(FILECARTON_ARCHIVE_MAX_SIZE / 1024 / 1024 / 1024, 1)]);
    }

    $skipped = [];
    if ($format === 'zip') {
        create_zip($dirAbs, $input['items'], $archivePath, $pathSec, true, $skipped);
    } else {
        create_tar($dirAbs, $input['items'], $archivePath, $pathSec, true, $skipped);
    }

    $relativePath = substr($archivePath, strlen($pathSec->getRootPath()) + 1);

    Response::ok([
        'archivePath' => $relativePath,
        'size'        => filesize($archivePath),
        'skipped'     => $skipped,
    ]);
}

function should_skip_archive_entry(string $fullPath, PathSecurity $pathSec): bool {
    return $pathSec->isIgnored($fullPath);
}

function count_items_for_archive(string $baseDir, array $items, PathSecurity $pathSec, int &$fileCount, int &$totalSize): void {
    foreach ($items as $name) {
        if (!is_string($name) || $name === '') continue;
        try {
            $itemPath = $pathSec->resolveItemIn($baseDir, $name);
        } catch (\Throwable $e) {
            continue;
        }
        if (!file_exists($itemPath) || is_link($itemPath)) continue;
        if (should_skip_archive_entry($itemPath, $pathSec)) continue;

        if (is_file($itemPath)) {
            $fileCount++;
            $totalSize += filesize($itemPath);
        } elseif (is_dir($itemPath)) {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($itemPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iter as $item) {
                if (is_link($item->getPathname())) continue;
                $fullItemPath = str_replace('\\', '/', $item->getPathname());
                if (should_skip_archive_entry($fullItemPath, $pathSec)) continue;
                if ($item->isFile()) {
                    $fileCount++;
                    $totalSize += $item->getSize();
                }
                if ($fileCount > FILECARTON_ARCHIVE_MAX_FILES || $totalSize > FILECARTON_ARCHIVE_MAX_SIZE) return;
            }
        }
    }
}

function create_zip(string $baseDir, array $items, string $archivePath, PathSecurity $pathSec, bool $filter = false, array &$skipped = []): void {
    $zip = new \ZipArchive();
    if ($zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
        throw new ApiException('server_error', 500);
    }

    foreach ($items as $name) {
        if (!is_string($name) || $name === '') continue;
        try {
            $itemPath = $pathSec->resolveItemIn($baseDir, $name);
        } catch (\Throwable $e) {
            continue;
        }
        if (!file_exists($itemPath) || is_link($itemPath)) continue;
        if ($filter && should_skip_archive_entry($itemPath, $pathSec)) {
            $skipped[] = basename($itemPath);
            continue;
        }

        $entryName = basename($itemPath);
        if (is_file($itemPath)) {
            $zip->addFile($itemPath, $entryName);
        } elseif (is_dir($itemPath)) {
            add_dir_to_zip($zip, $itemPath, $entryName, $filter ? $pathSec : null, $skipped);
        }
    }

    $zip->close();
}

function add_dir_to_zip(\ZipArchive $zip, string $dirPath, string $prefix, ?PathSecurity $pathSec = null, array &$skipped = []): void {
    $zip->addEmptyDir($prefix);
    $entries = scandir($dirPath);
    if ($entries === false) return;

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $dirPath . '/' . $entry;
        if (is_link($full)) continue;
        if ($pathSec !== null && should_skip_archive_entry($full, $pathSec)) {
            $skipped[] = $prefix . '/' . $entry;
            continue;
        }
        $zipPath = $prefix . '/' . $entry;

        if (is_file($full)) {
            $zip->addFile($full, $zipPath);
        } elseif (is_dir($full)) {
            add_dir_to_zip($zip, $full, $zipPath, $pathSec, $skipped);
        }
    }
}

function create_tar(string $baseDir, array $items, string $archivePath, PathSecurity $pathSec, bool $filter = false, array &$skipped = []): void {
    $phar = new \PharData($archivePath);

    foreach ($items as $name) {
        if (!is_string($name) || $name === '') continue;
        try {
            $itemPath = $pathSec->resolveItemIn($baseDir, $name);
        } catch (\Throwable $e) {
            continue;
        }
        if (!file_exists($itemPath) || is_link($itemPath)) continue;
        if ($filter && should_skip_archive_entry($itemPath, $pathSec)) {
            $skipped[] = basename($itemPath);
            continue;
        }

        $entryName = basename($itemPath);
        if (is_file($itemPath)) {
            $phar->addFile($itemPath, $entryName);
        } elseif (is_dir($itemPath)) {
            add_dir_to_tar($phar, $itemPath, $entryName, $filter ? $pathSec : null, $skipped);
        }
    }
}

function add_dir_to_tar(\PharData $phar, string $dirPath, string $prefix, ?PathSecurity $pathSec = null, array &$skipped = []): void {
    $phar->addEmptyDir($prefix);
    $entries = scandir($dirPath);
    if ($entries === false) return;

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $dirPath . '/' . $entry;
        if (is_link($full)) continue;
        if ($pathSec !== null && should_skip_archive_entry($full, $pathSec)) {
            $skipped[] = $prefix . '/' . $entry;
            continue;
        }
        $tarPath = $prefix . '/' . $entry;

        if (is_file($full)) {
            $phar->addFile($full, $tarPath);
        } elseif (is_dir($full)) {
            add_dir_to_tar($phar, $full, $tarPath, $pathSec, $skipped);
        }
    }
}
