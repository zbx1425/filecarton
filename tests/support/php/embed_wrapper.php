<?php

/**
 * Reference embedder. state_repo is checked here, before FileCarton runs.
 * FileCarton then serves whatever root this wrapper defined.
 */

$repo = isset($_GET['state_repo']) && is_string($_GET['state_repo']) ? $_GET['state_repo'] : 'default';
if (!preg_match('/^[a-z0-9_-]{1,32}$/', $repo)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'bad state';
    return;
}

$base = getenv('FC_EMBED_BASE');
if (!is_string($base) || $base === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'FC_EMBED_BASE missing';
    return;
}

$root = rtrim(str_replace('\\', '/', $base), '/') . '/' . $repo;
if (!is_dir($root)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'missing repo';
    return;
}

$real = realpath($root);
$backend = getenv('FC_BACKEND');
if ($real === false || !is_string($backend) || !is_file($backend . '/filecarton.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'embed setup failed';
    return;
}

define('FILECARTON_EMBED', true);
define('FILECARTON_DEV_SERVER', 'http://127.0.0.1:9');
define('FILECARTON_REPO_SETTING', [
    'rootPath' => str_replace('\\', '/', $real),
    'readonly' => false,
    'branding' => 'FileCarton',
    'repoName' => $repo,
]);

require $backend . '/filecarton.php';
