<?php

use PHPUnit\Framework\TestCase;

class IgnoreRulesTest extends TestCase
{
    public function testAbsolutePathHidesThePathAndItsChildren(): void
    {
        $root = sys_get_temp_dir();
        $rules = new FileCarton\IgnoreRules(str_replace('\\', '/', $root), true);
        $secret = str_replace('\\', '/', $root) . '/fc-secret';
        $rules->addAbsolutePath($secret);
        $this->assertTrue($rules->isIgnored($secret));
        $this->assertTrue($rules->isIgnored($secret . '/child.txt'));
        $this->assertFalse($rules->isIgnored(str_replace('\\', '/', $root) . '/other.txt'));
    }

    public function testWouldBeIgnoredMatchesAMissingPath(): void
    {
        $root = sys_get_temp_dir();
        $rules = new FileCarton\IgnoreRules(str_replace('\\', '/', $root), true);
        $secret = str_replace('\\', '/', $root) . '/fc-missing';
        $rules->addAbsolutePath($secret);
        $this->assertTrue($rules->wouldBeIgnored($secret . '/new.txt', false));
    }
}
