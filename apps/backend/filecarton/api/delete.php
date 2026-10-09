<?php

namespace FileCarton;

/**
 * API: delete — Delete files/directories (batch, partial success).
 * POST ?fcapi=delete
 * Body: { path, items: string[] }
 *
 * Missing names and ignore-rule names are counted as deleted and not unlinked.
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

    try {
        $itemPath = $pathSec->resolveItemIn($basePath, $name);
    } catch (\Throwable $e) {
        $failed[] = ['name' => $name, 'error' => 'invalid_filename'];
        continue;
    }

    if ($pathSec->isIgnored($itemPath) || (!file_exists($itemPath) && !is_link($itemPath))) {
        $deleted++;
        continue;
    }

    if (is_dir($itemPath) && $pathSec->hasProtectedDescendants($itemPath)) {
        $failed[] = ['name' => $name, 'error' => 'protected_items'];
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
