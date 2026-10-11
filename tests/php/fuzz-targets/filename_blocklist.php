<?php

/** @var PhpFuzzer\Config $config */

require dirname(__DIR__) . '/bootstrap.php';

$config->setTarget(function (string $input) {
    if (strlen($input) > 200) {
        return;
    }
    $list = new FileCarton\FilenameBlocklist(['*', '!*.txt', '*.php', '.htaccess']);
    $blocked = $list->isBlocked($input);
    if (strlen($input) >= 4 && substr(strtolower($input), -4) === '.txt' && $blocked) {
        throw new Error('allow rule lost for .txt');
    }
});
