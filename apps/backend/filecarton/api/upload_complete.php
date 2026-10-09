<?php

namespace FileCarton;

/**
 * API: upload_complete — Merge chunks into final file.
 * POST ?fcapi=upload_complete
 * Body: { uploadId, targetPath, fileName, totalChunks }
 *
 * Overwrites existing files silently. Uses file locking to prevent concurrent merges.
 */

function api_upload_complete(PathSecurity $pathSec, FileOps $fileOps): void {
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['uploadId'], $input['targetPath'], $input['fileName'], $input['totalChunks'])) {
    Response::error('missing_fields', 400, ['fields' => 'uploadId, targetPath, fileName, totalChunks']);
}

$uploadId = $input['uploadId'];
$totalChunks = (int)$input['totalChunks'];
$fileName = $input['fileName'];

if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $uploadId)) {
    Response::error('upload.invalid_id', 400);
}

$sanitizedName = $pathSec->sanitizeFileName($fileName);

$targetAbs = $pathSec->resolveOrCreate($input['targetPath']);

$finalPath = $targetAbs . '/' . $sanitizedName;
$pathSec->assertWithinRoot($finalPath);
$pathSec->assertCanCreateAt($finalPath, false);

$tempDir = sys_get_temp_dir() . '/filecarton_chunks/' . $uploadId;
if (!is_dir($tempDir)) {
    Response::error('upload.session_not_found', 404);
}

$tempOutputPath = $finalPath . '.' . bin2hex(random_bytes(4)) . '.tmp';

$lockFile = $tempDir . '/.merge_lock';
$lockFp = fopen($lockFile, 'c');
if ($lockFp === false) {
    Response::error('server_error', 500);
}
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    fclose($lockFp);
    Response::error('upload.merge_in_progress', 409);
}

try {
    $totalSize = 0;
    for ($i = 0; $i < $totalChunks; $i++) {
        $chunkPath = $tempDir . '/chunk_' . $i;
        if (!is_file($chunkPath)) {
            Response::error('upload.missing_chunk', 400, ['index' => $i]);
        }
        $totalSize += filesize($chunkPath);
    }

    if ($totalSize > FILECARTON_UPLOAD_MAX_FILE_SIZE) {
        Response::error('file_too_large', 413, ['maxMB' => round(FILECARTON_UPLOAD_MAX_FILE_SIZE / 1024 / 1024)]);
    }

    $outFile = fopen($tempOutputPath, 'wb');
    if ($outFile === false) {
        Response::error('server_error', 500);
    }

    for ($i = 0; $i < $totalChunks; $i++) {
        $chunkPath = $tempDir . '/chunk_' . $i;
        $chunkFp = fopen($chunkPath, 'rb');
        if ($chunkFp === false) {
            fclose($outFile);
            @unlink($tempOutputPath);
            Response::error('server_error', 500);
        }
        $chunkSize = filesize($chunkPath);
        $written = stream_copy_to_stream($chunkFp, $outFile);
        fclose($chunkFp);
        if ($written === false || ($chunkSize > 0 && $written !== $chunkSize)) {
            fclose($outFile);
            @unlink($tempOutputPath);
            Response::error('server_error', 500);
        }
    }
    if (!fflush($outFile)) {
        fclose($outFile);
        @unlink($tempOutputPath);
        Response::error('server_error', 500);
    }
    fclose($outFile);

    if (!rename($tempOutputPath, $finalPath)) {
        @unlink($tempOutputPath);
        Response::error('server_error', 500);
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
}
