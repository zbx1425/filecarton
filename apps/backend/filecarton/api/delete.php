<?php

namespace FileCarton;

/**
 * API: delete — Delete files/directories (batch, partial success).
 * POST ?fcapi=delete
 * Body: { path, items: string[] }
 *
 * Items that don't exist are silently counted as deleted.
 * Items that fail are added to the `failed` array.
 */

function api_delete(PathSecurity $pathSec, FileOps $fileOps): void {
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['path'], $input['items']) || !is_array($input['items'])) {
    Response::error('missing_fields', 400, ['fields' => 'path, items']);
}

$basePath = $pathSec->resolveExistingDir($input['path']);

$deleted = 0;
$failed = [];

foreach ($input['items'] as $name) {
    if (!is_string($name) || $name === '') continue;

    $check = $pathSec->checkItemOperable($basePath, $name, true);
    if ($check !== null) {
        $failed[] = ['name' => $name, 'error' => $check['error']];
        continue;
    }

    $itemPath = $pathSec->resolveItemIn($basePath, $name);

    if (!file_exists($itemPath) && !is_link($itemPath)) {
        $deleted++;
        continue;
    }

    try {
        $fileOps->deleteItem($itemPath);
        $deleted++;
    } catch (\Throwable $e) {
        $failed[] = ['name' => $name, 'error' => 'server_error'];
    }
}

Response::ok(['deleted' => $deleted, 'failed' => $failed]);
}
