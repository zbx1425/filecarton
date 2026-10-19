<?php

namespace FileCarton;

/**
 * API: archive_extract — Extract a ZIP or TAR archive.
 * POST ?fcapi=archive_extract
 * Body: { path (archive file), targetPath, createSubdir?, dryRun? }
 */

function api_archive_extract(PathSecurity $pathSec, FileOps $fileOps): void {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['path'], $input['targetPath'])) {
        Response::error('missing_fields', 400, ['fields' => 'path, targetPath']);
    }

    $archiveAbs = $pathSec->resolveExistingFile($input['path']);
    $baseTargetAbs = $pathSec->resolveExistingDir($input['targetPath']);
    $targetAbs = $baseTargetAbs;

    $dryRun = !empty($input['dryRun']);
    $createSubdir = !empty($input['createSubdir']);

    if ($createSubdir) {
        $baseName = $pathSec->sanitizeFileName(archive_base_name($archiveAbs));
        $candidate = $baseTargetAbs . '/' . $baseName;
        $pathSec->assertWithinRoot($candidate);
        if ($pathSec->wouldBeIgnored($candidate, true)) {
            Response::error('access_denied', 403);
        }
        if (is_link($candidate) || (file_exists($candidate) && !is_dir($candidate))) {
            Response::error('already_exists', 409);
        }
        if (is_dir($candidate)) {
            $realCandidate = realpath($candidate);
            if ($realCandidate === false) {
                Response::error('not_found.dir', 404);
            }
            $targetAbs = str_replace('\\', '/', $realCandidate);
            $pathSec->assertWithinRoot($targetAbs);
            $pathSec->assertNotIgnored($targetAbs);
        } else {
            $targetAbs = $candidate;
        }
    }

    $normalizedTarget = str_replace('\\', '/', $targetAbs);
    ArchiveReader::detectFormat($archiveAbs);

    if ($dryRun) {
        $result = dry_run_archive($archiveAbs, $normalizedTarget, $pathSec);
        $relTarget = substr($normalizedTarget, strlen($pathSec->getRootPath()) + 1);
        Response::ok([
            'wouldExtract' => $result['wouldExtract'],
            'conflicts'    => $result['conflicts'],
            'failed'       => $result['failed'],
            'targetPath'   => $relTarget ?: '',
        ]);
    }

    $tempExtractDir = $baseTargetAbs . '/__fc_extract_' . bin2hex(random_bytes(4));
    if (!mkdir($tempExtractDir, 0755, true)) {
        Response::error('server_error', 500);
    }
    $normalizedTemp = str_replace('\\', '/', $tempExtractDir);

    $extracted = 0;
    $failed = [];
    try {
        $maxBytes = FILECARTON_ARCHIVE_MAX_SIZE;
        $extracted = extract_archive_safe($archiveAbs, $normalizedTemp, $pathSec, $maxBytes, $normalizedTarget, $failed);

        if (!is_dir($targetAbs) && !mkdir($targetAbs, 0755, true) && !is_dir($targetAbs)) {
            throw new ApiException('server_error', 500);
        }

        $mergeSkipped = merge_extracted_to_target($tempExtractDir, $targetAbs, $pathSec);
        foreach ($mergeSkipped as $skippedEntry) {
            $failed[] = ['path' => $skippedEntry, 'reason' => 'blocked.ignored'];
        }
    } finally {
        Platform::deleteRecursive($tempExtractDir);
    }

    $relTarget = substr(str_replace('\\', '/', $targetAbs), strlen($pathSec->getRootPath()) + 1);

    Response::ok([
        'extracted'  => $extracted,
        'failed'     => $failed,
        'targetPath' => $relTarget ?: '',
    ]);
}

function archive_base_name(string $absPath): string {
    $filename = basename($absPath);
    if (preg_match('/^(.+)\.tar\.(?:gz|bz2|xz)$/i', $filename, $m)) {
        return $m[1];
    }
    return pathinfo($filename, PATHINFO_FILENAME);
}

/**
 * Check if an archive entry should be blocked by security rules.
 * Returns a reason string (dot-separated) or null if allowed.
 */
function check_extract_entry(string $normalized, string $finalTarget, PathSecurity $pathSec, bool $isDir): ?string {
    $segments = explode('/', $normalized);
    foreach ($segments as $seg) {
        if (strpos($seg, "\0") !== false) {
            return 'blocked.invalid';
        }
        if (preg_match('#[:*?"<>|]#', $seg)) {
            return 'blocked.invalid';
        }
    }

    if (!$isDir) {
        $basename = basename($normalized);
        if ($pathSec->isFilenameBlocked($basename)) {
            return 'blocked.name';
        }
    }

    $destPath = $finalTarget . '/' . $normalized;
    if ($pathSec->wouldBeIgnored($destPath, $isDir)) {
        return 'blocked.ignored';
    }

    return null;
}

/**
 * Sanitize an archive entry path by trimming trailing dots/spaces from each segment.
 */
