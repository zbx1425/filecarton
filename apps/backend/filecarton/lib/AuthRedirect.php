<?php

namespace FileCarton;

abstract class RedirectAuth implements AuthProvider {
    public function kind(): string {
        return 'redirect';
    }

    /**
     * Button icon. https://... or data:image/svg+xml,...; empty = text only.
     */
    public function icon(): string {
        return '';
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
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $ctx->setExtra('pkce_verifier', $verifier);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $q = [
            'client_id'             => $this->clientId(),
            'redirect_uri'          => $ctx->callbackUrl(false),
            'scope'                 => $this->scopes(),
            'state'                 => $ctx->signedState(),
            'response_type'         => 'code',
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
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
        $verifier = $ctx->getExtra('pkce_verifier', '');
        $resp = HttpClient::post($this->tokenEndpoint(), http_build_query([
            'client_id'     => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'code'          => $code,
            'redirect_uri'  => $ctx->callbackUrl(false),
            'grant_type'    => 'authorization_code',
            'code_verifier' => $verifier,
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

    public function clientIdPublic(): string { return $this->clientId; }
    public function clientSecretPublic(): string { return $this->clientSecret; }

    public function icon(): string {
        return 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><path fill="currentColor" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>');
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
        return new AuthIdentity(
            $login,
            $login,
            $this->id(),
            ['github_id' => (string)$user['id']]
        );
    }
}
