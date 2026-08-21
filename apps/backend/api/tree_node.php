<?php
/**
 * API: tree_node — Tree node children (lazy load).
 * GET ?api=1&action=tree_node&path={dirPath}
 */

$path = $_GET['path'] ?? '';
$absPath = $pathSec->resolve($path);

if (!is_dir($absPath)) {
    Response::error('Not a directory', 404);
}

$children = $fileOps->treeChildren($absPath);
Response::ok(['children' => $children]);
