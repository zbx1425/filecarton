<?php

namespace FileCarton;

abstract class RedirectAuth implements AuthProvider {
    public function kind(): string {
        return 'redirect';
    }

    public function validateConfig(): array {
        return [];
    }

    /**
     * Button icon.
     *
     * Return '' for no icon, a URL string for a color image, or an array
     * for a monochrome mask icon:
     *   ['mono_url' => 'data:image/svg+xml,...']
     *   ['mono_url' => '...', 'light_tint' => '#24292f', 'dark_tint' => '#fff']
     *
     * @return string|array{mono_url: string, light_tint?: string, dark_tint?: string}
     */
    public function icon() {
        return '';
    }

    /**
     * Build an AuthIdentity with a provider-prefixed stable id.
     *
     * The resulting id is "{providerId}:{remoteId}", e.g. "github:583231".
     * This prevents collisions between different providers and ensures the
     * id stays stable even if the user renames their account.
     *
     * @param string $remoteId Alphanumeric remote user id (max 128 chars)
     * @param string $displayName Human-readable name
     * @param array  $extra      Optional extra data
     */
    protected function makeIdentity(string $remoteId, string $displayName, array $extra = []): AuthIdentity {
        if (!preg_match('/^[A-Za-z0-9._-]{1,128}$/', $remoteId)) {
            throw new AuthException('exchange', 500, 'Invalid remote id format');
        }
        return new AuthIdentity(
            $this->id() . ':' . $remoteId,
            $displayName,
            $this->id(),
            $extra
        );
    }

    /** Return an absolute URL to redirect the browser to. */
    abstract public function start(AuthContext $ctx): string;

    /**
     * Read the incoming callback request via $ctx->param() (GET).
     * Throw AuthException with a token and 4xx code on failure.
     */
    abstract public function complete(AuthContext $ctx): AuthIdentity;
}

abstract class OAuth2Auth extends RedirectAuth {
    abstract protected function authorizeEndpoint(): string;
    abstract protected function tokenEndpoint(): string;
    abstract protected function clientId(): string;
    abstract protected function clientSecret(): string;
    abstract protected function scopes(): string;

    /**
     * @param array $token decoded token-endpoint JSON
     */
    abstract protected function fetchIdentity(array $token, AuthContext $ctx): AuthIdentity;

    public function start(AuthContext $ctx): string {
        $q = [
            'client_id'     => $this->clientId(),
            'redirect_uri'  => $ctx->callbackUrl(false),
            'scope'         => $this->scopes(),
            'state'         => $ctx->signedState(),
            'response_type' => 'code',
        ];
        return $this->authorizeEndpoint() . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }

    public function complete(AuthContext $ctx): AuthIdentity {
        $err = $ctx->param('error');
        if ($err !== null) {
            throw new AuthException('denied', 401, 'OAuth denied');
        }
        $code = $ctx->param('code');
        if ($code === null) {
            throw new AuthException('exchange', 400, 'OAuth code missing');
        }
        $resp = HttpClient::post($this->tokenEndpoint(), http_build_query([
            'client_id'     => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'code'          => $code,
            'redirect_uri'  => $ctx->callbackUrl(false),
            'grant_type'    => 'authorization_code',
        ]), [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);
        $token = json_decode($resp['body'], true);
        if (!is_array($token) || empty($token['access_token'])) {
            error_log('FileCarton auth: token endpoint HTTP ' . $resp['status']);
            throw new AuthException('exchange', 401, 'OAuth token exchange failed');
        }
        return $this->fetchIdentity($token, $ctx);
    }
}

class GitHubOAuth extends OAuth2Auth {
    /** @var string */
    private $clientId;
    /** @var string */
    private $clientSecret;

    public function __construct(array $opts = []) {
        $this->clientId = isset($opts['clientId']) ? (string)$opts['clientId'] : '';
        $this->clientSecret = isset($opts['clientSecret']) ? (string)$opts['clientSecret'] : '';
    }

    public function id(): string { return 'github'; }
    public function label(): string { return 'GitHub'; }

    public function validateConfig(): array {
        $p = [];
        if ($this->clientId === '' || $this->clientSecret === '') {
            $p[] = 'GitHubOAuth clientId / clientSecret is empty.';
        }
        return $p;
    }

