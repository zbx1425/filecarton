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
    $temp = new TempStorage(RepoSetting::current()->resolvedTempDir());
    $tempFile = $temp->createTempFile('archive', '.' . $format);

    try {
        if ($format === 'zip') {
            create_zip($dirAbs, $input['items'], $tempFile, $pathSec, $skipped);
        } else {
            create_tar($dirAbs, $input['items'], $tempFile, $pathSec, $skipped);
        }

        if (!Platform::safePublish($tempFile, $archivePath)) {
            throw new ApiException('server_error', 500);
        }
    } catch (\Throwable $e) {
        @unlink($tempFile);
        throw $e;
    }

    $relativePath = substr($archivePath, strlen($pathSec->getRootPath()) + 1);

    Response::ok([
        'archivePath' => $relativePath,
        'size'        => filesize($archivePath),
        'skipped'     => $skipped,
    ]);
}

function create_zip(string $baseDir, array $items, string $archivePath, PathSecurity $pathSec, array &$skipped = []): void {
    $zip = new \ZipArchive();
    if ($zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
        throw new ApiException('server_error', 500);
    }

    foreach (enumerate_archive_entries($baseDir, $items, $pathSec, $skipped) as $entry) {
        if ($entry['isDir']) {
            if ($zip->addEmptyDir(rtrim($entry['entryName'], '/')) === false) {
                $zip->close();
                throw new ApiException('server_error', 500);
            }
        } else {
            if ($zip->addFile($entry['fullPath'], $entry['entryName']) === false) {
                $zip->close();
                throw new ApiException('server_error', 500);
            }
        }
    }

    if ($zip->close() === false) {
        throw new ApiException('server_error', 500);
    }
}

function create_tar(string $baseDir, array $items, string $archivePath, PathSecurity $pathSec, array &$skipped = []): void {
    try {
        $phar = new \PharData($archivePath);
        foreach (enumerate_archive_entries($baseDir, $items, $pathSec, $skipped) as $entry) {
            if ($entry['isDir']) {
                $phar->addEmptyDir(rtrim($entry['entryName'], '/'));
            } else {
                $phar->addFile($entry['fullPath'], $entry['entryName']);
            }
        }
    } catch (\Throwable $e) {
        @unlink($archivePath);
        throw new ApiException('server_error', 500);
    }
}
