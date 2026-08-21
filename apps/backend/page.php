<?php
/**
 * FileCarton Page Renderer
 *
 * Outputs the HTML shell with injected config and Vite asset references.
 * Supports both Vite dev server mode and production manifest mode.
 */

require_once __DIR__ . '/lib/Csrf.php';

$csrfToken = Csrf::getToken();
$apiBase = $_SERVER['SCRIPT_NAME'] . '/' . $auCrntRepo;
$repoName = FM_REPO_NAME;
$readonly = FM_GLOBAL_READONLY ? true : false;
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
$resBase = $_SERVER['SCRIPT_NAME'] . $pathInfo . '_res/';

$isDevMode = defined('FILECARTON_DEV_SERVER') && FILECARTON_DEV_SERVER;
$devServerUrl = $isDevMode ? rtrim(FILECARTON_DEV_SERVER, '/') : '';

$configJson = json_encode([
    'apiBase'   => $apiBase,
    'csrfToken' => $csrfToken,
    'readonly'  => $readonly,
    'repoName'  => $repoName,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if (!$isDevMode) {
    $manifestPath = __DIR__ . '/public/.vite/manifest.json';
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
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FileCarton - <?= htmlspecialchars($repoName, ENT_QUOTES, 'UTF-8') ?></title>
<?php if ($isDevMode): ?>
<?php elseif (!$manifestError):
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
    <div id="app"></div>
    <script>window.__FILECARTON__ = <?= $configJson ?>;</script>
<?php if ($isDevMode): ?>
    <script type="module" src="<?= $devServerUrl ?>/@vite/client"></script>
    <script type="module" src="<?= $devServerUrl ?>/src/main.ts"></script>
<?php elseif ($manifestError): ?>
    <div style="font-family:system-ui,sans-serif;max-width:480px;margin:80px auto;text-align:center;color:#555">
        <h2>Frontend Not Built</h2>
        <p>The Vite manifest was not found. Please build the frontend first:</p>
        <pre style="background:#f3f3f3;padding:12px;border-radius:6px;text-align:left">cd filecarton-frontend
pnpm install
pnpm run build</pre>
        <p>Or enable dev mode by defining <code>FILECARTON_DEV_SERVER</code> in <code>conf/config.php</code>.</p>
    </div>
<?php else:
    $entry = $manifest['src/main.ts']; ?>
    <script type="module" src="<?= htmlspecialchars($resBase . $entry['file']) ?>"></script>
<?php endif; ?>
</body>
</html>
