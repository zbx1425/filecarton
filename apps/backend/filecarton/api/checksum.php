<?php

namespace FileCarton;

/**
 * API: checksum — Compute file hash digests.
 * GET ?fcapi=checksum&path={filePath}
 *
 * Returns file name, size, and SHA-256 / SHA-1 / MD5 digests (uppercase hex).
 */

function api_checksum(PathSecurity $pathSec, FileOps $fileOps): void {
    $path = $_GET['path'] ?? '';
    $absPath = $pathSec->resolveExistingFile($path);
    $size = filesize($absPath);

    @set_time_limit(0);

    $ctx256 = hash_init('sha256');
    $ctx1   = hash_init('sha1');
    $ctxMd5 = hash_init('md5');

    $fp = fopen($absPath, 'rb');
    if ($fp === false) {
        throw new ApiException('server_error', 500);
    }
    while (($chunk = fread($fp, 65536)) !== false && $chunk !== '') {
        hash_update($ctx256, $chunk);
        hash_update($ctx1, $chunk);
        hash_update($ctxMd5, $chunk);
    }
    fclose($fp);

    Response::ok([
        'name'   => basename($path),
        'size'   => $size,
        'sha256' => strtoupper(hash_final($ctx256)),
        'sha1'   => strtoupper(hash_final($ctx1)),
        'md5'    => strtoupper(hash_final($ctxMd5)),
    ]);
}
