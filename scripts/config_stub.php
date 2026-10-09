<?php

/**
 * IDE stub to make LSP happy that those constants are defined.
 */

if (false) {
define('FILECARTON_EMBED', false);
define('FILECARTON_STATIC_USER_LIST', [['id' => 'local']]);
define('FILECARTON_AUTH_PROVIDERS', [['class' => \FileCarton\NoLoginAuth::class]]);
define('FILECARTON_GRANT_RESOLVERS', [['class' => \FileCarton\StaticGrantResolver::class]]);
define('FILECARTON_PUBLIC_ORIGIN', '');
define('FILECARTON_SESSION_NAME', 'FILECARTON');

define('FILECARTON_REPO_SETTING', [
    'rootPath' => '',
    'readonly' => false,
    'branding' => '',
    'repoName' => '',
]);
define('FILECARTON_MUST_READONLY', false);
define('FILECARTON_MUST_EMBED', false);

define('FILECARTON_DOTFILES_BLOCK', false);
define('FILECARTON_DOTFILES_FORCE_VISIBLE', false);

define('FILECARTON_EXTENSIONS_ALLOWLIST', []);
define('FILECARTON_EXTENSIONS_BLOCKLIST', ['.php']);

define('FILECARTON_IGNORE_PATTERN', []);
define('FILECARTON_IGNORE_REALPATH', [
    __DIR__ . '/filecarton.php',
    __DIR__ . '/filecarton.config.php',
    __DIR__ . '/filecarton',
]);

define('FILECARTON_ASSET_URL', '');

define('FILECARTON_MAX_EDIT_SIZE', 5 * 1024 * 1024);

define('FILECARTON_ARCHIVE_MAX_FILES', 10000);
define('FILECARTON_ARCHIVE_MAX_SIZE', 2 * 1024 * 1024 * 1024);

define('FILECARTON_UPLOAD_MAX_FILE_SIZE', 10 * 1024 * 1024 * 1024);
define('FILECARTON_UPLOAD_CHUNK_SIZE', 5 * 1024 * 1024);
define('FILECARTON_UPLOAD_MAX_DEPTH', 50);
define('FILECARTON_CHUNK_EXPIRY', 86400);
define('FILECARTON_CHUNK_CLEANUP_CHANCE', 10);

define('FILECARTON_SEARCH_DEFAULT_LIMIT', 200);
define('FILECARTON_SEARCH_MAX_LIMIT', 1000);
define('FILECARTON_SEARCH_MAX_SCAN', 100000);

define('FILECARTON_SENDFILE', false);

define('FILECARTON_DEV_SERVER', false);
}
