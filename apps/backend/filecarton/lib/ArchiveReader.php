<?php

namespace FileCarton;

class ArchiveReader {

    /**
     * Detect archive format from file path.
     * Returns 'zip', 'tar', or 'targz'.
     */
    public static function detectFormat(string $absPath): string {
        $lower = strtolower(basename($absPath));
        if (str_ends_with($lower, '.zip')) {
            return 'zip';
        }
        if (str_ends_with($lower, '.tar')) {
            return 'tar';
        }
        if (str_ends_with($lower, '.tar.gz') || str_ends_with($lower, '.tgz') || str_ends_with($lower, '.gz')) {
            return 'targz';
        }
        throw new ApiException('archive.unsupported_format', 400);
    }

    /**
     * Assert that the archive file size is within limits for online opening.
     */
    public static function assertCanOpen(string $absPath, string $format): void {
        if ($format === 'targz') {
            $maxSize = defined('FILECARTON_ARCHIVE_TARGZ_MAX_ACCESS_SIZE')
                ? (int)FILECARTON_ARCHIVE_TARGZ_MAX_ACCESS_SIZE
                : (64 * 1024 * 1024);
            $compressedSize = (int)filesize($absPath);
            if ($compressedSize > $maxSize) {
                throw new ApiException('archive.too_large_to_open', 413, [
                    'maxMB' => round($maxSize / 1024 / 1024, 1),
                ]);
            }
        }
    }

