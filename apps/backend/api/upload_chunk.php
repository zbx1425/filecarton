<?php
/**
 * API: upload_chunk — Receive a single chunk of a large file upload.
 * POST ?api=1&action=upload_chunk
 * Fields: uploadId, chunkIndex, totalChunks, chunk (file blob)
 *
 * Also performs opportunistic cleanup of expired temp directories (>24h).
 */

$uploadId = $_POST['uploadId'] ?? '';
$chunkIndex = (int)($_POST['chunkIndex'] ?? -1);
$totalChunks = (int)($_POST['totalChunks'] ?? 0);

if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $uploadId)) {
    Response::error('Invalid uploadId format', 400);
}

if ($chunkIndex < 0 || $totalChunks < 1 || $chunkIndex >= $totalChunks) {
    Response::error('Invalid chunkIndex or totalChunks', 400);
}

if (empty($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
    Response::error('Chunk upload failed', 400);
}

$chunksBase = sys_get_temp_dir() . '/filecarton_chunks';
$tempDir = $chunksBase . '/' . $uploadId;
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0755, true);
}

$chunkFile = $tempDir . '/chunk_' . $chunkIndex;
if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkFile)) {
    Response::error('Failed to save chunk', 500);
}

cleanupExpiredChunks($chunksBase);

Response::ok(['received' => $chunkIndex]);

function cleanupExpiredChunks(string $chunksBase): void {
    if (!is_dir($chunksBase)) return;
    if (random_int(1, FILECARTON_CHUNK_CLEANUP_CHANCE) > 1) return;

    $expiry = time() - FILECARTON_CHUNK_EXPIRY;
    $dirs = @scandir($chunksBase);
    if ($dirs === false) return;

    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') continue;
        $dirPath = $chunksBase . '/' . $dir;
        if (!is_dir($dirPath)) continue;

        $mtime = @filemtime($dirPath);
        if ($mtime !== false && $mtime < $expiry) {
            $files = @glob($dirPath . '/*');
            if ($files) {
                foreach ($files as $f) @unlink($f);
            }
            @rmdir($dirPath);
        }
    }
}
