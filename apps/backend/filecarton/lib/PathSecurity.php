<?php

namespace FileCarton;

/**
 * Precompiled filename blocklist matcher.
 *
 * Rules are parsed once at construction; matching is O(1) hash lookup for
 * exact names, O(k) suffix comparison for wildcard rules (k = rule count).
 */
class FilenameBlocklist {
    /** @var bool */
    private $blockAll = false;
    /** @var bool */
    private $allowAll = false;
    /** @var string[] Pre-lowercased suffixes, e.g. ['.php', '.tar.gz'] */
    private $blockSuffixes = [];
    /** @var string[] */
    private $allowSuffixes = [];
    /** @var array<string,true> Pre-lowercased hash map, e.g. ['.htaccess' => true] */
    private $blockExact = [];
    /** @var array<string,true> */
    private $allowExact = [];

    public function __construct(array $rules) {
        foreach ($rules as $rule) {
            if (!is_string($rule) || $rule === '') continue;
            $allow = $rule[0] === '!';
            $pattern = $allow ? substr($rule, 1) : $rule;
            if ($pattern === '') continue;

            if ($pattern === '*') {
                $allow ? ($this->allowAll = true) : ($this->blockAll = true);
            } elseif ($pattern[0] === '*') {
                $suffix = strtolower(substr($pattern, 1));
                $allow
                    ? ($this->allowSuffixes[] = $suffix)
                    : ($this->blockSuffixes[] = $suffix);
            } else {
                $lower = strtolower($pattern);
                $allow
                    ? ($this->allowExact[$lower] = true)
                    : ($this->blockExact[$lower] = true);
            }
        }
    }

    /**
     * Check whether a filename is blocked.
     * Allow (!) rules always win: any matching allow rule permits the file.
     */
    public function isBlocked(string $basename): bool {
        $lower = strtolower($basename);
        $len = strlen($lower);

        // Phase 1: Allow rules — any match permits the file.
        if ($this->allowAll) return false;
        if (isset($this->allowExact[$lower])) return false;
        foreach ($this->allowSuffixes as $suffix) {
            $sLen = strlen($suffix);
            if ($len >= $sLen && substr_compare($lower, $suffix, -$sLen) === 0) return false;
        }

        // Phase 2: Block rules — any match blocks the file.
        if ($this->blockAll) return true;
        if (isset($this->blockExact[$lower])) return true;
        foreach ($this->blockSuffixes as $suffix) {
            $sLen = strlen($suffix);
            if ($len >= $sLen && substr_compare($lower, $suffix, -$sLen) === 0) return true;
        }

        return false;
    }
}

/**
 * Gitignore-ish path ignore rules, combining absolute-path matches
 * and compiled regex patterns.
 */
class IgnoreRules {
    /** @var string Root path (normalized, no trailing slash) */
    private $rootPath;
    /** @var bool */
    private $caseInsensitive;
    /** @var string[] Resolved absolute ignore paths */
    private $absolutePaths = [];
    /** @var string[] Compiled regex patterns */
    private $regexes = [];
    /** @var array[] Pattern metadata for hasProtectedDescendants optimization */
    private $patternMeta = [];
    /** @var bool Whether any pattern requires filesystem scanning */
    private $hasDeepPatterns = false;

    public function __construct(string $rootPath, bool $caseInsensitive) {
        $this->rootPath = $rootPath;
        $this->caseInsensitive = $caseInsensitive;
    }

    public function addAbsolutePath(string $absPath): void {
        $this->absolutePaths[] = $absPath;
    }

    public function addPattern(string $pattern): void {
        $compiled = $this->patternToRegex($pattern);
        if ($compiled !== null) {
            $this->regexes[] = $compiled['regex'];
            $this->patternMeta[] = $compiled;
            if ($compiled['needsScan']) {
                $this->hasDeepPatterns = true;
            }
        }
    }

