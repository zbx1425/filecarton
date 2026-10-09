<?php

namespace FileCarton;

class RepoSetting {
    /** @var string|null */
    public $rootPath;
    /** @var bool|null */
    public $readonly;
    /** @var string|null */
    public $branding;
    /** @var string|null */
    public $repoName;

    public function __construct() {
        $this->rootPath = null;
        $this->readonly = null;
        $this->branding = null;
        $this->repoName = null;
    }

    /**
     * Parse from the FILECARTON_REPO_SETTING config array.
     * Missing or wrong-typed keys become null.
     */
    public static function fromConfigArray(array $arr): self {
        $s = new self();
        if (array_key_exists('rootPath', $arr) && is_string($arr['rootPath'])) {
            $s->rootPath = $arr['rootPath'];
        }
        if (array_key_exists('readonly', $arr)) {
            $s->readonly = (bool)$arr['readonly'];
        }
        if (array_key_exists('branding', $arr) && is_string($arr['branding'])) {
            $s->branding = $arr['branding'];
        }
        if (array_key_exists('repoName', $arr) && is_string($arr['repoName'])) {
            $s->repoName = $arr['repoName'];
        }
        return $s;
    }

    /**
     * Parse from a STATIC_USER_LIST row (per-user override).
     * Only present values are set; null means inherit the default.
     */
    public static function fromStaticRow(array $row): self {
        $s = new self();
        if (isset($row['root']) && is_string($row['root']) && $row['root'] !== '') {
            $s->rootPath = $row['root'];
        }
        if (array_key_exists('readonly', $row) && is_bool($row['readonly'])) {
            $s->readonly = $row['readonly'];
        }
        if (array_key_exists('branding', $row) && is_string($row['branding'])) {
            $s->branding = $row['branding'];
        }
        if (array_key_exists('repoName', $row) && is_string($row['repoName'])) {
            $s->repoName = $row['repoName'];
        }
        return $s;
    }

    /**
     * Structural validation: all 4 fields must be present with correct types.
     * rootPath='' is valid (means "must be overridden by a per-user grant").
     * @return string[] problem descriptions; empty array if valid
     */
    public function verifyAllSet(): array {
        $p = [];
        if ($this->rootPath === null) {
            $p[] = 'FILECARTON_REPO_SETTING is missing or has invalid rootPath (expected string).';
        }
        if ($this->readonly === null) {
            $p[] = 'FILECARTON_REPO_SETTING is missing or has invalid readonly (expected bool).';
        }
        if ($this->branding === null) {
            $p[] = 'FILECARTON_REPO_SETTING is missing or has invalid branding (expected string).';
        }
        if ($this->repoName === null) {
            $p[] = 'FILECARTON_REPO_SETTING is missing or has invalid repoName (expected string).';
        }
        return $p;
    }

    /**
     * Apply non-null fields from $override onto this setting.
     * Returns a new instance; $this is not modified.
     */
    public function combine(RepoSetting $override): self {
        $result = clone $this;
        if ($override->rootPath !== null) $result->rootPath = $override->rootPath;
        if ($override->readonly !== null) $result->readonly = $override->readonly;
        if ($override->branding !== null) $result->branding = $override->branding;
        if ($override->repoName !== null) $result->repoName = $override->repoName;
        return $result;
    }

    // ─── Global resolution ─────────────────────────────────────────────

    /** @var self|null */
    private static $resolved;
    /** @var bool */
    private static $resolving = false;

    /**
     * Whether FileCarton is running in embed mode.
     */
    public static function embed(): bool {
        return defined('FILECARTON_EMBED') && FILECARTON_EMBED === true;
    }

    /**
     * Whether the resolved setting has a usable root path.
     */
    public static function filesOpen(): bool {
        $s = self::current();
        return $s->rootPath !== null && $s->rootPath !== '';
    }

    /**
     * Returns the resolved global RepoSetting for this request.
     * Lazy-initialized on first access.
     */
    public static function current(): self {
        if (self::$resolved !== null) return self::$resolved;
        self::resolve();
        return self::$resolved;
    }

    /** @internal Clear cached resolution for the next request. */
    public static function resetForRequest(): void {
        self::$resolved = null;
    }

    private static function resolve(): void {
        if (self::$resolving) {
            self::$resolved = new self();
            return;
        }
        self::$resolving = true;

        $arr = defined('FILECARTON_REPO_SETTING') ? FILECARTON_REPO_SETTING : [];
        if (!is_array($arr)) $arr = [];
        $default = self::fromConfigArray($arr);

        if (self::embed()) {
            $merged = $default;
        } else {
            $identity = Auth::identity();
            if ($identity === null) {
                $merged = clone $default;
                $merged->rootPath = '';
            } else {
                $grant = Grants::current();
                if ($grant === null) {
                    $merged = clone $default;
                    $merged->rootPath = '';
                } else {
                    $merged = $default->combine($grant);
                }
            }
        }

        if (defined('FILECARTON_MUST_READONLY') && FILECARTON_MUST_READONLY === true) {
            $merged->readonly = true;
        }

        if (is_string($merged->rootPath) && $merged->rootPath !== '') {
            $real = realpath($merged->rootPath);
            $merged->rootPath = ($real !== false && is_dir($real)) ? $real : '';
        }

        self::$resolved = $merged;
        self::$resolving = false;
    }
}
