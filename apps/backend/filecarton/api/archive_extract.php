<?php

namespace FileCarton;

/**
 * API: archive (extract) — Extract a ZIP or TAR archive.
 * POST ?fcapi=archive
 * Body: { operation: "extract", path (archive file), targetPath, createSubdir?, dryRun? }
 */

function api_archive_extract(PathSecurity $pathSec, FileOps $fileOps): void {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['path'], $input['targetPath'])) {
        Response::error('missing_fields', 400, ['fields' => 'path, targetPath']);
    }

    $archiveAbs = $pathSec->resolveExistingFile($input['path']);
    $targetAbs = $pathSec->resolveExistingDir($input['targetPath']);

    $dryRun = !empty($input['dryRun']);
    $createSubdir = !empty($input['createSubdir']);

    if ($createSubdir) {
        $baseName = $pathSec->sanitizeFileName(pathinfo($archiveAbs, PATHINFO_FILENAME));
        $targetAbs .= '/' . $baseName;
        $pathSec->assertWithinRoot($targetAbs);
        if ($pathSec->wouldBeIgnored($targetAbs, true)) {
            Response::error('access_denied', 403);
        }
        if (!$dryRun && !is_dir($targetAbs)) {
            mkdir($targetAbs, 0755, true);
        }
    }

    $normalizedTarget = str_replace('\\', '/', $targetAbs);
    $ext = strtolower(pathinfo($archiveAbs, PATHINFO_EXTENSION));

    if ($dryRun) {
        $result = ['wouldExtract' => 0, 'conflicts' => [], 'failed' => []];
        if ($ext === 'zip') {
            $result = dry_run_zip($archiveAbs, $normalizedTarget, $pathSec);
        } elseif ($ext === 'tar' || $ext === 'gz' || $ext === 'tgz') {
            $result = dry_run_tar($archiveAbs, $normalizedTarget, $pathSec);
        } else {
            Response::error('archive.unsupported_format', 400);
        }

        $relTarget = substr($normalizedTarget, strlen($pathSec->getRootPath()) + 1);
        Response::ok([
            'wouldExtract' => $result['wouldExtract'],
            'conflicts'    => $result['conflicts'],
            'failed'       => $result['failed'],
            'targetPath'   => $relTarget ?: '',
        ]);
    }

    $tempExtractDir = $targetAbs . '/__fc_extract_' . bin2hex(random_bytes(4));
    if (!mkdir($tempExtractDir, 0755, true)) {
        Response::error('server_error', 500);
    }
    $normalizedTemp = str_replace('\\', '/', $tempExtractDir);

    $extracted = 0;
    $failed = [];
    try {
        $bytesExtracted = 0;
        $maxBytes = FILECARTON_ARCHIVE_MAX_SIZE;

        if ($ext === 'zip') {
            $extracted = extract_zip_safe($archiveAbs, $tempExtractDir, $normalizedTemp, $pathSec, $bytesExtracted, $maxBytes, $normalizedTarget, $failed);
        } elseif ($ext === 'tar' || $ext === 'gz' || $ext === 'tgz') {
            $extracted = extract_tar_safe($archiveAbs, $tempExtractDir, $normalizedTemp, $pathSec, $bytesExtracted, $maxBytes, $normalizedTarget, $failed);
        } else {
            throw new ApiException('archive.unsupported_format', 400);
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

/**
 * Check if an archive entry should be blocked by security rules.
 * Returns a reason string (dot-separated) or null if allowed.
 */
function check_extract_entry(string $normalized, string $finalTarget, PathSecurity $pathSec, bool $isDir): ?string {
    $segments = explode('/', $normalized);
    foreach ($segments as $seg) {
        if (preg_match('#[:*?"<>|]#', $seg)) {
            return 'blocked.invalid';
        }
    }

    if (!$isDir) {
        $basename = basename($normalized);
        if ($pathSec->isExtensionBlocked($basename)) {
            return 'blocked.extension';
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

function extract_zip_safe(string $archiveAbs, string $targetAbs, string $normalizedTarget, PathSecurity $pathSec, int &$bytesExtracted, int $maxBytes, string $finalTarget, array &$failed): int {
    $zip = new \ZipArchive();
    if ($zip->open($archiveAbs) !== true) {
        throw new ApiException('archive.cannot_open', 400);
    }

    $extracted = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = $zip->getNameIndex($i);
        $entryPath = str_replace('\\', '/', $entryName);

        $normalized = $pathSec->normalizePath($entryPath);
        if ($normalized === '') continue;
        $normalized = sanitize_entry_path($normalized);
        if ($normalized === '') continue;

        $destPath = $normalizedTarget . '/' . $normalized;

        if (!str_starts_with($destPath, $normalizedTarget . '/')) {
            continue;
        }

        if (PathSecurity::isAppleJunkPath($normalized)) continue;

        $isDir = str_ends_with($entryPath, '/');
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

            $stream = $zip->getStream($entryName);
            if ($stream === false) {
                $failed[] = ['path' => $normalized, 'reason' => 'io_error'];
                continue;
            }
            $outFile = fopen($destPath, 'wb');
            if ($outFile === false) {
                fclose($stream);
                $failed[] = ['path' => $normalized, 'reason' => 'io_error'];
                continue;
            }

            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || $chunk === '') break;
                fwrite($outFile, $chunk);
                $bytesExtracted += strlen($chunk);
                if ($bytesExtracted > $maxBytes) {
                    fclose($stream);
                    fclose($outFile);
                    $zip->close();
                    throw new ApiException('archive.size_exceeded', 413, ['limitGB' => round($maxBytes / 1024 / 1024 / 1024, 1)]);
                }
            }

            fclose($stream);
            fflush($outFile);
            fclose($outFile);
            $extracted++;
        }
    }
    $zip->close();
    return $extracted;
}

function extract_tar_safe(string $archiveAbs, string $targetAbs, string $normalizedTarget, PathSecurity $pathSec, int &$bytesExtracted, int $maxBytes, string $finalTarget, array &$failed): int {
    try {
        $phar = new \PharData($archiveAbs);
    } catch (\Throwable $e) {
        throw new ApiException('archive.cannot_open', 400);
    }

    $extracted = 0;
    $iter = new \RecursiveIteratorIterator($phar, \RecursiveIteratorIterator::SELF_FIRST);

    foreach ($iter as $item) {
        $entryPath = $item->getPathname();
        $entryPath = preg_replace('#^phar://.*?\.tar(?:\.gz)?/#', '', $entryPath);
        $entryPath = str_replace('\\', '/', $entryPath);

        $normalized = $pathSec->normalizePath($entryPath);
        if ($normalized === '') continue;
        $normalized = sanitize_entry_path($normalized);
        if ($normalized === '') continue;

        $destPath = $normalizedTarget . '/' . $normalized;

        if (!str_starts_with($destPath, $normalizedTarget . '/')) {
            continue;
        }

        if (PathSecurity::isAppleJunkPath($normalized)) continue;

        $isDir = $item->isDir();
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

            $inStream = @fopen($item->getPathname(), 'rb');
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
                    throw new ApiException('archive.size_exceeded', 413, ['limitGB' => round($maxBytes / 1024 / 1024 / 1024, 1)]);
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

function dry_run_zip(string $archiveAbs, string $normalizedTarget, PathSecurity $pathSec): array {
    $zip = new \ZipArchive();
    if ($zip->open($archiveAbs) !== true) {
        Response::error('archive.cannot_open', 400);
    }

    $wouldExtract = 0;
    $conflicts = [];
    $failed = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = $zip->getNameIndex($i);
        $entryPath = str_replace('\\', '/', $entryName);

        $normalized = $pathSec->normalizePath($entryPath);
        if ($normalized === '') continue;
        $normalized = sanitize_entry_path($normalized);
        if ($normalized === '') continue;

        if (PathSecurity::isAppleJunkPath($normalized)) continue;

        $isDir = str_ends_with($entryPath, '/');

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
    $zip->close();

    return ['wouldExtract' => $wouldExtract, 'conflicts' => $conflicts, 'failed' => $failed];
}

function dry_run_tar(string $archiveAbs, string $normalizedTarget, PathSecurity $pathSec): array {
    try {
        $phar = new \PharData($archiveAbs);
    } catch (\Throwable $e) {
        Response::error('archive.cannot_open', 400);
    }

    $wouldExtract = 0;
    $conflicts = [];
    $failed = [];
    $iter = new \RecursiveIteratorIterator($phar, \RecursiveIteratorIterator::SELF_FIRST);

    foreach ($iter as $item) {
        $entryPath = $item->getPathname();
        $entryPath = preg_replace('#^phar://.*?\.tar(?:\.gz)?/#', '', $entryPath);
        $entryPath = str_replace('\\', '/', $entryPath);

        $normalized = $pathSec->normalizePath($entryPath);
        if ($normalized === '') continue;
        $normalized = sanitize_entry_path($normalized);
        if ($normalized === '') continue;

        if (PathSecurity::isAppleJunkPath($normalized)) continue;

        $isDir = $item->isDir();

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
