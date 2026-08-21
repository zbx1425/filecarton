<?php
/**
 * FileCarton Router
 *
 * Dispatches requests based on the remaining PATH_INFO after the repoKey segment.
 *
 * Available from fm_bootstrap.php scope:
 *   $auCrntRepo  - current repo key (string)
 *   $auRepos     - all repos for this user (array)
 *   FM_ROOT_PATH - absolute disk path for the current pack
 *   FM_REPO_NAME - display name for the current pack
 *   FM_GLOBAL_READONLY - boolean
 */

require_once __DIR__ . '/config.php';

$pathInfo = $_SERVER['PATH_INFO'] ?? '';
$repoPrefix = '/' . $auCrntRepo;
$remainingPath = substr($pathInfo, strlen($repoPrefix));

if ($remainingPath === false) {
    $remainingPath = '';
}

if (str_starts_with($remainingPath, '/_res/')) {
    $assetRelPath = substr($remainingPath, 6);
    require __DIR__ . '/asset_serve.php';
} elseif (isset($_GET['api']) && $_GET['api'] === '1') {
    require __DIR__ . '/api_handler.php';
} elseif ($remainingPath === '' || $remainingPath === false) {
    $redirectUrl = $_SERVER['REQUEST_URI'];
    if (!str_contains($redirectUrl, '?')) {
        $redirectUrl .= '/';
    } else {
        $qpos = strpos($redirectUrl, '?');
        $redirectUrl = substr($redirectUrl, 0, $qpos) . '/' . substr($redirectUrl, $qpos);
    }
    header('Location: ' . $redirectUrl, true, 301);
    exit;
} else {
    require __DIR__ . '/page.php';
}
