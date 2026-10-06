<?php

namespace FileCarton;

/**
 * API: archive — Create or extract an archive.
 * POST ?fcapi=archive
 * Body: { operation: "create"|"extract", ... }
 *
 * Create: { operation, format: "zip"|"tar", path, items: string[], archiveName? }
 * Extract: { operation, path (archive file), targetPath, createSubdir? }
 */

function api_archive(PathSecurity $pathSec, FileOps $fileOps): void {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['operation'])) {
        Response::error('Missing required field: operation', 400);
    }

    $operation = $input['operation'];

    if ($operation === 'create') {
        archive_create($input, $pathSec, $fileOps);
    } elseif ($operation === 'extract') {
        archive_extract($input, $pathSec);
    } else {
        Response::error('Operation must be "create" or "extract"', 400);
    }
}

function archive_create(array $input, PathSecurity $pathSec, FileOps $fileOps) {
    if (!isset($input['format'], $input['path'], $input['items']) || !is_array($input['items'])) {
        Response::error('Missing fields: format, path, items', 400);
    }

    $format = $input['format'];
    if ($format !== 'zip' && $format !== 'tar') {
        Response::error('Format must be "zip" or "tar"', 400);
    }

    $dirAbs = $pathSec->resolve($input['path']);
    $pathSec->assertNotIgnored($dirAbs);
    if (!is_dir($dirAbs)) {
        Response::error('Directory not found', 404);
    }

    $archiveName = $input['archiveName'] ?? ('archive_' . date('ymd_His') . '.' . $format);
    $sanitizedName = $pathSec->sanitizeFileName($archiveName);

    if ($pathSec->isExtensionBlocked($sanitizedName)) {
        Response::error('Archive file type is restricted', 403);
    }

    $archivePath = $dirAbs . '/' . $sanitizedName;
    $pathSec->assertWithinRoot($archivePath);

    if ($pathSec->wouldBeIgnored($archivePath, false)) {
        Response::error('Access denied', 403);
    }

    if (file_exists($archivePath)) {
        Response::error('Archive name already exists', 409);
    }

    $fileCount = 0;
    $totalSize = 0;
    count_items_for_archive($dirAbs, $input['items'], $pathSec, $fileCount, $totalSize);

    if ($fileCount > FILECARTON_ARCHIVE_MAX_FILES) {
        Response::error('Too many files to archive (limit: ' . FILECARTON_ARCHIVE_MAX_FILES . ')', 400);
    }
    if ($totalSize > FILECARTON_ARCHIVE_MAX_SIZE) {
        Response::error('Total size too large to archive (limit: ' . round(FILECARTON_ARCHIVE_MAX_SIZE / 1024 / 1024 / 1024, 1) . ' GB)', 400);
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

/**
 * Check if an archive entry should be blocked by security rules.
 * Returns a reason string (e.g. 'blocked_dotfile') or null if allowed.
 */
function check_extract_entry(string $normalized, string $finalTarget, PathSecurity $pathSec, bool $isDir): ?string {
    $segments = explode('/', $normalized);
    foreach ($segments as $seg) {
        if (preg_match('#[:*?"<>|]#', $seg)) {
            return 'blocked_invalid';
        }
    }

    if (!$isDir) {
        $basename = basename($normalized);
        if ($pathSec->isExtensionBlocked($basename)) {
            return 'blocked_extension';
        }
    }

    $destPath = $finalTarget . '/' . $normalized;
    if ($pathSec->wouldBeIgnored($destPath, $isDir)) {
        return 'blocked_ignored';
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

function archive_extract(array $input, PathSecurity $pathSec) {
    if (!isset($input['path'], $input['targetPath'])) {
        Response::error('Missing fields: path, targetPath', 400);
    }

    $archiveAbs = $pathSec->resolve($input['path']);
    $pathSec->assertNotIgnored($archiveAbs);
    if (!is_file($archiveAbs)) {
        Response::error('Archive file not found', 404);
    }

    $targetAbs = $pathSec->resolve($input['targetPath']);
    $pathSec->assertNotIgnored($targetAbs);
    if (!is_dir($targetAbs)) {
        Response::error('Target directory not found', 404);
    }

    $dryRun = !empty($input['dryRun']);
    $createSubdir = !empty($input['createSubdir']);

    if ($createSubdir) {
        $baseName = $pathSec->sanitizeFileName(pathinfo($archiveAbs, PATHINFO_FILENAME));
        $targetAbs .= '/' . $baseName;
        $pathSec->assertWithinRoot($targetAbs);
        if ($pathSec->wouldBeIgnored($targetAbs, true)) {
            Response::error('Access denied', 403);
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
            Response::error('Unsupported archive format', 400);
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
        Response::error('Cannot create temporary extraction directory', 500);
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
            throw new \RuntimeException('Unsupported archive format');
        }

        $mergeSkipped = merge_extracted_to_target($tempExtractDir, $targetAbs, $pathSec);
        foreach ($mergeSkipped as $skippedEntry) {
            $failed[] = ['path' => $skippedEntry, 'reason' => 'blocked_ignored'];
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
                throw new \RuntimeException('Failed to move extracted item: ' . $entry);
            }
        }
    }
    return $skipped;
}

function extract_zip_safe(string $archiveAbs, string $targetAbs, string $normalizedTarget, PathSecurity $pathSec, int &$bytesExtracted, int $maxBytes, string $finalTarget, array &$failed): int {
    $zip = new \ZipArchive();
    if ($zip->open($archiveAbs) !== true) {
        throw new \RuntimeException('Cannot open ZIP archive');
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
                    throw new \RuntimeException('Extracted data exceeds size limit (' . round($maxBytes / 1024 / 1024 / 1024, 1) . ' GB)', 413);
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
        throw new \RuntimeException('Cannot open TAR archive: ' . $e->getMessage());
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
                    throw new \RuntimeException('Extracted data exceeds size limit (' . round($maxBytes / 1024 / 1024 / 1024, 1) . ' GB)', 413);
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
        Response::error('Cannot open ZIP archive', 400);
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
        Response::error('Cannot open TAR archive: ' . $e->getMessage(), 400);
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
        throw new \RuntimeException('Cannot create ZIP file');
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