    public function icon() {
        return [
            'mono_url' => 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><path fill="white" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>'),
        ];
    }

    protected function authorizeEndpoint(): string { return 'https://github.com/login/oauth/authorize'; }
    protected function tokenEndpoint(): string { return 'https://github.com/login/oauth/access_token'; }
    protected function clientId(): string { return $this->clientId; }
    protected function clientSecret(): string { return $this->clientSecret; }
    protected function scopes(): string { return 'read:user'; }

    protected function fetchIdentity(array $token, AuthContext $ctx): AuthIdentity {
        $resp = HttpClient::get('https://api.github.com/user', [
            'Accept'        => 'application/vnd.github+json',
            'Authorization' => 'Bearer ' . $token['access_token'],
        ]);
        $user = json_decode($resp['body'], true);
        if (!is_array($user) || empty($user['id'])) {
            throw new AuthException('exchange', 401, 'GitHub user lookup failed');
        }
        $login = (string)($user['login'] ?? '');
        if ($login === '') {
            throw new AuthException('exchange', 401, 'GitHub user lookup failed');
        }
        return $this->makeIdentity((string)$user['id'], $login);
    }
}

class MicrosoftOAuth extends OAuth2Auth {
    /** @var string */
    private $clientId;
    /** @var string */
    private $clientSecret;
    /** @var string */
    private $tenant;

    public function __construct(array $opts = []) {
        $this->clientId = isset($opts['clientId']) ? (string)$opts['clientId'] : '';
        $this->clientSecret = isset($opts['clientSecret']) ? (string)$opts['clientSecret'] : '';
        $this->tenant = isset($opts['tenant']) ? (string)$opts['tenant'] : 'common';
    }

    public function id(): string { return 'microsoft'; }
    public function label(): string { return 'Microsoft'; }

    public function validateConfig(): array {
        $p = [];
        if ($this->clientId === '' || $this->clientSecret === '') {
            $p[] = 'MicrosoftOAuth clientId / clientSecret is empty.';
        }
        return $p;
    }

    public function icon() {
        return 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 23 23"><rect x="1" y="1" width="10" height="10" fill="#f35325"/><rect x="12" y="1" width="10" height="10" fill="#81bc06"/><rect x="1" y="12" width="10" height="10" fill="#05a6f0"/><rect x="12" y="12" width="10" height="10" fill="#ffba08"/></svg>');
    }

    protected function authorizeEndpoint(): string { return 'https://login.microsoftonline.com/' . $this->tenant . '/oauth2/v2.0/authorize'; }
    protected function tokenEndpoint(): string { return 'https://login.microsoftonline.com/' . $this->tenant . '/oauth2/v2.0/token'; }
    protected function clientId(): string { return $this->clientId; }
    protected function clientSecret(): string { return $this->clientSecret; }
    protected function scopes(): string { return 'User.Read'; }

    protected function fetchIdentity(array $token, AuthContext $ctx): AuthIdentity {
        $resp = HttpClient::get('https://graph.microsoft.com/v1.0/me', [
            'Authorization' => 'Bearer ' . $token['access_token'],
        ]);
        $user = json_decode($resp['body'], true);
        if (!is_array($user) || empty($user['id'])) {
            throw new AuthException('exchange', 401, 'Microsoft user lookup failed');
        }
        $displayName = (string)($user['displayName'] ?? $user['userPrincipalName'] ?? '');
        if ($displayName === '') {
            throw new AuthException('exchange', 401, 'Microsoft user lookup failed');
        }
        return $this->makeIdentity((string)$user['id'], $displayName);
    }
}

class GoogleOAuth extends OAuth2Auth {
    /** @var string */
    private $clientId;
    /** @var string */
    private $clientSecret;

    public function __construct(array $opts = []) {
        $this->clientId = isset($opts['clientId']) ? (string)$opts['clientId'] : '';
        $this->clientSecret = isset($opts['clientSecret']) ? (string)$opts['clientSecret'] : '';
    }

    public function id(): string { return 'google'; }
    public function label(): string { return 'Google'; }

    public function validateConfig(): array {
        $p = [];
        if ($this->clientId === '' || $this->clientSecret === '') {
            $p[] = 'GoogleOAuth clientId / clientSecret is empty.';
        }
        return $p;
    }

