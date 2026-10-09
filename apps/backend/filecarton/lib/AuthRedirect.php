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
