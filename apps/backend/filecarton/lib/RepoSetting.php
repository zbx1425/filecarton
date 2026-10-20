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

    /**
     * Parse from the FILECARTON_REPO_SETTING config array.
     * Missing or wrong-typed keys become null (except optional feature flags which default to false).
     */
    public static function fromConfigArray(array $arr): self {
        $s = new self();
        if (array_key_exists('root_path', $arr) && is_string($arr['root_path'])) {
            $s->rootPath = $arr['root_path'];
        }
        if (array_key_exists('readonly', $arr)) {
            $s->readonly = (bool)$arr['readonly'];
        }
        if (array_key_exists('branding', $arr) && is_string($arr['branding'])) {
            $s->branding = $arr['branding'];
        }
        if (array_key_exists('repo_name', $arr) && is_string($arr['repo_name'])) {
            $s->repoName = $arr['repo_name'];
        }
        $s->featureArchive = (array_key_exists('feature_archive', $arr) && is_bool($arr['feature_archive']))
            ? $arr['feature_archive']
            : false;
        $s->featureUnixPerm = (array_key_exists('feature_unix_perm', $arr) && is_bool($arr['feature_unix_perm']))
            ? $arr['feature_unix_perm']
            : false;
        $s->featureUnixPermModify = (array_key_exists('feature_unix_perm_modify', $arr) && is_bool($arr['feature_unix_perm_modify']))
            ? $arr['feature_unix_perm_modify']
            : false;
        $s->featureRemoteDownload = (array_key_exists('feature_remote_download', $arr) && is_bool($arr['feature_remote_download']))
            ? $arr['feature_remote_download']
            : false;
        return $s;
    }

    /**
     * Parse from a STATIC_USER_LIST row (per-user override).
     * Only present values are set; null means inherit the default.
     */
    public static function fromStaticRow(array $row): self {
        $s = new self();
        if (isset($row['root_path']) && is_string($row['root_path']) && $row['root_path'] !== '') {
            $s->rootPath = $row['root_path'];
        }
        if (array_key_exists('readonly', $row) && is_bool($row['readonly'])) {
            $s->readonly = $row['readonly'];
        }
        if (array_key_exists('branding', $row) && is_string($row['branding'])) {
            $s->branding = $row['branding'];
        }
        if (array_key_exists('repo_name', $row) && is_string($row['repo_name'])) {
            $s->repoName = $row['repo_name'];
        }
        if (array_key_exists('feature_archive', $row) && is_bool($row['feature_archive'])) {
            $s->featureArchive = $row['feature_archive'];
        }
        if (array_key_exists('feature_unix_perm', $row) && is_bool($row['feature_unix_perm'])) {
            $s->featureUnixPerm = $row['feature_unix_perm'];
        }
        if (array_key_exists('feature_unix_perm_modify', $row) && is_bool($row['feature_unix_perm_modify'])) {
            $s->featureUnixPermModify = $row['feature_unix_perm_modify'];
        }
        if (array_key_exists('feature_remote_download', $row) && is_bool($row['feature_remote_download'])) {
            $s->featureRemoteDownload = $row['feature_remote_download'];
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
