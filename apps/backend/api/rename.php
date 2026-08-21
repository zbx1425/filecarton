<?php
/**
 * API: rename — Rename a file or directory.
 * POST ?api=1&action=rename
 * Body: { path, oldName, newName }
 */

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['path'], $input['oldName'], $input['newName'])) {
    Response::error('Missing required fields: path, oldName, newName', 400);
}

$dirPath = $input['path'];
$oldName = $input['oldName'];
$newName = $input['newName'];

$dirAbs = $pathSec->resolve($dirPath);
if (!is_dir($dirAbs)) {
    Response::error('Directory not found', 404);
}

$sanitizedNew = $pathSec->sanitizeFileName($newName);

if ($oldName === $sanitizedNew) {
    $relativePath = ($dirPath === '' ? '' : $dirPath . '/') . $sanitizedNew;
    Response::ok(['renamed' => $relativePath]);
}

$oldAbs = $dirAbs . '/' . $pathSec->sanitizeFileName($oldName);
$newAbs = $dirAbs . '/' . $sanitizedNew;

$pathSec->assertWithinRoot($oldAbs);
$pathSec->assertWithinRoot($newAbs);

if (!file_exists($oldAbs)) {
    Response::error('Source not found', 404);
}

if (file_exists($newAbs)) {
    Response::error('Name already exists', 409);
}

$fileOps->moveItem($oldAbs, $newAbs);

$relativePath = ($dirPath === '' ? '' : $dirPath . '/') . $sanitizedNew;
Response::ok(['renamed' => $relativePath]);
