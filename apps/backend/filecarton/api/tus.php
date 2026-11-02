<?php

namespace FileCarton;

/**
 * API: tus — TUS resumable upload protocol server.
 *
 * All requests arrive as POST. The logical TUS method is determined by the
 * X-HTTP-Method-Override header (clients send HEAD/PATCH/DELETE as POST).
 *
 * Supports: Core protocol, Creation, Creation-With-Upload, Termination.
 *
 * Routes:
 *   POST ?fcapi=tus                           → Create upload
 *   POST ?fcapi=tus&id={id}  Override=PATCH   → Upload data
 *   POST ?fcapi=tus&id={id}  Override=HEAD    → Query offset
 *   POST ?fcapi=tus&id={id}  Override=DELETE  → Terminate upload
 *   POST ?fcapi=tus          Override=OPTIONS  → Server capabilities
 */

function api_tus(PathSecurity $pathSec, FileOps $fileOps): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $logicalMethod = strtoupper(
        $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']
            ?? ($_SERVER['REQUEST_METHOD'] ?? 'POST')
    );
    $uploadId = $_GET['id'] ?? '';

    // Always send TUS headers
    header('Tus-Resumable: 1.0.0');

    switch ($logicalMethod) {
        case 'OPTIONS':
            tus_options();
            break;
        case 'POST':
            if ($uploadId !== '') {
                Response::error('invalid_input', 400, ['field' => 'id']);
            }
            tus_create($pathSec);
            break;
        case 'HEAD':
            tus_head($uploadId);
            break;
        case 'PATCH':
            tus_patch($pathSec, $uploadId);
            break;
        case 'DELETE':
            tus_delete($uploadId);
            break;
        default:
            Response::error('method_not_allowed', 405);
    }
}

// ------------------------------------------------------------------
// OPTIONS — Server capabilities
// ------------------------------------------------------------------

function tus_options(): void {
    header('Tus-Version: 1.0.0');
    header('Tus-Extension: creation,creation-with-upload,termination');
    header('Tus-Max-Size: ' . FILECARTON_UPLOAD_MAX_FILE_SIZE);
    http_response_code(204);
    exit;
}

// ------------------------------------------------------------------
// POST — Create upload (with optional creation-with-upload data)
// ------------------------------------------------------------------

function tus_create(PathSecurity $pathSec): void {
    $uploadLength = tus_parse_upload_length();
    $metadata = tus_parse_metadata();

    $fileName = $metadata['filename'] ?? '';
    $targetPath = $metadata['targetPath'] ?? '';

    if ($fileName === '') {
        Response::error('missing_fields', 400, ['fields' => 'filename metadata']);
    }

    if (!$pathSec->isValidFileName($fileName)) {
        Response::error('invalid_filename', 400);
    }

    $targetAbs = $pathSec->resolveOrCreate($targetPath);
    $finalPath = $targetAbs . '/' . $fileName;
    $pathSec->assertWithinRoot($finalPath);
    $pathSec->assertCanCreateAt($finalPath, false);

    if ($uploadLength > FILECARTON_UPLOAD_MAX_FILE_SIZE) {
        Response::error('upload.content_too_large', 413, [
            'maxMB' => round(FILECARTON_UPLOAD_MAX_FILE_SIZE / 1024 / 1024),
        ]);
    }

    // Read creation-with-upload body (may be empty)
    $body = file_get_contents('php://input');
    $bodyLen = strlen($body);
    if ($bodyLen > $uploadLength) {
        Response::error('upload.content_too_large', 413);
    }
    $offset = $bodyLen;

    // Small file / zero-byte optimization: if we got the entire file in the
    // creation request, write to a sibling temp and rename to the final path.
    // This avoids creating a TUS temp directory for complete uploads.
    if ($bodyLen === $uploadLength) {
        if (is_dir($finalPath)) {
            Response::error('upload.target_is_directory', 409);
        }
        $tempOutputPath = $finalPath . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tempOutputPath, $body) === false) {
            Response::error('server_error', 500);
        }
        if (!Platform::safePublish($tempOutputPath, $finalPath)) {
            @unlink($tempOutputPath);
            Response::error('server_error', 500);
        }

        $uploadId = bin2hex(random_bytes(16));
        header('Location: ' . tus_upload_url($uploadId));
        header('Upload-Offset: ' . $uploadLength);
        http_response_code(201);
        exit;
    }

    // Large file: create TUS temp directory
    $tempStorage = new TempStorage(RepoSetting::current()->resolvedTempDir());
    $uploadId = bin2hex(random_bytes(16));
    $tusDir = $tempStorage->createNamedDir('tus_' . $uploadId);

    $meta = [
        'uploadLength' => $uploadLength,
        'fileName'     => $sanitizedName,
        'targetPath'   => $targetPath,
        'targetAbs'    => $targetAbs,
        'finalPath'    => $finalPath,
        'offset'       => $offset,
        'created'      => time(),
    ];

    $dataPath = $tusDir . '/data';
    if ($bodyLen > 0) {
        if (@file_put_contents($dataPath, $body) === false) {
            Platform::deleteRecursive($tusDir);
            Response::error('server_error', 500);
        }
    } else {
        @touch($dataPath);
    }

    if (@file_put_contents($tusDir . '/meta.json', json_encode($meta)) === false) {
        Platform::deleteRecursive($tusDir);
        Response::error('server_error', 500);
    }

    $tempStorage->touchActivity($tusDir);

    header('Location: ' . tus_upload_url($uploadId));
    header('Upload-Offset: ' . $offset);
    http_response_code(201);
    exit;
}