    /**
     * Normalize an archive entry path to a canonical relative path without leading/trailing slashes.
     */
    public static function normalizeEntryPath(string $rawPath): string {
        $path = str_replace('\\', '/', $rawPath);
        $parts = explode('/', $path);
        $result = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') {
                array_pop($result);
            } else {
                $result[] = $part;
            }
        }
        return implode('/', $result);
    }

    /**
     * Extract relative entry path from a PharFileInfo item inside PharData.
     */
    public static function extractPharEntryPath(\SplFileInfo $item, string $absPath): string {
        $raw = str_replace('\\', '/', $item->getPathname());
        $cleanAbs = str_replace('\\', '/', realpath($absPath) ?: $absPath);
        foreach (['phar://' . str_replace('\\', '/', $absPath) . '/', 'phar://' . $cleanAbs . '/'] as $prefix) {
            if (strncasecmp($raw, $prefix, strlen($prefix)) === 0) {
                return self::normalizeEntryPath(substr($raw, strlen($prefix)));
            }
        }
        return '';
    }

    /**
     * Iterate over normalized entries in a ZIP, TAR, or TAR.GZ archive.
     *
     * @return \Generator<int, array{path: string, isDir: bool, openStream: callable}>
     */
    public static function iterateEntries(string $absPath): \Generator {
        $format = self::detectFormat($absPath);
        if ($format === 'zip') {
            $zip = new \ZipArchive();
            if ($zip->open($absPath) !== true) {
                throw new ApiException('archive.cannot_open', 400);
            }
            try {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entryName = $zip->getNameIndex($i);
                    if ($entryName === false) continue;
                    $isDir = str_ends_with($entryName, '/') || str_ends_with($entryName, '\\');
                    $norm = self::normalizeEntryPath($entryName);
                    if ($norm === '') continue;
                    yield [
                        'path'       => $norm,
                        'isDir'      => $isDir,
                        'openStream' => static function () use ($zip, $entryName) {
                            return $zip->getStream($entryName);
                        },
                    ];
                }
            } finally {
                @$zip->close();
            }
        } else {
            try {
                $phar = new \PharData($absPath);
                $iter = new \RecursiveIteratorIterator($phar, \RecursiveIteratorIterator::SELF_FIRST);
            } catch (\Throwable $e) {
                throw new ApiException('archive.cannot_open', 400);
            }
            foreach ($iter as $item) {
                $norm = self::extractPharEntryPath($item, $absPath);
                if ($norm === '') continue;
                $pathname = $item->getPathname();
                yield [
                    'path'       => $norm,
                    'isDir'      => $item->isDir(),
                    'openStream' => static function () use ($pathname) {
                        return @fopen($pathname, 'rb');
                    },
                ];
            }
        }
    }

    /**
     * List contents of a ZIP, TAR, or TAR.GZ archive with truncation and random-access metadata.
     */
    public static function listArchive(string $absPath): array {
        $format = self::detectFormat($absPath);
        self::assertCanOpen($absPath, $format);

        $listMaxFiles = defined('FILECARTON_ARCHIVE_LIST_MAX_FILES')
            ? (int)FILECARTON_ARCHIVE_LIST_MAX_FILES
            : 10000;
        $tarMaxAccessFiles = defined('FILECARTON_ARCHIVE_TAR_MAX_ACCESS_FILES')
            ? (int)FILECARTON_ARCHIVE_TAR_MAX_ACCESS_FILES
            : 1000;

        $entries = [];
        $totalFiles = 0;
        $totalSize = 0;
        $truncated = false;
        $compressedSize = (int)filesize($absPath);
        $archiveMtime = (int)filemtime($absPath);

        if ($format === 'zip') {
            $zip = new \ZipArchive();
            if ($zip->open($absPath) !== true) {
                throw new ApiException('archive.cannot_open', 400);
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) continue;
                $rawName = $stat['name'];
                $isDir = str_ends_with($rawName, '/') || str_ends_with($rawName, '\\');
                $size = $isDir ? 0 : (int)$stat['size'];

                if (count($entries) < $listMaxFiles) {
                    $entryPath = self::normalizeEntryPath($rawName);
                    if ($entryPath === '') continue;

                    if (!$isDir) {
                        $totalFiles++;
                        $totalSize += $size;
                    }

                    $entries[] = [
                        'path'  => $entryPath,
                        'size'  => $size,
                        'mtime' => (int)($stat['mtime'] ?? $archiveMtime),
                        'isDir' => $isDir,
                    ];
                } else {
                    if (trim($rawName, '/\\.') === '') continue;
                    $truncated = true;
                    if (!$isDir) {
                        $totalFiles++;
                        $totalSize += $size;
                    }
                }
            }
            $zip->close();
            $randomAccess = true;
        } else {
            try {
                $phar = new \PharData($absPath);
                $iter = new \RecursiveIteratorIterator($phar, \RecursiveIteratorIterator::SELF_FIRST);
                foreach ($iter as $item) {
                    $isDir = $item->isDir();
                    $size = $isDir ? 0 : (int)$item->getSize();

                    if (count($entries) < $listMaxFiles) {
                        $entryPath = self::extractPharEntryPath($item, $absPath);
                        if ($entryPath === '') continue;

                        if (!$isDir) {
                            $totalFiles++;
                            $totalSize += $size;
                        }

                        $entries[] = [
                            'path'  => $entryPath,
                            'size'  => $size,
                            'mtime' => (int)$item->getMTime(),
                            'isDir' => $isDir,
                        ];
                    } else {
                        $truncated = true;
                        if (!$isDir) {
                            $totalFiles++;
                            $totalSize += $size;
                        }
                    }
                }
            } catch (ApiException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new ApiException('archive.cannot_open', 400);
            }
            $randomAccess = $totalFiles <= $tarMaxAccessFiles;
        }

        return [
            'entries'        => $entries,
            'totalFiles'     => $totalFiles,
            'totalSize'      => $totalSize,
            'compressedSize' => $compressedSize,
            'truncated'      => $truncated,
            'entryLimit'     => $listMaxFiles,
            'randomAccess'   => $randomAccess,
        ];
    }

    /**
     * Open a stream to a single file entry inside an archive.
     *
     * @return array{stream: resource, size: int, mtime: int, name: string, cleanup: callable}
     */
    public static function openEntryStream(string $absPath, string $entryPath): array {
        if ($entryPath === '' || str_contains($entryPath, "\0")) {
            throw new ApiException('invalid_input', 400, ['field' => 'entry']);
        }

        $targetPath = self::normalizeEntryPath($entryPath);
        if ($targetPath === '') {
            throw new ApiException('not_found.file', 404);
        }

        $format = self::detectFormat($absPath);
        self::assertCanOpen($absPath, $format);
        $archiveMtime = (int)filemtime($absPath);
        $maxBytes = defined('FILECARTON_ARCHIVE_MAX_SIZE')
            ? (int)FILECARTON_ARCHIVE_MAX_SIZE
            : (1024 * 1024 * 1024);

        if ($format === 'zip') {
            $zip = new \ZipArchive();
            if ($zip->open($absPath) !== true) {
                throw new ApiException('archive.cannot_open', 400);
            }

            $stat = $zip->statName($targetPath);
            if ($stat !== false && (str_ends_with($stat['name'], '/') || str_ends_with($stat['name'], '\\'))) {
                $stat = false;
            }

            if ($stat === false) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $s = $zip->statIndex($i);
                    if ($s === false) continue;
                    $rawName = $s['name'];
                    if (str_ends_with($rawName, '/') || str_ends_with($rawName, '\\')) continue;
                    if (self::normalizeEntryPath($rawName) === $targetPath) {
                        $stat = $s;
                        break;
                    }
                }
            }

            if ($stat === false) {
                $zip->close();
                throw new ApiException('not_found.file', 404);
            }

            $size = (int)$stat['size'];
            if ($size > $maxBytes) {
                $zip->close();
                throw new ApiException('archive.too_large_to_extract', 413, [
                    'limitMB' => round($maxBytes / 1024 / 1024, 1),
                ]);
            }

            $stream = $zip->getStream($stat['name']);
            if ($stream === false) {
                $zip->close();
                throw new ApiException('io_error', 500);
            }

            return [
                'stream'  => $stream,
                'size'    => $size,
                'mtime'   => (int)($stat['mtime'] ?? $archiveMtime),
                'name'    => basename($targetPath),
                'cleanup' => function () use ($stream, $zip) {
                    if (is_resource($stream)) {
                        @fclose($stream);
                    }
                    @$zip->close();
                },
            ];
        } else {
            $tarMaxAccessFiles = defined('FILECARTON_ARCHIVE_TAR_MAX_ACCESS_FILES')
                ? (int)FILECARTON_ARCHIVE_TAR_MAX_ACCESS_FILES
                : 1000;

            try {
                $phar = new \PharData($absPath);
                $iter = new \RecursiveIteratorIterator($phar, \RecursiveIteratorIterator::SELF_FIRST);
            } catch (\Throwable $e) {
                throw new ApiException('archive.cannot_open', 400);
            }

            $fileCount = 0;
            $matchedPathname = null;
            $matchedSize = 0;
            $matchedMtime = $archiveMtime;

            try {
                foreach ($iter as $item) {
                    $norm = self::extractPharEntryPath($item, $absPath);
                    if ($norm === '') continue;

                    if (!$item->isDir()) {
                        $fileCount++;
                        if ($fileCount > $tarMaxAccessFiles) {
                            throw new ApiException('archive.too_many_to_open', 403, [
                                'limit' => $tarMaxAccessFiles,
                            ]);
                        }
                        if ($matchedPathname === null && $norm === $targetPath) {
                            $matchedPathname = $item->getPathname();
                            $matchedSize = (int)$item->getSize();
                            $matchedMtime = (int)$item->getMTime();
                        }
                    }
                }
            } catch (ApiException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new ApiException('archive.cannot_open', 400);
            }

            if ($matchedPathname === null) {
                throw new ApiException('not_found.file', 404);
            }

            if ($matchedSize > $maxBytes) {
                throw new ApiException('archive.too_large_to_extract', 413, [
                    'limitMB' => round($maxBytes / 1024 / 1024, 1),
                ]);
            }

            $stream = @fopen($matchedPathname, 'rb');
            if ($stream === false) {
                throw new ApiException('io_error', 500);
            }

            return [
                'stream'  => $stream,
                'size'    => $matchedSize,
                'mtime'   => $matchedMtime,
                'name'    => basename($targetPath),
                'cleanup' => function () use ($stream) {
                    if (is_resource($stream)) {
                        @fclose($stream);
                    }
                },
            ];
        }
    }
}
