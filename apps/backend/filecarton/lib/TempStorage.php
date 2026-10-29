<?php

namespace FileCarton;

/**
 * Centralized temporary file/directory management.
 *
 * All temp artifacts are stored under a single base directory (default: {root}/__fc_tmp/).
 * Supports on-demand creation, file locking for active-operation detection,
 * activity-timestamp tracking for multi-request operations (TUS uploads),
 * and automatic stale cleanup with empty-directory removal.
 */
class TempStorage {

    /** @var string Resolved absolute path of the temp base directory */
    private $baseDir;

    public function __construct(string $resolvedTempDir) {
        $this->baseDir = rtrim(str_replace('\\', '/', $resolvedTempDir), '/');
    }

    public function getBasePath(): string {
        return $this->baseDir;
    }

    // ------------------------------------------------------------------
    // Directory lifecycle
    // ------------------------------------------------------------------

    /**
     * Ensure the base temp directory exists. Creates it on demand.
     * @throws \RuntimeException if the directory cannot be created
     */
    private function ensureDir(): void {
        if (is_dir($this->baseDir)) return;
        if (!@mkdir($this->baseDir, 0755, true) && !is_dir($this->baseDir)) {
            throw new \RuntimeException('Cannot create temp directory: ' . $this->baseDir);
        }
    }

    /**
     * Remove the base temp directory if it is empty.
     */
    private function removeIfEmpty(): void {
        if (!is_dir($this->baseDir)) return;
        $entries = @scandir($this->baseDir);
        if ($entries === false) return;
        $hasChildren = false;
        foreach ($entries as $e) {
            if ($e !== '.' && $e !== '..') {
                $hasChildren = true;
                break;
            }
        }
        if (!$hasChildren) {
            @rmdir($this->baseDir);
        }
    }

    // ------------------------------------------------------------------
    // Temp file / directory creation
    // ------------------------------------------------------------------

    /**
     * Create a uniquely-named temporary file under the base dir.
     * Returns the absolute path. The file is created empty.
     */
    public function createTempFile(string $prefix, string $suffix = '.tmp'): string {
        $this->ensureDir();
        $name = $prefix . '_' . bin2hex(random_bytes(6)) . $suffix;
        $path = $this->baseDir . '/' . $name;
        if (@file_put_contents($path, '') === false) {
            throw new \RuntimeException('Cannot create temp file: ' . $path);
        }
        return $path;
    }

    /**
     * Create a uniquely-named temporary directory under the base dir.
     * Returns the absolute path.
     */
    public function createTempDir(string $prefix): string {
        $this->ensureDir();
        $name = $prefix . '_' . bin2hex(random_bytes(6));
        $path = $this->baseDir . '/' . $name;
        if (!@mkdir($path, 0755, true) && !is_dir($path)) {
            throw new \RuntimeException('Cannot create temp directory: ' . $path);
        }
        return $path;
    }

    // ------------------------------------------------------------------
    // Locking (for single-request operations)
    // ------------------------------------------------------------------

    /**
     * Acquire an exclusive lock on a temp path by creating a .lock companion file.
     * Returns the lock file handle. Caller MUST keep this handle open for the
     * duration of the operation and fclose() it when done.
     *
     * @return resource Lock file handle
     */
    public function acquireLock(string $tempPath) {
        $lockPath = $tempPath . '.lock';
        $fp = @fopen($lockPath, 'c');
        if ($fp === false) {
            throw new \RuntimeException('Cannot create lock file: ' . $lockPath);
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            throw new \RuntimeException('Cannot acquire lock: ' . $lockPath);
        }
        return $fp;
    }

    // ------------------------------------------------------------------
    // Activity tracking (for multi-request operations like TUS)
    // ------------------------------------------------------------------

    /**
     * Touch the .activity file inside a temp directory to record recent use.
     */
    public function touchActivity(string $tempDirPath): void {
        $activityFile = rtrim($tempDirPath, '/') . '/.activity';
        @touch($activityFile);
    }

    // ------------------------------------------------------------------
    // Cleanup
    // ------------------------------------------------------------------

    /**
     * Remove stale temporary files and directories.
     *
     * For files: attempts flock(LOCK_EX|LOCK_NB) on the .lock companion;
     *   if lock succeeds (no active holder) AND mtime is older than $expiry → delete.
     * For directories: checks .activity mtime; if older than $expiry → delete.
     *
     * After cleanup, removes the base directory if empty.
     *
     * @param int $expiry Maximum age in seconds before an unlocked temp item is considered stale.
     */
    public function cleanup(int $expiry): void {
        if (!is_dir($this->baseDir)) return;

        $entries = @scandir($this->baseDir);
        if ($entries === false) return;

        $now = time();

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_ends_with($entry, '.lock')) continue;

            $fullPath = $this->baseDir . '/' . $entry;

            if (is_dir($fullPath)) {
                $this->cleanupDir($fullPath, $expiry, $now);
            } elseif (is_file($fullPath)) {
                $this->cleanupFile($fullPath, $expiry, $now);
            }
        }

        $this->removeIfEmpty();
    }

    private function cleanupFile(string $filePath, int $expiry, int $now): void {
        $mtime = @filemtime($filePath);
        if ($mtime === false || ($now - $mtime) < $expiry) return;

        $lockPath = $filePath . '.lock';
        if (is_file($lockPath)) {
            $fp = @fopen($lockPath, 'c');
            if ($fp === false) return;
            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                fclose($fp);
                return;
            }
            flock($fp, LOCK_UN);
            fclose($fp);
            @unlink($lockPath);
        }

        @unlink($filePath);
    }

    private function cleanupDir(string $dirPath, int $expiry, int $now): void {
        $activityFile = $dirPath . '/.activity';
        if (is_file($activityFile)) {
            $mtime = @filemtime($activityFile);
            if ($mtime !== false && ($now - $mtime) < $expiry) return;
        } else {
            $mtime = @filemtime($dirPath);
            if ($mtime !== false && ($now - $mtime) < $expiry) return;
        }

        $lockPath = $dirPath . '/.lock';
        if (is_file($lockPath)) {
            $fp = @fopen($lockPath, 'c');
            if ($fp === false) return;
            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                fclose($fp);
                return;
            }
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        Platform::deleteRecursive($dirPath);
    }
}