// ------------------------------------------------------------------
// HEAD (via POST+Override) — Query current upload offset
// ------------------------------------------------------------------

function tus_head(string $uploadId): void {
    $tusDir = tus_resolve_dir($uploadId);
    $meta = tus_read_meta($tusDir);

    header('Upload-Offset: ' . $meta['offset']);
    header('Upload-Length: ' . $meta['uploadLength']);
    header('Cache-Control: no-store');
    http_response_code(200);
    exit;
}

// ------------------------------------------------------------------
// PATCH (via POST+Override) — Upload data at current offset
// ------------------------------------------------------------------

function tus_patch(PathSecurity $pathSec, string $uploadId): void {
    $tusDir = tus_resolve_dir($uploadId);
    $clientOffset = tus_parse_header_int('Upload-Offset');
    if ($clientOffset === null) {
        Response::error('missing_fields', 400, ['fields' => 'Upload-Offset header']);
    }

    $dataPath = $tusDir . '/data';
    $lockPath = $tusDir . '.lock';
    $lockFp = @fopen($lockPath, 'c');
    if ($lockFp === false) {
        Response::error('server_error', 500);
    }
    if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
        fclose($lockFp);
        Response::error('upload.merge_in_progress', 409);
    }

    try {
        // Re-read meta under lock to prevent race conditions
        $meta = tus_read_meta($tusDir);

        if ($clientOffset !== $meta['offset']) {
            http_response_code(409);
            header('Upload-Offset: ' . $meta['offset']);
            exit;
        }

        $remaining = $meta['uploadLength'] - $meta['offset'];

        $fp = @fopen($dataPath, 'c+b');
        if ($fp === false) {
            Response::error('server_error', 500);
        }
        fseek($fp, $meta['offset']);

        $input = fopen('php://input', 'rb');
        $written = 0;
        while (!feof($input)) {
            $chunk = fread($input, 65536);
            if ($chunk === false || $chunk === '') break;
            if ($written + strlen($chunk) > $remaining) {
                fclose($fp);
                fclose($input);
                Response::error('upload.content_too_large', 413);
            }
            $w = fwrite($fp, $chunk);
            if ($w === false) {
                fclose($fp);
                fclose($input);
                Response::error('server_error', 500);
            }
            $written += $w;
        }
        fclose($input);
        fflush($fp);
        fclose($fp);

        $newOffset = $meta['offset'] + $written;

        $tempStorage = new TempStorage(RepoSetting::current()->resolvedTempDir());
        $tempStorage->touchActivity($tusDir);

        // Check if upload is complete
        if ($newOffset === $meta['uploadLength']) {
            if (connection_aborted()) {
                // Client cancelled the upload
                $meta['offset'] = $newOffset;
                @file_put_contents($tusDir . '/meta.json', json_encode($meta));
                exit;
            }
            $finalPath = $meta['finalPath'];
            if (is_dir($finalPath)) {
                Response::error('upload.target_is_directory', 409);
            }
            if (!Platform::safePublish($dataPath, $finalPath)) {
                Response::error('server_error', 500);
            }
            // Update meta only after successful publish
            $meta['offset'] = $newOffset;
            @file_put_contents($tusDir . '/meta.json', json_encode($meta));

            flock($lockFp, LOCK_UN);
            fclose($lockFp);
            Platform::deleteRecursive($tusDir);
            @unlink($tusDir . '.lock');

            header('Upload-Offset: ' . $newOffset);
            http_response_code(204);
            exit;
        }

        // Partial upload — update meta
        $meta['offset'] = $newOffset;
        @file_put_contents($tusDir . '/meta.json', json_encode($meta));

        header('Upload-Offset: ' . $newOffset);
        http_response_code(204);
        exit;
    } finally {
        @flock($lockFp, LOCK_UN);
        @fclose($lockFp);
    }
}

