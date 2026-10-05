<?php

namespace FileCarton;

/**
 * API: tree_node — Tree node children (lazy load).
 * GET ?fcapi=tree_node&path={dirPath}
 */

function api_tree_node(PathSecurity $pathSec, FileOps $fileOps): void {
$path = $_GET['path'] ?? '';
$absPath = $pathSec->resolve($path);

if (!is_dir($absPath)) {
    Response::error('Not a directory', 404);
}

$children = $fileOps->treeChildren($absPath);
Response::ok(['children' => $children]);
}
