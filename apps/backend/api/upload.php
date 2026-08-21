<?php
/**
 * API: upload — Small file upload (multipart/form-data).
 * POST ?api=1&action=upload
 * Fields: path (target directory), files[] (file array)
 * Optional: relativePaths[] (for folder upload, preserving subdirectory structure)
 *
 * Overwrites existing files silently.
 */

$targetDir = $_POST['path'] ?? '';
$targetAbs = $pathSec->resolve($targetDir);

if (!is_dir($targetAbs)) {
    Response::error('Target directory not found', 404);
}

if (empty($_FILES['files'])) {
    Response::error('No files uploaded', 400);
}

$files = $_FILES['files'];
$relativePaths = $_POST['relativePaths'] ?? [];
$uploaded = [];
$failed = [];

$isMultiple = is_array($files['name']);
$count = $isMultiple ? count($files['name']) : 1;

for ($i = 0; $i < $count; $i++) {
    $name = $isMultiple ? $files['name'][$i] : $files['name'];
    $tmpName = $isMultiple ? $files['tmp_name'][$i] : $files['tmp_name'];
    $error = $isMultiple ? $files['error'][$i] : $files['error'];
    $size = $isMultiple ? $files['size'][$i] : $files['size'];

    if ($error !== UPLOAD_ERR_OK) {
        $failed[] = ['name' => $name, 'error' => 'Upload error code: ' . $error];
        continue;
    }

    $relPath = is_array($relativePaths) ? ($relativePaths[$i] ?? '') : '';

    if ($relPath !== '') {
        $relParts = explode('/', str_replace('\\', '/', $relPath));
        array_pop($relParts);
        if (!empty($relParts)) {
            $subDir = $targetAbs;
            foreach ($relParts as $part) {
                $part = $pathSec->sanitizeFileName($part);
                $subDir .= '/' . $part;
                if (!is_dir($subDir)) {
                    mkdir($subDir, 0755, true);
                }
            }
            $pathSec->assertWithinRoot($subDir);
            $destination = $subDir . '/' . $pathSec->sanitizeFileName($name);
        } else {
            $destination = $targetAbs . '/' . $pathSec->sanitizeFileName($name);
        }
    } else {
        $destination = $targetAbs . '/' . $pathSec->sanitizeFileName($name);
    }

    try {
        $pathSec->assertWithinRoot($destination);
        if (!move_uploaded_file($tmpName, $destination)) {
            throw new \RuntimeException('move_uploaded_file failed');
        }
        $uploaded[] = ['name' => basename($destination), 'size' => $size];
    } catch (\Throwable $e) {
        $failed[] = ['name' => $name, 'error' => $e->getMessage()];
    }
}

Response::ok(['uploaded' => $uploaded, 'failed' => $failed]);