// ------------------------------------------------------------------
// DELETE (via POST+Override) — Terminate / cancel upload
// ------------------------------------------------------------------

function tus_delete(string $uploadId): void {
    $tusDir = tus_resolve_dir($uploadId);

    $lockPath = $tusDir . '.lock';
    $lockFp = @fopen($lockPath, 'c');
    if ($lockFp !== false) {
        if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
            fclose($lockFp);
            Response::error('upload.in_progress', 409);
        }
        // Hold lock through deleteRecursive to prevent a PATCH from starting
        Platform::deleteRecursive($tusDir);
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        @unlink($lockPath);
    } else {
        Platform::deleteRecursive($tusDir);
    }

    http_response_code(204);
    exit;
}

// ------------------------------------------------------------------
// Helpers
// ------------------------------------------------------------------

function tus_upload_url(string $uploadId): string {
    $base = $_SERVER['SCRIPT_NAME'] . ($_SERVER['PATH_INFO'] ?? '');
    $passthrough = '';
    foreach ($_GET as $k => $v) {
        if ($k === 'fcapi' || $k === 'id') continue;
        $passthrough .= '&' . urlencode($k) . '=' . urlencode($v);
    }
    return $base . '?fcapi=tus&id=' . urlencode($uploadId) . $passthrough;
}

function tus_resolve_dir(string $uploadId): string {
    if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
        Response::error('upload.session_not_found', 404);
    }
    $tempStorage = new TempStorage(RepoSetting::current()->resolvedTempDir());
    $tusDir = $tempStorage->getBasePath() . '/tus_' . $uploadId;
    if (!is_dir($tusDir)) {
        Response::error('upload.session_not_found', 404);
    }
    return $tusDir;
}

function tus_read_meta(string $tusDir): array {
    $metaPath = $tusDir . '/meta.json';
    $raw = @file_get_contents($metaPath);
    if ($raw === false) {
        Response::error('upload.session_not_found', 404);
    }
    $meta = json_decode($raw, true);
    if (!is_array($meta) || !isset($meta['uploadLength'], $meta['offset'], $meta['finalPath'])) {
        Response::error('server_error', 500);
    }
    return $meta;
}

function tus_parse_upload_length(): int {
    $val = $_SERVER['HTTP_UPLOAD_LENGTH'] ?? null;
    if ($val === null || !ctype_digit($val)) {
        Response::error('missing_fields', 400, ['fields' => 'Upload-Length header']);
    }
    return (int)$val;
}

function tus_parse_header_int(string $headerName): ?int {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
    $val = $_SERVER[$key] ?? null;
    if ($val === null) return null;
    if (!ctype_digit($val)) return null;
    return (int)$val;
}

/**
 * Parse TUS Upload-Metadata header.
 * Format: key1 base64val1,key2 base64val2,...
 */
function tus_parse_metadata(): array {
    $raw = $_SERVER['HTTP_UPLOAD_METADATA'] ?? '';
    if ($raw === '') return [];

    $result = [];
    $pairs = explode(',', $raw);
    foreach ($pairs as $pair) {
        $pair = trim($pair);
        if ($pair === '') continue;
        $parts = explode(' ', $pair, 2);
        $key = $parts[0];
        $value = isset($parts[1]) ? base64_decode($parts[1], true) : '';
        if ($value === false) $value = '';
        $result[$key] = $value;
    }
    return $result;
}
