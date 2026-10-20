<?php

namespace FileCarton;

/**
 * Define a global constant if it has not been defined yet.
 *
 * @param string $key
 * @param mixed  $value
 */
function define_default(string $key, $value): void {
    if (!defined($key)) {
        define($key, $value);
    }
}

/**
 * FileCarton TOML Configuration Loader.
 *
 * Parses filecarton.config.toml.php, interpolates path variables (${ENTRY_POINT_SCRIPT_DIR},
 * ${CONFIG_DIR}), converts human-readable byte sizes in [limits], and defines
 * FILECARTON_* constants via define_default().
 */
class Config {
    /** @var string[] */
    private static $problems = [];

    /** Keys under [limits] (or top-level) that accept byte-size strings like "4M", "1G". */
    private const SIZE_KEYS = [
        'max_edit_size'                 => true,
        'upload_chunk_size'             => true,
        'upload_max_file_size'          => true,
        'archive_max_size'              => true,
        'archive_targz_max_access_size' => true,
    ];

    /**
     * Load configuration from a TOML file and define FILECARTON_* constants.
     */
    public static function load(string $configFile): void {
        self::$problems = [];

        $realConfig = @realpath($configFile);
        $configDir = ($realConfig !== false) ? dirname($realConfig) : dirname($configFile);

        $scriptFile = $_SERVER['SCRIPT_FILENAME'] ?? '';
        $realScript = (is_string($scriptFile) && $scriptFile !== '') ? @realpath($scriptFile) : false;
        $scriptDir = ($realScript !== false) ? dirname($realScript) : $configDir;

        $isEmbed = defined('FILECARTON_EMBED') && \FILECARTON_EMBED === true;
        if ($isEmbed && !is_file($configFile)) {
            self::applyDefaults($configDir, $scriptDir);
            return;
        }

        try {
            $raw = Toml::parseFile($configFile);
            $constants = self::resolve($raw, $configDir, $scriptDir);
            foreach ($constants as $name => $val) {
                define_default($name, $val);
            }
        } catch (\Throwable $e) {
            self::$problems[] = $e->getMessage();
            // Fail closed: force read-only and empty root_path if not already embedded
            define_default('FILECARTON_MUST_READONLY', true);
            define_default('FILECARTON_REPO_SETTING', [
                'root_path'                => '',
                'readonly'                 => true,
                'branding'                 => 'FileCarton',
                'repo_name'                => '',
                'feature_archive'          => false,
                'feature_unix_perm'        => false,
                'feature_unix_perm_modify' => false,
                'feature_remote_download'  => false,
            ]);
        }

        self::applyDefaults($configDir, $scriptDir);
    }

    /**
     * Resolve a parsed TOML structure into a map of FILECARTON_* constant names to values.
     *
     * - [repo] is mapped to FILECARTON_REPO_SETTING.
     * - All other sections map each key to FILECARTON_<UPPERCASE_KEY>.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function resolve(array $raw, string $configDir, string $scriptDir): array {
        $vars = [
            '${ENTRY_POINT_SCRIPT_DIR}' => $scriptDir,
            '${CONFIG_DIR}' => $configDir,
        ];

        $interpolated = self::interpolate($raw, $vars);
        $out = [];

        foreach ($interpolated as $section => $content) {
            if ($section === 'repo') {
                if (!is_array($content)) {
                    throw new \RuntimeException('TOML section [repo] must be a table.');
                }
                $out['FILECARTON_REPO_SETTING'] = $content;
                continue;
            }

            if (is_array($content)) {
                foreach ($content as $key => $value) {
                    $lowerKey = strtolower((string)$key);
                    if (isset(self::SIZE_KEYS[$lowerKey]) && is_string($value)) {
                        $value = self::parseSize($value, $key);
                    }
                    $constName = 'FILECARTON_' . strtoupper((string)$key);
                    $out[$constName] = $value;
                }
            } else {
                $lowerKey = strtolower((string)$section);
                if (isset(self::SIZE_KEYS[$lowerKey]) && is_string($content)) {
                    $content = self::parseSize($content, $section);
                }
                $constName = 'FILECARTON_' . strtoupper((string)$section);
                $out[$constName] = $content;
            }
        }

        return $out;
    }

    /**
     * Parse a human-readable size string (e.g. "4M", "1G", "512K", "1024") into bytes.
     */
    public static function parseSize(string $size, string $keyName = ''): int {
        $trimmed = trim($size);
        if (!preg_match('/^(\d+)\s*([kmgKMG]?)[bB]?$/', $trimmed, $m)) {
            $label = $keyName !== '' ? " for '{$keyName}'" : '';
            throw new \RuntimeException("Invalid byte size value '{$size}'{$label}.");
        }
        return Platform::parsePhpSize($m[1] . $m[2]);
    }

