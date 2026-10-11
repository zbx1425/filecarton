<?php

use PHPUnit\Framework\TestCase;

class FilenameBlocklistTest extends TestCase
{
    public function testSuffixAndExactRulesAreCaseInsensitive(): void
    {
        $list = new FileCarton\FilenameBlocklist(['*.php', '.htaccess', '.user.ini', 'web.config']);
        $this->assertTrue($list->isBlocked('shell.php'));
        $this->assertTrue($list->isBlocked('SHELL.PHP'));
        $this->assertTrue($list->isBlocked('.HTACCESS'));
        $this->assertTrue($list->isBlocked('Web.Config'));
        $this->assertFalse($list->isBlocked('notes.txt'));
        $this->assertFalse($list->isBlocked('php.txt'));
    }

    public function testAllowRuleBeatsBlockAll(): void
    {
        $list = new FileCarton\FilenameBlocklist(['*', '!*.txt', '!readme']);
        $this->assertFalse($list->isBlocked('notes.txt'));
        $this->assertFalse($list->isBlocked('README'));
        $this->assertTrue($list->isBlocked('notes.md'));
        $this->assertTrue($list->isBlocked('shell.php'));
    }

    public function testEmptyAndDotNamesAreNotBlockedBySuffixRules(): void
    {
        $list = new FileCarton\FilenameBlocklist(['*.php']);
        $this->assertFalse($list->isBlocked(''));
        $this->assertFalse($list->isBlocked('.'));
        $this->assertFalse($list->isBlocked('..'));
    }
}
