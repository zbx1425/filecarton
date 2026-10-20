<?php

$backend = dirname(__DIR__, 2) . '/apps/backend/filecarton/lib';

require $backend . '/Polyfill.php';
require $backend . '/ApiException.php';
require $backend . '/Platform.php';
require $backend . '/Toml.php';
require $backend . '/Config.php';
require $backend . '/RepoSetting.php';
\FileCarton\Config::load(dirname(__DIR__, 2) . '/apps/backend/filecarton.config.toml.php');
require $backend . '/PathSecurity.php';
