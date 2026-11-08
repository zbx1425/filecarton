<?php

namespace FileCarton\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PageAnalyticsTest extends TestCase
{
    private const SNIPPET = '<script data-fc="fc-analytics-marker" data-domain="a&b"></script>';

    public function testAppPageInsertsRawAnalyticsBeforeHeadCloses(): void
    {
        $html = $this->renderPage($this->config(self::SNIPPET, false));

        self::assertStringContainsString('FileCarton Loading', $html, $html);
        $snippetAt = strpos($html, self::SNIPPET);
        $headEnd = strpos($html, '</head>');
        self::assertNotFalse($snippetAt, $html);
        self::assertNotFalse($headEnd);
        self::assertLessThan($headEnd, $snippetAt);
        self::assertSame(1, substr_count($html, self::SNIPPET));

        $configAt = strpos($html, 'window.__FILECARTON__');
        self::assertNotFalse($configAt);
        self::assertGreaterThan($headEnd, $configAt);
        $configEnd = strpos($html, '</script>', $configAt);
        self::assertNotFalse($configEnd);
        $configScript = substr($html, $configAt, $configEnd - $configAt);
        self::assertStringNotContainsString('fc-analytics-marker', $configScript);
        self::assertStringNotContainsString('a&amp;b', $html);
    }

    public function testStartupErrorPageOmitsAnalytics(): void
    {
        $html = $this->renderPage($this->config(self::SNIPPET, true));

        self::assertStringContainsString('FileCarton Startup Issues', $html, $html);
        self::assertStringNotContainsString('fc-analytics-marker', $html);
    }

    private function config(string $analytics, bool $dotfilesConflict): string
    {
        $block = $dotfilesConflict ? 'true' : 'false';
        $visible = $dotfilesConflict ? 'true' : 'false';
        return <<<TOML
#<?php exit;?>
[auth]
auth_providers = [{ class = "FileCarton\\\\NoLoginAuth" }]
static_user_list = [{ id = "admin" }]

[repo]
root_path = "__ROOT__"
readonly = true
branding = "FileCarton"
repo_name = ""
feature_archive = false

[limits]
max_edit_size = "1K"
upload_chunk_size = "1K"
upload_max_file_size = "1K"

[security]
dotfiles_block = {$block}
dotfiles_force_visible = {$visible}

[misc]
analytics = '{$analytics}'
TOML;
    }

    private function renderPage(string $toml): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fc_page_' . bin2hex(random_bytes(4));
        mkdir($root);
        $rootPath = str_replace('\\', '/', $root);
        $configFile = $root . DIRECTORY_SEPARATOR . 'filecarton.config.toml.php';
        file_put_contents($configFile, str_replace('__ROOT__', $rootPath, $toml));

        $backend = dirname(__DIR__, 3) . '/apps/backend/filecarton.php';
        $script = 'ini_set("display_errors", "stderr");'
            . '$_SERVER["SCRIPT_NAME"] = "/fc.php";'
            . '$_SERVER["SCRIPT_FILENAME"] = ' . var_export($backend, true) . ';'
            . 'define("FILECARTON_CONFIG_PATH", ' . var_export($configFile, true) . ');'
            . 'define("FILECARTON_DEV_SERVER", "http://127.0.0.1:9");'
            . 'require ' . var_export($backend, true) . ';';

        $proc = proc_open(
            [PHP_BINARY, '-r', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($proc);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $this->removeDir($root);

        self::assertSame(0, $code, $err . $out);
        self::assertIsString($out);
        if ($err !== '') {
            self::fail($err);
        }
        return $out;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
