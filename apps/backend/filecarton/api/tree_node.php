<?php

namespace FileCarton;

/**
 * API: tree_node — Tree node children (lazy load).
 * GET ?fcapi=tree_node&path={dirPath}
 */

function api_tree_node(PathSecurity $pathSec, FileOps $fileOps): void {
$path = $_GET['path'] ?? '';
$absPath = $pathSec->resolve($path);
$pathSec->assertNotIgnored($absPath);

if (!is_dir($absPath)) {
    Response::error('not_found.dir', 404);
}

$children = $fileOps->treeChildren($absPath);
$children = $pathSec->filterEntries($children, $absPath);

foreach ($children as &$child) {
    if ($child['type'] === 'dir' && $child['hasChildren']) {
        $childAbs = $absPath . '/' . $child['name'];
        $sub = @scandir($childAbs);
        if ($sub !== false) {
            $hasVisible = false;
            foreach ($sub as $s) {
                if ($s === '.' || $s === '..') continue;
                if (!$pathSec->isIgnored($childAbs . '/' . $s)) {
                    $hasVisible = true;
                    break;
                }
            }
            $child['hasChildren'] = $hasVisible;
        }
    }
}
unset($child);

Response::ok(['children' => $children]);
}
