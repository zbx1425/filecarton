<?php
/**
 * API: create — Create a new file or directory.
 * POST ?api=1&action=create
 * Body: { path, name, type: "file"|"dir" }
 */

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

$parentAbs = $pathSec->resolve($parentPath);
if (!is_dir($parentAbs)) {
    Response::error('Parent directory not found', 404);
}

$sanitized = $pathSec->sanitizeFileName($name);
$targetAbs = $parentAbs . '/' . $sanitized;
$pathSec->assertWithinRoot($targetAbs);

if (file_exists($targetAbs)) {
    Response::error('Name already exists', 409);
}

if ($type === 'dir') {
    $fileOps->createDir($targetAbs);
} else {
    $fileOps->createFile($targetAbs);
}

$relativePath = ($parentPath === '' ? '' : $parentPath . '/') . $sanitized;
Response::ok(['created' => $relativePath]);
