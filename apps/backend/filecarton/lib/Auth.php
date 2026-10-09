<?php

namespace FileCarton;

interface AuthProvider {
    /** Stable id used in URLs and session. ^[a-z][a-z0-9_]{0,31}$ */
    public function id(): string;

    /** Button caption / form heading fragment. Not HTML. */
    public function label(): string;

    /** 'password' | 'redirect' | 'implicit' */
    public function kind(): string;

    /** @return string[] Configuration problems specific to this provider. */
    public function validateConfig(): array;
}

class AuthIdentity {
    /** @var string */
    public $id;
    /** @var string */
    public $displayName;
    /** @var string */
    public $pluginId;
    /** @var array */
    public $extra;

    public function __construct($id, $displayName, $pluginId, array $extra = []) {
        $this->id = (string)$id;
        $this->displayName = (string)$displayName;
        $this->pluginId = (string)$pluginId;
        $this->extra = $extra;
    }

    public function toPublicArray(): array {
        return [
            'id'          => $this->id,
            'displayName' => $this->displayName,
            'pluginId'    => $this->pluginId,
        ];
    }
}

class AuthException extends \RuntimeException {
    /** @var string SPA token: denied|expired|allowlist|exchange|config|unknown */
    public $token;

    public function __construct($token, $httpCode, $logMessage = '') {
        $this->token = (string)$token;
        parent::__construct($logMessage !== '' ? $logMessage : (string)$token, (int)$httpCode);
    }
}

/**
 * Provider-facing request/session view for redirect auth flows.
 */
class AuthContext {
    /** @var RedirectAuth */
    private $plugin;
    /** @var string|null */
    private $nonce;
    /** @var array|null */
    private $consumed;

    private function __construct() {}

    public static function forStart(RedirectAuth $plugin, $nonce): AuthContext {
        $ctx = new AuthContext();
        $ctx->plugin = $plugin;
        $ctx->nonce = (string)$nonce;
        $ctx->consumed = null;
        return $ctx;
    }

    public static function forComplete(RedirectAuth $plugin, array $consumed): AuthContext {
        $ctx = new AuthContext();
        $ctx->plugin = $plugin;
        $ctx->nonce = isset($consumed['nonce']) ? (string)$consumed['nonce'] : '';
        $ctx->consumed = $consumed;
        if (!isset($ctx->consumed['extra']) || !is_array($ctx->consumed['extra'])) {
            $ctx->consumed['extra'] = [];
        }
        return $ctx;
    }

    public function pluginId(): string {
        return $this->plugin->id();
    }

    public function nonce(): string {
        return (string)$this->nonce;
    }

    /**
     * Absolute callback URL. Pass true to append fc_nonce= (non-OAuth IdPs).
     */
    public function callbackUrl($includeNonce = false): string {
        $url = Auth::absoluteScriptUrl() . '?fcauth=callback&plugin='
            . rawurlencode($this->plugin->id());
        if ($includeNonce) {
            $url .= '&fc_nonce=' . rawurlencode($this->nonce());
        }
        return $url;
    }

    /** `{pluginId}.{nonce}.{hmac}` */
    public function signedState(): string {
        return Auth::signPending($this->plugin->id(), $this->nonce());
    }

    public function param($key) {
        if (isset($_GET[$key]) && is_string($_GET[$key]) && $_GET[$key] !== '') {
            return $_GET[$key];
        }
        return null;
    }

    public function setExtra($key, $value) {
        if ($this->consumed !== null) {
            $this->consumed['extra'][$key] = $value;
            return;
        }
        $n = $this->nonce;
        if ($n === null || $n === '' || empty($_SESSION[Auth::SESSION_PENDING][$n])
            || !is_array($_SESSION[Auth::SESSION_PENDING][$n])) {
            throw new \RuntimeException('Auth pending row missing', 500);
        }
        $_SESSION[Auth::SESSION_PENDING][$n]['extra'][$key] = $value;
    }

