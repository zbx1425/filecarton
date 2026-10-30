<?php

namespace FileCarton;

/**
 * Shared archive entry enumeration for archive_create and batch_download.
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
