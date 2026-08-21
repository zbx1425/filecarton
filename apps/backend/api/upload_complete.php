<?php
/**
 * API: upload_complete — Merge chunks into final file.
 * POST ?api=1&action=upload_complete
 * Body: { uploadId, targetPath, fileName, totalChunks }
 *
 * Overwrites existing files silently.
 */

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['uploadId'], $input['targetPath'], $input['fileName'], $input['totalChunks'])) {
    Response::error('Missing required fields: uploadId, targetPath, fileName, totalChunks', 400);
}

$uploadId = $input['uploadId'];
$totalChunks = (int)$input['totalChunks'];
$fileName = $input['fileName'];

if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $uploadId)) {
    Response::error('Invalid uploadId format', 400);
}

$targetAbs = $pathSec->resolve($input['targetPath']);
if (!is_dir($targetAbs)) {
    Response::error('Target directory not found', 404);
}

$sanitizedName = $pathSec->sanitizeFileName($fileName);
$finalPath = $targetAbs . '/' . $sanitizedName;
$pathSec->assertWithinRoot($finalPath);

$tempDir = sys_get_temp_dir() . '/filecarton_chunks/' . $uploadId;
if (!is_dir($tempDir)) {
    Response::error('Upload session not found', 404);
}

for ($i = 0; $i < $totalChunks; $i++) {
    if (!is_file($tempDir . '/chunk_' . $i)) {
        Response::error('Missing chunk: ' . $i, 400);
    }
}

$outFile = fopen($finalPath, 'wb');
if ($outFile === false) {
    Response::error('Cannot create target file', 500);
}

for ($i = 0; $i < $totalChunks; $i++) {
    $chunkPath = $tempDir . '/chunk_' . $i;
    $chunkContent = file_get_contents($chunkPath);
    if ($chunkContent === false) {
        fclose($outFile);
        Response::error('Failed to read chunk: ' . $i, 500);
    }
    fwrite($outFile, $chunkContent);
}
fclose($outFile);

// Clean up temp directory
$chunkFiles = glob($tempDir . '/chunk_*');
if ($chunkFiles) {
    foreach ($chunkFiles as $f) {
        @unlink($f);
    }
}
@rmdir($tempDir);

Response::ok([
    'name' => $sanitizedName,
    'size' => filesize($finalPath),
]);
