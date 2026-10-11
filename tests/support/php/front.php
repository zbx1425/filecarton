<?php

/**
 * Front controller for the integration rig.
 *
 * php-cgi runs a fresh request each time, so define() in the scenario file
 * is evaluated again on every request. A worker is still pinned to one
 * scenario: FC_SCENARIO is process environment and is not changed in place.
 */

$mode = getenv('FC_FRONT');
if ($mode === 'embed') {
    require __DIR__ . '/embed_wrapper.php';
    return;
}

$scenario = getenv('FC_SCENARIO');
$backend = getenv('FC_BACKEND');

if (!is_string($scenario) || !is_file($scenario)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'FC_SCENARIO missing';
    return;
}
if (!is_string($backend) || !is_file($backend . '/filecarton.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'FC_BACKEND missing';
    return;
}

require $scenario;
require $backend . '/filecarton.php';
