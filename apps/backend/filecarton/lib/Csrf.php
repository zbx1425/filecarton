<?php

class Csrf {

    private const SESSION_KEY = 'filecarton_csrf';

    /**
     * Generate a new CSRF token and store it in the session.
     */
    public static function generate(): string {
        $token = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_KEY] = $token;
        return $token;
    }

    /**
     * Get the current session token without regenerating.
     * Generates one if none exists.
     */
    public static function getToken(): string {
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
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $sessionToken = $_SESSION[self::SESSION_KEY] ?? '';

        if ($sessionToken === '' || $headerToken === '') {
            throw new \RuntimeException('CSRF token missing', 403);
        }
        if (!hash_equals($sessionToken, $headerToken)) {
            throw new \RuntimeException('CSRF token mismatch', 403);
        }
    }
}
