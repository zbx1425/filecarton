<?php
/**
 * FileCarton Configuration
 */

// --- Core ---

// Absolute filesystem path to the managed root directory.
define_default('FILECARTON_ROOT_PATH', '');

// Read-only mode: when true, all write APIs return 403.
define_default('FILECARTON_READONLY', false);

// Display name shown in the page title and UI.
define_default('FILECARTON_REPO_NAME', 'Files');

// Brand name displayed in the top bar (passed to frontend as branding).
define_default('FILECARTON_BRANDING', 'FileCarton');


// --- Routing ---
// Number of leading PATH_INFO segments to skip (consumed by parent router).
// E.g. set to 1 when mounted under fm_bootstrap.php/{repoKey}/.
define_default('FILECARTON_PATHINFO_OFFSET', 0);

// Base URL for static assets. When empty, assets are served from '.../<entry_point>.php/__fcres/'.
define_default('FILECARTON_ASSET_URL', '');


// --- Editor ---
// Maximum file size for text read/write API (bytes).
// Should be less than PHP's upload_max_filesize and post_max_size,
// and your web server (e.g. Nginx)'s body size limit.
// NOTE: It's likely that you need to edit those configurations,
// to either increase them, or decrease FILECARTON_UPLOAD_CHUNK_SIZE,
// as the values in PHP and Nginx's default configurations are quite small!
define_default('FILECARTON_MAX_EDIT_SIZE', 4 * 1024 * 1024); // 4 MB


// --- Archive ---
// Maximum number of files allowed in a single archive creation.
define_default('FILECARTON_ARCHIVE_MAX_FILES', 10000);

// Maximum total (uncompressed) size for archive creation (bytes).
define_default('FILECARTON_ARCHIVE_MAX_SIZE', 1 * 1024 * 1024 * 1024); // 1 GB


// --- Upload ---
// Maximum file size for a single upload (bytes). Applies to chunked uploads.
define_default('FILECARTON_UPLOAD_MAX_FILE_SIZE', 1 * 1024 * 1024 * 1024); // 1 GB

// Expected chunk size for chunked uploads (bytes).
// Should be less than PHP's upload_max_filesize and post_max_size,
// and your web server (e.g. Nginx)'s body size limit.
// NOTE: It's likely that you need to edit those configurations,
// to either increase them, or decrease FILECARTON_UPLOAD_CHUNK_SIZE,
// as the values in PHP and Nginx's default configurations are quite small!
define_default('FILECARTON_UPLOAD_CHUNK_SIZE', 4 * 1024 * 1024); // 4 MB

// Maximum number of chunks per chunked upload session.
define_default('FILECARTON_UPLOAD_MAX_CHUNKS', 10000);

// Maximum directory nesting depth for folder upload relative paths.
define_default('FILECARTON_UPLOAD_MAX_DEPTH', 50);

// Chunk upload temporary directory expiry time (seconds).
define_default('FILECARTON_CHUNK_EXPIRY', 86400); // 24 hours

// Probability of running cleanup on each chunk upload request (1 in N).
define_default('FILECARTON_CHUNK_CLEANUP_CHANCE', 10);


// --- Search ---
// Default maximum number of search results returned.
define_default('FILECARTON_SEARCH_DEFAULT_LIMIT', 200);

// Hard cap on search results.
define_default('FILECARTON_SEARCH_MAX_LIMIT', 1000);

// Maximum filesystem entries to scan per search.
define_default('FILECARTON_SEARCH_MAX_SCAN', 100000);


// --- SendFile / X-Accel-Redirect ---
// Offload file serving to the web server. Possible values:
//   false                   - Disabled (default). PHP serves via readfile().
//   'xsendfile'             - Apache mod_xsendfile. Sends absolute path via X-Sendfile header.
//   [[phys, uri], ...]      - Nginx X-Accel-Redirect. Maps filesystem prefixes to internal URIs.
//                             Matched top-to-bottom, first matching prefix wins.
//
// Nginx example:
//   define_default('FILECARTON_SENDFILE', [
//       [__DIR__ . '/', '/internal/fc-root'],
//       ['/var/www/user-content', '/internal/user-content'],
//   ]);
//
// Apache example:
//   define_default('FILECARTON_SENDFILE', 'xsendfile');
define_default('FILECARTON_SENDFILE', false);


// --- Development ---
// Vite dev server URL. When defined and truthy, page.php loads assets from
// this server instead of the built manifest. Comment out for production.
// define_default('FILECARTON_DEV_SERVER', 'http://localhost:5173');


function define_default(string $key, mixed $value) {
    if (!defined($key)) define($key, $value);
}
