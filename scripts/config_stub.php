<?php

/**
 * IDE stub to make LSP happy that those constants are defined.
 */

if (false) {
define('FILECARTON_ROOT_PATH', '');
define('FILECARTON_READONLY', false);
define('FILECARTON_REPO_NAME', 'Files');
define('FILECARTON_BRANDING', '');

define('FILECARTON_DOTFILES_BLOCK', false);
define('FILECARTON_DOTFILES_FORCE_VISIBLE', false);

define('FILECARTON_EXTENSIONS_ALLOWLIST', []);
// Restrict creation/upload/rename-to/save. Existing files remain readable/downloadable.
// Folder copy/move containing such files is generally not prevented.
define('FILECARTON_EXTENSIONS_BLOCKLIST', ['.php']);

// Patterns with ** or unanchored patterns require filesystem scanning for folder operations.
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
define('FILECARTON_UPLOAD_MAX_CHUNKS', 10000);
define('FILECARTON_UPLOAD_MAX_DEPTH', 50);
define('FILECARTON_CHUNK_EXPIRY', 86400);
define('FILECARTON_CHUNK_CLEANUP_CHANCE', 10);

define('FILECARTON_SEARCH_DEFAULT_LIMIT', 200);
define('FILECARTON_SEARCH_MAX_LIMIT', 1000);
define('FILECARTON_SEARCH_MAX_SCAN', 100000);

define('FILECARTON_SENDFILE', false);

define('FILECARTON_DEV_SERVER', false);
}