    public function getExtra($key, $default = null) {
        if ($this->consumed !== null) {
            return array_key_exists($key, $this->consumed['extra'])
                ? $this->consumed['extra'][$key]
                : $default;
        }
        $n = $this->nonce;
        if ($n === null || empty($_SESSION[Auth::SESSION_PENDING][$n]['extra'])
            || !is_array($_SESSION[Auth::SESSION_PENDING][$n]['extra'])) {
            return $default;
        }
        $extra = $_SESSION[Auth::SESSION_PENDING][$n]['extra'];
        return array_key_exists($key, $extra) ? $extra[$key] : $default;
    }
}

class Auth {
    const SESSION_USER    = 'filecarton_auth';
    const SESSION_PENDING = 'filecarton_auth_pending';
    const SESSION_HMAC    = 'filecarton_auth_hmac_key';
    const PENDING_TTL     = 600;
    const PENDING_MAX     = 10;
    const ID_PATTERN      = '/^[a-z][a-z0-9_]{0,31}$/';

    /** @var AuthProvider[] */
    private static $providers = [];
    /** @var bool */
    private static $booted = false;
    /** @var string[] */
    private static $bootErrors = [];
    /** @var string[] provider id => absolute path */
    private static $pluginFiles = [];
    /** @var string Error token to inject into the frontend config (in-place rendering). */
    private static $inlineError = '';

    public static function instantiateFromSpec(array $spec, $mustBe) {
        if (isset($spec['file']) && is_string($spec['file']) && $spec['file'] !== '') {
            $file = $spec['file'];
            if (!is_file($file)) {
                throw new \InvalidArgumentException('Plugin file not found: ' . basename($file));
            }
            $loaded = require_once $file; // Unused, this is to make our compiler not remove it
        }
        if (empty($spec['class']) || !is_string($spec['class'])) {
            throw new \InvalidArgumentException('Spec missing class');
        }
        $class = $spec['class'];
        if (!class_exists($class)) {
            throw new \InvalidArgumentException('Class not found: ' . $class);
        }
        $opts = $spec;
        unset($opts['class'], $opts['file']);
        $obj = new $class($opts);
        if ($mustBe === GrantResolver::class) {
            return $obj;
        }
        if (!$obj instanceof AuthProvider) {
            throw new \InvalidArgumentException($class . ' is not an AuthProvider');
        }
        return $obj;
    }

    private static function validateProvider(AuthProvider $plugin) {
        $id = $plugin->id();
        if (!preg_match(self::ID_PATTERN, $id)) {
            throw new \InvalidArgumentException('Invalid auth provider id: ' . $id);
        }
        $kind = $plugin->kind();
        if ($kind === 'password' && !$plugin instanceof PasswordAuth) {
            throw new \InvalidArgumentException($id . ' kind=password must extend PasswordAuth');
        }
        if ($kind === 'redirect' && !$plugin instanceof RedirectAuth) {
            throw new \InvalidArgumentException($id . ' kind=redirect must extend RedirectAuth');
        }
        if ($kind === 'implicit' && !$plugin instanceof NoLoginAuth) {
            throw new \InvalidArgumentException($id . ' kind=implicit must be NoLoginAuth');
        }
        if ($kind !== 'password' && $kind !== 'redirect' && $kind !== 'implicit') {
            throw new \InvalidArgumentException($id . ' unknown kind ' . $kind);
        }
    }

    private static function appendProvider(AuthProvider $plugin) {
        self::validateProvider($plugin);
        foreach (self::$providers as $existing) {
            if ($existing->id() === $plugin->id()) {
                throw new \InvalidArgumentException('Duplicate auth provider id: ' . $plugin->id());
            }
        }
        self::$providers[] = $plugin;
    }

    /** @return AuthProvider[] */
    public static function providers(): array {
        return self::$providers;
    }

    public static function pluginById($id) {
        foreach (self::$providers as $p) {
            if ($p->id() === $id) return $p;
        }
        return null;
    }

    /** @return string[] provider id => absolute path */
    public static function pluginFiles(): array {
        return self::$pluginFiles;
    }

