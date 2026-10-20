<?php

namespace FileCarton\Tests\Unit;

use FileCarton\RepoSetting;
use PHPUnit\Framework\TestCase;

final class RepoSettingTest extends TestCase
{
    public function testMissingOrInvalidFeatureFlagsDefaultToFalse(): void
    {
        $s = RepoSetting::fromConfigArray([
            'root_path' => '/tmp/repo',
            'readonly'  => false,
            'branding'  => 'FileCarton',
            'repo_name' => 'test',
            // feature_archive, feature_unix_perm, feature_unix_perm_modify omitted
        ]);

        self::assertFalse($s->featureArchive);
        self::assertFalse($s->featureUnixPerm);
        self::assertFalse($s->featureUnixPermModify);
        self::assertFalse($s->featureRemoteDownload);
        self::assertSame([], $s->verifyAllSet());

        // Non-boolean values should also silently default to false and not fail verifyAllSet
        $s2 = RepoSetting::fromConfigArray([
            'root_path'                => '/tmp/repo',
            'readonly'                 => false,
            'branding'                 => 'FileCarton',
            'repo_name'                => 'test',
            'feature_archive'          => 'invalid',
            'feature_unix_perm'        => 123,
            'feature_unix_perm_modify' => 'yes',
            'feature_remote_download'  => 1,
        ]);

        self::assertFalse($s2->featureArchive);
        self::assertFalse($s2->featureUnixPerm);
        self::assertFalse($s2->featureUnixPermModify);
        self::assertFalse($s2->featureRemoteDownload);
        self::assertSame([], $s2->verifyAllSet());
    }

    public function testCombineOverridesFeatureFlags(): void
    {
        $base = RepoSetting::fromConfigArray([
            'root_path'                => '/tmp/repo',
            'readonly'                 => false,
            'branding'                 => 'FileCarton',
            'repo_name'                => 'test',
            'feature_archive'          => true,
            'feature_unix_perm'        => false,
            'feature_unix_perm_modify' => false,
            'feature_remote_download'  => false,
        ]);

        $override = RepoSetting::fromStaticRow([
            'root_path'                => '/tmp/user',
            'feature_unix_perm'        => true,
            'feature_unix_perm_modify' => true,
            'feature_remote_download'  => true,
        ]);

        $combined = $base->combine($override);
        self::assertSame('/tmp/user', $combined->rootPath);
        self::assertTrue($combined->featureArchive);
        self::assertTrue($combined->featureUnixPerm);
        self::assertTrue($combined->featureUnixPermModify);
        self::assertTrue($combined->featureRemoteDownload);
        self::assertSame([], $combined->verifyAllSet());
    }

    public function testNonBooleanReadonlyInConfigArrayFailsVerification(): void
    {
        $s = RepoSetting::fromConfigArray([
            'root_path' => '/tmp/repo',
            'readonly'  => 'false',
            'branding'  => 'FileCarton',
            'repo_name' => 'test',
        ]);

        self::assertNull($s->readonly);
        self::assertNotEmpty($s->verifyAllSet());
    }
}
