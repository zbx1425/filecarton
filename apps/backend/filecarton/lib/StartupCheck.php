<?php

namespace FileCarton;

class StartupCheck {

    /**
     * Collect all environment/configuration problems.
     * Returns an empty array when everything is OK.
     *
     * @return string[]
     */
    public static function problems(): array {
        require_once FILECARTON_SCRIPT_DIR . '/lib/Platform.php';
        
        $p = class_exists(__NAMESPACE__ . '\\Config', false) ? Config::problems() : [];

        // FILECARTON_REPO_SETTING structural validation
        $repoArr = FILECARTON_REPO_SETTING;
        if (!is_array($repoArr)) {
            $p[] = 'FILECARTON_REPO_SETTING must be an array.';
        } else {
            foreach (RepoSetting::fromConfigArray($repoArr)->verifyAllSet() as $msg) {
                $p[] = $msg;
            }
        }

        // PHP version
        if (PHP_VERSION_ID < 70200) {
            $p[] = 'PHP 7.2 or later is required (running ' . PHP_VERSION . ').';
        }

        // Required extensions
        foreach (['json', 'session', 'mbstring'] as $ext) {
            if (!extension_loaded($ext)) {
                $p[] = 'Required PHP extension missing: ' . $ext;
            }
        }
        $archiveEnabled = RepoSetting::current()->featureArchive !== false;
        if ($archiveEnabled) {
            if (!extension_loaded('zip') || !class_exists('ZipArchive')) {
                $p[] = 'Required PHP extension missing: zip (ZipArchive)';
            }
            if (!extension_loaded('phar') || !class_exists('PharData')) {
                $p[] = 'Required PHP extension missing: phar (PharData)';
            }
        }
        if (!extension_loaded('zlib') || !function_exists('gzdecode')) {
            $p[] = 'Required PHP extension missing: zlib (gzdecode)';
        }
        // Require CURL anyways regardless of remote_download / auth feature state,
        // as this is very commonly required by other PHP apps,
        // thus the user will likely need it one way or another anyways.
        if (!extension_loaded('curl')) {
            $p[] = 'Required PHP extension missing: curl';
        }

        // Dotfiles mutex
        if (FILECARTON_DOTFILES_BLOCK && FILECARTON_DOTFILES_FORCE_VISIBLE) {
            $p[] = 'FILECARTON_DOTFILES_BLOCK and FILECARTON_DOTFILES_FORCE_VISIBLE cannot both be enabled.';
        }

        // Size checks (only meaningful when writes are possible)
        $mustReadonly = FILECARTON_MUST_READONLY === true;
        if (!$mustReadonly) {
            if (FILECARTON_UPLOAD_CHUNK_SIZE <= 0) {
                $p[] = 'FILECARTON_UPLOAD_CHUNK_SIZE must be greater than 0.';
            }
            if (FILECARTON_UPLOAD_MAX_FILE_SIZE < FILECARTON_UPLOAD_CHUNK_SIZE) {
                $p[] = 'FILECARTON_UPLOAD_MAX_FILE_SIZE must be at least FILECARTON_UPLOAD_CHUNK_SIZE.';
            }
            
            $phpMaxPost = Platform::parsePhpSize(ini_get('post_max_size') ?: '0');
            $postLabel  = self::formatIniLimit('post_max_size', $phpMaxPost);
            $nginxNote  = 'Note: If you are using nginx, you likely need to also increase client_max_body_size in your nginx configuration, which cannot be detected by this self-check.';

            $requiredPostSize = max(FILECARTON_UPLOAD_CHUNK_SIZE, FILECARTON_MAX_EDIT_SIZE);
            if ($phpMaxPost > 0 && $requiredPostSize > $phpMaxPost) {
                $p[] = 'post_max_size (' . $postLabel . ') is too small. '
                    . 'post_max_size must be at least ' . self::mb($requiredPostSize) . ' MB '
                    . $nginxNote;
            }
        }
        if (FILECARTON_MAX_EDIT_SIZE <= 0) {
            $p[] = 'FILECARTON_MAX_EDIT_SIZE must be greater than 0.';
        }

        // Vite manifest (skip in dev mode)
        $isDevMode = defined('FILECARTON_DEV_SERVER') && FILECARTON_DEV_SERVER;
        if (!$isDevMode) {
            if (defined('FILECARTON_SINGLE_FILE') && \FILECARTON_SINGLE_FILE) {
                $manifest = defined('FILECARTON_MANIFEST') ? \FILECARTON_MANIFEST : null;
                if ($manifest === null || !isset($manifest['src/main.ts'])) {
                    $p[] = 'Vite manifest is missing or invalid (single-file mode).';
                }
            } else {
                $path = FILECARTON_SCRIPT_DIR . '/public/.vite/manifest.json';
                if (!is_file($path)) {
                    error_log('FC(startup): Vite manifest not found at ' . $path);
                    $p[] = 'Frontend assets not found. Is the frontend built? Check server logs for details.';
                } else {
                    $m = json_decode(file_get_contents($path), true);
                    if (!isset($m['src/main.ts'])) {
                        $p[] = 'Vite manifest exists but is missing the src/main.ts entry.';
                    }
                }
            }
        }

        // ROOT_PATH — use the merged per-user view from RepoSetting when logged in.
        // Skip on the login page (grant overlays resolve after login).
        $isLoginPage = false;
        $readonly = false;
        if (class_exists(__NAMESPACE__ . '\\Auth', false) && !RepoSetting::embed()) {
            try {
                $isLoginPage = Auth::identity() === null;
            } catch (\Throwable $e) {
                $p[] = $e->getMessage();
                $isLoginPage = true;
            }
        }
        if (!$isLoginPage) {
            $current = RepoSetting::current();
            $rootPath = $current->rootPath ?? '';
            $readonly = (bool)($current->readonly ?? false);
            if ($rootPath === '' && class_exists(__NAMESPACE__ . '\\Auth', false)
                && !RepoSetting::embed() && Auth::hasImplicit()) {
                $p[] = 'Root directory is not available for the current identity. '
                    . 'Check root_path in your configuration or per-user grant settings.';
            } elseif ($rootPath !== '') {
                if (!is_dir($rootPath)) {
                    error_log('FC(startup): Root directory is not a directory: ' . $rootPath);
                    $p[] = 'Root directory is invalid. Check server logs for details.';
                } elseif (!is_readable($rootPath)) {
                    error_log('FC(startup): Root directory is not readable: ' . $rootPath);
                    $p[] = 'Root directory is not readable. Check server logs for details.';
                }
            }
        }

        // Temp dir is created only by upload, archive extract, and remote download.
        // Those routes are rejected for a read-only grant, and list/tree cleanup
        // returns without creating the directory when it is absent.
        if (!$mustReadonly && !$readonly && !$isLoginPage) {
            $tempDir = RepoSetting::current()->resolvedTempDir();
            if ($tempDir !== '') {
                if (is_dir($tempDir)) {
                    if (!is_writable($tempDir)) {
                        error_log('FC(startup): Temporary directory is not writable: ' . $tempDir);
                        $p[] = 'Temporary directory is not writable. Configure temp_dir in [repo] or check server logs.';
                    }
                } else {
                    $parent = dirname($tempDir);
                    if (!is_dir($parent) || !is_writable($parent)) {
                        error_log('FC(startup): Cannot create temporary directory at: ' . $tempDir . ' (parent: ' . $parent . ')');
                        $p[] = 'Cannot create temporary directory. Configure temp_dir in [repo] or check server logs.';
                    }
                }
            }
        }

        // Session
        if (session_status() === PHP_SESSION_DISABLED) {
            $p[] = 'PHP sessions are disabled.';
        }

        // Session name
        $sessionName = FILECARTON_SESSION_NAME;
        if (!is_string($sessionName) || !preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $sessionName)) {
            $p[] = 'FILECARTON_SESSION_NAME must start with a letter and contain only letters and digits.';
        }

        return $p;
    }

    private static function mb(int $bytes): string {
        return number_format($bytes / 1048576, 2);
    }

    private static function kb(int $bytes): string {
        return (string)(int)round($bytes / 1024);
    }

    /** Human-readable php.ini size, or "unlimited" when parsed as 0. */
    private static function formatIniLimit(string $directive, int $parsed): string {
        if ($parsed <= 0) {
            return 'unlimited';
        }
        $raw = trim((string)(ini_get($directive) ?: ''));
        return $raw !== '' ? $raw : (self::mb($parsed) . ' MB');
    }
}
