<?php

use PHPUnit\Framework\TestCase;

class NormalizePathTest extends TestCase
{
    private function security(): FileCarton\PathSecurity
    {
        return new FileCarton\PathSecurity(sys_get_temp_dir());
    }

    public function testDotSegmentsCollapseInsideTheString(): void
    {
        $path = $this->security();
        $this->assertSame('b', $path->normalizePath('a/../../b'));
        $this->assertSame('secret', $path->normalizePath('../secret'));
        $this->assertSame('foo/x', $path->normalizePath('foo/bar/../x'));
        $this->assertSame('foo/x', $path->normalizePath('foo\\bar\\..\\x'));
        $this->assertSame('', $path->normalizePath('../..'));
    }

    public function testControlCharactersAreNotValidFileNames(): void
    {
        $path = $this->security();
        $this->assertFalse($path->isValidFileName("\x1f"));
        $this->assertFalse($path->isValidFileName("a\nb"));
        $this->assertTrue($path->isValidFileName('notes.txt'));
    }

    public function testAppleJunk(): void
    {
        $this->assertTrue(FileCarton\PathSecurity::isAppleJunkPath('__MACOSX/foo'));
        $this->assertTrue(FileCarton\PathSecurity::isAppleJunkPath('dir/._notes'));
        $this->assertTrue(FileCarton\PathSecurity::isAppleJunkPath('.DS_Store'));
        $this->assertFalse(FileCarton\PathSecurity::isAppleJunkPath('notes.txt'));
    }
}
