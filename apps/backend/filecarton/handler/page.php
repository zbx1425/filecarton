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

$apiBase = entry_url();

$assetUrl = function (string $file): string {
    if (FILECARTON_ASSET_URL !== '') {
        return FILECARTON_ASSET_URL . '/' . $file;
    }
    return script_url() . '?' . http_build_query(['fcres' => $file]);
};

$isDevMode = defined('FILECARTON_DEV_SERVER') && FILECARTON_DEV_SERVER;
$devServerUrl = $isDevMode ? rtrim(FILECARTON_DEV_SERVER, '/') : '';

$configData = [
    'apiBase'   => $apiBase,
    'csrfToken' => $csrfToken,
    'readonly'  => $readonly,
];
if ($repoName !== '') {
    $configData['repoName'] = $repoName;
}
if ($branding !== '') {
    $configData['branding'] = $branding;
}
if (!$readonly) {
    $configData['upload'] = [
        'maxFileSize' => FILECARTON_UPLOAD_MAX_FILE_SIZE,
        'chunkSize'   => FILECARTON_UPLOAD_CHUNK_SIZE,
    ];
}
$configData['dotfiles'] = [
    'block'        => (bool)FILECARTON_DOTFILES_BLOCK,
    'forceVisible' => (bool)FILECARTON_DOTFILES_FORCE_VISIBLE,
];
$configData['extensions'] = [
    'allowlist' => FILECARTON_EXTENSIONS_ALLOWLIST,
    'blocklist' => FILECARTON_EXTENSIONS_BLOCKLIST,
];
$configJson = json_encode($configData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

$entry = null;
$cdn = null;
$manifestError = true;

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

    if (!$manifestError) {
        $entry = $manifest['src/main.ts'];
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $repoName !== '' ? 'FileCarton - ' . htmlspecialchars($repoName, ENT_QUOTES, 'UTF-8') : 'FileCarton' ?></title>
<?php if ($isDevMode): ?>
<?php elseif (!$manifestError):
    if (!empty($cdn['stylesheets'])):
        foreach ($cdn['stylesheets'] as $href): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($href) ?>">
<?php       endforeach;
    endif;
    if (!empty($entry['css'])):
        foreach ($entry['css'] as $cssFile): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetUrl($cssFile)) ?>">
<?php       endforeach;
    endif;
    if (!empty($entry['imports'])):
        foreach ($entry['imports'] as $importKey):
            if (isset($manifest[$importKey]['css'])):
                foreach ($manifest[$importKey]['css'] as $cssFile): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetUrl($cssFile)) ?>">
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
    <p>Vite manifest was not found! Is it because the frontend was not built, because you did not copy the .vite directory, or because you did not set FILECARTON_DEV_SERVER?</p>
<?php else:
    if (!empty($cdn['importmap'])): ?>
    <script type="importmap"><?= json_encode($cdn['importmap'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
    <script type="module" src="<?= htmlspecialchars($assetUrl($entry['file'])) ?>"></script>
<?php endif; ?>
</body>
</html>
<?php
}
