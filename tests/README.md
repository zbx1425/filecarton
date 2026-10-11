# FileCarton backend tests

Integration tests drive the real PHP entry through Caddy and a pool of `php-cgi` workers. Unit tests call the path and filename helpers in-process. Nothing here is wired into GitHub Actions.

## Setup

1. Install PHP (the Windows zip includes both `php.exe` and `php-cgi.exe`) and [Caddy](https://github.com/caddyserver/caddy/releases).
2. Copy `tests/.env.example` to `tests/.env.local` and set the absolute paths.
3. PHP needs the extensions the app already checks for: `json`, `session`, `mbstring`, `zip`, `phar`, `zlib`, `curl`.

```
copy tests\.env.example tests\.env.local
```

Caddy listens on `127.0.0.1` only. The rig starts and stops it. `php-fpm` is not used: Windows builds do not ship it. Each `php-cgi.exe` handles one request at a time, so `FC_WORKERS` (default 4) is the pool size.

## Integration

From the repo root. The venv is created with `python3-64.exe` and is not committed:

```
python3-64 -m venv tests\.venv
tests\.venv\Scripts\python.exe -m pip install pytest httpx hypothesis
tests\.venv\Scripts\python.exe -m pytest tests/integration
tests\.venv\Scripts\python.exe -m pytest tests/integration --fuzz-deep
```

`--fuzz-deep` raises Hypothesis from 60 examples to 2000. The ordinary run already includes the small fuzz budget.

## Unit and php-fuzzer

```
cd tests/php
composer install
php vendor/bin/phpunit -c phpunit.xml.dist
php vendor/bin/php-fuzzer fuzz fuzz-targets/normalize_path.php
```

`php-fuzzer` is for local runs against `FilenameBlocklist`, `IgnoreRules`, and `normalizePath`. It does not boot `filecarton.php`.

## Layout

`tests/support/php/front.php` is the front controller. It `define()`s the generated scenario, then loads `apps/backend/filecarton.php`. That is the same seam as embedding. `tests/integration/rig` owns Caddy, the worker pool, the HTTP client, and the filesystem oracle.
