# Getting started

FileCarton is a single-file PHP web file manager.  
Drop one PHP file on a server and it gives you a browser-based UI for uploading, downloading, editing, and organizing files.

FileCarton is primarily designed for two categories of usages:

- **Standalone** — You access FileCarton directly.
- **Embedded** — FileCarton is used for file management inside your existing PHP application. Your app handles its own authentication, which you wish to transparently pass on to FileCarton for e.g. selecting the folder to manage.

## Standalone setup

### Requirements

- A web server
- PHP 7.1+ (PHP 8+ is recommended)

### 1. Place the files

Copy `filecarton.php` and `filecarton.config.php` to somewhere your web server can serve.

### 2. Set a password

Open `filecarton.config.php`. Find `FILECARTON_STATIC_USER_LIST` and replace the default hash.

Run this command in terminal to compute a password hash:

```bash
php -r "echo password_hash('<your-password>', PASSWORD_DEFAULT), PHP_EOL;"
```

Paste the output:

```php
define_default('FILECARTON_STATIC_USER_LIST', [
    ['id' => 'admin', 'passwordHash' => '$2y$10$...paste-here...'],
]);
```

#### Note

- PHP's default `upload_max_filesize` and `post_max_size` are small (often 2 MB).
  You should raise them to at least match `FILECARTON_UPLOAD_CHUNK_SIZE` (default 4 MB). Nginx has its own `client_max_body_size` that you may also need to increase.
  *(While reducing `FILECARTON_UPLOAD_CHUNK_SIZE` can also get it working, it's generally not a good idea.)*

### 3. Open it

Point your browser at `filecarton.php`. Log in with username `admin` and the password you chose. You are managing the directory that contains `filecarton.php`.

### 4. (Optional) Choose a different directory

By default, FileCarton manages the directory where `filecarton.php` sits. To change this, edit `rootPath` in `FILECARTON_REPO_SETTING`:

```php
define_default('FILECARTON_REPO_SETTING', [
    'rootPath' => '/var/www/user-files',
    'readonly' => false,
    'branding' => 'FileCarton',
    'repoName' => '',
]);
```

### What next

- Add OAuth login (GitHub, Google, Microsoft, Discord): see [config_auth.md](config_auth.md).
- Add more users or per-user directories: see the "user list" section in [config_auth.md](config_auth.md).
- Write a custom auth plugin or grant resolver: see [auth_provider.md](auth_provider.md).

---

## Embedded setup

If your PHP application already handles authentication and you want to add a file manager inside it, you should be embedding FileCarton.

### 1. Set the embed flag

In `filecarton.config.php`:

```php
define_default('FILECARTON_MUST_EMBED', true);
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
    'rootPath' => '/var/www/user-files/' . $_SESSION['user'],
    'readonly' => false,
    'branding' => 'My App',
    'repoName' => '',
]);

require __DIR__ . '/filecarton.php';
```

#### Note

- PHP's default `upload_max_filesize` and `post_max_size` are small (often 2 MB).
  You should raise them to at least match `FILECARTON_UPLOAD_CHUNK_SIZE` (default 4 MB). Nginx has its own `client_max_body_size` that you may also need to increase.
  *(While reducing `FILECARTON_UPLOAD_CHUNK_SIZE` can also get it working, it's generally not a good idea.)*

### 3. Open it

Navigate to `files.php` (after logging in through your app).  
FileCarton's built-in auth is disabled and your logic controls its configuration.

### What next

- Pass per-tab state to FileCarton (for multi-repo setups): see [embedding.md](embedding.md).
- Serve files through Nginx/Apache instead of PHP: set `FILECARTON_SENDFILE` in `filecarton.config.php`.

---

## Deployment notes

- Always use HTTPS in production. If TLS is terminated at a reverse proxy, configure it to send `X-Forwarded-Proto: https`.
- PHP's default `upload_max_filesize` and `post_max_size` are small (often 2 MB).
  You should raise them to at least match `FILECARTON_UPLOAD_CHUNK_SIZE` (default 4 MB). Nginx has its own `client_max_body_size` that you may also need to increase.
  *(While reducing `FILECARTON_UPLOAD_CHUNK_SIZE` can also get it working, it's generally not a good idea.)*
- If you're using IIS, it's a good idea to set `fastcgi.logging = 0` in `php.ini`.
- FileCarton is configured by default to hide its own files (`filecarton.php`, `filecarton.config.php`) from the file listing automatically.
