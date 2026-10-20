# Embedding FileCarton

> For standalone deployment, see [getting_started.md](getting_started.md) and [config_auth.md](config_auth.md) instead.

FileCarton can be embedded into your PHP application, with its configuration dynamically provided by your own business logic.

## Basic setup

1. In `filecarton.config.toml.php`, set `must_embed = true` under `[auth]`. This makes FileCarton refuse to start when `filecarton.php` is accessed directly.
2. In your bootstrap script, define `FILECARTON_EMBED` and `FILECARTON_REPO_SETTING` **before** including `filecarton.php`.  
   You should set `FILECARTON_EMBED` to `true` to disable the built-in auth system, which you most likely don't want to run alongside your own authentication logic.
   You must provide a `FILECARTON_REPO_SETTING` array with the required fields (`root_path`, `readonly`, `branding`, `repo_name`, and optional feature flags such as `feature_archive`).
3. Include `filecarton.php` at the end of your bootstrap script. FileCarton handles routing from there.
4. Add an early exit for `?fcres` as described below.
5. If needed, embed the bootstrap script into your main page using an `<iframe>`.

## Overriding configuration with `define()`

Any `define('FILECARTON_...', ...)` executed before `require __DIR__ . '/filecarton.php'` takes precedence over the settings in `filecarton.config.toml.php` (as well as FileCarton's built-in defaults).

### Naming mapping rules

Settings in `filecarton.config.toml.php` map to PHP constants as follows:

- **`[repo]` section → `FILECARTON_REPO_SETTING`**  
  The entire `[repo]` table maps to a single associative array constant `FILECARTON_REPO_SETTING`, using the same `snake_case` keys.

- **All other sections (`[auth]`, `[limits]`, `[security]`, `[deployment]`, `[misc]`) → `FILECARTON_<UPPERCASE_KEY>`**  
  Each key in any section other than `[repo]` maps directly to a constant named `FILECARTON_` followed by the key name in `UPPER_SNAKE_CASE` (the section name itself is not part of the constant name). For example:
  - `must_embed` (`[auth]`) → `FILECARTON_MUST_EMBED`
  - `max_edit_size` (`[limits]`) → `FILECARTON_MAX_EDIT_SIZE`
  - `upload_chunk_size` (`[limits]`) → `FILECARTON_UPLOAD_CHUNK_SIZE`
  - `upload_max_file_size` (`[limits]`) → `FILECARTON_UPLOAD_MAX_FILE_SIZE`
  - `must_readonly` (`[security]`) → `FILECARTON_MUST_READONLY`
  - `filename_blocklist` (`[security]`) → `FILECARTON_FILENAME_BLOCKLIST`
  - `ignore_pattern` (`[security]`) → `FILECARTON_IGNORE_PATTERN`
  - `ignore_realpath` (`[security]`) → `FILECARTON_IGNORE_REALPATH`
  - `sendfile` (`[deployment]`) → `FILECARTON_SENDFILE`

  > **Note:** When overriding byte-size limits (such as `FILECARTON_MAX_EDIT_SIZE` or `FILECARTON_UPLOAD_CHUNK_SIZE`) via `define()` in PHP, pass an integer number of bytes (e.g. `4 * 1024 * 1024`) rather than a string like `"4M"`.

- When embedding FileCarton, the `filecarton.config.toml.php` file is optional.  
  You can keep it for easy changes to default configuration values.
  Or alternatively, you can remove it and supply all the configuration via `define()` (Unsupplied entries will fall back to built-in default values). In this case the `must_embed = true` is also unneeded as FileCarton will refuse to start without `FILECARTON_EMBED` when the config file is absent.

## Early exit for serving static assets

FileCarton's frontend loads CSS and JS via URLs like `yourscript.php?fcres=assets/main-xxxxxxxx.css`. These are static, cacheable files that do not need authentication or session handling.

Check for `?fcres` at the top of your bootstrap script and skip to FileCarton immediately:

```php
if (isset($_GET['fcres'])) {
    require __DIR__ . '/filecarton.php';
    exit;
}
```


## Complete example

```php
<?php
define('FILECARTON_EMBED', true);

// Early exit for static assets (no auth needed)
if (isset($_GET['fcres'])) {
    require __DIR__ . '/filecarton.php';
    exit;
}

// --- Your authentication logic ---
session_start();
if (!isset($_SESSION['user'])) {
    http_response_code(403);
    exit('Not logged in');
}

// In our example configuration, each user has multiple repositories, and the user can manage any one of them.
// --- Determine repository that the user wants to use, from per-tab state ---
$allRepos = getUserRepositories($_SESSION['user']);
$repoId   = $_GET['state_selected_repo'] ?? array_key_first($allRepos) ?? 'default';

// Always validate state parameters against an allowlist of known values or regex.
if (!isset($allRepos[$repoId])) {
    http_response_code(404);
    exit('Repository not found');
}

$repo = $allRepos[$repoId];

// --- Configure FileCarton ---
define('FILECARTON_REPO_SETTING', [
    'root_path' => $repo['path'],
    'readonly' => (bool)($repo['readonly'] ?? false),
    'branding' => 'FileCarton',
    'repo_name' => $repo['name'] ?? $repoId,
    'feature_archive' => true,
]);

// --- Hand off to FileCarton ---
require __DIR__ . '/filecarton.php';
```


## Passing states to FileCarton

When embedding, you typically need to pass context into FileCarton's configuration, e.g. which user is currently logged in, which directory to manage, whether the view is read-only, etc. You can do so with three ways:

### Session

```php
define('FILECARTON_REPO_SETTING', [
    'root_path' => '/user-contents/' . $_SESSION['user_dir_name'],
    'readonly' => false,
    'branding' => 'FileCarton',
    'repo_name' => '',
    'feature_archive' => true,
]);
```

Good for login state and user identity. Not suitable for per-tab state, since PHP sessions are shared across all browser tabs (If a user tries to open two tabs to manage different directories simultaneously, one will overwrite the other's session value).

### Query Parameters `?state` / `?state_*`

FileCarton's frontend automatically forwards any query parameter named `state`, starting with `state_`, or matching `state[...]` on every API request. State is preserved per tab without session conflicts.

```php
$repo = $_GET['state_selected_repo'] ?? 'default';
define('FILECARTON_REPO_SETTING', [
    'root_path' => '/user-contents/' . $_SESSION['user_dir_name'] . '/' . $repo,
    'readonly' => false,
    'branding' => 'FileCarton',
    'repo_name' => $repo,
    'feature_archive' => true,
]);
```

The user can then access FileCarton via:

```
https://example.com/your_entry_point.php?state_selected_repo=photos
```

Each tab can carry a different `state_selected_repo` value without interference. Note that you should also handle the situation where there is no such GET query parameter.

### PATH_INFO

Or alternatively, you can encode context in the URL path. FileCarton preserves PATH_INFO across API requests。

```php
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
$repo = explode('/', trim($pathInfo, '/'))[0] ?: 'default';
define('FILECARTON_REPO_SETTING', [
    'root_path' => '/user-contents/' . $_SESSION['user_dir_name'] . '/' . $repo,
    'readonly' => false,
    'branding' => 'FileCarton',
    'repo_name' => $repo,
    'feature_archive' => true,
]);
```

The user can then access FileCarton via:

```
https://example.com/your_entry_point.php/photos
```

This might require web server configuration. Nginx needs `fastcgi_split_path_info`; Apache usually works without changes (add `AcceptPathInfo On` if it does not work out of the box).

An example nginx `location` block for supporting PATH_INFO is provided below for your convenience:

```nginx
location ~ [^/]\.php(/|$) {
    fastcgi_split_path_info ^(.+?\.php)(/.*)$;
    set $path_info $fastcgi_path_info;
    try_files $fastcgi_script_name =404;
    
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_param PATH_INFO $path_info;
    fastcgi_param HTTP_PROXY "";
    fastcgi_index index.php;
    
    # Adjust the socket or localhost port path according to your actual PHP-FPM configuration
    fastcgi_pass unix:/var/run/php/php8.3-fpm.sock; 
}
```

## See also

- [getting_started.md](getting_started.md): Quick setup, including a minimal embedded example.
- [config_auth.md](config_auth.md): Built-in password/OAuth auth (for standalone deployments).
- [auth_provider.md](auth_provider.md): Writing custom auth providers and grant resolvers.
