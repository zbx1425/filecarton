<?php
/**
 * API: paste — Copy or move files/directories (batch, partial success).
 * POST ?api=1&action=paste
 * Body: { mode: "copy"|"cut", sourcePath, items: string[], targetPath, overwrite: bool }
 *
 * Self-reference checks for both copy and cut modes.
 * Copy to same directory auto-renames (e.g. "file - Copy.txt").
 * Returns { completed, conflicts, failed, renamed }.
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

$normalizedSource = str_replace('\\', '/', $sourceAbs);
$normalizedTarget = str_replace('\\', '/', $targetAbs);
$completed = 0;
$conflicts = [];
$failed = [];
$renamed = [];

foreach ($input['items'] as $name) {
    if (!is_string($name) || $name === '') continue;

    try {
        $srcAbs = $pathSec->resolveItemIn($sourceAbs, $name);
    } catch (\Throwable $e) {
        $failed[] = ['name' => $name, 'error' => 'Invalid item name'];
        continue;
    }

    if (!file_exists($srcAbs) && !is_link($srcAbs)) {
        $failed[] = ['name' => $name, 'error' => 'Source not found'];
        continue;
    }

    $itemBaseName = basename($srcAbs);
    $dstAbs = $normalizedTarget . '/' . $itemBaseName;

    try {
        $pathSec->assertWithinRoot($dstAbs);
    } catch (\Throwable $e) {
        $failed[] = ['name' => $name, 'error' => 'Invalid target path'];
        continue;
    }

    if (is_dir($srcAbs)) {
        $realSrc = str_replace('\\', '/', realpath($srcAbs) ?: $srcAbs);
        if ($normalizedTarget === $realSrc || str_starts_with($normalizedTarget . '/', $realSrc . '/')) {
            $failed[] = ['name' => $name, 'error' => 'Cannot ' . ($mode === 'copy' ? 'copy' : 'move') . ' directory into itself'];
            continue;
        }
    }

    $srcNormalized = str_replace('\\', '/', $srcAbs);
    if ($srcNormalized === $dstAbs) {
        if ($mode === 'cut') {
            $completed++;
            continue;
        }
        $newName = generateCopyName($itemBaseName, $targetAbs);
        $dstAbs = $normalizedTarget . '/' . $newName;
        $renamed[] = ['original' => $itemBaseName, 'newName' => $newName];
    } elseif (file_exists($dstAbs)) {
        if (!$overwrite) {
            $conflicts[] = $name;
            continue;
        }
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
    'renamed'   => $renamed,
]);

/**
 * Generate a copy name like "file - Copy.txt", "file - Copy (2).txt", etc.
 */
function generateCopyName(string $originalName, string $dirAbs): string {
    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    $stem = $ext !== '' ? substr($originalName, 0, -(strlen($ext) + 1)) : $originalName;

    $candidate = $ext !== '' ? "$stem - Copy.$ext" : "$stem - Copy";
    if (!file_exists($dirAbs . '/' . $candidate)) {
        return $candidate;
    }

    for ($i = 2; $i <= 100; $i++) {
        $candidate = $ext !== '' ? "$stem - Copy ($i).$ext" : "$stem - Copy ($i)";
        if (!file_exists($dirAbs . '/' . $candidate)) {
            return $candidate;
        }
    }

    $unique = substr(bin2hex(random_bytes(4)), 0, 8);
    return $ext !== '' ? "$stem - Copy ($unique).$ext" : "$stem - Copy ($unique)";
}
