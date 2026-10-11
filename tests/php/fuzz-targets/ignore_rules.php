<?php

/** @var PhpFuzzer\Config $config */

require dirname(__DIR__) . '/bootstrap.php';

$root = str_replace('\\', '/', sys_get_temp_dir());
$config->setTarget(function (string $input) use ($root) {
    if (strlen($input) > 120 || str_contains($input, "\0")) {
        return;
    }
    $rules = new FileCarton\IgnoreRules($root, true);
    $rules->addPattern($input);
    $rules->isIgnored($root . '/probe.txt');
    $rules->wouldBeIgnored($root . '/probe.txt', false);
});
