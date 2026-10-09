<?php

namespace FileCarton;

class Csrf {

    private const SESSION_KEY = 'filecarton_csrf';
    /** @var bool Whether FileCarton called session_start() itself. */
    private static $ownSession = false;

    /** True when FileCarton started the session (safe to regenerate ID). */
    public static function isOwnSession(): bool {
        return self::$ownSession;
    }

    /**
     * Generate a new CSRF token and store it in the session.
     */
    public static function generate(): string {
        self::ensureSession();
        $token = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_KEY] = $token;
        return $token;
    }

    /**
     * Get the current session token without regenerating.
     * Generates one if none exists.
     */
    public static function getToken(): string {
        self::ensureSession();
        if (!isset($_SESSION[self::SESSION_KEY])) {
            return self::generate();
        }
        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Validate the CSRF token from the X-CSRF-Token request header.
     * Throws on mismatch or missing token.
     */
    public static function validate(): void {
        self::ensureSession();
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $sessionToken = $_SESSION[self::SESSION_KEY] ?? '';

        if ($sessionToken === '' || $headerToken === '') {
            throw new ApiException('auth.csrf', 403);
        }
        if (!hash_equals($sessionToken, $headerToken)) {
            throw new ApiException('auth.csrf', 403);
        }
    }

    /**
     * Detect whether the current request arrived over HTTPS.
     * Checks HTTPS env, port 443, and X-Forwarded-Proto from a reverse proxy.
     * X-Forwarded-Proto is trusted as sent. A client that can reach PHP
     * directly can mark its own cookie Secure and its own OAuth redirect_uri
     * as https; the IdP still requires redirect_uri to match its allowlist.
     */
    public static function isHttps(): bool {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
        if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
        $proto = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0]);
        if (strtolower($proto) === 'https') return true;
        return false;
    }

    /**
     * Embedders may already have started a session. Leave that session alone.
     * When we start the session ourselves, set SameSite=Lax for OAuth GET callbacks.
     */
    public static function ensureSession(): void {
        if (session_status() === PHP_SESSION_DISABLED) {
            throw new \RuntimeException('Sessions are disabled', 500);
        }
        if (session_status() === PHP_SESSION_NONE) {
            $name = defined('FILECARTON_SESSION_NAME') ? FILECARTON_SESSION_NAME : 'FILECARTON';
            if (is_string($name) && preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $name)) {
                session_name($name);
            }
            $secure = self::isHttps();
            if (PHP_VERSION_ID >= 70300) {
                session_set_cookie_params([
                    'lifetime' => 0,
                    'path'     => '/',
                    'secure'   => $secure,
                    'httponly'  => true,
                    'samesite'  => 'Lax',
                ]);
            } else {
                session_set_cookie_params(0, '/; SameSite=Lax', '', $secure, true);
            }
            session_start();
            self::$ownSession = true;
        }
    }
}
