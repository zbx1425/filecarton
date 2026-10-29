<?php

namespace FileCarton;

/**
 * API: archive_create — Create a ZIP or TAR archive.
 * POST ?fcapi=archive_create
 * Body: { format: "zip"|"tar", path, items: string[], archiveName? }
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
    $skipped = [];
    foreach (enumerate_archive_entries($dirAbs, $input['items'], $pathSec, $skipped) as $entry) {
        if (!$entry['isDir']) {
            $fileCount++;
            $totalSize += filesize($entry['fullPath']);
        }
        if ($fileCount > FILECARTON_ARCHIVE_MAX_FILES) {
            Response::error('archive.too_many_to_create', 400, ['limit' => FILECARTON_ARCHIVE_MAX_FILES]);
        }
        if ($totalSize > FILECARTON_ARCHIVE_MAX_SIZE) {
            Response::error('archive.too_large_to_create', 400, ['limitMB' => round(FILECARTON_ARCHIVE_MAX_SIZE / 1024 / 1024, 1)]);
        }
    }

    $skipped = [];
    if ($format === 'zip') {
        create_zip($dirAbs, $input['items'], $archivePath, $pathSec, $skipped);
    } else {
        create_tar($dirAbs, $input['items'], $archivePath, $pathSec, $skipped);
    }

    $relativePath = substr($archivePath, strlen($pathSec->getRootPath()) + 1);

    Response::ok([
        'archivePath' => $relativePath,
        'size'        => filesize($archivePath),
        'skipped'     => $skipped,
    ]);
}

/**
 * Check if an archive entry should be skipped (ignored path).
 */
function should_skip_archive_entry(string $fullPath, PathSecurity $pathSec): bool {
    return $pathSec->isIgnored($fullPath);
}

/**
 * Yield all files and directories for archiving from a selection of items.
 *
 * @param string $baseDir     Absolute path of the directory containing the items
 * @param array  $items       Array of item names (basenames) to include
 * @param PathSecurity $pathSec
 * @param array  &$skipped    Collects names/paths of entries skipped due to ignore rules
 * @yields array{entryName: string, fullPath: string, isDir: bool}
 */
function enumerate_archive_entries(string $baseDir, array $items, PathSecurity $pathSec, array &$skipped): \Generator {
    foreach ($items as $name) {
        if (!is_string($name) || $name === '') continue;
        try {
            $itemPath = $pathSec->resolveItemIn($baseDir, $name);
        } catch (\Throwable $e) {
            continue;
        }
        if (!file_exists($itemPath) || is_link($itemPath)) continue;
        if (should_skip_archive_entry($itemPath, $pathSec)) {
            $skipped[] = basename($itemPath);
            continue;
        }

        $entryName = basename($itemPath);
        if (is_file($itemPath)) {
            yield ['entryName' => $entryName, 'fullPath' => $itemPath, 'isDir' => false];
        } elseif (is_dir($itemPath)) {
            yield ['entryName' => $entryName . '/', 'fullPath' => $itemPath, 'isDir' => true];
            yield from enumerate_dir_entries($itemPath, $entryName, $pathSec, $skipped);
        }
    }
}

/**
 * Recursively yield directory contents for archiving.
 * @yields array{entryName: string, fullPath: string, isDir: bool}
 */
function enumerate_dir_entries(string $dirPath, string $prefix, PathSecurity $pathSec, array &$skipped): \Generator {
    $entries = @scandir($dirPath);
    if ($entries === false) return;

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $dirPath . '/' . $entry;
        if (is_link($full)) continue;
        if (should_skip_archive_entry($full, $pathSec)) {
            $skipped[] = $prefix . '/' . $entry;
            continue;
        }
        $archivePath = $prefix . '/' . $entry;

        if (is_file($full)) {
            yield ['entryName' => $archivePath, 'fullPath' => $full, 'isDir' => false];
        } elseif (is_dir($full)) {
            yield ['entryName' => $archivePath . '/', 'fullPath' => $full, 'isDir' => true];
            yield from enumerate_dir_entries($full, $archivePath, $pathSec, $skipped);
        }
    }
}

function create_zip(string $baseDir, array $items, string $archivePath, PathSecurity $pathSec, array &$skipped = []): void {
    $zip = new \ZipArchive();
    if ($zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
        throw new ApiException('server_error', 500);
    }

    foreach (enumerate_archive_entries($baseDir, $items, $pathSec, $skipped) as $entry) {
        if ($entry['isDir']) {
            $zip->addEmptyDir(rtrim($entry['entryName'], '/'));
        } else {
            $zip->addFile($entry['fullPath'], $entry['entryName']);
        }
    }

    $zip->close();
}

function create_tar(string $baseDir, array $items, string $archivePath, PathSecurity $pathSec, array &$skipped = []): void {
    $phar = new \PharData($archivePath);

    foreach (enumerate_archive_entries($baseDir, $items, $pathSec, $skipped) as $entry) {
        if ($entry['isDir']) {
            $phar->addEmptyDir(rtrim($entry['entryName'], '/'));
        } else {
            $phar->addFile($entry['fullPath'], $entry['entryName']);
        }
    }
}