    /**
     * Check if an absolute path is ignored by any configured rule.
     * Matches exact paths and prefixes (directory content is also ignored).
     */
    public function isIgnored(string $absPath): bool {
        $normalized = str_replace('\\', '/', $absPath);

        foreach ($this->absolutePaths as $ignorePath) {
            if ($this->pathEquals($normalized, $ignorePath) || $this->pathStartsWith($normalized, $ignorePath . '/')) {
                return true;
            }
        }

        if (!empty($this->regexes)) {
            $relative = $this->getRelativePath($normalized);
            if ($relative !== null) {
                $isDir = is_dir($absPath);
                foreach ($this->regexes as $regex) {
                    if (preg_match($regex, $relative . ($isDir ? '/' : ''))) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if a path (which may not yet exist on disk) would be ignored.
     */
    public function wouldBeIgnored(string $absPath, bool $isDir): bool {
        $normalized = str_replace('\\', '/', $absPath);

        foreach ($this->absolutePaths as $ignorePath) {
            if ($this->pathEquals($normalized, $ignorePath) || $this->pathStartsWith($normalized, $ignorePath . '/')) {
                return true;
            }
        }

        if (!empty($this->regexes)) {
            $relative = $this->getRelativePath($normalized);
            if ($relative !== null) {
                foreach ($this->regexes as $regex) {
                    if (preg_match($regex, $relative . ($isDir ? '/' : ''))) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if a directory contains any protected (ignored) descendants.
     */
    public function hasProtectedDescendants(string $dirPath): bool {
        $normalized = str_replace('\\', '/', $dirPath);

        foreach ($this->absolutePaths as $ip) {
            if ($this->pathStartsWith($ip, $normalized . '/')) {
                return true;
            }
        }

        $dirRel = $this->getRelativePath($normalized);
        if ($dirRel === null) return false;

        foreach ($this->patternMeta as $meta) {
            if ($meta['needsScan']) continue;
            if ($this->patternCouldMatchInside($meta['segments'], $dirRel)) {
                return true;
            }
        }

        if (!$this->hasDeepPatterns) {
            return false;
        }

        return $this->scanForProtectedDescendant($dirPath);
    }

    // ── Private: pattern compilation ──

    /**
     * Convert a simplified gitignore pattern to a regex.
     * Supports: * (non-slash wildcard), ** (zero or more directories),
     * ? (single non-slash char), leading / (root anchor),
     * trailing / (directory-only match).
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

        $regex .= $dirOnly ? '/' : '(?:$|/)';

        $needsScan = !$anchored || $hasGlobstar;
        $flags = $this->caseInsensitive ? 'i' : '';

        return [
            'regex'     => '#' . $regex . '#' . $flags,
            'needsScan' => $needsScan,
            'segments'  => $needsScan ? null : $segments,
        ];
    }

    // ── Private: descendant protection helpers ──

    private function patternCouldMatchInside(array $patternSegments, string $dirRelPath): bool {
        if ($dirRelPath === '') {
            return $this->matchRemainingSegments($this->rootPath, $patternSegments);
        }

        $dirSegments = explode('/', trim($dirRelPath, '/'));

        for ($i = 0; $i < count($dirSegments); $i++) {
            if ($i >= count($patternSegments)) return false;
            if (!$this->segmentMatches($dirSegments[$i], $patternSegments[$i])) return false;
        }

        if (count($patternSegments) > count($dirSegments)) {
            $remaining = array_slice($patternSegments, count($dirSegments));
            $basePath = $this->rootPath . '/' . implode('/', $dirSegments);
            return $this->matchRemainingSegments($basePath, $remaining);
        }

        return true;
    }

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

    private function segmentMatches(string $actual, string $pattern): bool {
        if ($this->caseInsensitive ? strcasecmp($pattern, $actual) === 0 : $pattern === $actual) return true;
        $escaped = preg_quote($pattern, '#');
        $escaped = str_replace('\\*', '[^/]*', $escaped);
        $escaped = str_replace('\\?', '[^/]', $escaped);
        $flags = $this->caseInsensitive ? 'i' : '';
        return preg_match('#^' . $escaped . '$#' . $flags, $actual) === 1;
    }

    private function scanForProtectedDescendant(string $dirPath): bool {
        $entries = @scandir($dirPath);
        if ($entries === false) return true;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $full = str_replace('\\', '/', $dirPath . '/' . $entry);

            if ($this->isIgnored($full)) return true;

            if (is_dir($full) && !is_link($full)) {
                if ($this->scanForProtectedDescendant($full)) return true;
            }
        }

        return false;
    }

    // ── Private: path helpers ──

    private function getRelativePath(string $absPath): ?string {
        if ($absPath === $this->rootPath) return '';
        if (str_starts_with($absPath, $this->rootPath . '/')) {
            return substr($absPath, strlen($this->rootPath) + 1);
        }
        return null;
    }

    private function pathEquals(string $a, string $b): bool {
        return $this->caseInsensitive ? strcasecmp($a, $b) === 0 : $a === $b;
    }

    private function pathStartsWith(string $haystack, string $prefix): bool {
        if ($this->caseInsensitive) {
            return strncasecmp($haystack, $prefix, strlen($prefix)) === 0;
        }
        return str_starts_with($haystack, $prefix);
    }
}

// ─────────────────────────────────────────────────────────────────────────────

class PathSecurity {
    /** @var string */
    private $rootPath;

    /** @var bool Whether the filesystem is case-insensitive (Windows) */
    private $caseInsensitive = false;

    /** @var FilenameBlocklist */
    private $blocklist;

    /** @var IgnoreRules */
    private $ignore;

    public function __construct(string $rootPath) {
        $real = realpath($rootPath);
        if ($real === false) {
            throw new \RuntimeException('Root path does not exist: ' . $rootPath);
        }
        $this->rootPath = rtrim(str_replace('\\', '/', $real), '/');
        $this->caseInsensitive = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        $this->blocklist = new FilenameBlocklist(is_array(FILECARTON_FILENAME_BLOCKLIST) ? FILECARTON_FILENAME_BLOCKLIST : []);

        $this->ignore = new IgnoreRules($this->rootPath, $this->caseInsensitive);

        if (is_array(FILECARTON_IGNORE_REALPATH)) {
            foreach (FILECARTON_IGNORE_REALPATH as $path) {
                $resolved = realpath($path);
                if ($resolved !== false) {
                    $this->ignore->addAbsolutePath(str_replace('\\', '/', $resolved));
                } else {
                    $parent = realpath(dirname($path));
                    if ($parent !== false) {
                        $this->ignore->addAbsolutePath(str_replace('\\', '/', $parent) . '/' . basename($path));
                    } else {
                        $this->ignore->addAbsolutePath(rtrim(str_replace('\\', '/', $path), '/'));
                    }
                }
            }
        }

        if (class_exists(__NAMESPACE__ . '\\Auth', false)) {
            foreach (Auth::pluginIgnorePaths() as $pluginPath) {
                $this->ignore->addAbsolutePath($pluginPath);
            }
        }

        if (is_array(FILECARTON_IGNORE_PATTERN)) {
            foreach (FILECARTON_IGNORE_PATTERN as $pattern) {
                $this->ignore->addPattern($pattern);
            }
        }

        if (FILECARTON_DOTFILES_BLOCK) {
            $this->ignore->addPattern('.*');
        }
    }

    public function getRootPath(): string {
        return $this->rootPath;
    }

    // ------------------------------------------------------------------
    // Path resolution
    // ------------------------------------------------------------------

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
            throw new ApiException('not_found.path', 404);
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
            throw new ApiException('path_too_deep', 400, ['max' => FILECARTON_UPLOAD_MAX_DEPTH]);
        }

        $current = $this->rootPath;

        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new ApiException('invalid_input', 400, ['field' => 'path']);
            }
            if (!$this->isValidFileName($part)) {
                throw new ApiException('invalid_input', 400, ['field' => 'path']);
            }

            $next = $current . '/' . $part;

            if ($this->wouldBeIgnored($next, true)) {
                throw new ApiException('access_denied', 403);
            }

            if (is_dir($next)) {
                $resolved = realpath($next);
                if ($resolved === false) {
                    throw new ApiException('not_found.path', 404);
                }
                $resolved = str_replace('\\', '/', $resolved);
                $this->assertWithinRoot($resolved);
                $current = $resolved;
            } elseif (file_exists($next) || is_link($next)) {
                throw new ApiException('not_a_dir', 409);
            } else {
                if (!mkdir($next, 0755)) {
                    throw new ApiException('server_error', 500);
                }
                $current = $next;
            }
        }

        $final = realpath($current);
        if ($final === false) {
            throw new ApiException('not_found.path', 404);
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
            throw new ApiException('invalid_input', 400, ['field' => 'name']);
        }

        if (!$this->isValidFileName($basename)) {
            throw new ApiException('invalid_input', 400, ['field' => 'name']);
        }

        $parentAbs = ($dirname === '.' || $dirname === '')
            ? $this->rootPath
            : $this->resolve($dirname);

        if (!is_dir($parentAbs)) {
            throw new ApiException('not_found.dir', 404);
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
            throw new ApiException('path_traversal', 403);
        }
    }

    // ------------------------------------------------------------------
    // Filename validation
    // ------------------------------------------------------------------

    /**
     * Remove dangerous characters from a filename, keeping it usable.
     */
    public function sanitizeFileName(string $name): string {
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
        $name = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '', $name);
        $name = rtrim($name, '. ');
        if ($name === '' || $name === '.' || $name === '..') {
            throw new ApiException('invalid_input', 400, ['field' => 'name']);
        }
        return $name;
    }

    /**
     * Check whether a filename is valid for creation (no dangerous characters, not empty).
     */
    public function isValidFileName(string $name): bool {
        if ($name === '' || $name === '.' || $name === '..') return false;
        if (preg_match('/[\x00-\x1F\x7F]/', $name)) return false;
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
     */
    public function resolveItemIn(string $baseAbs, string $itemName): string {
        if ($itemName === '' || $itemName === '.' || $itemName === '..') {
            throw new ApiException('invalid_input', 400, ['field' => 'name']);
        }
        if (str_contains($itemName, "\0") || str_contains($itemName, '/') || str_contains($itemName, '\\')) {
            throw new ApiException('invalid_input', 400, ['field' => 'name']);
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
     */
    public static function normalizePath(string $path): string {
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
    // Filename blocklist (delegates to FilenameBlocklist)
    // ------------------------------------------------------------------

    /**
     * Check whether a filename is blocked by the configured blocklist rules.
     * Directories are never blocked by name rules.
     */
    public function isFilenameBlocked(string $basename): bool {
        return $this->blocklist->isBlocked($basename);
    }

    // ------------------------------------------------------------------
    // Ignore checks (delegates to IgnoreRules)
    // ------------------------------------------------------------------

    public function isIgnored(string $absPath): bool {
        return $this->ignore->isIgnored($absPath);
    }

    public function wouldBeIgnored(string $absPath, bool $isDir): bool {
        return $this->ignore->wouldBeIgnored($absPath, $isDir);
    }

    public function hasProtectedDescendants(string $dirPath): bool {
        return $this->ignore->hasProtectedDescendants($dirPath);
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

    // ------------------------------------------------------------------
    // Composite validation helpers
    // ------------------------------------------------------------------

    /**
     * Reject an ignored path with the same code a missing path would use.
     */
    public function assertNotIgnored(string $absPath, string $missingCode = 'not_found.path'): void {
        if ($this->isIgnored($absPath)) {
            throw new ApiException($missingCode, 404);
        }
    }

    /**
     * Validate that a file can be modified (not ignored, name not blocked).
     */
    public function assertCanModify(string $absPath): void {
        $this->assertNotIgnored($absPath);
        $basename = basename($absPath);
        if (is_file($absPath) && $this->isFilenameBlocked($basename)) {
            throw new ApiException('filename_blocked', 403);
        }
    }

    /**
     * Resolve a relative path to an existing directory.
     */
    public function resolveExistingDir(string $relativePath): string {
        $absPath = $this->resolve($relativePath);
        $this->assertNotIgnored($absPath);
        if (!file_exists($absPath)) {
            throw new ApiException('not_found.dir', 404);
        }
        if (!is_dir($absPath)) {
            throw new ApiException('not_a_dir', 400);
        }
        return $absPath;
    }

    /**
     * Resolve a relative path to an existing file.
     */
    public function resolveExistingFile(string $relativePath): string {
        $absPath = $this->resolve($relativePath);
        $this->assertNotIgnored($absPath);
        if (!file_exists($absPath)) {
            throw new ApiException('not_found.file', 404);
        }
        if (!is_file($absPath)) {
            throw new ApiException('not_a_file', 400);
        }
        return $absPath;
    }

    /**
     * Assert that a path that is about to be created is allowed.
     * Combines wouldBeIgnored() + isFilenameBlocked() (for files).
     */
    public function assertCanCreateAt(string $absPath, bool $isDir): void {
        if ($this->wouldBeIgnored($absPath, $isDir)) {
            throw new ApiException('access_denied', 403);
        }
        if (!$isDir && $this->isFilenameBlocked(basename($absPath))) {
            throw new ApiException('filename_blocked', 403);
        }
    }

    /**
     * Filter a list of directory entries, removing ignored entries.
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
            throw new ApiException('invalid_input', 400, ['field' => 'path']);
        }
    }
}
