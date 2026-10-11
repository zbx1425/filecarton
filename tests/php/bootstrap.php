<?php

$backend = dirname(__DIR__, 2) . '/apps/backend/filecarton/lib';

require $backend . '/Polyfill.php';
require $backend . '/ApiException.php';
require $backend . '/PathSecurity.php';

if (!defined('FILECARTON_DOTFILES_BLOCK')) {
    define('FILECARTON_DOTFILES_BLOCK', false);
}
if (!defined('FILECARTON_FILENAME_BLOCKLIST')) {
    define('FILECARTON_FILENAME_BLOCKLIST', ['*.php', '.htaccess', '.user.ini', 'web.config']);
}
if (!defined('FILECARTON_UPLOAD_MAX_DEPTH')) {
    define('FILECARTON_UPLOAD_MAX_DEPTH', 50);
}
