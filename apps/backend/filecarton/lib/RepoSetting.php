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
    /** @var bool|null */
    public $featureArchive;
    /** @var bool|null */
    public $featureUnixPerm;
    /** @var bool|null */
    public $featureUnixPermModify;
    /** @var bool|null */
    public $featureRemoteDownload;

    public function __construct() {
        $this->rootPath = null;
        $this->readonly = null;
        $this->branding = null;
        $this->repoName = null;
        $this->featureArchive = null;
        $this->featureUnixPerm = null;
        $this->featureUnixPermModify = null;
        $this->featureRemoteDownload = null;
    }

    private const BOOL_MAP = [
        'readonly'                 => 'readonly',
        'feature_archive'          => 'featureArchive',
        'feature_unix_perm'        => 'featureUnixPerm',
        'feature_unix_perm_modify' => 'featureUnixPermModify',
        'feature_remote_download'  => 'featureRemoteDownload',
    ];

    /**
     * Parse from the FILECARTON_REPO_SETTING config array.
     * Missing or wrong-typed keys become null (except optional feature flags which default to false).
     */
    public static function fromConfigArray(array $arr): self {
        $s = self::fromStaticRow($arr, true);
        foreach (self::BOOL_MAP as $k => $prop) {
            if ($k !== 'readonly' && $s->$prop === null) {
                $s->$prop = false;
            }
        }
        return $s;
    }

    /**
     * Parse from a STATIC_USER_LIST row (per-user override).
     * Only present values are set; null means inherit the default.
     */
    public static function fromStaticRow(array $row, bool $allowEmptyRoot = false): self {
        $s = new self();
        if (isset($row['root_path']) && is_string($row['root_path']) && ($allowEmptyRoot || $row['root_path'] !== '')) {
            $s->rootPath = $row['root_path'];
        }
        if (isset($row['branding']) && is_string($row['branding'])) {
            $s->branding = $row['branding'];
        }
        if (isset($row['repo_name']) && is_string($row['repo_name'])) {
            $s->repoName = $row['repo_name'];
        }
        foreach (self::BOOL_MAP as $k => $prop) {
            if (isset($row[$k]) && is_bool($row[$k])) {
                $s->$prop = $row[$k];
            }
        }
        return $s;
    }

    /**
     * Structural validation: core 4 fields must be present with correct types.
     * Feature flags default to false if absent or non-bool.
     * rootPath='' is valid (means "must be overridden by a per-user grant").
     * @return string[] problem descriptions; empty array if valid
     */
    public function verifyAllSet(): array {
        $p = [];
        if ($this->rootPath === null
            || $this->readonly === null
            || $this->branding === null
            || $this->repoName === null
        ) {
            $p[] = 'FILECARTON_REPO_SETTING is not containing all fields.';
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
        if ($override->featureArchive !== null) $result->featureArchive = $override->featureArchive;
        if ($override->featureUnixPerm !== null) $result->featureUnixPerm = $override->featureUnixPerm;
        if ($override->featureUnixPermModify !== null) $result->featureUnixPermModify = $override->featureUnixPermModify;
        if ($override->featureRemoteDownload !== null) $result->featureRemoteDownload = $override->featureRemoteDownload;
        return $result;
    }

    /**
     * Whether displaying Unix permissions is enabled and supported on the current OS.
     */
    public function unixPermEnabled(): bool {
        if (!$this->featureUnixPerm) return false;
        require_once FILECARTON_SCRIPT_DIR . '/lib/Platform.php';
        return Platform::supportsUnixPerm();
    }

    /**
     * Whether modifying Unix permissions is enabled and supported on the current OS.
     */
    public function unixPermModifyEnabled(): bool {
        return $this->unixPermEnabled() && (bool)$this->featureUnixPermModify && !$this->readonly;
    }

    /**
     * Whether remote URL download is enabled.
     */
    public function remoteDownloadEnabled(): bool {
        return (bool)$this->featureRemoteDownload && !$this->readonly;
    }

    /**
     * Build the features dictionary exposed to the frontend.
     * @return array<string, bool>
     */
    public function featuresConfig(): array {
        return [
            'archive'        => (bool)$this->featureArchive,
            'unixPerm'       => $this->unixPermEnabled(),
            'unixPermModify' => $this->unixPermModifyEnabled(),
            'remoteDownload' => $this->remoteDownloadEnabled(),
        ];
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

        $arr = FILECARTON_REPO_SETTING;
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

        if (FILECARTON_MUST_READONLY === true) {
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
