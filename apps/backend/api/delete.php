<?php
/**
 * API: delete — Delete files/directories (batch, partial success).
 * POST ?api=1&action=delete
 * Body: { path, items: string[] }
 *
 * Items that don't exist are silently counted as deleted.
 * Items that fail are added to the `failed` array.
 */

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['path'], $input['items']) || !is_array($input['items'])) {
    Response::error('Missing required fields: path, items', 400);
}

$basePath = $pathSec->resolve($input['path']);
if (!is_dir($basePath)) {
    Response::error('Not a directory', 404);
}

$deleted = 0;
$failed = [];

foreach ($input['items'] as $name) {
    if (!is_string($name) || $name === '') continue;

    $sanitized = $pathSec->sanitizeFileName($name);
    $itemPath = $basePath . '/' . $sanitized;

    try {
        $pathSec->assertWithinRoot($itemPath);
    } catch (\Throwable $e) {
        $failed[] = ['name' => $name, 'error' => 'Invalid path'];
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
        $failed[] = ['name' => $name, 'error' => $e->getMessage()];
    }
}

Response::ok(['deleted' => $deleted, 'failed' => $failed]);
