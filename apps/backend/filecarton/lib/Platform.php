<?php

namespace FileCarton;

/**
 * FileCarton Platform Utilities
 *
 * Self-contained filesystem operations with cross-platform support.
 * On Linux, uses native shell commands (rm -rf, cp -r) for performance
 * when exec() is available. Falls back to pure PHP on Windows or when
 * exec() is disabled.
 */

class Platform {

    private static ?bool $canExec = null;
    private static ?bool $isWindows = null;

    /**
     * Recursively delete a file, symlink, or directory and all its contents.
     * Returns true if the path no longer exists after the operation.
     */
    public static function deleteRecursive(string $path): bool {
        if (!file_exists($path) && !is_link($path)) {
            return true;
        }

        if (self::useNativeCommands()) {
            return self::nativeDelete($path);
        }

        return self::phpDelete($path);
    }

    /**
     * Recursively copy a directory's contents to a destination.
     * Creates the destination directory if it doesn't exist.
     * Returns true on success.
     */
    public static function copyRecursive(string $source, string $dest): bool {
        if (!is_dir($source)) {
            return false;
        }

        if (self::useNativeCommands()) {
            return self::nativeCopy($source, $dest);
        }

        return self::phpCopy($source, $dest);
    }

    // ------------------------------------------------------------------
    // Native (Linux) implementations
    // ------------------------------------------------------------------

    private static function nativeDelete(string $path): bool {
        $escaped = escapeshellarg($path);
        $output = [];
        $exitCode = -1;
        @exec('rm -rf ' . $escaped . ' 2>/dev/null', $output, $exitCode);

        if ($exitCode === 0) {
            return true;
        }

        return self::phpDelete($path);
    }

    private static function nativeCopy(string $source, string $dest): bool {
        if (!is_dir($dest)) {
            if (!@mkdir($dest, 0755, true) && !is_dir($dest)) {
                return false;
            }
        }

        $cpSource = escapeshellarg(rtrim($source, '/') . '/.');
        $cpDest = escapeshellarg($dest);
        $output = [];
        $exitCode = -1;
        @exec('cp -rfp ' . $cpSource . ' ' . $cpDest . ' 2>/dev/null', $output, $exitCode);

        if ($exitCode === 0) {
            return true;
        }

        return self::phpCopy($source, $dest);
    }

    // ------------------------------------------------------------------
    // Pure PHP implementations (fallback, always available)
    // ------------------------------------------------------------------

    private static function phpDelete(string $path): bool {
        if (is_link($path) || !is_dir($path)) {
            return @unlink($path);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::CURRENT_AS_FILEINFO),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $itemPath = $item->getPathname();
            if (is_link($itemPath) || !$item->isDir()) {
                if (!@unlink($itemPath)) {
                    return false;
                }
            } else {
                if (!@rmdir($itemPath)) {
                    return false;
                }
            }
        }

        return @rmdir($path);
    }

    private static function phpCopy(string $source, string $dest): bool {
        if (!is_dir($dest)) {
            if (!@mkdir($dest, 0755, true) && !is_dir($dest)) {
                return false;
            }
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $sourceLen = strlen(rtrim(str_replace('\\', '/', $source), '/')) + 1;

        foreach ($iterator as $item) {
            $itemPath = $item->getPathname();

            if (is_link($itemPath)) {
                continue;
            }

            $relativePath = substr(str_replace('\\', '/', $itemPath), $sourceLen);
            $targetPath = $dest . '/' . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    if (!@mkdir($targetPath, 0755, true) && !is_dir($targetPath)) {
                        return false;
                    }
                }
            } else {
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    if (!@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                        return false;
                    }
                }
                if (!@copy($itemPath, $targetPath)) {
                    return false;
                }
            }
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Detection
    // ------------------------------------------------------------------

    private static function useNativeCommands(): bool {
        if (self::$isWindows === null) {
            self::$isWindows = (PHP_OS_FAMILY === 'Windows');
        }

        if (self::$isWindows) {
            return false;
        }

        if (self::$canExec === null) {
            self::$canExec = self::detectExec();
        }

        return self::$canExec;
    }

    private static function detectExec(): bool {
        if (!function_exists('exec')) {
            return false;
        }

        $disabled = ini_get('disable_functions');
        if ($disabled !== false && $disabled !== '') {
            $list = array_map('trim', explode(',', strtolower($disabled)));
            if (in_array('exec', $list, true)) {
                return false;
            }
        }

        return true;
    }
}
