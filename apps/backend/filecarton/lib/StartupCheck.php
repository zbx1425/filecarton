<?php

namespace FileCarton;

class StartupCheck {
    /**
     * Extra bytes so a multipart POST of a given payload still fits post_max_size.
     *
     * RFC 2046 caps the boundary at 70 chars. FileCarton sends a few parts
     * (path + content for writes; uploadId/chunkIndex/totalChunks + chunk
     * for uploads). Wrapping is ~1–2 KB even with a long filename. The
     * dominant extra is a long `path` value (Windows long paths ~32 KB).
     * 64 KB covers wrapping + a long path. File bytes are not percent-encoded.
     */
    private const MULTIPART_HEADROOM = 65536;

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
            $uploadLabel  = self::formatIniLimit('upload_max_filesize', $phpMaxUpload);
            $postLabel    = self::formatIniLimit('post_max_size', $phpMaxPost);
            $nginxNote    = 'Note: If you are using nginx, you likely need to also increase client_max_body_size in your nginx configuration, which cannot be detected by this self-check.';

            $chunkProblem = self::multipartSizeProblem(
                'FILECARTON_UPLOAD_CHUNK_SIZE',
                FILECARTON_UPLOAD_CHUNK_SIZE,
                $phpMaxUpload,
                $phpMaxPost,
                $uploadLabel,
                $postLabel,
                $nginxNote
            );
            if ($chunkProblem !== null) {
                $p[] = $chunkProblem;
            }

            $editProblem = self::multipartSizeProblem(
                'FILECARTON_MAX_EDIT_SIZE',
                FILECARTON_MAX_EDIT_SIZE,
                $phpMaxUpload,
                $phpMaxPost,
                $uploadLabel,
                $postLabel,
                $nginxNote
            );
            if ($editProblem !== null) {
                $p[] = $editProblem;
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

    /**
     * Payload must fit upload_max_filesize; payload plus multipart headroom
     * must fit post_max_size. Returns a problem string, or null if OK.
     */
    private static function multipartSizeProblem(
        string $constantName,
        int $payloadBytes,
        int $phpMaxUpload,
        int $phpMaxPost,
        string $uploadLabel,
        string $postLabel,
        string $nginxNote
    ): ?string {
        $needPost = $payloadBytes + self::MULTIPART_HEADROOM;
        $exceedsUpload = $phpMaxUpload > 0 && $payloadBytes > $phpMaxUpload;
        $exceedsPost = $phpMaxPost > 0 && $needPost > $phpMaxPost;
        if (!$exceedsUpload && !$exceedsPost) {
            return null;
        }
        return $constantName . ' ('
            . self::mb($payloadBytes) . ' MB) exceeds the PHP upload limit '
            . '(payload must fit upload_max_filesize; payload plus '
            . self::kb(self::MULTIPART_HEADROOM) . ' KB multipart headroom must fit post_max_size). '
            . 'upload_max_filesize is ' . $uploadLabel
            . '; post_max_size is ' . $postLabel . '. '
            . 'Adjust these in php.ini, or lower ' . $constantName . '. '
            . $nginxNote;
    }

    private static function mb(int $bytes): string {
        return number_format($bytes / 1048576, 1);
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
