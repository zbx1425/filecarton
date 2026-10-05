<?php

namespace FileCarton;

/**
 * API: upload — Small file upload (multipart/form-data).
 * POST ?fcapi=upload
 * Fields: path (target directory), files[] (file array)
 * Optional: relativePaths[] (for folder upload, preserving subdirectory structure)
 *
 * Overwrites existing files silently.
 */

function api_upload(PathSecurity $pathSec, FileOps $fileOps): void {
$targetDir = $_POST['path'] ?? '';
$targetAbs = $pathSec->resolveOrCreate($targetDir);

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

    try {
        $sanitizedName = $pathSec->sanitizeFileName($name);

        if ($pathSec->isExtensionBlocked($sanitizedName)) {
            $failed[] = ['name' => $name, 'error' => 'File type is restricted'];
            continue;
        }

        $relPath = is_array($relativePaths) ? ($relativePaths[$i] ?? '') : '';

        if ($relPath !== '') {
            $relParts = explode('/', str_replace('\\', '/', $relPath));
            array_pop($relParts);

            if (!empty($relParts)) {
                $subRelPath = ($targetDir === '' ? '' : $targetDir . '/') . implode('/', $relParts);
                $subDir = $pathSec->resolveOrCreate($subRelPath);
                $destination = $subDir . '/' . $sanitizedName;
            } else {
                $destination = $targetAbs . '/' . $sanitizedName;
            }
        } else {
            $destination = $targetAbs . '/' . $sanitizedName;
        }

        $pathSec->assertWithinRoot($destination);

        if ($pathSec->wouldBeIgnored($destination, false)) {
            $failed[] = ['name' => $name, 'error' => 'Access denied'];
            continue;
        }

        if (!move_uploaded_file($tmpName, $destination)) {
            throw new \RuntimeException('move_uploaded_file failed');
        }
        $uploaded[] = ['name' => basename($destination), 'size' => $size];
    } catch (\Throwable $e) {
        $failed[] = ['name' => $name, 'error' => $e->getMessage()];
    }
}

Response::ok(['uploaded' => $uploaded, 'failed' => $failed]);
}
