<?php
/**
 * FileCarton Configuration
 */

// --- Core ---

// Filesystem path to the managed directory, preferably absolute.
// If set to an empty string '', FileCarton will refuse to start. This is useful if you're
// embedding FileCarton and want to disable invoking the script directly.
// To allow browsing the current path, set it to __DIR__.
// When embedding FileCarton, set it to ''.
define_default('FILECARTON_ROOT_PATH', dirname(realpath($_SERVER['SCRIPT_FILENAME'])));

// Read-only mode, file mutation functions are disabled.
define_default('FILECARTON_READONLY', false);

// Brand name displayed in the top bar. Empty string '' means no branding.
define_default('FILECARTON_BRANDING', 'FileCarton');

// An informative label describing what directory the user is managing, shown in the top bar.
define_default('FILECARTON_REPO_NAME', '');


// --- Dotfiles ---

// When true, the API never returns dotfiles and users cannot create them.
define_default('FILECARTON_DOTFILES_BLOCK', false);

// When true, the frontend forces dotfiles visible; users cannot hide them.
define_default('FILECARTON_DOTFILES_FORCE_VISIBLE', false);


// --- File Extensions ---

// If non-empty, ONLY these extensions are allowed for file creation/modification.
// Each entry includes the leading dot, e.g. ['.txt', '.md'].
// '' matches files with no extension (e.g. Makefile); '.' matches trailing-dot names.
define_default('FILECARTON_EXTENSIONS_ALLOWLIST', []);

// Extensions blocked from file creation/modification (ignored when ALLOWLIST is non-empty).
// Same format as ALLOWLIST. Case-insensitive.
// Note: These restrict file creation, upload, rename-to, and save operations.
// Existing files with blocked extensions remain readable and downloadable.
// Folder-level operations (copy, move, delete, rename) do not enforce extension rules.
define_default('FILECARTON_EXTENSIONS_BLOCKLIST', ['.php']);


// --- Ignore Rules ---

// Gitignore-ish patterns matched against paths relative to FILECARTON_ROOT_PATH.
// Supports * (single-level wildcard), ** (multi-level), leading / (anchor to root),
// trailing / (directories only).
// Note: Patterns containing ** or unanchored patterns (without a leading /) require
// filesystem scanning during folder copy, move, delete, and rename operations.
// For large directory trees, prefer anchored patterns without ** where possible.
define_default('FILECARTON_IGNORE_PATTERN', []);

// Absolute filesystem paths to ignore. Paths are resolved via realpath() at runtime.
// Matching is exact or prefix-based (a directory entry hides everything beneath it).
define_default('FILECARTON_IGNORE_REALPATH', [
    __DIR__ . '/filecarton.php',
    __DIR__ . '/filecarton.config.php',
    __DIR__ . '/filecarton',
]);


// --- Routing ---

// Base URL for static assets, if you have some good CDN.
// The files are expected to be at <FILECARTON_ASSET_URL>/assets/main-xxxxxxxx.css (and .js).
// If you're doing multi-file deployment, cdn.json and .vite/manifest.json still need to be at public/.
// When empty, assets are served by the PHP script via '?fcres=assets/main-xxxxxxxx.css'.
define_default('FILECARTON_ASSET_URL', '');


// --- Editor ---

// Maximum file size for text read/write API (bytes).
// Should be less than PHP's upload_max_filesize and post_max_size,
// and your web server (e.g. Nginx)'s body size limit.
// NOTE: It's likely that you need to edit those configurations,
// to either increase them, or decrease FILECARTON_UPLOAD_CHUNK_SIZE,
// as the values in PHP and Nginx's default configurations are quite small!
define_default('FILECARTON_MAX_EDIT_SIZE', 4 * 1024 * 1024); // 4 MB


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

// Maximum directory nesting depth for folder upload relative paths.
define_default('FILECARTON_UPLOAD_MAX_DEPTH', 50);

// Chunk upload temporary directory expiry time (seconds).
define_default('FILECARTON_CHUNK_EXPIRY', 86400); // 24 hours

// Probability of running cleanup on each chunk upload request (1 in N).
define_default('FILECARTON_CHUNK_CLEANUP_CHANCE', 10);


// --- Archive ---

// Maximum number of files allowed in a single archive creation.
define_default('FILECARTON_ARCHIVE_MAX_FILES', 10000);

// Maximum total (uncompressed) size for archive creation (bytes).
define_default('FILECARTON_ARCHIVE_MAX_SIZE', 1 * 1024 * 1024 * 1024); // 1 GB


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


function define_default(string $key, $value) {
    if (!defined($key)) define($key, $value);
}
