<?php

namespace FileCarton;

/**
 * API: paste — Copy or move files/directories.
 * POST ?api=1&action=paste
 * Body: { mode: "copy"|"cut", sourcePath, items: string[], targetPath, overwrite: bool }
 *
 * Atomic semantics for overwrite=false:
 *   Phase 1: check all items for conflicts (no side effects)
 *   If conflicts exist → return immediately with completed=0
 *   If no conflicts → Phase 2: execute all items
 *
 * Copy to same directory auto-renames (e.g. "file - Copy.txt").
 * Returns { completed, conflicts, failed, renamed }.
 */

function api_paste(PathSecurity $pathSec, FileOps $fileOps): void {
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

    // Phase 1: When overwrite=false, scan ALL items for conflicts first (no execution).
    if (!$overwrite) {
        $conflicts = [];
        $failed = [];

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

            $srcNormalized = str_replace('\\', '/', $srcAbs);
            if ($srcNormalized === $dstAbs && $mode === 'copy') {
                continue;
            }

            if ($srcNormalized !== $dstAbs && file_exists($dstAbs)) {
                if (is_dir($srcAbs) && is_dir($dstAbs)) {
                    // Directory merge: report individual file conflicts
                    $dirConflicts = collect_merge_conflicts($srcAbs, $dstAbs, $itemBaseName);
                    foreach ($dirConflicts as $c) {
                        $conflicts[] = $c;
                    }
                } else {
                    $conflicts[] = $name;
                }
            }
        }

        if (!empty($conflicts)) {
            Response::ok([
                'completed' => 0,
                'conflicts' => $conflicts,
                'failed'    => $failed,
                'renamed'   => [],
            ]);
        }
        // No conflicts found — fall through to Phase 2 (execute all)
    }

    // Phase 2: Execute all items
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
            $newName = generate_copy_name($itemBaseName, $targetAbs);
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
                if (is_dir($srcAbs) && is_dir($dstAbs)) {
                    // Directory merge copy (copyRecursive already merges)
                    $fileOps->copyItem($srcAbs, $dstAbs);
                } else {
                    // For type mismatch (file→dir or dir→file), remove dest first
                    if (file_exists($dstAbs) && $overwrite) {
                        $fileOps->deleteItem($dstAbs);
                    }
                    $fileOps->copyItem($srcAbs, $dstAbs);
                }
            } else {
                if (is_dir($srcAbs) && is_dir($dstAbs)) {
                    $fileOps->mergeMove($srcAbs, $dstAbs);
                } else {
                    // For type mismatch (file→dir or dir→file), remove dest first
                    if (file_exists($dstAbs) && $overwrite && is_dir($dstAbs) !== is_dir($srcAbs)) {
                        $fileOps->deleteItem($dstAbs);
                    }
                    $fileOps->moveItem($srcAbs, $dstAbs);
                }
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
}

/**
 * Generate a copy name like "file - Copy.txt", "file - Copy (2).txt", etc.
 */
function generate_copy_name(string $originalName, string $dirAbs): string {
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

/**
 * Recursively collect file paths that would conflict when merging srcDir into dstDir.
 * Returns paths relative to targetPath (prefixed with the top-level item name).
 */
function collect_merge_conflicts(string $srcDir, string $dstDir, string $prefix): array {
    $conflicts = [];
    $srcDir = rtrim(str_replace('\\', '/', $srcDir), '/');
    $dstDir = rtrim(str_replace('\\', '/', $dstDir), '/');

    $iter = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($srcDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
    );

    $srcLen = strlen($srcDir) + 1;

    foreach ($iter as $item) {
        if ($item->isDir()) continue;

        $itemPath = str_replace('\\', '/', $item->getPathname());
        $relativePath = substr($itemPath, $srcLen);
        $dstPath = $dstDir . '/' . $relativePath;

        if (file_exists($dstPath)) {
            $conflicts[] = $prefix . '/' . $relativePath;
        }
    }

    return $conflicts;
}