    /**
     * Resolved absolute paths of all auth/grant plugin files, for PathSecurity auto-ignore.
     * @return string[]
     */
    public static function pluginIgnorePaths(): array {
        $paths = [];
        $files = self::$pluginFiles + Grants::pluginFiles();
        foreach ($files as $file) {
            $real = realpath($file);
            if ($real !== false) {
                $paths[] = str_replace('\\', '/', $real);
            }
        }
        return $paths;
    }

    public static function boot() {
        if (self::$booted) return;
        self::$booted = true;
        if (defined('FILECARTON_EMBED') && FILECARTON_EMBED === true) {
            return;
        }
        self::ensureSession();
        $specs = defined('FILECARTON_AUTH_PROVIDERS') ? FILECARTON_AUTH_PROVIDERS : [];
        if (!is_array($specs)) {
            self::$bootErrors[] = 'FILECARTON_AUTH_PROVIDERS must be an array.';
            $specs = [];
        }
        foreach ($specs as $i => $spec) {
            if (!is_array($spec)) {
                self::$bootErrors[] = 'FILECARTON_AUTH_PROVIDERS[' . $i . '] must be an array.';
                continue;
            }
            try {
                $p = self::instantiateFromSpec($spec, AuthProvider::class);
                self::appendProvider($p);
                if (isset($spec['file']) && is_string($spec['file']) && $spec['file'] !== '') {
                    self::$pluginFiles[$p->id()] = $spec['file'];
                }
            } catch (\Throwable $e) {
                self::$bootErrors[] = $e->getMessage();
            }
        }
        Grants::boot();
        self::gcPending();
    }

    public static function staticUserList(): array {
        $list = defined('FILECARTON_STATIC_USER_LIST') ? FILECARTON_STATIC_USER_LIST : [];
        return is_array($list) ? $list : [];
    }

    /**
     * @return string[]
     */
    public static function configProblems(): array {
        $p = self::$bootErrors;
        if (defined('FILECARTON_EMBED') && FILECARTON_EMBED === true) return $p;

        // Static user list format
        $list = self::staticUserList();
        $ids = [];
        foreach ($list as $i => $row) {
            if (!is_array($row) || !isset($row['id']) || !is_string($row['id']) || $row['id'] === '') {
                $p[] = 'FILECARTON_STATIC_USER_LIST[' . $i . '] missing id.';
                continue;
            }
            $key = strtolower($row['id']);
            if (isset($ids[$key])) {
                $p[] = 'FILECARTON_STATIC_USER_LIST contains a duplicate user ID.';
            }
            $ids[$key] = true;
            if (isset($row['passwordHash']) && is_string($row['passwordHash']) && $row['passwordHash'] !== ''
                && strpos($row['id'], ':') !== false) {
                $p[] = 'FILECARTON_STATIC_USER_LIST[' . $i . '] id must not contain ":" when passwordHash is set (reserved for OAuth provider prefixes).';
            }
        }

        // Provider combination rules
        $nImplicit = 0;
        $nInteractive = 0;
        foreach (self::$providers as $prov) {
            if ($prov instanceof NoLoginAuth) $nImplicit++;
            if ($prov instanceof PasswordAuth || $prov instanceof RedirectAuth) $nInteractive++;
        }
        if ($nImplicit > 1) {
            $p[] = 'At most one implicit (NoLoginAuth) provider is allowed.';
        }
        if ($nImplicit > 0 && $nInteractive > 0) {
            $p[] = 'NoLoginAuth cannot be combined with password or redirect providers.';
        }
        if ($nImplicit === 0 && $nInteractive === 0) {
            $p[] = 'No auth providers registered (add NoLoginAuth, passwordHash rows, or AUTH_PROVIDERS).';
        }

        // Grant resolver availability
        $grantSpecs = defined('FILECARTON_GRANT_RESOLVERS') ? FILECARTON_GRANT_RESOLVERS : [];
        $grantSpecs = is_array($grantSpecs) ? $grantSpecs : [];
        $hasAnyResolver = false;
        foreach ($grantSpecs as $spec) {
            if (is_array($spec) && !empty($spec['class'])) {
                $hasAnyResolver = true;
            }
        }
        if ($nInteractive > 0 && !$hasAnyResolver) {
            $p[] = 'No grant resolvers configured; interactive logins will always be rejected.';
        }

        foreach (Grants::bootErrors() as $e) {
            $p[] = $e;
        }

        // Provider and resolver self-reported problems
        foreach (self::$providers as $prov) {
            foreach ($prov->validateConfig() as $msg) {
                $p[] = $msg;
            }
        }
        foreach (Grants::resolvers() as $r) {
            foreach ($r->validateConfig() as $msg) {
                $p[] = $msg;
            }
        }

        return $p;
    }

