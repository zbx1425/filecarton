<?php
/**
 * FileCarton Default Configuration
 *
 * All values use define-if-not-defined pattern, so they can be overridden
 * by defining the constants earlier (e.g. in conf/config.php).
 *
 * To customize, add the relevant define() calls to your conf/config.php
 * BEFORE the filecarton module is loaded.
 */

// --- Editor ---

// Maximum file size for text read/write API (bytes).
// Files larger than this cannot be opened in the editor.
if (!defined('FILECARTON_MAX_EDIT_SIZE')) {
    define('FILECARTON_MAX_EDIT_SIZE', 5 * 1024 * 1024); // 5 MB
}

// --- Archive ---

// Maximum number of files allowed in a single archive creation.
if (!defined('FILECARTON_ARCHIVE_MAX_FILES')) {
    define('FILECARTON_ARCHIVE_MAX_FILES', 10000);
}

// Maximum total (uncompressed) size for archive creation (bytes).
if (!defined('FILECARTON_ARCHIVE_MAX_SIZE')) {
    define('FILECARTON_ARCHIVE_MAX_SIZE', 2 * 1024 * 1024 * 1024); // 2 GB
}

// --- Upload ---

// Maximum directory nesting depth for folder upload relative paths.
if (!defined('FILECARTON_UPLOAD_MAX_DEPTH')) {
    define('FILECARTON_UPLOAD_MAX_DEPTH', 50);
}

// Chunk upload temporary directory expiry time (seconds).
// Incomplete uploads older than this are eligible for cleanup.
if (!defined('FILECARTON_CHUNK_EXPIRY')) {
    define('FILECARTON_CHUNK_EXPIRY', 86400); // 24 hours
}

// Probability of running cleanup on each chunk upload request (1 in N).
// Set to 1 to always clean up, higher values reduce overhead.
if (!defined('FILECARTON_CHUNK_CLEANUP_CHANCE')) {
    define('FILECARTON_CHUNK_CLEANUP_CHANCE', 10); // 1 in 10
}

// --- Search ---

// Default maximum number of search results returned.
if (!defined('FILECARTON_SEARCH_DEFAULT_LIMIT')) {
    define('FILECARTON_SEARCH_DEFAULT_LIMIT', 200);
}

// Hard cap on search results (cannot be exceeded even if client requests more).
if (!defined('FILECARTON_SEARCH_MAX_LIMIT')) {
    define('FILECARTON_SEARCH_MAX_LIMIT', 1000);
}

// --- Development ---

// Vite dev server URL. When defined and truthy, page.php loads assets from
// this server instead of the built manifest. Comment out for production.
// define('FILECARTON_DEV_SERVER', 'http://localhost:5173');

// --- Performance / Offload ---

// Nginx internal redirect prefix for static assets. When defined, asset_serve.php
// sends an X-Accel-Redirect header instead of reading the file in PHP.
// define('FILECARTON_NGINX_INTERNAL_PREFIX', '/internal/filecarton');
