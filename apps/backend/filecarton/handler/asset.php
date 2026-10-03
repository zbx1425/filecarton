<?php

namespace FileCarton;

/**
 * FileCarton Static Asset Server
 *
 * Maps /_res/{path} and /__fcres/{path} onto a built asset and serves it.
 */

function handle_asset(string $assetRelPath): void {
if ($assetRelPath === '') {
    http_response_code(400);
    exit;
}

if (defined('FILECARTON_SINGLE_FILE') && \FILECARTON_SINGLE_FILE) {
    serve_embedded_asset($assetRelPath);
    return;
}

$publicDir = FILECARTON_SCRIPT_DIR . '/public';
$targetPath = realpath($publicDir . '/' . $assetRelPath);

if ($targetPath === false || !is_file($targetPath)) {
    http_response_code(404);
    exit;
}

$normalizedPublic = str_replace('\\', '/', realpath($publicDir));
$normalizedTarget = str_replace('\\', '/', $targetPath);
if (!str_starts_with($normalizedTarget, $normalizedPublic . '/')) {
    http_response_code(403);
    exit;
}

$ext = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));

require_once FILECARTON_SCRIPT_DIR . '/lib/MimeType.php';
$contentType = MimeType::contentTypeForServing($ext);

header('Content-Type: ' . $contentType);
header('Content-Length: ' . filesize($targetPath));
header('Cache-Control: public, max-age=31536000, immutable');

require_once FILECARTON_SCRIPT_DIR . '/lib/Response.php';

if (Response::trySendfile($targetPath)) {
    exit;
}

readfile($targetPath);
}

function serve_embedded_asset(string $assetRelPath): void {
if (!defined('FILECARTON_ASSET_OFFSETS')) {
    http_response_code(500);
    exit;
}

$offsets = constant('FILECARTON_ASSET_OFFSETS');
$base = __COMPILER_HALT_OFFSET__;
$bundle = __FILE__;
$entry = is_array($offsets) ? ($offsets[$assetRelPath] ?? null) : null;
if (!is_array($entry) || count($entry) < 2) {
    http_response_code(404);
    exit;
}

[$offset, $size] = $entry;
if (!is_int($offset) || !is_int($size) || $offset < 0 || $size < 0) {
    http_response_code(500);
    exit;
}

$fp = fopen($bundle, 'rb');
if ($fp === false) {
    http_response_code(500);
    exit;
}

$start = $base + $offset;

$ext = strtolower(pathinfo($assetRelPath, PATHINFO_EXTENSION));
header('Content-Type: ' . MimeType::contentTypeForServing($ext));
header('Content-Length: ' . $size);
header('Cache-Control: public, max-age=31536000, immutable');

fseek($fp, $start);
echo fread($fp, $size);
fclose($fp);
}
