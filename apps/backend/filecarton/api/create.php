<?php

namespace FileCarton;

/**
 * API: create — Create a new file or directory.
 * POST ?api=1&action=create
 * Body: { path, name, type: "file"|"dir" }
 */

function api_create(PathSecurity $pathSec, FileOps $fileOps): void {
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['path'], $input['name'], $input['type'])) {
    Response::error('Missing required fields: path, name, type', 400);
}

$parentPath = $input['path'];
$name = $input['name'];
$type = $input['type'];

if ($type !== 'file' && $type !== 'dir') {
    Response::error('Type must be "file" or "dir"', 400);
}

if (!$pathSec->isValidFileName($name)) {
    Response::error('Invalid filename', 400);
}

$relativePath = ($parentPath === '' || $parentPath === '/') ? $name : rtrim($parentPath, '/') . '/' . $name;
$targetAbs = $pathSec->resolveParent($relativePath);

if (file_exists($targetAbs)) {
    Response::error('Name already exists', 409);
}

if ($type === 'dir') {
    $fileOps->createDir($targetAbs);
} else {
    $fileOps->createFile($targetAbs);
}

Response::ok(['created' => $relativePath]);
}
