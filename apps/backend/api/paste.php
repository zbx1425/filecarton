<?php
/**
 * API: paste — Copy or move files/directories (batch, partial success).
 * POST ?api=1&action=paste
 * Body: { mode: "copy"|"cut", sourcePath, items: string[], targetPath, overwrite: bool }
 *
 * Self-reference check: prevents moving a directory into itself.
 * Returns { completed, conflicts, failed }.
 */

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['mode'], $input['sourcePath'], $input['items'], $input['targetPath'])) {
    Response::error('Missing required fields: mode, sourcePath, items, targetPath', 400);
}

$mode = $input['mode'];
$overwrite = !empty($input['overwrite']);

if ($mode !== 'copy' && $mode !== 'cut') {
    Response::error('Mode must be "copy" or "cut"', 400);
}

if (!is_array($input['items']) || empty($input['items'])) {
    Response::error('Items array is empty', 400);
}

$sourceAbs = $pathSec->resolve($input['sourcePath']);
$targetAbs = $pathSec->resolve($input['targetPath']);

if (!is_dir($sourceAbs)) {
    Response::error('Source directory not found', 404);
}
if (!is_dir($targetAbs)) {
    Response::error('Target directory not found', 404);
}

$normalizedTarget = str_replace('\\', '/', $targetAbs);
$completed = 0;
$conflicts = [];
$failed = [];

foreach ($input['items'] as $name) {
    if (!is_string($name) || $name === '') continue;

    $sanitized = $pathSec->sanitizeFileName($name);
    $srcAbs = $sourceAbs . '/' . $sanitized;
    $dstAbs = $targetAbs . '/' . $sanitized;

    try {
        $pathSec->assertWithinRoot($srcAbs);
        $pathSec->assertWithinRoot($dstAbs);
    } catch (\Throwable $e) {
        $failed[] = ['name' => $name, 'error' => 'Invalid path'];
        continue;
    }

    if (!file_exists($srcAbs)) {
        $failed[] = ['name' => $name, 'error' => 'Source not found'];
        continue;
    }

    if ($mode === 'cut' && is_dir($srcAbs)) {
        $realSrc = str_replace('\\', '/', realpath($srcAbs) ?: $srcAbs);
        if ($normalizedTarget === $realSrc || str_starts_with($normalizedTarget . '/', $realSrc . '/')) {
            $failed[] = ['name' => $name, 'error' => 'Cannot move directory into itself'];
            continue;
        }
    }

    if (file_exists($dstAbs) && !$overwrite) {
        $conflicts[] = $name;
        continue;
    }

    try {
        if ($mode === 'copy') {
            $fileOps->copyItem($srcAbs, $dstAbs);
        } else {
            $fileOps->moveItem($srcAbs, $dstAbs);
        }
        $completed++;
    } catch (\Throwable $e) {
        $failed[] = ['name' => $name, 'error' => $e->getMessage()];
    }
}

Response::ok([
    'completed' => $completed,
    'conflicts' => $conflicts,
    'failed'    => $failed,
]);
