<?php

/** @var PhpFuzzer\Config $config */

require dirname(__DIR__) . '/bootstrap.php';

$path = new FileCarton\PathSecurity(sys_get_temp_dir());
$config->setTarget(function (string $input) use ($path) {
    $out = $path->normalizePath($input);
    foreach (explode('/', $out) as $segment) {
        if ($segment === '.' || $segment === '..') {
            throw new Error('normalizePath kept a dot segment');
        }
    }
});
