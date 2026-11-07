<?php

// This script is supposed to be ran on PHP 8.2+.

const FILECARTON_DEMO_DATA = '/data';
const FILECARTON_DEMO_ROOT = '/data/demo';
const FILECARTON_DEMO_TMP = '/data/tmp';
const FILECARTON_DEMO_SEED = '/opt/filecarton/seed';
const FILECARTON_DEMO_TEMPLATE = '/opt/filecarton/filecarton.config.toml.php.template';
const FILECARTON_DEMO_CONFIG = '/var/www/html/filecarton.config.toml.php';
const FILECARTON_DEMO_APACHE = '/usr/local/bin/apache2-foreground';
const FILECARTON_DEMO_PROBE_NAME = '.fc-write-probe';

function filecarton_demo_fail(string $message): void {
    fwrite(STDERR, "filecarton-demo: {$message}\n");
    exit(1);
}

function filecarton_demo_env(string $name): string {
    $value = getenv($name);
    return is_string($value) ? $value : '';
}

function filecarton_demo_reject_token(string $name, string $value, bool $allowEmpty): void {
    if ($value === '') {
        if ($allowEmpty) {
            return;
        }
        filecarton_demo_fail("{$name} is required");
    }
    if (preg_match('/["\\\\\s]/', $value) === 1 || str_contains($value, '__')) {
        filecarton_demo_fail("{$name} contains quotes, backslashes, whitespace, or __");
    }
}

function filecarton_demo_origin(): string {
    $origin = filecarton_demo_env('PUBLIC_ORIGIN');
    filecarton_demo_reject_token('PUBLIC_ORIGIN', $origin, true);
    if ($origin === '') {
        return '';
    }
    if (preg_match('/\Ahttps:\/\/[A-Za-z0-9.-]+(?::([0-9]{1,5}))?\z/', $origin, $m) !== 1) {
        filecarton_demo_fail('PUBLIC_ORIGIN must be https://host with an optional port');
    }
    if (isset($m[1]) && $m[1] !== '' && ((int)$m[1] < 1 || (int)$m[1] > 65535)) {
        filecarton_demo_fail('PUBLIC_ORIGIN port is out of range');
    }
    return $origin;
}

function filecarton_demo_dir_is_empty(string $dir): bool {
    $items = scandir($dir);
    if ($items === false) {
        filecarton_demo_fail("cannot read {$dir}");
    }
    foreach ($items as $name) {
        if ($name !== '.' && $name !== '..') {
            return false;
        }
    }
    return true;
}

function filecarton_demo_copy_tree(string $src, string $dst, array &$seen): void {
    $real = realpath($src);
    if ($real === false) {
        filecarton_demo_fail("cannot resolve {$src}");
    }
    if (isset($seen[$real])) {
        filecarton_demo_fail("symlink cycle at {$src}");
    }
    $seen[$real] = true;

    $items = scandir($src);
    if ($items === false) {
        filecarton_demo_fail("cannot read {$src}");
    }
    foreach ($items as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $from = $src . '/' . $name;
        $to = $dst . '/' . $name;
        if (is_dir($from)) {
            if (!is_dir($to) && !mkdir($to, 0755)) {
                filecarton_demo_fail("mkdir failed for {$to}");
            }
            filecarton_demo_copy_tree($from, $to, $seen);
            continue;
        }
        if (!copy($from, $to)) {
            filecarton_demo_fail("copy failed for {$from}");
        }
    }
}

function filecarton_demo_hand_over(string $path, int $uid, int $gid): void {
    if (!chown($path, $uid) || !chgrp($path, $gid)) {
        filecarton_demo_fail("chown failed for {$path}");
    }
    if (is_link($path)) {
        return;
    }
    if (is_dir($path)) {
        if (!chmod($path, 0755)) {
            filecarton_demo_fail("chmod failed for {$path}");
        }
        $items = scandir($path);
        if ($items === false) {
            filecarton_demo_fail("cannot read {$path}");
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            filecarton_demo_hand_over($path . '/' . $name, $uid, $gid);
        }
        return;
    }
    if (!chmod($path, 0644)) {
        filecarton_demo_fail("chmod failed for {$path}");
    }
}

function filecarton_demo_first_file(string $dir): ?string {
    $items = scandir($dir);
    if ($items === false) {
        return null;
    }
    $dirs = [];
    foreach ($items as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $dir . '/' . $name;
        if (is_link($path)) {
            if (is_file($path)) {
                return $path;
            }
            continue;
        }
        if (is_dir($path)) {
            $dirs[] = $path;
            continue;
        }
        if (is_file($path)) {
            return $path;
        }
    }
    foreach ($dirs as $sub) {
        $found = filecarton_demo_first_file($sub);
        if ($found !== null) {
            return $found;
        }
    }
    return null;
}

function filecarton_demo_probe_file(string $dir): int {
    $path = $dir . '/' . FILECARTON_DEMO_PROBE_NAME;
    $handle = @fopen($path, 'wb');
    if ($handle === false) {
        return 3;
    }
    if (fwrite($handle, 'ok') === false) {
        fclose($handle);
        return 3;
    }
    fclose($handle);
    if (!unlink($path)) {
        return 4;
    }
    return 0;
}

