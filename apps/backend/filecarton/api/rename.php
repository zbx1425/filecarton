<?php

namespace FileCarton;

/**
 * API: rename — Rename a file or directory.
 * POST ?api=1&action=rename
 * Body: { path, oldName, newName }
 */

function api_rename(PathSecurity $pathSec, FileOps $fileOps): void {
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['path'], $input['oldName'], $input['newName'])) {
    Response::error('Missing required fields: path, oldName, newName', 400);
}

$dirPath = $input['path'];
$oldName = $input['oldName'];
$newName = $input['newName'];

if (!$pathSec->isValidFileName($newName)) {
    Response::error('Invalid new filename', 400);
}

$dirAbs = $pathSec->resolve($dirPath);
if (!is_dir($dirAbs)) {
    Response::error('Directory not found', 404);
}

$oldAbs = $pathSec->resolveItemIn($dirAbs, $oldName);

if (!file_exists($oldAbs)) {
    Response::error('Source not found', 404);
}

$newRelPath = ($dirPath === '' || $dirPath === '/') ? $newName : rtrim($dirPath, '/') . '/' . $newName;
$newAbs = $pathSec->resolveParent($newRelPath);

if (basename($oldAbs) === $newName) {
    Response::ok(['renamed' => $newRelPath]);
}

if (file_exists($newAbs)) {
    $oldReal = realpath($oldAbs);
    $newReal = realpath($newAbs);
    if ($oldReal === false || $newReal === false || $oldReal !== $newReal) {
        Response::error('Name already exists', 409);
    }
}

$fileOps->moveItem($oldAbs, $newAbs);

Response::ok(['renamed' => $newRelPath]);
}
