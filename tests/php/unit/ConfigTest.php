<?php

namespace FileCarton\Tests\Unit;

use FileCarton\Config;
use FileCarton\Toml;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaultConfigTomlFileParsesAndResolvesCleanly(): void
    {
        $configFile = dirname(__DIR__, 3) . '/apps/backend/filecarton.config.toml.php';
        self::assertFileExists($configFile);

        $raw = Toml::parseFile($configFile);
        $resolved = Config::resolve($raw, '/var/www/config', '/var/www/script');

        // Verify [repo] section maps to FILECARTON_REPO_SETTING with variable interpolation
        self::assertArrayHasKey('FILECARTON_REPO_SETTING', $resolved);
        $repo = $resolved['FILECARTON_REPO_SETTING'];
        self::assertSame('/var/www/script', $repo['root_path']);
        self::assertFalse($repo['readonly']);
        self::assertSame('FileCarton', $repo['branding']);
        self::assertSame('', $repo['repo_name']);
        self::assertTrue($repo['feature_archive']);
        self::assertFalse($repo['feature_unix_perm']);
        self::assertFalse($repo['feature_unix_perm_modify']);
        self::assertFalse($repo['feature_remote_download']);

        // Verify [auth] section
        self::assertSame(
            [['class' => 'FileCarton\StaticPasswordAuth']],
            $resolved['FILECARTON_AUTH_PROVIDERS']
        );
        self::assertSame(
            [['class' => 'FileCarton\StaticGrantResolver']],
            $resolved['FILECARTON_GRANT_RESOLVERS']
        );
        self::assertCount(1, $resolved['FILECARTON_STATIC_USER_LIST']);
        self::assertSame('admin', $resolved['FILECARTON_STATIC_USER_LIST'][0]['id']);
        self::assertFalse($resolved['FILECARTON_MUST_EMBED']);

        // Verify [limits] size conversion
        self::assertSame(4 * 1024 * 1024, $resolved['FILECARTON_MAX_EDIT_SIZE']);
        self::assertSame(1024 * 1024 * 1024, $resolved['FILECARTON_UPLOAD_MAX_FILE_SIZE']);
        self::assertSame(4 * 1024 * 1024, $resolved['FILECARTON_UPLOAD_CHUNK_SIZE']);
        self::assertSame(1024 * 1024 * 1024, $resolved['FILECARTON_ARCHIVE_MAX_SIZE']);
        self::assertSame(64 * 1024 * 1024, $resolved['FILECARTON_ARCHIVE_TARGZ_MAX_ACCESS_SIZE']);
        self::assertSame(200, $resolved['FILECARTON_SEARCH_DEFAULT_LIMIT']);
        self::assertSame(1000, $resolved['FILECARTON_SEARCH_MAX_LIMIT']);
        self::assertSame(100000, $resolved['FILECARTON_SEARCH_MAX_SCAN']);

        // Verify [security] variable interpolation
        self::assertSame([
            '/var/www/config/filecarton.php',
            '/var/www/config/filecarton.config.toml.php',
            '/var/www/config/filecarton',
        ], $resolved['FILECARTON_IGNORE_REALPATH']);
    }

    public function testLoadInvalidConfigFileRecordsProblems(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'fc_bad_toml_');
        self::assertIsString($tmpFile);
        file_put_contents($tmpFile, "[repo\nbroken = true\n");
        try {
            Config::load($tmpFile);
            $problems = Config::problems();
            self::assertNotEmpty($problems);
            self::assertStringContainsString("Expected ']'", $problems[0]);
        } finally {
            Config::resetForRequest();
            @unlink($tmpFile);
        }
    }

    public function testLoadMissingConfigFileFailsWhenNotEmbedded(): void
    {
        $nonExistent = sys_get_temp_dir() . '/fc_nonexistent_' . bin2hex(random_bytes(4)) . '.toml.php';
        try {
            Config::load($nonExistent);
            $problems = Config::problems();
            self::assertNotEmpty($problems);
            self::assertStringContainsString('Configuration file cannot be read', $problems[0]);
        } finally {
            Config::resetForRequest();
        }
    }

    public function testVariableInterpolationInNestedArrays(): void
    {
        $raw = [
            'auth' => [
                'static_user_list' => [
                    [
                        'id' => 'alice',
                        'root_path' => '${ENTRY_POINT_SCRIPT_DIR}/users/alice',
                        'branding' => 'From ${CONFIG_DIR}',
                    ],
                ],
            ],
            'repo' => [
                'root_path' => '${CONFIG_DIR}/files',
            ],
        ];

        $resolved = Config::resolve($raw, '/etc/filecarton', '/srv/www');
        self::assertSame('/etc/filecarton/files', $resolved['FILECARTON_REPO_SETTING']['root_path']);
        self::assertSame('/srv/www/users/alice', $resolved['FILECARTON_STATIC_USER_LIST'][0]['root_path']);
        self::assertSame('From /etc/filecarton', $resolved['FILECARTON_STATIC_USER_LIST'][0]['branding']);
    }

    public function testParseSizeValidAndInvalid(): void
    {
        self::assertSame(512, Config::parseSize('512'));
        self::assertSame(64 * 1024, Config::parseSize('64K'));
        self::assertSame(4 * 1024 * 1024, Config::parseSize('4M'));
        self::assertSame(2 * 1024 * 1024 * 1024, Config::parseSize('2G'));

        $this->expectException(\RuntimeException::class);
        Config::parseSize('invalid_size');
    }

    public function testRejectsReservedAndUnknownKeys(): void
    {
        foreach (['embed' => true, 'single_file' => true, 'script_dir' => '/tmp', 'nonexistent_key' => 1] as $key => $val) {
            try {
                Config::resolve(['auth' => [$key => $val]], '/cfg', '/scr');
                self::fail("Expected exception for disallowed key '{$key}'");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString("Unknown config key: {$key}", $e->getMessage());
            }
        }
    }

    public function testRejectsWrongValueTypes(): void
    {
        $invalidCases = [
            ['security' => ['ignore_realpath' => '/not/an/array']],
            ['security' => ['filename_blocklist' => '*.php']],
            ['security' => ['must_readonly' => 'true']],
            ['limits'   => ['upload_max_depth' => '50']],
            ['repo'     => 'not_a_table'],
        ];

        foreach ($invalidCases as $raw) {
            try {
                Config::resolve($raw, '/cfg', '/scr');
                self::fail('Expected exception for invalid config value type');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('Invalid config value for:', $e->getMessage());
            }
        }
    }

    public function testLoadBrokenConfigFileFailsClosedInCleanProcess(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'fc_bad_toml_');
        self::assertIsString($tmpFile);
        file_put_contents($tmpFile, "[repo\nbroken = true\n");
        try {
            $res = $this->runCleanConfigLoad($tmpFile, false);
            self::assertNotEmpty($res['problems']);
            self::assertTrue($res['must_readonly']);
            self::assertSame('', $res['repo']['root_path']);
            self::assertTrue($res['repo']['readonly']);
            self::assertFalse($res['repo']['feature_archive']);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testLoadMissingConfigFileSucceedsWhenEmbeddedInCleanProcess(): void
    {
        $nonExistent = sys_get_temp_dir() . '/fc_nonexistent_' . bin2hex(random_bytes(4)) . '.toml.php';
        $res = $this->runCleanConfigLoad($nonExistent, true);

        self::assertSame([], $res['problems']);
        self::assertFalse($res['must_readonly']);
    }

    /**
     * @return array<string, mixed>
     */
    private function runCleanConfigLoad(string $configFile, bool $embed): array
    {
        $backend = dirname(__DIR__, 3) . '/apps/backend/filecarton/lib';
        $script = sprintf(
            'require %s; require %s; require %s; require %s; %s \FileCarton\Config::load(%s); echo json_encode([\'problems\' => \FileCarton\Config::problems(), \'must_readonly\' => FILECARTON_MUST_READONLY, \'repo\' => FILECARTON_REPO_SETTING]);',
            var_export($backend . '/Polyfill.php', true),
            var_export($backend . '/Platform.php', true),
            var_export($backend . '/Toml.php', true),
            var_export($backend . '/Config.php', true),
            $embed ? "define('FILECARTON_EMBED', true);" : '',
            var_export($configFile, true)
        );

        $proc = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        self::assertIsString($out);
        $decoded = json_decode($out, true);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