function sanitize_entry_path(string $entryPath): string {
    $parts = explode('/', $entryPath);
    $result = [];
    foreach ($parts as $part) {
        $cleaned = rtrim($part, '. ');
        if ($cleaned !== '' && $cleaned !== '.' && $cleaned !== '..') {
            $result[] = $cleaned;
        }
    }
    return implode('/', $result);
}

/**
 * Merge extracted files from temp directory to target, with protection checks.
 * Returns array of skipped entry names (due to ignored/protected targets).
 */
function merge_extracted_to_target(string $tempDir, string $targetDir, PathSecurity $pathSec, string $prefix = ''): array {
    $entries = @scandir($tempDir);
    if ($entries === false) return [];

    $skipped = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $src = $tempDir . '/' . $entry;
        $dst = $targetDir . '/' . $entry;
        $entryPath = $prefix === '' ? $entry : $prefix . '/' . $entry;

        if (is_dir($src) && is_dir($dst)) {
            $childSkipped = merge_extracted_to_target($src, $dst, $pathSec, $entryPath);
            foreach ($childSkipped as $s) {
                $skipped[] = $s;
            }
            @rmdir($src);
        } else {
            if (file_exists($dst) || is_link($dst)) {
                $dstNormalized = str_replace('\\', '/', $dst);
                if ($pathSec->isIgnored($dstNormalized)) {
                    $skipped[] = $entryPath;
                    continue;
                }
                if (is_dir($dst) && $pathSec->hasProtectedDescendants($dstNormalized)) {
                    $skipped[] = $entryPath;
                    continue;
                }
                Platform::deleteRecursive($dst);
            }
            if (!rename($src, $dst)) {
                throw new ApiException('server_error', 500);
            }
        }
    }
    return $skipped;
}

function extract_archive_safe(string $archiveAbs, string $normalizedTarget, PathSecurity $pathSec, int $maxBytes, string $finalTarget, array &$failed): int {
    $extracted = 0;
    $bytesExtracted = 0;

    foreach (ArchiveReader::iterateEntries($archiveAbs) as $entry) {
        $normalized = sanitize_entry_path($entry['path']);
        if ($normalized === '') continue;

        $destPath = $normalizedTarget . '/' . $normalized;
        if (!str_starts_with($destPath, $normalizedTarget . '/')) {
            continue;
        }

        if (PathSecurity::isAppleJunkPath($normalized)) continue;

        $isDir = $entry['isDir'];
        $blockReason = check_extract_entry($normalized, $finalTarget, $pathSec, $isDir);
        if ($blockReason !== null) {
            $failed[] = ['path' => $normalized, 'reason' => $blockReason];
            continue;
        }

        if ($isDir) {
            if (!is_dir($destPath)) mkdir($destPath, 0755, true);
        } else {
            $parentDir = dirname($destPath);
            if (!is_dir($parentDir)) mkdir($parentDir, 0755, true);

            $inStream = ($entry['openStream'])();
            if ($inStream === false) {
                $failed[] = ['path' => $normalized, 'reason' => 'io_error'];
                continue;
            }
            $outFile = @fopen($destPath, 'wb');
            if ($outFile === false) {
                fclose($inStream);
                $failed[] = ['path' => $normalized, 'reason' => 'io_error'];
                continue;
            }

            while (!feof($inStream)) {
                $chunk = fread($inStream, 65536);
                if ($chunk === false || $chunk === '') break;
                fwrite($outFile, $chunk);
                $bytesExtracted += strlen($chunk);
                if ($bytesExtracted > $maxBytes) {
                    fclose($inStream);
                    fclose($outFile);
                    throw new ApiException('archive.too_large_to_extract', 413, ['limitMB' => round($maxBytes / 1024 / 1024, 1)]);
                }
            }

            fclose($inStream);
            fflush($outFile);
            fclose($outFile);
            $extracted++;
        }
    }

    return $extracted;
}

function dry_run_archive(string $archiveAbs, string $normalizedTarget, PathSecurity $pathSec): array {
    $wouldExtract = 0;
    $conflicts = [];
    $failed = [];

    foreach (ArchiveReader::iterateEntries($archiveAbs) as $entry) {
        $normalized = sanitize_entry_path($entry['path']);
        if ($normalized === '') continue;

        if (PathSecurity::isAppleJunkPath($normalized)) continue;

        $isDir = $entry['isDir'];
        $destPath = $normalizedTarget . '/' . $normalized;
        if (!str_starts_with($destPath, $normalizedTarget . '/')) continue;

        $blockReason = check_extract_entry($normalized, $normalizedTarget, $pathSec, $isDir);
        if ($blockReason !== null) {
            $failed[] = ['path' => $normalized, 'reason' => $blockReason];
            continue;
        }

        if ($isDir) continue;

        $wouldExtract++;
        if (file_exists($destPath)) {
            $conflicts[] = $normalized;
        }
    }

    return ['wouldExtract' => $wouldExtract, 'conflicts' => $conflicts, 'failed' => $failed];
}
