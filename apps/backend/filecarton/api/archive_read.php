<?php

namespace FileCarton;

/**
 * API: archive_read — Read UTF-8 text content of a single file inside an archive.
 * GET ?fcapi=archive_read&path={archivePath}&entry={entryPath}
 */

function api_archive_read(PathSecurity $pathSec, FileOps $fileOps): void {
    $path = $_GET['path'] ?? '';
    $entryPath = $_GET['entry'] ?? '';
    $absPath = $pathSec->resolveExistingFile($path);

    $opened = ArchiveReader::openEntryStream($absPath, $entryPath);
    $stream = $opened['stream'];
    $declaredSize = $opened['size'];
    $mtime = $opened['mtime'];
    $name = $opened['name'];
    $cleanup = $opened['cleanup'];

    $maxSize = FILECARTON_MAX_EDIT_SIZE;
    if ($declaredSize > $maxSize) {
        $cleanup();
        Response::error('file_too_large', 413, ['maxMB' => round($maxSize / 1024 / 1024, 1)]);
    }

    $content = '';
    $bytesRead = 0;
    try {
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false || $chunk === '') break;
            $bytesRead += strlen($chunk);
            if ($bytesRead > $maxSize) {
                Response::error('file_too_large', 413, ['maxMB' => round($maxSize / 1024 / 1024, 1)]);
            }
            $content .= $chunk;
        }
    } finally {
        $cleanup();
    }

    if (!mb_check_encoding($content, 'UTF-8')) {
        Response::error('not_utf8', 400);
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime = MimeType::fromExtension($ext);

    Response::ok([
        'content'  => $content,
        'size'     => strlen($content),
        'mtime'    => $mtime,
        'mime'     => $mime,
        'encoding' => 'utf-8',
    ]);
}
