<?php

namespace FileCarton;

/**
 * API: upload_chunk — Receive a single chunk of a large file upload.
 * POST ?api=1&action=upload_chunk
 * Fields: uploadId, chunkIndex, totalChunks, chunk (file blob)
 *
 * Also performs opportunistic cleanup of expired temp directories (>24h).
 */

function api_upload_chunk(PathSecurity $pathSec, FileOps $fileOps): void {
    $uploadId = $_POST['uploadId'] ?? '';
    $chunkIndex = (int)($_POST['chunkIndex'] ?? -1);
    $totalChunks = (int)($_POST['totalChunks'] ?? 0);

    if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $uploadId)) {
        Response::error('Invalid uploadId format', 400);
    }

    if ($chunkIndex < 0 || $totalChunks < 1 || $chunkIndex >= $totalChunks) {
        Response::error('Invalid chunkIndex or totalChunks', 400);
    }

    if ($totalChunks > FILECARTON_UPLOAD_MAX_CHUNKS) {
        Response::error('Too many chunks (max: ' . FILECARTON_UPLOAD_MAX_CHUNKS . ')', 400);
    }

    $phpMaxUpload = parse_php_size(ini_get('upload_max_filesize') ?: '0');
    $phpMaxPost = parse_php_size(ini_get('post_max_size') ?: '0');
    $effectivePhpLimit = ($phpMaxPost > 0) ? min($phpMaxUpload, $phpMaxPost) : $phpMaxUpload;
    if ($effectivePhpLimit > 0 && FILECARTON_UPLOAD_CHUNK_SIZE > $effectivePhpLimit) {
        Response::error(
            'Server misconfiguration: FILECARTON_UPLOAD_CHUNK_SIZE (' .
            round(FILECARTON_UPLOAD_CHUNK_SIZE / 1024 / 1024, 1) . ' MB) exceeds PHP upload limit (' .
            round($effectivePhpLimit / 1024 / 1024, 1) . ' MB). Adjust php.ini or FileCarton config.',
            500
        );
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

    cleanup_expired_chunks($chunksBase);

    Response::ok(['received' => $chunkIndex]);
}

function parse_php_size(string $size): int {
    $size = trim($size);
    if ($size === '' || $size === '0') return 0;
    $unit = strtolower(substr($size, -1));
    $value = (int)$size;
    return match ($unit) {
        'g' => $value * 1024 * 1024 * 1024,
        'm' => $value * 1024 * 1024,
        'k' => $value * 1024,
        default => $value,
    };
}

function cleanup_expired_chunks(string $chunksBase): void {
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
