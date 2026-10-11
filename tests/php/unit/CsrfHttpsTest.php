<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/apps/backend/filecarton/lib/Csrf.php';

class CsrfHttpsTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
    }

    public function testPlainHttpIsNotSecure(): void
    {
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['SERVER_PORT'] = '80';
        unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
        $this->assertFalse(FileCarton\Csrf::isHttps());
    }

    public function testForwardedProtoHttpsIsTrusted(): void
    {
        $_SERVER['HTTPS'] = '';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertTrue(FileCarton\Csrf::isHttps());
    }

    public function testFirstForwardedValueWins(): void
    {
        $_SERVER['HTTPS'] = '';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http, https';
        $this->assertFalse(FileCarton\Csrf::isHttps());
    }
}
