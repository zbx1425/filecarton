# Writing custom auth providers and grant resolvers

This page is for developers who need authentication beyond the built-in `StaticPasswordAuth` and `GitHubOAuth`, or who need to look up user permissions from somewhere other than `STATIC_USER_LIST`.

If you just want to configure passwords or GitHub login, see [config_auth.md](config_auth.md).

## File layout

Your custom class goes in a PHP file next to `filecarton.php`. You reference it in `filecarton.config.php` via the `file` and `class` keys, for example:

```php
define_default('FILECARTON_AUTH_PROVIDERS', [
    ['file' => __DIR__ . '/my_ldap_auth.php', 'class' => \MyApp\LdapAuth::class, 'host' => 'ldap://ldap.example.com'],
]);
```

The `file` is loaded at boot time via `require_once`, after all built-in classes are available. Do **not** `require` the file at the top of your config as the parent classes have not been loaded yet at that point.

Any extra keys in the spec array (here, `host`) are passed to your constructor as `$opts`.

If the plugin file sits inside the directory managed by FileCarton (i.e. under `FILECARTON_ROOT_PATH`), you should add its path to `FILECARTON_IGNORE_REALPATH` so it cannot be downloaded through the file manager.

## Custom password provider

Extend `FileCarton\PasswordAuth`. You need two methods from `AuthProvider` (`id`, `label`) and one method from `PasswordAuth` (`verify`):

```php
<?php
namespace MyApp;

use FileCarton\PasswordAuth;
use FileCarton\AuthIdentity;

class LdapAuth extends PasswordAuth {
    private $host;

    public function __construct(array $opts = []) {
        $this->host = isset($opts['host']) ? (string)$opts['host'] : '';
    }

    public function id(): string { return 'ldap'; }

    public function label(): string { return 'Company account'; }

    public function verify(string $username, string $password) {
        if ($username === '' || $password === '') return null;

        $conn = @ldap_connect($this->host);
        if ($conn === false) return null;
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);

        $dn = 'uid=' . ldap_escape($username, '', LDAP_ESCAPE_DN)
            . ',ou=people,dc=example,dc=com';
        $ok = @ldap_bind($conn, $dn, $password);
        ldap_unbind($conn);

        if (!$ok) return null;
        return new AuthIdentity('ldap:' . $username, $username, $this->id());
    }
}
```

Rules:

- Return `null` on bad credentials. Do not throw.
- Do not distinguish "unknown user" from "wrong password" in the return value.
- Do not call `session_start()`, write to `$_SESSION`, or send HTTP headers. The core handles all of that.
- The identity's `id` field is what grant resolvers see. If the ID needs to match a row in `STATIC_USER_LIST`, make sure they match.
- `id()` must be a lowercase alphanumeric string (regex `^[a-z][a-z0-9_]{0,31}$`), unique among all providers.

If no grant resolver returns a grant for the identity (e.g. the user is not in `STATIC_USER_LIST` and there is no custom resolver), the login will be rejected with "This account is not allowed to log in."

## Custom OAuth redirect provider

Extend `FileCarton\OAuth2Auth`. The base class handles PKCE challenge generation, the token exchange, and signed state verification. You provide the endpoints and a method that turns the token response into an identity.

Example — a GitLab OAuth provider:

```php
<?php
namespace MyApp;

use FileCarton\OAuth2Auth;
use FileCarton\AuthContext;
use FileCarton\AuthIdentity;
use FileCarton\AuthException;
use FileCarton\HttpClient;

class GitLabOAuth extends OAuth2Auth {
    private $clientId;
    private $clientSecret;
    private $baseUrl;

    public function __construct(array $opts = []) {
        $this->clientId     = isset($opts['clientId'])     ? (string)$opts['clientId']     : '';
        $this->clientSecret = isset($opts['clientSecret']) ? (string)$opts['clientSecret'] : '';
        $this->baseUrl      = rtrim(isset($opts['baseUrl']) ? (string)$opts['baseUrl'] : 'https://gitlab.com', '/');
    }

    public function id(): string { return 'gitlab'; }
    public function label(): string { return 'GitLab'; }

    protected function authorizeEndpoint(): string { return $this->baseUrl . '/oauth/authorize'; }
    protected function tokenEndpoint(): string     { return $this->baseUrl . '/oauth/token'; }
    protected function clientId(): string           { return $this->clientId; }
    protected function clientSecret(): string       { return $this->clientSecret; }
    protected function scopes(): string             { return 'read_user'; }

    protected function fetchIdentity(array $token, AuthContext $ctx): AuthIdentity {
        $resp = HttpClient::get($this->baseUrl . '/api/v4/user', [
            'Authorization' => 'Bearer ' . $token['access_token'],
        ]);
        $user = json_decode($resp['body'], true);
        if (!is_array($user) || empty($user['id'])) {
            throw new AuthException('exchange', 401, 'GitLab user lookup failed');
        }
        return $this->makeIdentity(
            (string)$user['id'],
            (string)($user['name'] ?: $user['username'] ?: '')
        );
    }
}
```

Config:

```php
define_default('FILECARTON_AUTH_PROVIDERS', [
    [
        'file'         => __DIR__ . '/gitlab_auth.php',
        'class'        => \MyApp\GitLabOAuth::class,
        'clientId'     => 'app-id-from-gitlab',
        'clientSecret' => 'app-secret-from-gitlab',
        'baseUrl'      => 'https://gitlab.example.com',
    ],
]);
```

The built-in `GitHubOAuth` in `AuthRedirect.php` is another complete reference.

Rules for OAuth providers:

