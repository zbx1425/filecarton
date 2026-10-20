<?php

namespace FileCarton;

/**
 * OAuth2 provider aimed at the rig's local IdP. Exercises the production
 * state, nonce, and callback code. The token JSON carries the identity so
 * no second user-info hop is required.
 */
class FakeOAuth extends OAuth2Auth {
    /** @var string */
    private $base;
    /** @var string */
    private $clientId;
    /** @var string */
    private $clientSecret;

    public function __construct(array $opts = []) {
        $this->base = rtrim(isset($opts['base_url']) ? (string)$opts['base_url'] : (string)getenv('FC_IDP'), '/');
        $this->clientId = 'rig';
        $this->clientSecret = 'rig-secret';
    }

    public function id(): string { return 'fake'; }
    public function label(): string { return 'Fake'; }

    protected function authorizeEndpoint(): string { return $this->base . '/authorize'; }
    protected function tokenEndpoint(): string { return $this->base . '/token'; }
    protected function clientId(): string { return $this->clientId; }
    protected function clientSecret(): string { return $this->clientSecret; }
    protected function scopes(): string { return 'read'; }

    protected function fetchIdentity(array $token, AuthContext $ctx): AuthIdentity {
        $remote = isset($token['user_id']) ? (string)$token['user_id'] : '';
        $name = isset($token['name']) ? (string)$token['name'] : $remote;
        return $this->makeIdentity($remote, $name);
    }
}
