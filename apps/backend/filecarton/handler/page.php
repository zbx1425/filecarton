<?php

namespace FileCarton;

/**
 * FileCarton Page Renderer
 *
 * Outputs the HTML shell with injected config and Vite asset references.
 * Supports both Vite dev server mode and production manifest mode.
 */

function handle_page(): void {
require_once FILECARTON_SCRIPT_DIR . '/lib/Csrf.php';

$csrfToken = Csrf::getToken();
$repoName = FILECARTON_REPO_NAME;
$readonly = FILECARTON_READONLY ? true : false;
$branding = FILECARTON_BRANDING;

// Compute apiBase: SCRIPT_NAME + the consumed PATH_INFO prefix (offset segments)
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if (FILECARTON_PATHINFO_OFFSET > 0) {
    $segments = explode('/', ltrim($pathInfo, '/'));
    $prefix = implode('/', array_slice($segments, 0, FILECARTON_PATHINFO_OFFSET));
    $apiBase = $_SERVER['SCRIPT_NAME'] . '/' . $prefix;
} else {
    $apiBase = $_SERVER['SCRIPT_NAME'];
}

// Compute resBase for asset URLs
if (FILECARTON_ASSET_URL !== '') {
    $resBase = FILECARTON_ASSET_URL;
} else {
    $resBase = $_SERVER['SCRIPT_NAME'] . '/__fcres/';
}

$isDevMode = defined('FILECARTON_DEV_SERVER') && FILECARTON_DEV_SERVER;
$devServerUrl = $isDevMode ? rtrim(FILECARTON_DEV_SERVER, '/') : '';

$configData = [
    'apiBase'   => $apiBase,
    'csrfToken' => $csrfToken,
    'readonly'  => $readonly,
    'repoName'  => $repoName,
];
if ($branding !== '') {
    $configData['branding'] = $branding;
}
if (!$readonly) {
    $configData['upload'] = [
        'maxFileSize' => FILECARTON_UPLOAD_MAX_FILE_SIZE,
        'chunkSize'   => FILECARTON_UPLOAD_CHUNK_SIZE,
    ];
}
$configJson = json_encode($configData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

if (!$isDevMode) {
    if (defined('FILECARTON_SINGLE_FILE') && \FILECARTON_SINGLE_FILE) {
        $manifest = defined('FILECARTON_MANIFEST') ? \FILECARTON_MANIFEST : null;
        $cdn = defined('FILECARTON_CDN') ? \FILECARTON_CDN : null;
        $manifestError = ($manifest === null || !isset($manifest['src/main.ts']));
    } else {
        $manifestPath = FILECARTON_SCRIPT_DIR . '/public/.vite/manifest.json';
        $manifest = null;
        $manifestError = false;

        if (is_file($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true);
            if (!isset($manifest['src/main.ts'])) {
                $manifestError = true;
            }
        } else {
            $manifestError = true;
        }

        $cdnPath = FILECARTON_SCRIPT_DIR . '/public/cdn.json';
        $cdn = is_file($cdnPath) ? json_decode(file_get_contents($cdnPath), true) : null;
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FileCarton - <?= htmlspecialchars($repoName, ENT_QUOTES, 'UTF-8') ?></title>
<?php if ($isDevMode): ?>
<?php elseif (!$manifestError):
    if (!empty($cdn['stylesheets'])):
        foreach ($cdn['stylesheets'] as $href): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($href) ?>">
<?php       endforeach;
    endif;
    $entry = $manifest['src/main.ts'];
    if (!empty($entry['css'])):
        foreach ($entry['css'] as $cssFile): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($resBase . $cssFile) ?>">
<?php       endforeach;
    endif;
    if (!empty($entry['imports'])):
        foreach ($entry['imports'] as $importKey):
            if (isset($manifest[$importKey]['css'])):
                foreach ($manifest[$importKey]['css'] as $cssFile): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($resBase . $cssFile) ?>">
<?php               endforeach;
            endif;
        endforeach;
    endif;
endif; ?>
</head>
<body>
    <div id="app">
        <div id="app-splash" style="margin-left: 4em; margin-top: 4em; font-family: Arial, Helvetica, sans-serif;">
            <h1>FileCarton Loading</h1>
            <p>Please wait while FileCarton is being loaded.</p>
        </div>
    </div>
    <script>window.__FILECARTON__ = <?= $configJson ?>;</script>
<?php if ($isDevMode): ?>
    <script type="module" src="<?= htmlspecialchars($devServerUrl, ENT_QUOTES, 'UTF-8') ?>/@vite/client"></script>
    <script type="module" src="<?= htmlspecialchars($devServerUrl, ENT_QUOTES, 'UTF-8') ?>/src/main.ts"></script>
<?php elseif ($manifestError): ?>
    <div style="font-family:system-ui,sans-serif;max-width:480px;margin:80px auto;text-align:center;color:#555">
        <h2>Frontend Not Built</h2>
        <p>The Vite manifest was not found. Please build the frontend first:</p>
        <pre style="background:#f3f3f3;padding:12px;border-radius:6px;text-align:left">cd filecarton-frontend
pnpm install
pnpm run build</pre>
        <p>Or enable dev mode by defining <code>FILECARTON_DEV_SERVER</code> in your config.</p>
    </div>
<?php else:
    $entry = $manifest['src/main.ts'];
    if (!empty($cdn['importmap'])): ?>
    <script type="importmap"><?= json_encode($cdn['importmap'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
    <script type="module" src="<?= htmlspecialchars($resBase . $entry['file']) ?>"></script>
<?php endif; ?>
</body>
</html>
<?php
}
