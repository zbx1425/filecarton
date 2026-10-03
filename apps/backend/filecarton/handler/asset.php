<?php

namespace FileCarton;

/**
 * FileCarton Static Asset Server
 *
 * Maps /_res/{path} to inc/filecarton/public/{path} and serves with correct headers.
 * $assetRelPath is passed in by the router.
 */

function handle_asset(string $assetRelPath): void {
if (!isset($assetRelPath) || $assetRelPath === '') {
    http_response_code(400);
    exit;
}

if (str_contains($assetRelPath, '..')) {
    http_response_code(403);
    exit;
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
