<?php

namespace FileCarton;

/**
 * API: check_upload_conflicts — Check which files already exist before upload.
 * POST ?fcapi=check_upload_conflicts
 * Body: { paths: string[] }
 *
 * Returns the subset of paths that already exist as files on the server.
 * Only checks for files, not directories.
 */

function api_check_upload_conflicts(PathSecurity $pathSec, FileOps $fileOps): void {
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['paths']) || !is_array($input['paths'])) {
    Response::error('missing_fields', 400, ['fields' => 'paths']);
}

if (empty($input['paths'])) {
    Response::ok(['existing' => []]);
}

$existing = [];

foreach ($input['paths'] as $relativePath) {
    if (!is_string($relativePath) || $relativePath === '') {
        continue;
    }

    try {
        $absPath = $pathSec->resolve($relativePath);
        if ($pathSec->isIgnored($absPath)) continue;
        if (is_file($absPath)) {
            $existing[] = $relativePath;
        }
    } catch (\Throwable $e) {
        continue;
    }
}

Response::ok(['existing' => $existing]);
}
