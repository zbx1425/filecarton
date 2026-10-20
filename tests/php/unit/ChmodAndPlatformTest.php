<?php

namespace FileCarton\Tests\Unit;

use FileCarton\PathSecurity;
use FileCarton\Platform;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/apps/backend/filecarton/api/chmod.php';

final class ChmodAndPlatformTest extends TestCase
{
    public function testProcessUserInfoReturnsValidStructure(): void
    {
        $info = Platform::processUserInfo();
        self::assertArrayHasKey('user', $info);
        self::assertArrayHasKey('isRoot', $info);
        self::assertIsString($info['user']);
        self::assertNotSame('', $info['user']);
        self::assertIsBool($info['isRoot']);
    }

    public function testResolveChownTargetRejectsControlCharsAndColons(): void
    {
        self::assertNull(\FileCarton\resolve_chown_target("root:root", 'posix_getpwnam', 'uid'));
        self::assertNull(\FileCarton\resolve_chown_target("root\0", 'posix_getpwnam', 'uid'));
        self::assertNull(\FileCarton\resolve_chown_target("root\n", 'posix_getgrnam', 'gid'));
        self::assertSame(1000, \FileCarton\resolve_chown_target('1000', 'posix_getpwnam', 'uid'));
    }

    public function testRecursiveChmodAllowsBlockedFilenamesAndSkipsIgnored(): void
    {
        $tmpRoot = sys_get_temp_dir() . '/fc_chmod_test_' . bin2hex(random_bytes(4));
        mkdir($tmpRoot . '/sub/.git', 0755, true);
        file_put_contents($tmpRoot . '/sub/index.php', "<?php echo 1;\n");
        file_put_contents($tmpRoot . '/sub/readme.txt', "hello\n");
        file_put_contents($tmpRoot . '/sub/.git/config', "secret\n");

        try {
            $pathSec = new PathSecurity($tmpRoot);
            // Add an ignore pattern for .git so it is treated as protected/ignored
            $ref = new \ReflectionProperty(PathSecurity::class, 'ignore');
            $ref->setAccessible(true);
            $ignore = $ref->getValue($pathSec);
            $ignore->addPattern('.git');

            // Verify .php is blocked by default blocklist for creation, yet recursive chmod succeeds on it
            self::assertTrue($pathSec->isFilenameBlocked('index.php'));
            self::assertTrue($pathSec->hasProtectedDescendants($tmpRoot . '/sub'));

            $completed = 0;
            $failed = [];
            \FileCarton\apply_unix_perm_recursive(
                str_replace('\\', '/', realpath($tmpRoot . '/sub')),
                'sub',
                $pathSec,
                true,
                0644,
                0,
                false,
                null,
                false,
                null,
                $completed,
                $failed
            );

            self::assertSame([], $failed);
            // Should have processed index.php and readme.txt, and silently skipped .git and its contents
            self::assertSame(2, $completed);
        } finally {
            Platform::deleteRecursive($tmpRoot);
        }
    }
}