    /**
     * Return any configuration loading / parsing problems recorded during Config::load().
     *
     * @return string[]
     */
    public static function problems(): array {
        return self::$problems;
    }

    /**
     * Clear recorded problems (for testing).
     */
    public static function resetForRequest(): void {
        self::$problems = [];
    }

    /**
     * Recursively interpolate ${ENTRY_POINT_SCRIPT_DIR} and ${CONFIG_DIR} in strings.
     *
     * @param mixed                $value
     * @param array<string,string> $vars
     * @return mixed
     */
    private static function interpolate($value, array $vars) {
        if (is_string($value)) {
            return strtr($value, $vars);
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = self::interpolate($v, $vars);
            }
            return $result;
        }
        return $value;
    }

    /**
     * Ensure all standard FILECARTON_* constants have fallback defaults.
     */
    public static function applyDefaults(string $configDir, string $scriptDir): void {
        define_default('FILECARTON_AUTH_PROVIDERS', [
            ['class' => StaticPasswordAuth::class],
        ]);
        define_default('FILECARTON_GRANT_RESOLVERS', [
            ['class' => StaticGrantResolver::class],
        ]);
        define_default('FILECARTON_STATIC_USER_LIST', [
            ['id' => 'admin', 'password_hash' => ''],
        ]);
        define_default('FILECARTON_MUST_EMBED', false);

        define_default('FILECARTON_REPO_SETTING', [
            'root_path'                => $scriptDir,
            'readonly'                 => false,
            'branding'                 => 'FileCarton',
            'repo_name'                => '',
            'feature_archive'          => true,
            'feature_unix_perm'        => false,
            'feature_unix_perm_modify' => false,
            'feature_remote_download'  => false,
        ]);

        define_default('FILECARTON_MUST_READONLY', false);

        define_default('FILECARTON_MAX_EDIT_SIZE', 4 * 1024 * 1024);
        define_default('FILECARTON_UPLOAD_CHUNK_SIZE', 4 * 1024 * 1024);
        define_default('FILECARTON_UPLOAD_MAX_FILE_SIZE', 1 * 1024 * 1024 * 1024);
        define_default('FILECARTON_UPLOAD_MAX_DEPTH', 50);
        define_default('FILECARTON_CHUNK_EXPIRY', 86400);
        define_default('FILECARTON_CHUNK_CLEANUP_CHANCE', 10);
        define_default('FILECARTON_ARCHIVE_MAX_FILES', 10000);
        define_default('FILECARTON_ARCHIVE_MAX_SIZE', 1 * 1024 * 1024 * 1024);
        define_default('FILECARTON_ARCHIVE_LIST_MAX_FILES', 10000);
        define_default('FILECARTON_ARCHIVE_TAR_MAX_ACCESS_FILES', 1000);
        define_default('FILECARTON_ARCHIVE_TARGZ_MAX_ACCESS_SIZE', 64 * 1024 * 1024);
        define_default('FILECARTON_SEARCH_DEFAULT_LIMIT', 200);
        define_default('FILECARTON_SEARCH_MAX_LIMIT', 1000);
        define_default('FILECARTON_SEARCH_MAX_SCAN', 100000);

        define_default('FILECARTON_FILENAME_BLOCKLIST', [
            '*.php',
            '.htaccess',
            '.user.ini',
            'web.config',
        ]);
        define_default('FILECARTON_IGNORE_PATTERN', []);
        define_default('FILECARTON_IGNORE_REALPATH', [
            $configDir . '/filecarton.php',
            $configDir . '/filecarton.config.toml.php',
            $configDir . '/filecarton',
        ]);
        define_default('FILECARTON_DOTFILES_BLOCK', false);
        define_default('FILECARTON_DOTFILES_FORCE_VISIBLE', false);

        define_default('FILECARTON_PUBLIC_ORIGIN', '');
        define_default('FILECARTON_SENDFILE', false);
        define_default('FILECARTON_ASSET_URL', '');
        define_default('FILECARTON_HTTP_CLIENT_PROXY', '');

        define_default('FILECARTON_SESSION_NAME', 'FILECARTON');
    }
}
