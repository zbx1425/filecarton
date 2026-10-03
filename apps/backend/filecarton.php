<?php
/**
 * FileCarton Router
 *
 * Self-contained entry point. Dispatches requests based on PATH_INFO.
 *
 * Required configuration (define before including this file):
 *   FILECARTON_ROOT_PATH       - absolute disk path for the managed directory
 *   FILECARTON_READONLY        - boolean
 *   FILECARTON_REPO_NAME       - display name
 *   FILECARTON_PATHINFO_OFFSET - int, segments to skip from PATH_INFO
 *   FILECARTON_ASSET_URL       - string, static asset base URL (empty = auto)
 */

define("FILECARTON_SCRIPT_DIR", __DIR__ . '/filecarton');
require_once __DIR__ . '/filecarton_config.php';

if (FILECARTON_ROOT_PATH === '') {
    http_response_code(500);
    echo 'FileCarton: FILECARTON_ROOT_PATH is not configured.';
    exit;
}

$pathInfo = $_SERVER['PATH_INFO'] ?? '';

// Special case: __fcres is always handled regardless of PATHINFO_OFFSET.
// This allows a single globally-cacheable asset URL even when subrouted.
if (str_starts_with($pathInfo, '/__fcres/')) {
    $assetRelPath = substr($pathInfo, strlen('/__fcres/'));
    require FILECARTON_SCRIPT_DIR . '/handler/asset.php';
    exit;
}

// Strip leading segments according to PATHINFO_OFFSET
$remainingPath = $pathInfo;
if (FILECARTON_PATHINFO_OFFSET > 0) {
    $segments = explode('/', ltrim($pathInfo, '/'));
    $remainingPath = '/' . implode('/', array_slice($segments, FILECARTON_PATHINFO_OFFSET));
    if ($remainingPath === '/') {
        $remainingPath = '/';
    }
}

// Route based on remaining path
if (str_starts_with($remainingPath, '/_res/')) {
    $assetRelPath = substr($remainingPath, 6); // strlen('/_res/') = 6
    require FILECARTON_SCRIPT_DIR . '/handler/asset.php';
} elseif (isset($_GET['api']) && $_GET['api'] === '1') {
    require FILECARTON_SCRIPT_DIR . '/handler/api.php';
} elseif ($remainingPath === '' || $remainingPath === '/' || $remainingPath === false) {
    // Ensure trailing slash for correct relative URL resolution
    $requestUri = $_SERVER['REQUEST_URI'];
    $hasTrailingSlash = false;
    $uriPath = parse_url($requestUri, PHP_URL_PATH) ?? '';
    if (str_ends_with($uriPath, '/')) {
        $hasTrailingSlash = true;
    }

    if (!$hasTrailingSlash) {
        if (!str_contains($requestUri, '?')) {
            $redirectUrl = $requestUri . '/';
        } else {
            $qpos = strpos($requestUri, '?');
            $redirectUrl = substr($requestUri, 0, $qpos) . '/' . substr($requestUri, $qpos);
        }
        header('Location: ' . $redirectUrl, true, 302);
        exit;
    }

    require FILECARTON_SCRIPT_DIR . '/handler/page.php';
} else {
    require FILECARTON_SCRIPT_DIR . '/handler/page.php';
}