    public function icon() {
        return 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>');
    }

    protected function authorizeEndpoint(): string { return 'https://accounts.google.com/o/oauth2/v2/auth'; }
    protected function tokenEndpoint(): string { return 'https://oauth2.googleapis.com/token'; }
    protected function clientId(): string { return $this->clientId; }
    protected function clientSecret(): string { return $this->clientSecret; }
    protected function scopes(): string { return 'openid profile email'; }

    protected function fetchIdentity(array $token, AuthContext $ctx): AuthIdentity {
        $resp = HttpClient::get('https://openidconnect.googleapis.com/v1/userinfo', [
            'Authorization' => 'Bearer ' . $token['access_token'],
        ]);
        $user = json_decode($resp['body'], true);
        if (!is_array($user) || empty($user['sub'])) {
            throw new AuthException('exchange', 401, 'Google user lookup failed');
        }
        $displayName = (string)($user['name'] ?? $user['email'] ?? '');
        if ($displayName === '') {
            throw new AuthException('exchange', 401, 'Google user lookup failed');
        }
        return $this->makeIdentity((string)$user['sub'], $displayName);
    }
}

class DiscordOAuth extends OAuth2Auth {
    /** @var string */
    private $clientId;
    /** @var string */
    private $clientSecret;

    public function __construct(array $opts = []) {
        $this->clientId = isset($opts['clientId']) ? (string)$opts['clientId'] : '';
        $this->clientSecret = isset($opts['clientSecret']) ? (string)$opts['clientSecret'] : '';
    }

    public function id(): string { return 'discord'; }
    public function label(): string { return 'Discord'; }

    public function validateConfig(): array {
        $p = [];
        if ($this->clientId === '' || $this->clientSecret === '') {
            $p[] = 'DiscordOAuth clientId / clientSecret is empty.';
        }
        return $p;
    }

    public function icon() {
        return [
            'mono_url' => 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><path fill="white" d="M13.55 3.15A13.2 13.2 0 0010.3 2a9.7 9.7 0 00-.43.88 12.3 12.3 0 00-3.74 0A9.4 9.4 0 005.7 2a13.3 13.3 0 00-3.26 1.15C.35 6.4-.22 9.56.07 12.67a13.4 13.4 0 004.1 2.11 10 10 0 00.87-1.44 8.6 8.6 0 01-1.38-.67c.12-.09.23-.18.34-.27a9.5 9.5 0 008.18 0c.11.1.22.19.33.27a8.7 8.7 0 01-1.38.67 10 10 0 00.87 1.44 13.4 13.4 0 004.1-2.11c.34-3.65-.58-6.82-2.45-9.52zM5.35 10.8c-.8 0-1.46-.77-1.46-1.7s.64-1.71 1.46-1.71 1.47.77 1.46 1.71c0 .93-.65 1.7-1.46 1.7zm5.3 0c-.8 0-1.46-.77-1.46-1.7s.64-1.71 1.46-1.71 1.47.77 1.46 1.71c0 .93-.65 1.7-1.46 1.7z"/></svg>'),
            'light_tint' => '#5865F2',
            'dark_tint' => '#5865F2',
        ];
    }

    protected function authorizeEndpoint(): string { return 'https://discord.com/oauth2/authorize'; }
    protected function tokenEndpoint(): string { return 'https://discord.com/api/oauth2/token'; }
    protected function clientId(): string { return $this->clientId; }
    protected function clientSecret(): string { return $this->clientSecret; }
    protected function scopes(): string { return 'identify'; }

    protected function fetchIdentity(array $token, AuthContext $ctx): AuthIdentity {
        $resp = HttpClient::get('https://discord.com/api/v10/users/@me', [
            'Authorization' => 'Bearer ' . $token['access_token'],
        ]);
        $user = json_decode($resp['body'], true);
        if (!is_array($user) || empty($user['id'])) {
            throw new AuthException('exchange', 401, 'Discord user lookup failed');
        }
        $displayName = (string)($user['global_name'] ?? $user['username'] ?? '');
        if ($displayName === '') {
            throw new AuthException('exchange', 401, 'Discord user lookup failed');
        }
        return $this->makeIdentity((string)$user['id'], $displayName);
    }
}
