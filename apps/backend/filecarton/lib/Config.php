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

    /**
     * Allowed non-[repo] configuration keys: key => [type, default].
     * Types: 'bool', 'int', 'size', 'string', 'string[]', 'array', 'sendfile', 'dev_server'.
     * A null default means the constant is not defined by applyDefaults() unless dynamically computed.
     */
    private const SCHEMA = [
        // [auth]
        'must_embed'                    => ['bool',       false],
        'auth_providers'                => ['array',      [['class' => StaticPasswordAuth::class]]],
        'grant_resolvers'               => ['array',      [['class' => StaticGrantResolver::class]]],
        'static_user_list'              => ['array',      [['id' => 'admin', 'password_hash' => '']]],

        // [limits]
        'max_edit_size'                 => ['size',       4 * 1024 * 1024],
        'upload_chunk_size'             => ['size',       4 * 1024 * 1024],
        'upload_max_file_size'          => ['size',       1 * 1024 * 1024 * 1024],
        'upload_max_depth'              => ['int',        50],
        'chunk_expiry'                  => ['int',        86400],
        'chunk_cleanup_chance'          => ['int',        10],
        'archive_max_files'             => ['int',        10000],
        'archive_max_size'              => ['size',       1 * 1024 * 1024 * 1024],
        'archive_list_max_files'        => ['int',        10000],
        'archive_tar_max_access_files'  => ['int',        1000],
        'archive_targz_max_access_size' => ['size',       64 * 1024 * 1024],
        'search_default_limit'          => ['int',        200],
        'search_max_limit'              => ['int',        1000],
        'search_max_scan'               => ['int',        100000],

        // [security]
        'must_readonly'                 => ['bool',       false],
        'filename_blocklist'            => ['string[]',   ['*.php', '.htaccess', '.user.ini', 'web.config']],
        'ignore_pattern'                => ['string[]',   []],
        'ignore_realpath'               => ['string[]',   null],
        'dotfiles_block'                => ['bool',       false],
        'dotfiles_force_visible'        => ['bool',       false],

        // [deployment]
        'public_origin'                 => ['string',     ''],
        'sendfile'                      => ['sendfile',   false],
        'asset_url'                     => ['string',     ''],
        'http_client_proxy'             => ['string',     ''],

        // [misc]
        'session_name'                  => ['string',     'FILECARTON'],
        'dev_server'                    => ['dev_server', null],
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
            $sectionKey = strtolower((string)$section);
            if ($sectionKey === 'repo') {
                if (!is_array($content)) {
                    throw new \RuntimeException('TOML section [repo] must be a table.');
                }
                $out['FILECARTON_REPO_SETTING'] = $content;
                continue;
            }

            if (isset(self::SCHEMA[$sectionKey])) {
                $out['FILECARTON_' . strtoupper($sectionKey)] = self::validateValue($sectionKey, $content);
                continue;
            }

            if (!is_array($content)) {
                throw new \RuntimeException("Unknown configuration key '{$section}'.");
            }

            foreach ($content as $key => $value) {
                $lowerKey = strtolower((string)$key);
                $out['FILECARTON_' . strtoupper($lowerKey)] = self::validateValue($lowerKey, $value);
            }
        }

        return $out;
    }

    /**
     * Validate a configuration key against SCHEMA and convert byte-size strings.
     *
     * @param string $key   Lowercase configuration key name
     * @param mixed  $value Interpolated TOML value
     * @return mixed
     */
    private static function validateValue(string $key, $value) {
        if (!isset(self::SCHEMA[$key])) {
            throw new \RuntimeException("Unknown configuration key '{$key}'.");
        }

        $type = self::SCHEMA[$key][0];
        switch ($type) {
            case 'bool':
                if (!is_bool($value)) {
                    throw new \RuntimeException("Configuration key '{$key}' must be a boolean.");
                }
                return $value;

            case 'int':
                if (!is_int($value)) {
                    throw new \RuntimeException("Configuration key '{$key}' must be an integer.");
                }
                return $value;

            case 'size':
                if (is_int($value)) {
                    return $value;
                }
                if (is_string($value)) {
                    return self::parseSize($value, $key);
                }
                throw new \RuntimeException("Configuration key '{$key}' must be a byte-size string or integer.");

            case 'string':
                if (!is_string($value)) {
                    throw new \RuntimeException("Configuration key '{$key}' must be a string.");
                }
                return $value;

            case 'string[]':
                if (!is_array($value)) {
                    throw new \RuntimeException("Configuration key '{$key}' must be an array of strings.");
                }
                foreach ($value as $item) {
                    if (!is_string($item)) {
                        throw new \RuntimeException("Configuration key '{$key}' must be an array of strings.");
                    }
                }
                return $value;

            case 'array':
                if (!is_array($value)) {
                    throw new \RuntimeException("Configuration key '{$key}' must be an array.");
                }
                return $value;

            case 'sendfile':
                if ($value !== false && !is_string($value) && !is_array($value)) {
                    throw new \RuntimeException("Configuration key '{$key}' must be false, a string, or an array.");
                }
                return $value;

            case 'dev_server':
                if ($value !== false && !is_string($value)) {
                    throw new \RuntimeException("Configuration key '{$key}' must be a string or false.");
                }
                return $value;
        }

        return $value;
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

        define_default('FILECARTON_IGNORE_REALPATH', [
            $configDir . '/filecarton.php',
            $configDir . '/filecarton.config.toml.php',
            $configDir . '/filecarton',
        ]);

        foreach (self::SCHEMA as $key => $spec) {
            if ($spec[1] !== null) {
                define_default('FILECARTON_' . strtoupper($key), $spec[1]);
            }
        }
    }
}
