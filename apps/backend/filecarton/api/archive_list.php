<?php

namespace FileCarton;

/**
 * API: archive_list — List contents of a ZIP or TAR archive.
 * GET ?fcapi=archive_list&path={archivePath}
 */

function api_archive_list(PathSecurity $pathSec, FileOps $fileOps): void {
    $path = $_GET['path'] ?? '';
    $absPath = $pathSec->resolveExistingFile($path);

    $result = ArchiveReader::listArchive($absPath);
    Response::ok($result);
}
