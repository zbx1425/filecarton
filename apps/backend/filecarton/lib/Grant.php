<?php

namespace FileCarton;

interface GrantResolver {
    /**
     * @return RepoSetting|null  null = pass to the next resolver
     */
    public function resolve(AuthIdentity $identity);

    /** @return string[] Configuration problems specific to this resolver. */
    public function validateConfig(): array;
}

class StaticGrantResolver implements GrantResolver {
    public function __construct(array $opts = []) {
    }

    public function validateConfig(): array {
        $p = [];
        $list = Auth::staticUserList();
        if ($list === []) {
            $hasInteractive = false;
            foreach (Auth::providers() as $prov) {
                if ($prov instanceof PasswordAuth || $prov instanceof RedirectAuth) {
                    $hasInteractive = true;
                    break;
                }
            }
            $allStatic = true;
            foreach (Grants::resolvers() as $r) {
                if (!$r instanceof StaticGrantResolver) {
                    $allStatic = false;
                    break;
                }
            }
            if ($hasInteractive && $allStatic) {
                $p[] = 'FILECARTON_STATIC_USER_LIST is empty, users will not receive a grant.';
            }
        }
        return $p;
    }

    public function resolve(AuthIdentity $identity) {
        $list = defined('FILECARTON_STATIC_USER_LIST') ? FILECARTON_STATIC_USER_LIST : [];
        if (!is_array($list)) return null;
        foreach ($list as $row) {
            if (!is_array($row) || !isset($row['id']) || !is_string($row['id']) || $row['id'] === '') {
                continue;
            }
            if (strcasecmp($row['id'], $identity->id) === 0) {
                return RepoSetting::fromStaticRow($row);
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
    /** @var RepoSetting|null|false */
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

    /** @return GrantResolver[] */
    public static function resolvers(): array {
        return self::$resolvers;
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
     * @return RepoSetting|null
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
     * First non-null wins; resolver order follows FILECARTON_GRANT_RESOLVERS.
     * @return RepoSetting|null
     */
    public static function resolveFor(AuthIdentity $identity) {
        foreach (self::$resolvers as $r) {
            try {
                $g = $r->resolve($identity);
            } catch (\Throwable $e) {
                error_log('FileCarton auth: grant resolver threw: ' . $e->getMessage());
                continue;
            }
            if ($g instanceof RepoSetting) return $g;
        }
        return null;
    }
}
