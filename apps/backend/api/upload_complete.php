<?php
/**
 * API: upload_complete — Merge chunks into final file.
 * POST ?api=1&action=upload_complete
 * Body: { uploadId, targetPath, fileName, totalChunks }
 *
 * Overwrites existing files silently. Uses file locking to prevent concurrent merges.
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

$targetAbs = $pathSec->resolveOrCreate($input['targetPath']);

$sanitizedName = $pathSec->sanitizeFileName($fileName);
$finalPath = $targetAbs . '/' . $sanitizedName;
$pathSec->assertWithinRoot($finalPath);

$tempDir = sys_get_temp_dir() . '/filecarton_chunks/' . $uploadId;
if (!is_dir($tempDir)) {
    Response::error('Upload session not found', 404);
}

$tempOutputPath = $finalPath . '.' . bin2hex(random_bytes(4)) . '.tmp';

$lockFile = $tempDir . '/.merge_lock';
$lockFp = fopen($lockFile, 'c');
if ($lockFp === false) {
    Response::error('Cannot acquire merge lock', 500);
}
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    fclose($lockFp);
    Response::error('Merge already in progress for this upload', 409);
}

try {
    $totalSize = 0;
    for ($i = 0; $i < $totalChunks; $i++) {
        $chunkPath = $tempDir . '/chunk_' . $i;
        if (!is_file($chunkPath)) {
            Response::error('Missing chunk: ' . $i, 400);
        }
        $totalSize += filesize($chunkPath);
    }

    if ($totalSize > FILECARTON_UPLOAD_MAX_FILE_SIZE) {
        Response::error('File too large (max ' . round(FILECARTON_UPLOAD_MAX_FILE_SIZE / 1024 / 1024) . ' MB)', 413);
    }

    $outFile = fopen($tempOutputPath, 'wb');
    if ($outFile === false) {
        Response::error('Cannot create target file', 500);
    }

    for ($i = 0; $i < $totalChunks; $i++) {
        $chunkPath = $tempDir . '/chunk_' . $i;
        $chunkFp = fopen($chunkPath, 'rb');
        if ($chunkFp === false) {
            fclose($outFile);
            @unlink($tempOutputPath);
            Response::error('Failed to read chunk: ' . $i, 500);
        }
        $chunkSize = filesize($chunkPath);
        $written = stream_copy_to_stream($chunkFp, $outFile);
        fclose($chunkFp);
        if ($written === false || ($chunkSize > 0 && $written !== $chunkSize)) {
            fclose($outFile);
            @unlink($tempOutputPath);
            Response::error('Failed to write chunk: ' . $i, 500);
        }
    }
    if (!fflush($outFile)) {
        fclose($outFile);
        @unlink($tempOutputPath);
        Response::error('Failed to flush output file', 500);
    }
    fclose($outFile);

    if (!rename($tempOutputPath, $finalPath)) {
        @unlink($tempOutputPath);
        Response::error('Failed to finalize uploaded file', 500);
    }

    $chunkFiles = glob($tempDir . '/chunk_*');
    if ($chunkFiles) {
        foreach ($chunkFiles as $f) {
            @unlink($f);
        }
    }
    @unlink($lockFile);
} finally {
    @unlink($tempOutputPath);
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}

@rmdir($tempDir);

Response::ok([
    'name' => $sanitizedName,
    'size' => filesize($finalPath),
]);
