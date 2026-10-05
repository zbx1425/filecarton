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
    Response::error('Not a directory', 404);
}

$children = $fileOps->treeChildren($absPath);
$children = $pathSec->filterEntries($children, $absPath);
Response::ok(['children' => $children]);
}
