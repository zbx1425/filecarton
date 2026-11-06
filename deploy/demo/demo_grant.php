<?php

namespace FileCartonDemo;

/**
 * Demo-only grant: every GitHub account, and the password user "demo",
 * inherit the shared [repo] root. Loaded from outside the web root.
 */
class SharedFolderGrant implements \FileCarton\GrantResolver {
    public function __construct(array $opts = []) {
    }

    public function validateConfig(): array {
        return [];
    }

    public function resolve(\FileCarton\AuthIdentity $identity) {
        if ($identity->pluginId === 'github') {
            return new \FileCarton\RepoSetting();
        }
        if ($identity->pluginId === 'password' && $identity->id === 'demo') {
            return new \FileCarton\RepoSetting();
        }
        return null;
    }
}