- `fetchIdentity()` receives the decoded token-endpoint JSON. Use `HttpClient::get()` to call the provider's user-info API if needed.
- Use `$this->makeIdentity($remoteId, $displayName)` to build the identity. This produces a stable id like `gitlab:12345` that won't change if the user renames their account. The `$remoteId` must match `^[A-Za-z0-9._-]{1,128}$`.
- The resulting identity id (e.g. `gitlab:12345`) must match a row in `STATIC_USER_LIST` (or be resolvable by a custom grant resolver).
- Never log tokens or secrets. `HttpClient` already strips bodies from its error messages.
- Do not call `session_start()` or send headers yourself.

## Custom redirect provider (non-OAuth)

If your SSO does not speak OAuth 2.0, extend `FileCarton\RedirectAuth` directly. You get an `AuthContext` that handles nonce generation, session storage, and callback URLs:

```php
<?php
namespace MyApp;

use FileCarton\RedirectAuth;
use FileCarton\AuthContext;
use FileCarton\AuthIdentity;
use FileCarton\AuthException;

class TicketSsoAuth extends RedirectAuth {
    private $ssoLogin;

    public function __construct(array $opts = []) {
        $this->ssoLogin = isset($opts['ssoLogin']) ? (string)$opts['ssoLogin'] : '';
    }

    public function id(): string { return 'ticket'; }

    public function label(): string { return 'Company SSO'; }

    public function start(AuthContext $ctx): string {
        // $ctx->callbackUrl(true) appends ?fc_nonce=... so you can
        // round-trip the nonce without using OAuth's state parameter.
        return $this->ssoLogin . '?return=' . rawurlencode($ctx->callbackUrl(true));
    }

    public function complete(AuthContext $ctx): AuthIdentity {
        $ticket = $ctx->param('ticket');
        if ($ticket === null) {
            throw new AuthException('exchange', 400, 'Missing ticket');
        }
        // Validate the ticket against your SSO server.
        // Never log the ticket value.
        $user = $this->validateTicket($ticket);
        if ($user === null) {
            throw new AuthException('exchange', 401, 'Invalid ticket');
        }
        return new AuthIdentity('sso:' . $user, $user, $this->id());
    }

    private function validateTicket($ticket) {
        // Your validation logic here.
        return null;
    }
}
```

Rules:

- `start()` returns an absolute URL. The core sends a 302 redirect.
- `complete()` reads the callback query parameters through `$ctx->param()`, not `$_GET` directly.
- Throw `AuthException` on failure. The `$token` field (`denied`, `exchange`, `allowlist`, etc.) is shown to the user as a generic error message. The log message (third constructor argument) goes to `error_log`.
- Never log tokens, passwords, or ticket values.
- Do not call `session_start()` or send headers yourself.
- Callbacks must be **GET** requests. POST callbacks (like SAML ACS) are not supported.

## Provider icons

The `icon()` method on redirect providers controls the button icon on the login page. It can return:

- `''` — no icon (text-only button).
- A URL string (`'https://...'` or `'data:image/svg+xml,...'`) — displayed as a color image.
- An array with `mono_url` — rendered as a monochrome CSS mask that follows the text color:

```php
public function icon() {
    return [
        'mono_url' => 'data:image/svg+xml,' . rawurlencode('<svg ...>...</svg>'),
    ];
}
```

You can optionally add `light_tint` and `dark_tint` (`#RGB` or `#RRGGBB`) to use theme-specific colors instead of `currentColor`:

```php
return [
    'mono_url'   => 'data:image/svg+xml,...',
    'light_tint' => '#24292f',
    'dark_tint'  => '#ffffff',
];
```

For monochrome SVGs, the SVG path should use an opaque fill (e.g. `fill="white"`) and a transparent background, so the CSS mask renders only the shape.

## Custom grant resolver

Implement `FileCarton\GrantResolver`:

```php
<?php
namespace MyApp;

use FileCarton\GrantResolver;
use FileCarton\AuthIdentity;
use FileCarton\AuthGrant;

class DbGrantResolver implements GrantResolver {
    private $dsn;

    public function __construct(array $opts = []) {
        $this->dsn = isset($opts['dsn']) ? (string)$opts['dsn'] : '';
    }

    public function resolve(AuthIdentity $identity) {
        // Look up the user's directory from your database.
        $pdo = new \PDO($this->dsn);
        $stmt = $pdo->prepare('SELECT root_path, is_readonly FROM users WHERE username = ?');
        $stmt->execute([$identity->id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            // Return null to pass to the next resolver in the chain.
            return null;
        }

        $grant = new AuthGrant();
        $grant->rootPath = $row['root_path'];
        $grant->readonly = (bool)$row['is_readonly'];
        return $grant;
    }
}
```

Wire it into the config:

```php
define_default('FILECARTON_GRANT_RESOLVERS', [
    ['file' => __DIR__ . '/db_grant.php', 'class' => \MyApp\DbGrantResolver::class, 'dsn' => 'sqlite:/var/db/users.db'],
    ['class' => \FileCarton\StaticGrantResolver::class],
]);
```

`GRANT_RESOLVERS` are evaluated in a chained fashion. Each resolver either returns an `AuthGrant` or returns `null` to pass. The first non-null grant is accepted. If every resolver returns null, the user is denied access (files are closed for that user).

`AuthGrant` fields:

| Field | Effect of `null` | Effect of a value |
|---|---|---|
| `rootPath` | Inherit `FILECARTON_ROOT_PATH` | Use this directory instead. Resolved via `realpath()`; if the directory does not exist, the user gets "not configured." |
| `readonly` | Inherit `FILECARTON_READONLY` | `true` forces read-only. `false` inherits the global setting (you cannot use it to override a global read-only). |
| `branding` | Inherit `FILECARTON_BRANDING` | String (including `''`) overrides. |
| `repoName` | Inherit `FILECARTON_REPO_NAME` | String (including `''`) overrides. |