    public static function ensureSession() {
        Csrf::ensureSession();
        self::ensureHmacKey();
    }

    private static function ensureHmacKey() {
        if (empty($_SESSION[self::SESSION_HMAC]) || !is_string($_SESSION[self::SESSION_HMAC])) {
            $_SESSION[self::SESSION_HMAC] = bin2hex(random_bytes(32));
        }
    }

    private static function hmacSecret(): string {
        self::ensureSession();
        return $_SESSION[self::SESSION_HMAC];
    }

    /**
     * NoLoginAuth: synthesize every request, do not read/write session identity.
     * Password/redirect: session principal. Embed: null.
     * @return AuthIdentity|null
     */
    public static function identity() {
        if (defined('FILECARTON_EMBED') && FILECARTON_EMBED === true) return null;
        foreach (self::$providers as $p) {
            if ($p instanceof NoLoginAuth) {
                return $p->identity();
            }
        }
        self::ensureSession();
        if (!isset($_SESSION[self::SESSION_USER]['id'])) return null;
        $u = $_SESSION[self::SESSION_USER];
        return new AuthIdentity($u['id'], $u['displayName'], $u['pluginId'], $u['extra'] ?? []);
    }

    public static function hasImplicit(): bool {
        foreach (self::$providers as $p) {
            if ($p instanceof NoLoginAuth) return true;
        }
        return false;
    }

