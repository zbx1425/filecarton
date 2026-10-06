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
        $p = [];

        // PHP version
        if (PHP_VERSION_ID < 70100) {
            $p[] = 'PHP 7.1 or later is required (running ' . PHP_VERSION . ').';
        }

        // Required extensions
        foreach (['json', 'session', 'mbstring'] as $ext) {
            if (!extension_loaded($ext)) {
                $p[] = 'Required PHP extension missing: ' . $ext;
            }
        }
        if (!extension_loaded('zip') || !class_exists('ZipArchive')) {
            $p[] = 'Required PHP extension missing: zip (ZipArchive)';
        }
        if (!extension_loaded('phar') || !class_exists('PharData')) {
            $p[] = 'Required PHP extension missing: phar (PharData)';
        }
        if (!extension_loaded('zlib') || !function_exists('gzdecode')) {
            $p[] = 'Required PHP extension missing: zlib (gzdecode)';
        }

        // Dotfiles mutex
        if (FILECARTON_DOTFILES_BLOCK && FILECARTON_DOTFILES_FORCE_VISIBLE) {
            $p[] = 'FILECARTON_DOTFILES_BLOCK and FILECARTON_DOTFILES_FORCE_VISIBLE cannot both be enabled.';
        }

        // Size checks (only meaningful when writes are allowed)
        if (!FILECARTON_READONLY) {
            if (FILECARTON_UPLOAD_CHUNK_SIZE <= 0) {
                $p[] = 'FILECARTON_UPLOAD_CHUNK_SIZE must be greater than 0.';
            }
            if (FILECARTON_UPLOAD_MAX_FILE_SIZE < FILECARTON_UPLOAD_CHUNK_SIZE) {
                $p[] = 'FILECARTON_UPLOAD_MAX_FILE_SIZE must be at least FILECARTON_UPLOAD_CHUNK_SIZE.';
            }

            $phpMaxUpload = self::parsePhpSize(ini_get('upload_max_filesize') ?: '0');
            $phpMaxPost   = self::parsePhpSize(ini_get('post_max_size') ?: '0');
            $effective    = ($phpMaxPost > 0) ? min($phpMaxUpload, $phpMaxPost) : $phpMaxUpload;

            if ($effective > 0 && FILECARTON_UPLOAD_CHUNK_SIZE > $effective) {
                $p[] = 'FILECARTON_UPLOAD_CHUNK_SIZE ('
                    . self::mb(FILECARTON_UPLOAD_CHUNK_SIZE) . ' MB) exceeds the PHP upload limit ('
                    . self::mb($effective) . ' MB). '
                    . 'Adjust upload_max_filesize / post_max_size in php.ini, or lower FILECARTON_UPLOAD_CHUNK_SIZE. '
                    . 'Note: If you are using nginx, you likely need to also increase client_max_body_size in your nginx configuration, '
                    . 'which cannot be detected by this self-check.';
            }
            if ($phpMaxPost > 0 && FILECARTON_MAX_EDIT_SIZE > $phpMaxPost) {
                $p[] = 'FILECARTON_MAX_EDIT_SIZE ('
                    . self::mb(FILECARTON_MAX_EDIT_SIZE) . ' MB) exceeds post_max_size ('
                    . self::mb($phpMaxPost) . ' MB).';
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
                    $p[] = 'Vite manifest not found at ' . $path
                        . '. Is the frontend built? Set FILECARTON_DEV_SERVER for development.';
                } else {
                    $m = json_decode(file_get_contents($path), true);
                    if (!isset($m['src/main.ts'])) {
                        $p[] = 'Vite manifest exists but is missing the src/main.ts entry.';
                    }
                }
            }
        }

        // ROOT_PATH (empty string is handled by dispatch() before we get here)
        $rootPath = FILECARTON_ROOT_PATH;
        if ($rootPath !== '') {
            if (!is_dir($rootPath)) {
                $p[] = 'FILECARTON_ROOT_PATH is not a directory: ' . $rootPath;
            } elseif (!is_readable($rootPath)) {
                $p[] = 'FILECARTON_ROOT_PATH is not readable: ' . $rootPath;
            } elseif (!FILECARTON_READONLY && !is_writable($rootPath)) {
                $p[] = 'FILECARTON_ROOT_PATH is not writable (required unless FILECARTON_READONLY is true): ' . $rootPath;
            }
        }

        // Temp dir (chunked uploads need a writable tmp)
        if (!FILECARTON_READONLY) {
            $tmp = sys_get_temp_dir();
            if (!is_dir($tmp) || !is_writable($tmp)) {
                $p[] = 'Temporary directory is not writable: ' . $tmp . ' (needed for chunked uploads).';
            }
        }

        // Session
        if (session_status() === PHP_SESSION_DISABLED) {
            $p[] = 'PHP sessions are disabled.';
        } else {
            $savePath = session_save_path() ?: sys_get_temp_dir();
            if (!is_writable($savePath)) {
                $p[] = 'Session save path is not writable: ' . $savePath;
            }
        }

        return $p;
    }

    private static function parsePhpSize(string $size): int {
        $size = trim($size);
        if ($size === '' || $size === '0') return 0;
        $unit  = strtolower(substr($size, -1));
        $value = (int)$size;
        switch ($unit) {
            case 'g': return $value * 1073741824;
            case 'm': return $value * 1048576;
            case 'k': return $value * 1024;
            default:  return $value;
        }
    }

    private static function mb(int $bytes): string {
        return number_format($bytes / 1048576, 1);
    }
}
