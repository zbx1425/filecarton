<?php

namespace FileCarton;

class PathSecurity {
    /** @var string */
    private $rootPath;

    public function __construct(string $rootPath) {
        $real = realpath($rootPath);
        if ($real === false) {
            throw new \RuntimeException('Root path does not exist: ' . $rootPath);
        }
        $this->rootPath = rtrim(str_replace('\\', '/', $real), '/');
    }

    public function getRootPath(): string {
        return $this->rootPath;
    }

    /**
     * Resolve a user-supplied relative path to a safe absolute path.
     * Throws on traversal, null bytes, symlink escape, or non-existent target.
     */
    public function resolve(string $relativePath): string {
        $this->rejectNullBytes($relativePath);

        $relativePath = str_replace('\\', '/', $relativePath);
        $relativePath = ltrim($relativePath, '/');

        if ($relativePath === '') {
            return $this->rootPath;
        }

        $target = $this->rootPath . '/' . $relativePath;
        $resolved = realpath($target);
        if ($resolved === false) {
            throw new \RuntimeException('Path not found: ' . $relativePath, 404);
        }

        $resolved = str_replace('\\', '/', $resolved);
        $this->assertWithinRoot($resolved);
        return $resolved;
    }

    /**
     * Resolve a relative directory path, creating missing intermediate
     * directories as needed. Existing segments are verified via realpath()
     * to catch symlink traversal. New segments are validated then mkdir'd.
     * Final realpath() + assertWithinRoot() provides a closing security check.
     */
    public function resolveOrCreate(string $relativePath): string {
        $this->rejectNullBytes($relativePath);
        $relativePath = str_replace('\\', '/', $relativePath);
        $relativePath = ltrim($relativePath, '/');
        if ($relativePath === '') return $this->rootPath;

        $parts = explode('/', $relativePath);
        if (count($parts) > FILECARTON_UPLOAD_MAX_DEPTH) {
            throw new \RuntimeException('Path too deep (max ' . FILECARTON_UPLOAD_MAX_DEPTH . ' levels)', 400);
        }

        $current = $this->rootPath;

        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new \InvalidArgumentException('Invalid path component');
            }
            if (!$this->isValidFileName($part)) {
                throw new \InvalidArgumentException('Invalid character in path component: ' . $part);
            }

            $next = $current . '/' . $part;

            if (is_dir($next)) {
                $resolved = realpath($next);
                if ($resolved === false) {
                    throw new \RuntimeException('Path resolution failed: ' . $part);
                }
                $resolved = str_replace('\\', '/', $resolved);
                $this->assertWithinRoot($resolved);
                $current = $resolved;
            } elseif (file_exists($next) || is_link($next)) {
                throw new \RuntimeException('Path component is not a directory: ' . $part, 409);
            } else {
                if (!mkdir($next, 0755)) {
                    throw new \RuntimeException('Failed to create directory: ' . $part, 500);
                }
                $current = $next;
            }
        }

        $final = realpath($current);
        if ($final === false) {
            throw new \RuntimeException('Final path resolution failed');
        }
        $final = str_replace('\\', '/', $final);
        $this->assertWithinRoot($final);
        return $final;
    }

    /**
     * Resolve for create operations where the target doesn't exist yet.
     * Validates that the parent directory exists and is within root,
     * then appends the sanitized basename.
     */
    public function resolveParent(string $relativePath): string {
        $this->rejectNullBytes($relativePath);

        $relativePath = str_replace('\\', '/', $relativePath);
        $relativePath = ltrim($relativePath, '/');

        $dirname = dirname($relativePath);
        $basename = basename($relativePath);

        if ($basename === '' || $basename === '.' || $basename === '..') {
            throw new \InvalidArgumentException('Invalid target name');
        }

        if (!$this->isValidFileName($basename)) {
            throw new \InvalidArgumentException('Invalid characters in name: ' . $basename);
        }

        $parentAbs = ($dirname === '.' || $dirname === '')
            ? $this->rootPath
            : $this->resolve($dirname);

        if (!is_dir($parentAbs)) {
            throw new \RuntimeException('Parent directory not found', 404);
        }

        $result = $parentAbs . '/' . $basename;
        $this->assertWithinRoot($result);
        return $result;
    }

    /**
     * Assert that an absolute path is within the root directory.
     */
    public function assertWithinRoot(string $absPath): void {
        $normalized = str_replace('\\', '/', $absPath);
        if ($normalized !== $this->rootPath && !str_starts_with($normalized, $this->rootPath . '/')) {
            throw new \RuntimeException('Path traversal denied', 403);
        }
    }

    /**
     * Remove dangerous characters from a filename, keeping it usable.
     */
    public function sanitizeFileName(string $name): string {
        $name = str_replace("\0", '', $name);
        $name = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '', $name);
        $name = rtrim($name, '. ');
        if ($name === '' || $name === '.' || $name === '..') {
            throw new \InvalidArgumentException('Invalid filename');
        }
        return $name;
    }

    /**
     * Check whether a filename is valid for creation (no dangerous characters, not empty).
     */
    public function isValidFileName(string $name): bool {
        if ($name === '' || $name === '.' || $name === '..') return false;
        if (str_contains($name, "\0")) return false;
        if (preg_match('#[/\\\\:*?"<>|]#', $name)) return false;
        if (trim($name, '. ') === '') return false;
        return true;
    }

    /**
     * Safely resolve an item name within a known-good base directory.
     * Unlike sanitizeFileName, this does NOT strip characters — it validates
     * that the name is a single path segment (no traversal), then returns the
     * canonical absolute path if the item exists, or the logical path if not.
     *
     * Use this for referencing existing files by user-supplied name.
     */
    public function resolveItemIn(string $baseAbs, string $itemName): string {
        if ($itemName === '' || $itemName === '.' || $itemName === '..') {
            throw new \InvalidArgumentException('Invalid item name');
        }
        if (str_contains($itemName, "\0") || str_contains($itemName, '/') || str_contains($itemName, '\\')) {
            throw new \InvalidArgumentException('Invalid item name: contains path separators');
        }

        $target = $baseAbs . '/' . $itemName;

        if (file_exists($target) || is_link($target)) {
            $real = realpath($target);
            if ($real !== false) {
                $real = str_replace('\\', '/', $real);
                $this->assertWithinRoot($real);
                return $real;
            }
        }

        $this->assertWithinRoot(str_replace('\\', '/', $target));
        return str_replace('\\', '/', $target);
    }

    /**
     * Normalize a path logically (resolve . and .. segments) without requiring existence.
     * Used for zip slip detection on archive entry paths.
     */
    public function normalizePath(string $path): string {
        $path = str_replace('\\', '/', $path);
        $parts = explode('/', $path);
        $result = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') {
                array_pop($result);
            } else {
                $result[] = $part;
            }
        }
        return implode('/', $result);
    }

    private function rejectNullBytes(string $path): void {
        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Invalid path: null byte detected');
        }
    }
}
