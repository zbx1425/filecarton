# Embedding FileCarton

FileCarton can be embedded into your PHP application, with its configuration dynamically provided by your own business logic.

## Basic setup

1. In `filecarton.config.php`, set `define_default('FILECARTON_MUST_EMBED', true);`. This makes FileCarton refuse to start when accessed directly.
2. In your bootstrap script, define `FILECARTON_EMBED` and `FILECARTON_REPO_SETTING` **before** including `filecarton.php`.  
   These take precedence over the `define_default` values in `filecarton.config.php`.  
   You should set `FILECARTON_EMBED` to `true` to disable the built-in auth system, which you most likely don't want to run alongside your own authentication logic.
   You must provide a complete `FILECARTON_REPO_SETTING` array with all 4 fields (`rootPath`, `readonly`, `branding`, `repoName`).
3. Include `filecarton.php` at the end of your bootstrap script. FileCarton handles routing from there.
4. Add an early exit for `?fcres` as described below.
5. If needed, embed the bootstrap script into your main page using an `<iframe>`.

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
    'rootPath' => $repo['path'],
    'readonly' => (bool)($repo['readonly'] ?? false),
    'branding' => 'FileCarton',
    'repoName' => $repo['name'] ?? $repoId,
]);

// --- Hand off to FileCarton ---
require __DIR__ . '/filecarton.php';
```


## Passing states to FileCarton

When embedding, you typically need to pass context into FileCarton's configuration, e.g. which user is currently logged in, which directory to manage, whether the view is read-only, etc. You can do so with three ways:

### Session

```php
define('FILECARTON_REPO_SETTING', [
    'rootPath' => '/user-contents/' . $_SESSION['user_dir_name'],
    'readonly' => false,
    'branding' => 'FileCarton',
    'repoName' => '',
]);
```

Good for login state and user identity. Not suitable for per-tab state, since PHP sessions are shared across all browser tabs (If a user tries to open two tabs to manage different directories simultaneously, one will overwrite the other's session value).

### Query Parameters `?state` / `?state_*`

FileCarton's frontend automatically forwards any query parameter named `state`, starting with `state_`, or matching `state[...]` on every API request. State is preserved per tab without session conflicts.

```php
$repo = $_GET['state_selected_repo'] ?? 'default';
define('FILECARTON_REPO_SETTING', [
    'rootPath' => '/user-contents/' . $_SESSION['user_dir_name'] . '/' . $repo,
    'readonly' => false,
    'branding' => 'FileCarton',
    'repoName' => $repo,
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
    'rootPath' => '/user-contents/' . $_SESSION['user_dir_name'] . '/' . $repo,
    'readonly' => false,
    'branding' => 'FileCarton',
    'repoName' => $repo,
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
