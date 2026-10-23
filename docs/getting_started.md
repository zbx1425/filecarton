# Getting started

FileCarton is a single-file PHP web file manager.  
Drop one PHP file on a server and it gives you a browser-based UI for uploading, downloading, editing, and organizing files.

FileCarton is primarily designed for two categories of usages:

- **Standalone** — You access FileCarton directly.
- **Embedded** — FileCarton is used for file management inside your existing PHP application. Your app handles its own authentication, which you wish to transparently pass on to FileCarton for e.g. selecting the folder to manage.

## Standalone setup

### Requirements

- A web server
- PHP 7.2+ (PHP 8+ is recommended)

### 1. Place the files

Copy `filecarton.php` and `filecarton.config.toml.php` to somewhere your web server can serve.

### 2. Set a password

Open `filecarton.config.toml.php` (keep the `#<?php exit;?>` first line intact, as it blocks direct access to the config file). Find `static_user_list` under `[auth]` and replace the default hash.

Run this command in terminal to compute a password hash:

```bash
php -r "echo password_hash('<your-password>', PASSWORD_DEFAULT), PHP_EOL;"
```

Paste the output:

```toml
[auth]
static_user_list = [
  { id = "admin", password_hash = "$2y$10$...paste-here..." }
]
```

#### Note

- PHP's default `upload_max_filesize` and `post_max_size` are small (often 2 MB).
  You should raise them to at least match `upload_chunk_size` under `[limits]` (default `"4M"`). Nginx has its own `client_max_body_size` that you may also need to increase.
  *(While reducing `upload_chunk_size` can also get it working, it's generally not a good idea.)*

### 3. Open it

Point your browser at `filecarton.php`. Log in with username `admin` and the password you chose. You are managing the directory that contains `filecarton.php`.

### 4. (Optional) Choose a different directory

By default, FileCarton manages the directory where `filecarton.php` sits (`${ENTRY_POINT_SCRIPT_DIR}`). To change this, edit `root_path` under `[repo]` in `filecarton.config.toml.php`:

```toml
[repo]
root_path = "/var/www/user-files"
readonly = false
branding = "FileCarton"
repo_name = ""
feature_archive = true
```

### What next

- Add OAuth login (GitHub, Google, Microsoft, Discord): see [config_auth.md](config_auth.md).
- Add more users or per-user directories: see the "user list" section in [config_auth.md](config_auth.md).
- Write a custom auth plugin or grant resolver: see [auth_provider.md](auth_provider.md).

---

## Embedded setup

If your PHP application already handles authentication and you want to add a file manager inside it, you should be embedding FileCarton.

### 1. Set the embed flag

In `filecarton.config.toml.php`:

```toml
[auth]
must_embed = true
```

### 2. Create your entry-point script

Create a bootstrapper PHP file (for example `files.php`) that defines the repo settings and then includes FileCarton:

```php
<?php
define('FILECARTON_EMBED', true);

// Early exit, static assets do not need auth
if (isset($_GET['fcres'])) {
    require __DIR__ . '/filecarton.php';
    exit;
}

// --- Your auth logic, an example here ---
session_start();
if (!isset($_SESSION['user'])) {
    http_response_code(403);
    exit('Not logged in');
}

// --- Configure FileCarton ---
define('FILECARTON_REPO_SETTING', [
    'root_path' => '/var/www/user-files/' . $_SESSION['user'],
    'readonly' => false,
    'branding' => 'My App',
    'repo_name' => '',
    'feature_archive' => true,
]);

require __DIR__ . '/filecarton.php';
```

#### Note

- PHP's default `upload_max_filesize` and `post_max_size` are small (often 2 MB).
  You should raise them to at least match `upload_chunk_size` (default `"4M"`). Nginx has its own `client_max_body_size` that you may also need to increase.
  *(While reducing `upload_chunk_size` can also get it working, it's generally not a good idea.)*

### 3. Open it

Navigate to `files.php` (after logging in through your app).  
FileCarton's built-in auth is disabled and your logic controls its configuration.

### What next

- Pass per-tab state to FileCarton (for multi-repo setups): see [embedding.md](embedding.md).
- Serve files through Nginx/Apache instead of PHP: set `sendfile` under `[deployment]` in `filecarton.config.toml.php`.

---

## Deployment notes

- Always use HTTPS in production. If TLS is terminated at a reverse proxy, configure it to send `X-Forwarded-Proto: https`.
- PHP's default `upload_max_filesize` and `post_max_size` are small (often 2 MB).
  You should raise them to at least match `upload_chunk_size` (default `"4M"`). Nginx has its own `client_max_body_size` that you may also need to increase.
  *(While reducing `upload_chunk_size` can also get it working, it's generally not a good idea.)*
- If you're using IIS, it's a good idea to set `fastcgi.logging = 0` in `php.ini`.
- FileCarton is configured by default to hide its own files (`filecarton.php`, `filecarton.config.toml.php`) from the file listing automatically.
