<?php

namespace FileCarton;

/**
 * FileCarton Static Asset Server
 *
 * Serves built assets via ?fcres={path}.
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

fseek($fp, $base + $offset);
$data = fread($fp, $size);
fclose($fp);

$ext = strtolower(pathinfo($assetRelPath, PATHINFO_EXTENSION));
header('Content-Type: ' . MimeType::contentTypeForServing($ext));
header('Cache-Control: public, max-age=31536000, immutable');
header('Vary: Accept-Encoding');

$acceptGzip = str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip');
if ($acceptGzip) {
    ini_set('zlib.output_compression', 'Off');
    while (ob_get_level()) ob_end_clean();
    header('Content-Encoding: gzip');
    header('Content-Length: ' . $size);
    echo $data;
} else {
    $raw = gzdecode($data);
    header('Content-Length: ' . strlen($raw));
    echo $raw;
}
}
