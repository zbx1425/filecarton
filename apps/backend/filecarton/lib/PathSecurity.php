<?php

namespace FileCarton;

class PathSecurity {
    /** @var string */
    private $rootPath;

    /** @var string[] Resolved absolute ignore paths */
    private $ignorePaths = [];

    /** @var string[] Compiled regex patterns from IGNORE_PATTERN */
    private $ignoreRegexes = [];

    /** @var array[] Pattern metadata for hasProtectedDescendants optimization */
    private $ignorePatternMeta = [];

    /** @var bool Whether any pattern requires filesystem scanning */
    private $hasDeepPatterns = false;

    /** @var bool Whether the filesystem is case-insensitive (Windows) */
    private $caseInsensitive = false;

    /** @var bool Whether any extension allow/block list is configured */
    private $hasExtensionRules = false;

    public function __construct(string $rootPath) {
        $real = realpath($rootPath);
        if ($real === false) {
            throw new \RuntimeException('Root path does not exist: ' . $rootPath);
        }
        $this->rootPath = rtrim(str_replace('\\', '/', $real), '/');
        $this->caseInsensitive = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        $this->hasExtensionRules = (defined('FILECARTON_EXTENSIONS_ALLOWLIST') && !empty(FILECARTON_EXTENSIONS_ALLOWLIST))
            || (defined('FILECARTON_EXTENSIONS_BLOCKLIST') && !empty(FILECARTON_EXTENSIONS_BLOCKLIST));

        if (defined('FILECARTON_IGNORE_REALPATH') && is_array(FILECARTON_IGNORE_REALPATH)) {
            foreach (FILECARTON_IGNORE_REALPATH as $path) {
                $resolved = realpath($path);
                if ($resolved !== false) {
                    $this->ignorePaths[] = str_replace('\\', '/', $resolved);
                } else {
                    $parent = realpath(dirname($path));
                    if ($parent !== false) {
                        $this->ignorePaths[] = str_replace('\\', '/', $parent) . '/' . basename($path);
                    } else {
                        $this->ignorePaths[] = rtrim(str_replace('\\', '/', $path), '/');
                    }
                }
            }
        }

        if (class_exists(__NAMESPACE__ . '\\Auth', false)) {
            foreach (Auth::pluginIgnorePaths() as $pluginPath) {
                $this->ignorePaths[] = $pluginPath;
            }
        }

        if (defined('FILECARTON_IGNORE_PATTERN') && is_array(FILECARTON_IGNORE_PATTERN)) {
            foreach (FILECARTON_IGNORE_PATTERN as $pattern) {
                $this->addIgnorePattern($pattern);
            }
        }

        if (FILECARTON_DOTFILES_BLOCK) {
            $this->addIgnorePattern('.*');
        }
    }

