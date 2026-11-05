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
    /** @var bool|null */
    public $featureChecksum;
    /** @var bool|string|null */
    public $featureCopyLink;
    /** @var string|null */
    public $tempDir;
    /** @var string|null */
    public $themePrimary;
    /** @var string|null */
    public $themeToolbar;

    /**
     * Unified property map: TOML key => [property name, accepted types].
     * Types are pipe-separated: 'string', 'bool', or 'bool|string'.
     * Used by fromStaticRow(), fromConfigArray(), and combine().
     */
    private const PROP_MAP = [
        'root_path'                => ['rootPath',              'string'],
        'readonly'                 => ['readonly',              'bool'],
        'branding'                 => ['branding',              'string'],
        'repo_name'                => ['repoName',              'string'],
        'temp_dir'                 => ['tempDir',               'string'],
        'theme_primary'            => ['themePrimary',          'string'],
        'theme_toolbar'            => ['themeToolbar',          'string'],
        'feature_archive'          => ['featureArchive',        'bool'],
        'feature_unix_perm'        => ['featureUnixPerm',       'bool'],
        'feature_unix_perm_modify' => ['featureUnixPermModify', 'bool'],
        'feature_remote_download'  => ['featureRemoteDownload', 'bool'],
        'feature_checksum'         => ['featureChecksum',       'bool'],
        'feature_copy_link'        => ['featureCopyLink',       'bool|string'],
    ];

    /** Non-false defaults for feature_* keys in fromConfigArray(). */
    private const FEATURE_DEFAULTS = [
        'feature_checksum' => true,
    ];

    public function __construct() {
        foreach (self::PROP_MAP as [$prop]) {
            $this->$prop = null;
        }
    }

    private static function matchesType($value, string $types): bool {
        foreach (explode('|', $types) as $t) {
            if ($t === 'string' && is_string($value)) return true;
            if ($t === 'bool'   && is_bool($value))   return true;
        }
        return false;
    }

    /**
     * Parse from the FILECARTON_REPO_SETTING config array.
     * Missing or wrong-typed keys become null (except feature_* flags which get defaults).
     */
    public static function fromConfigArray(array $arr): self {
        $s = self::fromStaticRow($arr, true);
        foreach (self::PROP_MAP as $k => [$prop]) {
            if (str_starts_with($k, 'feature_') && $s->$prop === null) {
                $s->$prop = self::FEATURE_DEFAULTS[$k] ?? false;
            }
        }
        return $s;
    }

    /**
     * Parse from a STATIC_USER_LIST row (per-user override).
     * Only present values with correct types are set; null means inherit the default.
     */
    public static function fromStaticRow(array $row, bool $allowEmptyRoot = false): self {
        $s = new self();
        foreach (self::PROP_MAP as $k => [$prop, $types]) {
            if (!isset($row[$k])) continue;
            if (self::matchesType($row[$k], $types)) {
                $s->$prop = $row[$k];
            }
        }
        if (!$allowEmptyRoot && $s->rootPath === '') {
            $s->rootPath = null;
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
        foreach (self::PROP_MAP as [$prop]) {
            if ($override->$prop !== null) $result->$prop = $override->$prop;
        }
        return $result;
    }

    /**
     * Resolve the temporary directory path, substituting ${ROOT_PATH} with the actual root.
     * Returns empty string if rootPath is not set.
     */
    public function resolvedTempDir(): string {
        if ($this->rootPath === null || $this->rootPath === '') return '';
        $raw = $this->tempDir ?? '';
        if ($raw === '') return rtrim(str_replace('\\', '/', $this->rootPath), '/') . '/__fc_tmp';
        return rtrim(str_replace('\\', '/', strtr($raw, ['${ROOT_PATH}' => $this->rootPath])), '/');
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
     * Resolve the copy-link URL prefix for the frontend.
     * Returns '' when the feature is disabled or auto-detection fails.
     */
    public function resolvedCopyLinkPrefix(): string {
        if ($this->featureCopyLink === false || $this->featureCopyLink === null) {
            return '';
        }
        if (is_string($this->featureCopyLink)) {
            return $this->featureCopyLink;
        }
        // Auto-detect: featureCopyLink === true
        if ($this->rootPath === null || $this->rootPath === '') return '';

        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($docRoot === '') return '';
        $docRoot = @realpath($docRoot);
        if ($docRoot === false) return '';
        $docRoot = rtrim(str_replace('\\', '/', $docRoot), '/');

        $rootNorm = rtrim(str_replace('\\', '/', $this->rootPath), '/');

        // Case-insensitive prefix check (safe on Windows / macOS)
        if ($docRoot !== '' && stripos($rootNorm, $docRoot . '/') === 0) {
            $webPath = substr($rootNorm, strlen($docRoot));
        } elseif (strcasecmp($rootNorm, $docRoot) === 0) {
            $webPath = '/';
        } else {
            return '';
        }
        if ($webPath === '' || $webPath[0] !== '/') $webPath = '/' . $webPath;
        $webPath = rtrim($webPath, '/') . '/';

        return self::detectOrigin() . $webPath;
    }

    private static function detectOrigin(): string {
        if (defined('FILECARTON_PUBLIC_ORIGIN') && is_string(FILECARTON_PUBLIC_ORIGIN)
            && FILECARTON_PUBLIC_ORIGIN !== '') {
            return rtrim(FILECARTON_PUBLIC_ORIGIN, '/');
        }
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
                && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return ($isHttps ? 'https://' : 'http://') . $host;
    }

    /**
     * Build the features dictionary exposed to the frontend.
     * @return array<string, mixed>
     */
    public function featuresConfig(): array {
        $cfg = [
            'archive'        => (bool)$this->featureArchive,
            'unixPerm'       => $this->unixPermEnabled(),
            'unixPermModify' => $this->unixPermModifyEnabled(),
            'remoteDownload' => $this->remoteDownloadEnabled(),
            'checksum'       => (bool)$this->featureChecksum,
        ];
        $copyLink = $this->resolvedCopyLinkPrefix();
        if ($copyLink !== '') {
            $cfg['copyLink'] = $copyLink;
        }
        return $cfg;
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
