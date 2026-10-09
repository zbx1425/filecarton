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

$basePath = $pathSec->resolve($input['path']);
$pathSec->assertNotIgnored($basePath);
if (!file_exists($basePath)) {
    Response::error('not_found.dir', 404);
}
if (!is_dir($basePath)) {
    Response::error('not_a_dir', 400);
}

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

    if ($pathSec->isIgnored($itemPath)) {
        $failed[] = ['name' => $name, 'error' => 'access_denied'];
        continue;
    }

    if (is_dir($itemPath) && $pathSec->hasProtectedDescendants($itemPath)) {
        $failed[] = ['name' => $name, 'error' => 'protected_items'];
        continue;
    }

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
