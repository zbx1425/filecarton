<?php

namespace FileCarton\Tests\Unit;

use FileCarton\RepoSetting;
use PHPUnit\Framework\TestCase;

final class RepoSettingTest extends TestCase
{
    public function testMissingOrInvalidFeatureFlagsDefaultToFalse(): void
    {
        $s = RepoSetting::fromConfigArray([
            'rootPath' => '/tmp/repo',
            'readonly' => false,
            'branding' => 'FileCarton',
            'repoName' => 'test',
            // featureArchive, featureUnixPerm, featureUnixPermModify omitted
        ]);

        self::assertFalse($s->featureArchive);
        self::assertFalse($s->featureUnixPerm);
        self::assertFalse($s->featureUnixPermModify);
        self::assertFalse($s->featureRemoteDownload);
        $s->verifyAllSet();

        // Non-boolean values should also silently default to false and not fail verifyAllSet
        $s2 = RepoSetting::fromConfigArray([
            'rootPath' => '/tmp/repo',
            'readonly' => false,
            'branding' => 'FileCarton',
            'repoName' => 'test',
            'featureArchive' => 'invalid',
            'featureUnixPerm' => 123,
            'featureUnixPermModify' => 'yes',
            'featureRemoteDownload' => 1,
        ]);

        self::assertFalse($s2->featureArchive);
        self::assertFalse($s2->featureUnixPerm);
        self::assertFalse($s2->featureUnixPermModify);
        self::assertFalse($s2->featureRemoteDownload);
        $s2->verifyAllSet();
    }

    public function testCombineOverridesFeatureFlags(): void
    {
        $base = RepoSetting::fromConfigArray([
            'rootPath' => '/tmp/repo',
            'readonly' => false,
            'branding' => 'FileCarton',
            'repoName' => 'test',
            'featureArchive' => true,
            'featureUnixPerm' => false,
            'featureUnixPermModify' => false,
            'featureRemoteDownload' => false,
        ]);

        $override = RepoSetting::fromStaticRow([
            'root' => '/tmp/user',
            'featureUnixPerm' => true,
            'featureUnixPermModify' => true,
            'featureRemoteDownload' => true,
        ]);

        $combined = $base->combine($override);
        self::assertSame('/tmp/user', $combined->rootPath);
        self::assertTrue($combined->featureArchive);
        self::assertTrue($combined->featureUnixPerm);
        self::assertTrue($combined->featureUnixPermModify);
        self::assertTrue($combined->featureRemoteDownload);
        $combined->verifyAllSet();
    }
}
