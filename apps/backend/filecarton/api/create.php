<?php

namespace FileCarton;

/**
 * API: create — Create a new file or directory.
 * POST ?fcapi=create
 * Body: { path, name, type: "file"|"dir" }
 */

function api_create(PathSecurity $pathSec, FileOps $fileOps): void {
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['path'], $input['name'], $input['type'])) {
    Response::error('missing_fields', 400, ['fields' => 'path, name, type']);
}

$parentPath = $input['path'];
$name = $input['name'];
$type = $input['type'];

if ($type !== 'file' && $type !== 'dir') {
    Response::error('create.invalid_type', 400);
}

if (!$pathSec->isValidFileName($name)) {
    Response::error('invalid_filename', 400);
}

if ($type === 'file' && $pathSec->isExtensionBlocked($name)) {
    Response::error('extension_blocked', 403);
}

$relativePath = ($parentPath === '' || $parentPath === '/') ? $name : rtrim($parentPath, '/') . '/' . $name;
$targetAbs = $pathSec->resolveParent($relativePath);
$pathSec->assertNotIgnored(dirname($targetAbs));

if ($pathSec->wouldBeIgnored($targetAbs, $type === 'dir')) {
    Response::error('access_denied', 403);
}

if (file_exists($targetAbs)) {
    Response::error('already_exists', 409);
}

if ($type === 'dir') {
    $fileOps->createDir($targetAbs);
} else {
    $fileOps->createFile($targetAbs);
}

Response::ok(['created' => $relativePath]);
}
