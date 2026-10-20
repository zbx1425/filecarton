<?php

namespace FileCarton;

function define_default(string $key, $value): void {
    if (!defined($key)) {
        define($key, $value);
    }
}

class Config {
    /** @var string[] */
    private static $problems = [];

    /** Keys that accept human-readable byte-size strings like "4M", "1G". */
    private const SIZE_KEYS = [
        'max_edit_size'                 => true,
        'upload_chunk_size'             => true,
        'upload_max_file_size'          => true,
        'archive_max_size'              => true,
        'archive_targz_max_access_size' => true,
    ];

    private static function defaults(string $configDir): array {
        return [
            'auth_providers'                => [['class' => StaticPasswordAuth::class]],
            'grant_resolvers'               => [['class' => StaticGrantResolver::class]],
            'static_user_list'              => [['id' => 'admin', 'password_hash' => '']],
            'must_embed'                    => false,
            'must_readonly'                 => false,
            'max_edit_size'                 => 4 * 1024 * 1024,
            'upload_chunk_size'             => 4 * 1024 * 1024,
            'upload_max_file_size'          => 1 * 1024 * 1024 * 1024,
            'upload_max_depth'              => 50,
            'chunk_expiry'                  => 86400,
            'chunk_cleanup_chance'          => 10,
            'archive_max_files'             => 10000,
            'archive_max_size'              => 1 * 1024 * 1024 * 1024,
            'archive_list_max_files'        => 10000,
            'archive_tar_max_access_files'  => 1000,
            'archive_targz_max_access_size' => 64 * 1024 * 1024,
            'search_default_limit'          => 200,
            'search_max_limit'              => 1000,
            'search_max_scan'               => 100000,
            'filename_blocklist'            => ['*.php', '.htaccess', '.user.ini', 'web.config'],
            'ignore_pattern'                => [],
            'ignore_realpath'               => [
                $configDir . '/filecarton.php',
                $configDir . '/filecarton.config.toml.php',
                $configDir . '/filecarton',
            ],
            'dotfiles_block'                => false,
            'dotfiles_force_visible'        => false,
            'public_origin'                 => '',
            'sendfile'                      => false,
            'asset_url'                     => '',
            'http_client_proxy'             => '',
            'session_name'                  => 'FILECARTON',
            'dev_server'                    => false,
        ];
    }

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
            foreach (self::resolve($raw, $configDir, $scriptDir) as $name => $val) {
                define_default($name, $val);
            }
        } catch (\Throwable $e) {
            self::$problems[] = $e->getMessage();
            define_default('FILECARTON_MUST_READONLY', true);
            $scriptDir = '';
        }

        self::applyDefaults($configDir, $scriptDir);
    }

    /**
     * Resolve a parsed TOML structure into a map of FILECARTON_* constant names to values.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function resolve(array $raw, string $configDir, string $scriptDir): array {
        $interpolated = self::interpolate($raw, [
            '${ENTRY_POINT_SCRIPT_DIR}' => $scriptDir,
            '${CONFIG_DIR}'             => $configDir,
        ]);
        $defaults = self::defaults($configDir);
        $out = [];

        $set = function (string $k, $v) use ($defaults, &$out): void {
            $k = strtolower($k);
            if (!array_key_exists($k, $defaults)) {
                throw new \RuntimeException("Unknown config key: {$k}");
            }
            if (isset(self::SIZE_KEYS[$k]) && is_string($v)) {
                $v = self::parseSize($v, $k);
            }
            $ok = ($k === 'sendfile')
                ? ($v === false || is_string($v) || is_array($v))
                : (($k === 'dev_server')
                    ? ($v === false || is_string($v))
                    : (gettype($v) === gettype($defaults[$k])));
            if (!$ok) {
                throw new \RuntimeException("Invalid config value for: {$k}");
            }
            $out['FILECARTON_' . strtoupper($k)] = $v;
        };

        foreach ($interpolated as $section => $content) {
            if ($section === 'repo') {
                if (!is_array($content)) {
                    throw new \RuntimeException('Invalid config value for: repo');
                }
                $out['FILECARTON_REPO_SETTING'] = $content;
            } elseif (is_array($content) && !array_key_exists($section, $defaults)) {
                foreach ($content as $key => $value) {
                    $set((string)$key, $value);
                }
            } else {
                $set((string)$section, $content);
            }
        }

        return $out;
    }

    public static function parseSize(string $size, string $keyName = ''): int {
        if (!preg_match('/^(\d+)\s*([kmgKMG]?)[bB]?$/', trim($size), $m)) {
            $label = $keyName !== '' ? " for {$keyName}" : '';
            throw new \RuntimeException("Invalid byte size '{$size}'{$label}");
        }
        return Platform::parsePhpSize($m[1] . $m[2]);
    }

    /** @return string[] */
    public static function problems(): array {
        return self::$problems;
    }

    public static function resetForRequest(): void {
        self::$problems = [];
    }

    /**
     * @param mixed                $value
     * @param array<string,string> $vars
     * @return mixed
     */
    private static function interpolate($value, array $vars) {
        if (is_string($value)) {
            return strtr($value, $vars);
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::interpolate($v, $vars);
            }
        }
        return $value;
    }

    public static function applyDefaults(string $configDir, string $scriptDir): void {
        $ok = self::$problems === [];
        define_default('FILECARTON_REPO_SETTING', [
            'root_path'                => $scriptDir,
            'readonly'                 => !$ok,
            'branding'                 => 'FileCarton',
            'repo_name'                => '',
            'feature_archive'          => $ok,
            'feature_unix_perm'        => false,
            'feature_unix_perm_modify' => false,
            'feature_remote_download'  => false,
            'theme_primary'            => '',
            'theme_toolbar'            => '',
        ]);

        foreach (self::defaults($configDir) as $key => $default) {
            define_default('FILECARTON_' . strtoupper($key), $default);
        }
    }
}
