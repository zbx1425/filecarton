<?php
/**
 * API: upload_chunk — Receive a single chunk of a large file upload.
 * POST ?api=1&action=upload_chunk
 * Fields: uploadId, chunkIndex, totalChunks, chunk (file blob)
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

$tempDir = sys_get_temp_dir() . '/filecarton_chunks/' . $uploadId;
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0755, true);
}

$chunkFile = $tempDir . '/chunk_' . $chunkIndex;
if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkFile)) {
    Response::error('Failed to save chunk', 500);
}

Response::ok(['received' => $chunkIndex]);
