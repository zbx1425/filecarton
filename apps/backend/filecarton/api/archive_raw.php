<?php

namespace FileCarton;

/**
 * API: archive_raw — Serve a single file inside an archive inline with correct MIME type.
 * GET ?fcapi=archive_raw&path={archivePath}&entry={entryPath}
 */

function api_archive_raw(PathSecurity $pathSec, FileOps $fileOps): void {
    $path = $_GET['path'] ?? '';
    $entryPath = $_GET['entry'] ?? '';
    $absPath = $pathSec->resolveExistingFile($path);

    $opened = ArchiveReader::openEntryStream($absPath, $entryPath);
    $stream = $opened['stream'];
    $size = $opened['size'];
    $mtime = $opened['mtime'];
    $name = $opened['name'];
    $cleanup = $opened['cleanup'];

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime = MimeType::fromExtension($ext);
    $real = realpath($absPath);
    $canonicalPath = str_replace('\\', '/', $real !== false ? $real : $absPath);
    $etagSeed = $canonicalPath . "\0" . (int)filemtime($absPath) . "\0" . $entryPath . "\0" . $mtime . "\0" . $size;

    if ($mime === 'image/svg+xml') {
        header('Content-Security-Policy: sandbox');
        Response::streamFromResource($stream, $size, $mime, $etagSeed, $cleanup);
    } elseif (MimeType::isSafeForInline($mime)) {
        Response::streamFromResource($stream, $size, $mime, $etagSeed, $cleanup);
    } else {
        Response::fileFromResource($stream, $size, $name, $cleanup);
    }
}
