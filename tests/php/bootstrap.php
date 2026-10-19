<?php

$backend = dirname(__DIR__, 2) . '/apps/backend/filecarton/lib';

require $backend . '/Polyfill.php';
require $backend . '/ApiException.php';
require $backend . '/Platform.php';
require $backend . '/RepoSetting.php';
require dirname(__DIR__, 2) . '/apps/backend/filecarton.config.php';
require $backend . '/PathSecurity.php';

