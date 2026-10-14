<?php

namespace FileCarton;

/**
 * API: archive_download — Force-download a single file from an archive.
 * GET ?fcapi=archive_download&path={archivePath}&entry={entryPath}
 */

function api_archive_download(PathSecurity $pathSec, FileOps $fileOps): void {
    $path = $_GET['path'] ?? '';
    $entryPath = $_GET['entry'] ?? '';
    $absPath = $pathSec->resolveExistingFile($path);

    $opened = ArchiveReader::openEntryStream($absPath, $entryPath);
    Response::fileFromResource(
        $opened['stream'],
        $opened['size'],
        $opened['name'],
        $opened['cleanup']
    );
}