    /**
     * Sanitize icon value from a provider.
     *
     * Accepts a URL string (https: or data:image/) or an array with mono_url
     * and optional light_tint/dark_tint (#RGB or #RRGGBB).
     * Returns '' for invalid input, a string for color URLs, or an array
     * for monochrome mask icons.
     *
     * @param mixed $icon
     * @return string|array
     */
    public static function sanitizeIcon($icon) {
        if (is_string($icon)) {
            if ($icon === '') return '';
            if (strpbrk($icon, "\r\n\0") !== false) return '';
            if (stripos($icon, 'https:') === 0) return $icon;
            if (stripos($icon, 'data:image/') === 0) return $icon;
            return '';
        }
        if (!is_array($icon) || !isset($icon['mono_url']) || !is_string($icon['mono_url'])) {
            return '';
        }
        $url = $icon['mono_url'];
        if (strpbrk($url, "\r\n\0") !== false) return '';
        if (stripos($url, 'https:') !== 0 && stripos($url, 'data:image/') !== 0) return '';
        $out = ['mono_url' => $url];
        foreach (['light_tint', 'dark_tint'] as $key) {
            if (isset($icon[$key]) && is_string($icon[$key])
                && preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $icon[$key])) {
                $out[$key] = $icon[$key];
            }
        }
        return $out;
    }

    /**
     * Try each PasswordAuth in boot order.
     * @return AuthIdentity|null
     */
    public static function loginPassword($username, $password) {
        self::ensureSession();
        $username = is_string($username) ? $username : '';
        $password = is_string($password) ? $password : '';
        if ($username === '' || $password === '' || strlen($username) > 200 || strlen($password) > 1024) {
            return null;
        }
        foreach (self::$providers as $p) {
            if (!$p instanceof PasswordAuth) continue;
            try {
                $identity = $p->verify($username, $password);
            } catch (\Throwable $e) {
                error_log('FileCarton auth: provider ' . $p->id() . ' verify failed: ' . $e->getMessage());
                continue;
            }
            if ($identity instanceof AuthIdentity) {
                self::establish($identity);
                return $identity;
            }
        }
        return null;
    }

    /**
     * Password / redirect only. Rotates CSRF and session ID.
     * Session ID is only regenerated when FileCarton started the session
     * itself; embedders who share a session are left alone.
     */
    public static function establish(AuthIdentity $identity) {
        self::ensureSession();
        if (Grants::resolveFor($identity) === null) {
            throw new AuthException('allowlist', 403, 'No grant for identity');
        }
        $_SESSION[self::SESSION_USER] = [
            'id'          => $identity->id,
            'displayName' => $identity->displayName,
            'pluginId'    => $identity->pluginId,
            'extra'       => $identity->extra,
            'loggedInAt'  => time(),
        ];
        if (Csrf::isOwnSession()) {
            session_regenerate_id(true);
        }
        Csrf::generate();
    }

    public static function logout() {
        self::ensureSession();
        foreach (array_keys($_SESSION) as $k) {
            if (is_string($k) && strpos($k, 'filecarton_auth') === 0) {
                unset($_SESSION[$k]);
            }
        }
        if (Csrf::isOwnSession()) {
            session_regenerate_id(true);
        }
        Csrf::generate();
    }

    public static function catalog(): array {
        $out = [];
        foreach (self::$providers as $p) {
            if ($p instanceof NoLoginAuth) continue;
            $row = [
                'id'    => $p->id(),
                'label' => $p->label(),
                'kind'  => $p->kind(),
            ];
            if ($p instanceof RedirectAuth) {
                $icon = self::sanitizeIcon($p->icon());
                if ($icon !== '' && $icon !== []) $row['icon'] = $icon;
            }
            $out[] = $row;
        }
        return $out;
    }

    public static function setInlineError(string $token): void {
        self::$inlineError = $token;
    }

    public static function frontendConfig(): array {
        $user = self::identity();
        $cfg = [
            'enabled'       => true,
            'implicit'      => self::hasImplicit(),
            'authenticated' => $user !== null,
            'user'          => $user ? $user->toPublicArray() : null,
            'plugins'       => self::catalog(),
        ];
        if (self::$inlineError !== '') {
            $cfg['error'] = self::$inlineError;
        }
        return $cfg;
    }

    // ── Redirect pending bag ──

    public static function captureReturnContext(): array {
        $hash = isset($_GET['fc_return_hash']) && is_string($_GET['fc_return_hash'])
            ? self::sanitizeHash($_GET['fc_return_hash']) : '';
        return [
            'path_info'   => self::sanitizePathInfo($_SERVER['PATH_INFO'] ?? ''),
            'state_query' => self::capturePassthroughQuery(),
            'hash'        => $hash,
        ];
    }

    public static function emptyReturnContext(): array {
        return [
            'path_info'   => '',
            'state_query' => [],
            'hash'        => '',
        ];
    }

    public static function createPending($pluginId): string {
        self::ensureSession();
        self::gcPending();
        $nonce = bin2hex(random_bytes(16));
        $ctx = self::captureReturnContext();
        $row = [
            'nonce'       => $nonce,
            'plugin'      => $pluginId,
            'created'     => time(),
            'path_info'   => $ctx['path_info'],
            'state_query' => $ctx['state_query'],
            'hash'        => $ctx['hash'],
            'extra'       => [],
        ];
        if (!isset($_SESSION[self::SESSION_PENDING]) || !is_array($_SESSION[self::SESSION_PENDING])) {
            $_SESSION[self::SESSION_PENDING] = [];
        }
        $_SESSION[self::SESSION_PENDING][$nonce] = $row;
        return $nonce;
    }

    /**
     * Look up a pending row without consuming it (for error-recovery context).
     */
    public static function peekPending($pluginId, $signedOrNonce) {
        self::ensureSession();
        $nonce = null;
        if (is_string($signedOrNonce) && strpos($signedOrNonce, '.') !== false) {
            $nonce = self::verifySignedPending($pluginId, $signedOrNonce);
        } elseif (is_string($signedOrNonce) && preg_match('/^[a-f0-9]{32}$/', $signedOrNonce)) {
            $nonce = $signedOrNonce;
        }
        if ($nonce === null) return null;
        if (empty($_SESSION[self::SESSION_PENDING][$nonce])
            || !is_array($_SESSION[self::SESSION_PENDING][$nonce])) {
            return null;
        }
        $row = $_SESSION[self::SESSION_PENDING][$nonce];
        if (($row['plugin'] ?? '') !== $pluginId) return null;
        return $row;
    }

    public static function consumePending($pluginId, $signedOrNonce) {
        self::ensureSession();
        self::gcPending();
        $nonce = null;
        if (is_string($signedOrNonce) && strpos($signedOrNonce, '.') !== false) {
            $nonce = self::verifySignedPending($pluginId, $signedOrNonce);
        } elseif (is_string($signedOrNonce) && preg_match('/^[a-f0-9]{32}$/', $signedOrNonce)) {
            $nonce = $signedOrNonce;
        }
        if ($nonce === null) return null;
        if (empty($_SESSION[self::SESSION_PENDING][$nonce])
            || !is_array($_SESSION[self::SESSION_PENDING][$nonce])) {
            return null;
        }
        $row = $_SESSION[self::SESSION_PENDING][$nonce];
        if (($row['plugin'] ?? '') !== $pluginId) {
            return null;
        }
        unset($_SESSION[self::SESSION_PENDING][$nonce]);
        if (!isset($row['extra']) || !is_array($row['extra'])) {
            $row['extra'] = [];
        }
        return $row;
    }

    public static function signPending($pluginId, $nonce): string {
        $hmac = hash_hmac('sha256', $pluginId . "\0" . $nonce, self::hmacSecret());
        return $pluginId . '.' . $nonce . '.' . $hmac;
    }

    public static function verifySignedPending($pluginId, $signed) {
        $parts = explode('.', $signed, 3);
        if (count($parts) !== 3) return null;
        list($id, $nonce, $hmac) = $parts;
        if ($id !== $pluginId) return null;
        if (!preg_match('/^[a-f0-9]{32}$/', $nonce)) return null;
        $expect = hash_hmac('sha256', $id . "\0" . $nonce, self::hmacSecret());
        if (!hash_equals($expect, $hmac)) return null;
        return $nonce;
    }

    public static function errorToken(\Throwable $e): string {
        if ($e instanceof AuthException && $e->token !== '') {
            return $e->token;
        }
        $map = [
            'OAuth denied' => 'denied',
            'No grant for identity' => 'allowlist',
            'OAuth token exchange failed' => 'exchange',
            'OAuth code missing' => 'exchange',
            'GitHub user lookup failed' => 'exchange',
        ];
        $msg = $e->getMessage();
        if (isset($map[$msg])) return $map[$msg];
        $code = (int)$e->getCode();
        if ($code === 403) return 'allowlist';
        if ($code === 401) return 'denied';
        return 'unknown';
    }

    public static function returnLocation(array $pending, $error = null): string {
        $pathInfo = self::sanitizePathInfo($pending['path_info'] ?? '');
        $url = script_url() . $pathInfo;
        $pairs = [];
        if (!empty($pending['state_query']) && is_array($pending['state_query'])) {
            foreach ($pending['state_query'] as $pair) {
                if (!is_array($pair) || count($pair) < 2) continue;
                $k = (string)$pair[0];
                $v = (string)$pair[1];
                if (!self::isPassthroughKey($k)) continue;
                $pairs[] = rawurlencode($k) . '=' . rawurlencode($v);
            }
        }
        $hash = self::sanitizeHash($pending['hash'] ?? '');
        if ($hash !== '') {
            $pairs[] = 'fc_return_hash=' . rawurlencode($hash);
        }
        if (is_string($error) && $error !== '') {
            $pairs[] = 'fc_auth_error=' . rawurlencode($error);
        }
        if ($pairs) {
            $url .= '?' . implode('&', $pairs);
        }
        return $url;
    }

    /**
     * Absolute URL of the entry script, for OAuth callback URLs.
     *
     * When FILECARTON_PUBLIC_ORIGIN is empty, falls back to HTTP_HOST and
     * X-Forwarded-Proto. A spoofed Host could produce a wrong origin, but
     * PHP 7.0+ header() rejects CRLF so header injection is not possible,
     * and OAuth providers validate redirect_uri against their allowlist.
     * Set FILECARTON_PUBLIC_ORIGIN for production lockdown.
     */
    public static function absoluteScriptUrl(): string {
        $origin = defined('FILECARTON_PUBLIC_ORIGIN') ? FILECARTON_PUBLIC_ORIGIN : '';
        if (!is_string($origin) || $origin === '') {
            $origin = (self::requestIsHttps() ? 'https://' : 'http://')
                . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost');
        }
        return rtrim($origin, '/') . script_url();
    }

    public static function isPassthroughKey($k): bool {
        return $k === 'state' || str_starts_with($k, 'state_') || str_starts_with($k, 'state[');
    }

    // ── Internals ──

    private static function sanitizePathInfo($pathInfo): string {
        if (!is_string($pathInfo) || $pathInfo === '') return '';
        if ($pathInfo[0] !== '/') return '';
        if (strpos($pathInfo, '//') !== false) return '';
        if (strpbrk($pathInfo, "\r\n\0\\?#") !== false) return '';
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $pathInfo)) return '';
        foreach (explode('/', $pathInfo) as $seg) {
            if ($seg === '.' || $seg === '..') return '';
        }
        return $pathInfo;
    }

    private static function sanitizeHash($hash): string {
        if (!is_string($hash) || $hash === '') return '';
        if ($hash[0] === '#') $hash = substr($hash, 1);
        if (strpos($hash, "\n") !== false || strpos($hash, "\r") !== false || strpos($hash, "\0") !== false) return '';
        if (strpos($hash, '://') !== false) return '';
        return $hash;
    }

    private static function requestIsHttps(): bool {
        return Csrf::isHttps();
    }

    private static function capturePassthroughQuery(): array {
        $qs = isset($_SERVER['QUERY_STRING']) ? (string)$_SERVER['QUERY_STRING'] : '';
        if ($qs === '') return [];
        $pairs = [];
        foreach (explode('&', $qs) as $part) {
            if ($part === '') continue;
            $eq = strpos($part, '=');
            if ($eq === false) {
                $k = urldecode($part);
                $v = '';
            } else {
                $k = urldecode(substr($part, 0, $eq));
                $v = urldecode(substr($part, $eq + 1));
            }
            if (self::isPassthroughKey($k)) {
                $pairs[] = [$k, $v];
            }
        }
        return $pairs;
    }

    private static function gcPending() {
        if (empty($_SESSION[self::SESSION_PENDING]) || !is_array($_SESSION[self::SESSION_PENDING])) return;
        $now = time();
        foreach ($_SESSION[self::SESSION_PENDING] as $k => $row) {
            if (!is_array($row) || ($now - (int)($row['created'] ?? 0)) > self::PENDING_TTL) {
                unset($_SESSION[self::SESSION_PENDING][$k]);
            }
        }
        if (count($_SESSION[self::SESSION_PENDING]) > self::PENDING_MAX) {
            uasort($_SESSION[self::SESSION_PENDING], function ($a, $b) {
                return ((int)($a['created'] ?? 0)) <=> ((int)($b['created'] ?? 0));
            });
            while (count($_SESSION[self::SESSION_PENDING]) > self::PENDING_MAX) {
                $drop = array_keys($_SESSION[self::SESSION_PENDING])[0];
                unset($_SESSION[self::SESSION_PENDING][$drop]);
            }
        }
    }

}