function filecarton_demo_probe(string $demo, string $tmp, int $uid, int $gid): int {
    $pid = pcntl_fork();
    if ($pid === -1) {
        filecarton_demo_fail('pcntl_fork failed');
    }
    if ($pid === 0) {
        if (!posix_setgid($gid) || !posix_setuid($uid)) {
            exit(2);
        }
        if (posix_geteuid() !== $uid || posix_getegid() !== $gid) {
            exit(2);
        }
        $existing = filecarton_demo_first_file($demo);
        if ($existing === null) {
            exit(7);
        }
        $created = filecarton_demo_probe_file($demo);
        if ($created !== 0) {
            exit($created);
        }
        $created = filecarton_demo_probe_file($tmp);
        if ($created !== 0) {
            exit($created === 3 ? 5 : 6);
        }
        $handle = @fopen($existing, 'r+');
        if ($handle === false) {
            exit(8);
        }
        fclose($handle);
        exit(0);
    }

    $status = 0;
    if (pcntl_waitpid($pid, $status) !== $pid) {
        return 90;
    }
    if (!pcntl_wifexited($status)) {
        return 91;
    }
    return pcntl_wexitstatus($status);
}

function filecarton_demo_render_config(string $clientId, string $clientSecret, string $origin): void {
    $template = file_get_contents(FILECARTON_DEMO_TEMPLATE);
    if (!is_string($template) || $template === '') {
        filecarton_demo_fail('config template is missing');
    }
    $hash = password_hash('demo', PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === '') {
        filecarton_demo_fail('password_hash failed');
    }
    filecarton_demo_reject_token('password_hash', $hash, false);

    $replace = [
        '__GITHUB_CLIENT_ID__' => $clientId,
        '__GITHUB_CLIENT_SECRET__' => $clientSecret,
        '__PUBLIC_ORIGIN__' => $origin,
        '__PASSWORD_HASH__' => $hash,
    ];
    foreach ($replace as $placeholder => $_value) {
        if (substr_count($template, $placeholder) !== 1) {
            filecarton_demo_fail("config template placeholder {$placeholder} is missing or repeated");
        }
    }
    $rendered = strtr($template, $replace);
    if (!str_starts_with($rendered, "#<?php exit;?>\n")) {
        filecarton_demo_fail('rendered config lost its PHP guard');
    }
    foreach (array_keys($replace) as $placeholder) {
        if (str_contains($rendered, $placeholder)) {
            filecarton_demo_fail('config placeholder was not replaced');
        }
    }

    $tmp = FILECARTON_DEMO_CONFIG . '.tmp';
    if (file_put_contents($tmp, $rendered) === false) {
        filecarton_demo_fail('cannot write config');
    }
    if (!chown($tmp, 0) || !chgrp($tmp, 0) || !chmod($tmp, 0644)) {
        filecarton_demo_fail('cannot set config ownership');
    }
    if (!rename($tmp, FILECARTON_DEMO_CONFIG)) {
        filecarton_demo_fail('cannot install config');
    }
}

function filecarton_demo_main(): void {
    if (PHP_SAPI !== 'cli') {
        filecarton_demo_fail('bootstrap must run from the CLI');
    }
    foreach (['pcntl', 'posix'] as $extension) {
        if (!extension_loaded($extension)) {
            filecarton_demo_fail("PHP extension {$extension} is required");
        }
    }
    // The production ini caps Apache requests at 120s. Copying the seed is not a request.
    set_time_limit(0);
    ini_set('max_execution_time', '0');

    $clientId = filecarton_demo_env('GITHUB_CLIENT_ID');
    $clientSecret = filecarton_demo_env('GITHUB_CLIENT_SECRET');
    filecarton_demo_reject_token('GITHUB_CLIENT_ID', $clientId, false);
    filecarton_demo_reject_token('GITHUB_CLIENT_SECRET', $clientSecret, false);
    $origin = filecarton_demo_origin();

    if (!is_dir(FILECARTON_DEMO_DATA) && !mkdir(FILECARTON_DEMO_DATA, 0755)) {
        filecarton_demo_fail('cannot create /data');
    }
    foreach ([FILECARTON_DEMO_ROOT, FILECARTON_DEMO_TMP] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0755)) {
            filecarton_demo_fail("cannot create {$dir}");
        }
    }
    if (!is_dir(FILECARTON_DEMO_SEED)) {
        filecarton_demo_fail('seed directory is missing');
    }
    if (filecarton_demo_dir_is_empty(FILECARTON_DEMO_ROOT)) {
        fwrite(STDERR, "filecarton-demo: seeding " . FILECARTON_DEMO_ROOT . "\n");
        $seen = [];
        filecarton_demo_copy_tree(FILECARTON_DEMO_SEED, FILECARTON_DEMO_ROOT, $seen);
    }

    filecarton_demo_render_config($clientId, $clientSecret, $origin);

    $worker = posix_getpwnam('www-data');
    if (!is_array($worker) || !isset($worker['uid'], $worker['gid'])) {
        filecarton_demo_fail('www-data user not found');
    }
    $uid = (int)$worker['uid'];
    $gid = (int)$worker['gid'];
    filecarton_demo_hand_over(FILECARTON_DEMO_ROOT, $uid, $gid);
    filecarton_demo_hand_over(FILECARTON_DEMO_TMP, $uid, $gid);

    $probe = filecarton_demo_probe(FILECARTON_DEMO_ROOT, FILECARTON_DEMO_TMP, $uid, $gid);
    if ($probe !== 0) {
        filecarton_demo_fail("www-data write probe failed ({$probe})");
    }

    if (!chown(FILECARTON_DEMO_CONFIG, 0) || !chgrp(FILECARTON_DEMO_CONFIG, 0) || !chmod(FILECARTON_DEMO_CONFIG, 0644)) {
        filecarton_demo_fail('config must stay root:root mode 0644');
    }

    if (!is_file(FILECARTON_DEMO_APACHE)) {
        filecarton_demo_fail('apache2-foreground is missing');
    }
    fwrite(STDERR, "filecarton-demo: starting apache\n");
    pcntl_exec(FILECARTON_DEMO_APACHE);
    filecarton_demo_fail('pcntl_exec of apache2-foreground failed');
}

if (PHP_SAPI === 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)
) {
    filecarton_demo_main();
}
