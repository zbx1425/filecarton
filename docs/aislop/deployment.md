# FileCarton Deployment Guide

## SendFile / X-Accel-Redirect

FileCarton supports offloading file serving to the web server, avoiding PHP
`readfile()` overhead. Both Apache `mod_xsendfile` and Nginx `X-Accel-Redirect`
are supported through a single `FILECARTON_SENDFILE` configuration.

### Apache mod_xsendfile

Install and enable `mod_xsendfile`, then set:

```php
define('FILECARTON_SENDFILE', 'xsendfile');
```

FileCarton sends `X-Sendfile: /absolute/path/to/file` and Apache takes over.
No path mapping is needed — the absolute filesystem path is sent directly.

Apache configuration:

```apache
<Directory "/var/www">
    XSendFile On
    XSendFilePath /var/www/data
    XSendFilePath /path/to/inc/filecarton/public
</Directory>
```

### Nginx X-Accel-Redirect

Configure `FILECARTON_SENDFILE` as an array of `[physicalPrefix, uriPrefix]`
rules. At runtime, FileCarton resolves the file's absolute path, walks the
array top-to-bottom, and sends `X-Accel-Redirect` for the first matching
physical prefix.

```php
define('FILECARTON_SENDFILE', [
    [__DIR__ . '/inc/filecarton/public', '/internal/fc-assets'],
    ['/var/www/data/user-content',       '/internal/user-content'],
    ['/var/www/data/user-packs',         '/internal/user-packs'],
]);
```

Physical paths are resolved via `realpath()` during matching, so symlinked
paths work correctly. Using `__DIR__` makes the config portable across
deployments.

Corresponding Nginx configuration:

```nginx
location /internal/fc-assets/ {
    internal;
    alias /path/to/inc/filecarton/public/;
}

location /internal/user-content/ {
    internal;
    alias /var/www/data/user-content/;
}

location /internal/user-packs/ {
    internal;
    alias /var/www/data/user-packs/;
}
```

#### Simplified setup (trusted environment)

If all sendfile sources are trusted (e.g. only your own PHP backend issues the
headers), you can use a single broad mapping:

```php
define('FILECARTON_SENDFILE', [
    ['/', '/internal/'],
]);
```

```nginx
location /internal/ {
    internal;
    alias /;
}
```

This maps any absolute path to `/internal/{path}`. The `internal` directive
prevents direct client access. This is convenient but exposes the entire
filesystem to the backend — only use it when the PHP backend is fully trusted.

#### Multi-repository setup

Since `FILECARTON_SENDFILE` is a `define()`, it can be set per-request in the
bootstrap script. For multiple repositories with different root paths, include
all mappings in one array:

```php
define('FILECARTON_SENDFILE', [
    [__DIR__ . '/inc/filecarton/public', '/internal/fc-assets'],
    [$repoRootPath,                      '/internal/repo-' . $repoKey],
]);
```

### URL Encoding

Since Nginx 1.5.9, `X-Accel-Redirect` expects URI-escaped paths. FileCarton
automatically encodes path segments with `rawurlencode()`. This correctly
handles filenames containing spaces, Unicode characters, `%`, `?`, `#`, etc.

### Security Notes

- All Nginx `location` blocks for X-Accel-Redirect **must** include the
  `internal` directive to prevent direct client access.
- Physical paths in the mapping array are resolved via `realpath()` at match
  time, ensuring symlinks are evaluated to their canonical location.
- When `FILECARTON_SENDFILE` is `false` (default), PHP serves files directly
  via `readfile()`. This works without web server modules but has higher
  memory/CPU overhead for large files.
- Apache's `XSendFilePath` directive controls which directories mod_xsendfile
  is allowed to serve from — configure this to limit exposure.

---

## Configuration Reference

All configuration values use the `define_default()` pattern (define-if-not-defined)
and can be overridden by defining the constants before including `router.php`.

| Constant | Default | Description |
|----------|---------|-------------|
| `FILECARTON_ROOT_PATH` | `''` (required) | Absolute path to the managed directory |
| `FILECARTON_READONLY` | `false` | Disable all write operations |
| `FILECARTON_REPO_NAME` | `'Files'` | Display name in UI |
| `FILECARTON_BRANDING` | `''` | Brand name in top bar |
| `FILECARTON_PATHINFO_OFFSET` | `0` | PATH_INFO segments consumed by parent router |
| `FILECARTON_ASSET_URL` | `''` (auto `__fcres`) | Static asset base URL |
| `FILECARTON_SENDFILE` | `false` | File serving offload (see above) |
| `FILECARTON_MAX_EDIT_SIZE` | 5 MB | Max file size for text editor API |
| `FILECARTON_ARCHIVE_MAX_FILES` | 10,000 | Max files in archive creation |
| `FILECARTON_ARCHIVE_MAX_SIZE` | 2 GB | Max uncompressed size (create + extract) |
| `FILECARTON_UPLOAD_MAX_DEPTH` | 50 | Max directory nesting for folder uploads |
| `FILECARTON_CHUNK_EXPIRY` | 86400 (24h) | Chunk temp dir expiry time |
| `FILECARTON_CHUNK_CLEANUP_CHANCE` | 10 | Cleanup probability (1 in N) |
| `FILECARTON_UPLOAD_MAX_FILE_SIZE` | 10 GB | Max single file upload size |
| `FILECARTON_UPLOAD_CHUNK_SIZE` | 5 MB | Expected chunk size (must fit PHP limits) |
| `FILECARTON_UPLOAD_MAX_CHUNKS` | 10,000 | Max chunks per upload session |
| `FILECARTON_SEARCH_DEFAULT_LIMIT` | 200 | Default search result count |
| `FILECARTON_SEARCH_MAX_LIMIT` | 1,000 | Hard cap on search results |
| `FILECARTON_SEARCH_MAX_SCAN` | 100,000 | Max filesystem entries scanned per search |
