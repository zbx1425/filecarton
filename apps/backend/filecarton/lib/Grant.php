<?php

namespace FileCarton;

interface GrantResolver {
    /**
     * @return AuthGrant|null  null = pass to the next resolver
     */
    public function resolve(AuthIdentity $identity);
}

class AuthGrant {
    /** @var string|null null = inherit FILECARTON_ROOT_PATH; non-empty = overlay */
    public $rootPath;
    /** @var bool|null true = force readonly; null/false = inherit global */
    public $readonly;
    /** @var string|null null = inherit; string including '' = use it */
    public $branding;
    /** @var string|null */
    public $repoName;

    public function __construct() {
        $this->rootPath = null;
        $this->readonly = null;
        $this->branding = null;
        $this->repoName = null;
    }

    public static function fromStaticRow(array $row): AuthGrant {
        $g = new AuthGrant();
        if (isset($row['root']) && is_string($row['root']) && $row['root'] !== '') {
            $g->rootPath = $row['root'];
        }
        if (array_key_exists('readonly', $row) && $row['readonly'] === true) {
            $g->readonly = true;
        }
        if (array_key_exists('branding', $row) && is_string($row['branding'])) {
            $g->branding = $row['branding'];
        }
        if (array_key_exists('repoName', $row) && is_string($row['repoName'])) {
            $g->repoName = $row['repoName'];
        }
        return $g;
    }
}

class StaticGrantResolver implements GrantResolver {
    public function __construct(array $opts = []) {
    }

    public function resolve(AuthIdentity $identity) {
        $list = defined('FILECARTON_STATIC_USER_LIST') ? FILECARTON_STATIC_USER_LIST : [];
        if (!is_array($list)) return null;
        foreach ($list as $row) {
            if (!is_array($row) || !isset($row['id']) || !is_string($row['id']) || $row['id'] === '') {
                continue;
            }
            if (strcasecmp($row['id'], $identity->id) === 0) {
                return AuthGrant::fromStaticRow($row);
            }
        }
        return null;
    }
}

class Grants {
    /** @var GrantResolver[] */
    private static $resolvers = [];
    /** @var bool */
    private static $booted = false;
    /** @var AuthGrant|null|false */
    private static $cached = false;
    /** @var string[] */
    private static $bootErrors = [];
    /** @var string[] */
    private static $files = [];

    public static function resetForRequest() {
        self::$cached = false;
    }

    /** @return string[] */
    public static function bootErrors(): array {
        return self::$bootErrors;
    }

    /** @return string[] */
    public static function pluginFiles(): array {
        return self::$files;
    }

    public static function boot() {
        if (self::$booted) return;
        self::$booted = true;
        self::$resolvers = [];
        $specs = defined('FILECARTON_GRANT_RESOLVERS') ? FILECARTON_GRANT_RESOLVERS : [];
        if (!is_array($specs)) {
            self::$bootErrors[] = 'FILECARTON_GRANT_RESOLVERS must be an array.';
            $specs = [];
        }
        foreach ($specs as $i => $spec) {
            if (!is_array($spec)) {
                self::$bootErrors[] = 'FILECARTON_GRANT_RESOLVERS[' . $i . '] must be an array.';
                continue;
            }
            try {
                $obj = Auth::instantiateFromSpec($spec, GrantResolver::class);
                if (!$obj instanceof GrantResolver) {
                    self::$bootErrors[] = 'Grant resolver is not a GrantResolver.';
                    continue;
                }
                self::$resolvers[] = $obj;
                if (isset($spec['file']) && is_string($spec['file']) && $spec['file'] !== '') {
                    self::$files['grant:' . $i] = $spec['file'];
                }
            } catch (\Throwable $e) {
                self::$bootErrors[] = $e->getMessage();
            }
        }
    }

    /**
     * Request-cached grant for Auth::identity(). Null = unauthorized.
     * @return AuthGrant|null
     */
    public static function current() {
        $identity = Auth::identity();
        if ($identity === null) {
            self::$cached = null;
            return null;
        }
        if (self::$cached !== false) {
            return self::$cached;
        }
        self::$cached = self::resolveFor($identity);
        return self::$cached;
    }

    /**
     * First non-null wins. StaticGrantResolver is always last.
     * @return AuthGrant|null
     */
    public static function resolveFor(AuthIdentity $identity) {
        foreach (self::$resolvers as $r) {
            try {
                $g = $r->resolve($identity);
            } catch (\Throwable $e) {
                error_log('FileCarton auth: grant resolver threw: ' . $e->getMessage());
                continue;
            }
            if ($g instanceof AuthGrant) return $g;
        }
        return null;
    }
}