    private function addIgnorePattern(string $pattern): void {
        $compiled = $this->patternToRegex($pattern);
        if ($compiled !== null) {
            $this->ignoreRegexes[] = $compiled['regex'];
            $this->ignorePatternMeta[] = $compiled;
            if ($compiled['needsScan']) {
                $this->hasDeepPatterns = true;
            }
        }
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

            if ($this->wouldBeIgnored($next, true)) {
                throw new \RuntimeException('Access denied', 403);
            }

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
        if (str_ends_with($name, '.') || str_ends_with($name, ' ')) return false;
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

    // ------------------------------------------------------------------
    // Dotfile checks
    // ------------------------------------------------------------------

    /**
     * Check if a basename starts with a dot (dotfile / hidden file).
     * Used by frontend config and display logic; security enforcement
     * is handled via the implicit '.*' ignore pattern when DOTFILES_BLOCK is on.
     */
    public function isDotFile(string $basename): bool {
        return $basename !== '' && $basename[0] === '.' && $basename !== '.' && $basename !== '..';
    }

    // ------------------------------------------------------------------
    // Extension checks
    // ------------------------------------------------------------------

    /**
     * Get the normalized extension key for a filename.
     *   'file.PHP'   => '.php'
     *   'Makefile'   => ''
     *   'file.'      => '.'
     */
    public function getExtensionKey(string $basename): string {
        if (str_ends_with($basename, '.')) return '.';
        $ext = pathinfo($basename, PATHINFO_EXTENSION);
        return $ext !== '' ? '.' . strtolower($ext) : '';
    }

    /**
     * Check whether a file extension is blocked by the configured allow/block lists.
     * Directories are never blocked by extension.
     */
    public function isExtensionBlocked(string $basename): bool {
        $allowList = defined('FILECARTON_EXTENSIONS_ALLOWLIST') ? FILECARTON_EXTENSIONS_ALLOWLIST : [];
        $blockList = defined('FILECARTON_EXTENSIONS_BLOCKLIST') ? FILECARTON_EXTENSIONS_BLOCKLIST : [];

        if (empty($allowList) && empty($blockList)) return false;

        $key = $this->getExtensionKey($basename);

        if (!empty($allowList)) {
            return !in_array(strtolower($key), array_map('strtolower', $allowList), true);
        }
        return in_array(strtolower($key), array_map('strtolower', $blockList), true);
    }

    // ------------------------------------------------------------------
    // Ignore checks
    // ------------------------------------------------------------------

    /**
     * Check if an absolute path is ignored by any configured rule.
     * Matches exact paths and prefixes (directory content is also ignored).
     */
    public function isIgnored(string $absPath): bool {
        $normalized = str_replace('\\', '/', $absPath);

        foreach ($this->ignorePaths as $ignorePath) {
            if ($this->pathEquals($normalized, $ignorePath) || $this->pathStartsWith($normalized, $ignorePath . '/')) {
                return true;
            }
        }

        if (!empty($this->ignoreRegexes)) {
            $relative = $this->getRelativePath($normalized);
            if ($relative !== null) {
                $isDir = is_dir($absPath);
                foreach ($this->ignoreRegexes as $regex) {
                    if (preg_match($regex, $relative . ($isDir ? '/' : ''))) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if a relative path (which may not yet exist on disk) would be
     * ignored, treating it as the given type. Used for creation and
     * extraction checks where the target does not exist yet.
     */
    public function wouldBeIgnored(string $absPath, bool $isDir): bool {
        $normalized = str_replace('\\', '/', $absPath);

        foreach ($this->ignorePaths as $ignorePath) {
            if ($this->pathEquals($normalized, $ignorePath) || $this->pathStartsWith($normalized, $ignorePath . '/')) {
                return true;
            }
        }

        if (!empty($this->ignoreRegexes)) {
            $relative = $this->getRelativePath($normalized);
            if ($relative !== null) {
                foreach ($this->ignoreRegexes as $regex) {
                    if (preg_match($regex, $relative . ($isDir ? '/' : ''))) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Get the path relative to rootPath, or null if outside root.
     */
    public function getRelativePath(string $absPath): ?string {
        $normalized = str_replace('\\', '/', $absPath);
        if ($normalized === $this->rootPath) return '';
        if (str_starts_with($normalized, $this->rootPath . '/')) {
            return substr($normalized, strlen($this->rootPath) + 1);
        }
        return null;
    }

    /**
     * Convert a simplified gitignore pattern to a regex.
     * Supports: * (non-slash wildcard), ** (zero or more directories),
     * ? (single non-slash char), leading / (root anchor),
     * trailing / (directory-only match).
     *
     * Returns null for empty/comment patterns, or an array:
     *   'regex'    => compiled regex string
     *   'needsScan' => bool, whether hasProtectedDescendants needs filesystem scan
     *   'segments' => array of original segments (only when needsScan=false)
     */
    private function patternToRegex(string $pattern): ?array {
        $pattern = trim($pattern);
        if ($pattern === '' || $pattern[0] === '#') return null;

        $anchored = str_starts_with($pattern, '/');
        if ($anchored) $pattern = substr($pattern, 1);

        $dirOnly = str_ends_with($pattern, '/');
        if ($dirOnly) $pattern = rtrim($pattern, '/');

        if ($pattern === '') return null;

        $segments = explode('/', $pattern);
        $hasGlobstar = in_array('**', $segments, true);

        $inner = '';
        $prevWasGlobstar = false;
        $emittedAnything = false;

        foreach ($segments as $seg) {
            if ($seg === '**') {
                $prevWasGlobstar = true;
                continue;
            }

            $escaped = preg_quote($seg, '#');
            $escaped = str_replace('\\*', '[^/]*', $escaped);
            $escaped = str_replace('\\?', '[^/]', $escaped);

            if ($prevWasGlobstar) {
                if ($emittedAnything) {
                    $inner .= '(?:/[^/]+)*/';
                } else {
                    $inner .= '(?:[^/]+/)*';
                }
                $prevWasGlobstar = false;
            } elseif ($emittedAnything) {
                $inner .= '/';
            }

            $inner .= $escaped;
            $emittedAnything = true;
        }

        if ($prevWasGlobstar) {
            if ($emittedAnything) {
                $inner .= '(?:/.*)?';
            } else {
                $inner = '.*';
            }
        }

        if ($anchored) {
            $regex = '^' . $inner;
        } else {
            $regex = '(?:^|/)' . $inner;
        }

        if ($dirOnly) {
            $regex .= '/';
        } else {
            $regex .= '(?:$|/)';
        }

        $needsScan = !$anchored || $hasGlobstar;
        $flags = $this->caseInsensitive ? 'i' : '';

        return [
            'regex'     => '#' . $regex . '#' . $flags,
            'needsScan' => $needsScan,
            'segments'  => $needsScan ? null : $segments,
        ];
    }

    // ------------------------------------------------------------------
    // Composite validation helpers
    // ------------------------------------------------------------------

    /**
     * Validate that an existing path can be accessed (not ignored).
     */
    public function assertNotIgnored(string $absPath): void {
        if ($this->isIgnored($absPath)) {
            throw new \RuntimeException('Path not found', 404);
        }
    }

    /**
     * Validate that a file can be modified (not ignored, extension not blocked).
     * For write/edit operations on existing files.
     */
    public function assertCanModify(string $absPath): void {
        $this->assertNotIgnored($absPath);
        $basename = basename($absPath);
        if (is_file($absPath) && $this->isExtensionBlocked($basename)) {
            throw new \RuntimeException('File type is restricted', 403);
        }
    }

    /**
     * Filter a list of directory entries, removing ignored entries.
     * When DOTFILES_BLOCK is enabled, dotfiles are caught by the implicit
     * '.*' ignore pattern. Each entry must have a 'name' key.
     */
    public function filterEntries(array $entries, string $parentAbsPath): array {
        return array_values(array_filter($entries, function ($entry) use ($parentAbsPath) {
            $childAbs = $parentAbsPath . '/' . $entry['name'];
            if ($this->isIgnored($childAbs)) {
                return false;
            }
            if (is_link($childAbs)) {
                $resolved = realpath($childAbs);
                if ($resolved !== false && $this->isIgnored(str_replace('\\', '/', $resolved))) {
                    return false;
                }
            }
            return true;
        }));
    }

    // ------------------------------------------------------------------
    // Directory descendant protection
    // ------------------------------------------------------------------

    /**
     * Check if a directory contains any protected (ignored) descendants.
     * Uses a tiered approach: quick prefix checks first, filesystem scan only when needed.
     */
    public function hasProtectedDescendants(string $dirPath): bool {
        $normalized = str_replace('\\', '/', $dirPath);

        foreach ($this->ignorePaths as $ip) {
            if ($this->pathStartsWith($ip, $normalized . '/')) {
                return true;
            }
        }

        $dirRel = $this->getRelativePath($normalized);
        if ($dirRel === null) return false;

        foreach ($this->ignorePatternMeta as $meta) {
            if ($meta['needsScan']) {
                continue;
            }
            if ($this->patternCouldMatchInside($meta['segments'], $dirRel)) {
                return true;
            }
        }

        if (!$this->hasDeepPatterns) {
            return false;
        }

        return $this->scanForProtectedDescendant($dirPath);
    }

    /**
     * Check whether a root-anchored pattern (without **) could match
     * items inside the given directory. Verifies that matching files
     * actually exist via file_exists or narrow scandir for wildcard segments.
     */
    private function patternCouldMatchInside(array $patternSegments, string $dirRelPath): bool {
        if ($dirRelPath === '') {
            return $this->matchRemainingSegments($this->rootPath, $patternSegments);
        }

        $dirSegments = explode('/', trim($dirRelPath, '/'));

        for ($i = 0; $i < count($dirSegments); $i++) {
            if ($i >= count($patternSegments)) {
                return false;
            }
            if (!$this->segmentMatches($dirSegments[$i], $patternSegments[$i])) {
                return false;
            }
        }

        if (count($patternSegments) > count($dirSegments)) {
            $remaining = array_slice($patternSegments, count($dirSegments));
            $basePath = $this->rootPath . '/' . implode('/', $dirSegments);
            return $this->matchRemainingSegments($basePath, $remaining);
        }

        return true;
    }

    /**
     * Recursively verify that at least one filesystem entry matches the
     * remaining pattern segments beneath basePath. For literal segments
     * uses file_exists; for wildcard segments does a narrow scandir.
     * Fails closed (returns true) on scandir errors.
     */
    private function matchRemainingSegments(string $basePath, array $segments): bool {
        if (empty($segments)) return true;

        $segment = $segments[0];
        $rest = array_slice($segments, 1);
        $hasWild = strpos($segment, '*') !== false || strpos($segment, '?') !== false;

        if (!$hasWild) {
            $child = $basePath . '/' . $segment;
            if (!file_exists($child)) return false;
            return empty($rest) || $this->matchRemainingSegments($child, $rest);
        }

        $entries = @scandir($basePath);
        if ($entries === false) return true;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (!$this->segmentMatches($entry, $segment)) continue;
            if (empty($rest)) return true;
            $child = $basePath . '/' . $entry;
            if (is_dir($child) && $this->matchRemainingSegments($child, $rest)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a concrete path segment matches a pattern segment
     * (supports * and ? wildcards).
     */
    private function segmentMatches(string $actual, string $pattern): bool {
        if ($this->caseInsensitive ? strcasecmp($pattern, $actual) === 0 : $pattern === $actual) return true;
        $escaped = preg_quote($pattern, '#');
        $escaped = str_replace('\\*', '[^/]*', $escaped);
        $escaped = str_replace('\\?', '[^/]', $escaped);
        $flags = $this->caseInsensitive ? 'i' : '';
        return preg_match('#^' . $escaped . '$#' . $flags, $actual) === 1;
    }

    /**
     * Scan a directory tree for any ignored descendant.
     * Returns true at the first match (early exit). Fails closed on scandir error.
     */
    private function scanForProtectedDescendant(string $dirPath): bool {
        $entries = @scandir($dirPath);
        if ($entries === false) return true;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $full = str_replace('\\', '/', $dirPath . '/' . $entry);

            if ($this->isIgnored($full)) {
                return true;
            }

            if (is_dir($full) && !is_link($full)) {
                if ($this->scanForProtectedDescendant($full)) {
                    return true;
                }
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Apple junk detection (archive extraction)
    // ------------------------------------------------------------------

    /**
     * Check if an archive entry path contains macOS junk segments:
     * .DS_Store, AppleDouble (._*), or __MACOSX sidecar directories.
     */
    public static function isAppleJunkPath(string $relativePath): bool {
        foreach (explode('/', str_replace('\\', '/', $relativePath)) as $seg) {
            if ($seg === '') continue;
            if (substr($seg, 0, 2) === '._') return true;
            $lower = strtolower($seg);
            if ($lower === '.ds_store' || $lower === '__macosx') return true;
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private function rejectNullBytes(string $path): void {
        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Invalid path: null byte detected');
        }
    }

    /**
     * Case-aware path equality comparison.
     * Uses case-insensitive comparison on Windows.
     */
    private function pathEquals(string $a, string $b): bool {
        return $this->caseInsensitive ? strcasecmp($a, $b) === 0 : $a === $b;
    }

    /**
     * Case-aware str_starts_with for paths.
     * Uses case-insensitive comparison on Windows.
     */
    private function pathStartsWith(string $haystack, string $prefix): bool {
        if ($this->caseInsensitive) {
            return strncasecmp($haystack, $prefix, strlen($prefix)) === 0;
        }
        return str_starts_with($haystack, $prefix);
    }
}
