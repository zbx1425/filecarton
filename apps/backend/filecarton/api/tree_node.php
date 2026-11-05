<?php

namespace FileCarton;

/**
 * API: tree_node — Tree node children (lazy load).
 * GET ?fcapi=tree_node&path={dirPath}
 */

function api_tree_node(PathSecurity $pathSec, FileOps $fileOps): void {
$path = api_query_string('path');
$absPath = $pathSec->resolveExistingDir($path);

$children = $fileOps->treeChildren($absPath);
$children = $pathSec->filterEntries($children, $absPath);

foreach ($children as &$child) {
    if ($child['type'] === 'dir') {
        $childAbs = $absPath . '/' . $child['name'];
        $sub = @scandir($childAbs);
        $hasVisible = false;
        if ($sub !== false) {
            foreach ($sub as $s) {
                if ($s === '.' || $s === '..') continue;
                if (!$pathSec->isIgnored($childAbs . '/' . $s)) {
                    $hasVisible = true;
                    break;
                }
            }
        }
        $child['hasChildren'] = $hasVisible;
    }
}
unset($child);

$tempDir = RepoSetting::current()->resolvedTempDir();
if ($tempDir !== '') {
    (new TempStorage($tempDir))->cleanup((int)FILECARTON_CHUNK_EXPIRY);
}

Response::ok(['children' => $children]);
}
