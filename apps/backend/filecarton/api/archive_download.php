<?php

namespace FileCarton;

/**
 * API: archive_download — Force-download a single file from an archive.
 * GET ?fcapi=archive_download&path={archivePath}&entry={entryPath}
 */

function api_archive_download(PathSecurity $pathSec, FileOps $fileOps): void {
    $path = api_query_string('path');
    $entryPath = api_query_string('entry');
    $absPath = $pathSec->resolveExistingFile($path);

    $opened = ArchiveReader::openEntryStream($absPath, $entryPath);
    Response::fileFromResource(
        $opened['stream'],
        $opened['size'],
        $opened['name'],
        $opened['cleanup']
    );
}
