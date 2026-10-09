<?php

namespace FileCarton;

class Settings {
    /** @var bool */
    private static $resolved = false;
    /** @var string */
    private static $rootPath = '';
    /** @var bool */
    private static $readonly = false;
    /** @var string */
    private static $branding = '';
    /** @var string */
    private static $repoName = '';

    public static function embed(): bool {
        return defined('FILECARTON_EMBED') && FILECARTON_EMBED === true;
    }

    public static function filesOpen(): bool {
        return self::rootPath() !== '';
    }

    public static function rootPath(): string {
        self::resolve();
        return self::$rootPath;
    }

    public static function readonly(): bool {
        self::resolve();
        return self::$readonly;
    }

    public static function branding(): string {
        self::resolve();
        return self::$branding;
    }

    public static function repoName(): string {
        self::resolve();
        return self::$repoName;
    }

    /** @internal */
    public static function resetForRequest() {
        self::$resolved = false;
        self::$rootPath = '';
        self::$readonly = false;
        self::$branding = '';
        self::$repoName = '';
    }

    private static function constString($name, $default = '') {
        if (!defined($name)) return $default;
        $v = constant($name);
        return is_string($v) ? $v : $default;
    }

    private static function constBool($name) {
        return defined($name) && constant($name) === true;
    }

    private static function resolve() {
        if (self::$resolved) return;
        self::$resolved = true;

        $globalRoot = self::constString('FILECARTON_ROOT_PATH');
        $globalReadonly = self::constBool('FILECARTON_READONLY');
        $globalBranding = self::constString('FILECARTON_BRANDING');
        $globalRepo = self::constString('FILECARTON_REPO_NAME');

        if (self::embed()) {
            self::$rootPath = $globalRoot;
            self::$readonly = $globalReadonly;
            self::$branding = $globalBranding;
            self::$repoName = $globalRepo;
            return;
        }

        $identity = Auth::identity();
        if ($identity === null) {
            self::$rootPath = '';
            self::$readonly = $globalReadonly;
            self::$branding = $globalBranding;
            self::$repoName = $globalRepo;
            return;
        }

        $grant = Grants::current();
        if ($grant === null) {
            self::$rootPath = '';
            self::$readonly = $globalReadonly;
            self::$branding = $globalBranding;
            self::$repoName = $globalRepo;
            return;
        }

        if (is_string($grant->rootPath) && $grant->rootPath !== '') {
            $real = realpath($grant->rootPath);
            self::$rootPath = ($real !== false && is_dir($real)) ? $real : '';
        } else {
            self::$rootPath = $globalRoot;
        }

        self::$readonly = $globalReadonly || ($grant->readonly === true);
        self::$branding = ($grant->branding !== null) ? $grant->branding : $globalBranding;
        self::$repoName = ($grant->repoName !== null) ? $grant->repoName : $globalRepo;
    }
}
