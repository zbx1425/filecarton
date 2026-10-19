<?php

namespace FileCarton;

/**
 * API: chmod — Change Unix permissions and/or ownership of files/directories (batch, partial success).
 * POST ?fcapi=chmod
 * Body: {
 *   path: string,
 *   items: string[],
 *   recursive?: bool,
 *   setMask?: int,
 *   clearMask?: int,
 *   owner?: string,
 *   group?: string
 * }
 */

function api_chmod(PathSecurity $pathSec, FileOps $fileOps): void {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['path'], $input['items']) || !is_array($input['items'])) {
        Response::error('missing_fields', 400, ['fields' => 'path, items']);
    }

    $recursive = !empty($input['recursive']);
    $setMask = isset($input['setMask']) && is_int($input['setMask']) ? ($input['setMask'] & 07777) : 0;
    $clearMask = isset($input['clearMask']) && is_int($input['clearMask']) ? ($input['clearMask'] & 07777) : 0;
    $hasModeChange = ($setMask !== 0 || $clearMask !== 0);

    $ownerRaw = isset($input['owner']) && is_string($input['owner']) ? trim($input['owner']) : '';
    $groupRaw = isset($input['group']) && is_string($input['group']) ? trim($input['group']) : '';
    $hasOwnerChange = ($ownerRaw !== '');
    $hasGroupChange = ($groupRaw !== '');

    if (!$hasModeChange && !$hasOwnerChange && !$hasGroupChange) {
        Response::ok(['completed' => 0, 'failed' => []]);
    }

    if ($hasOwnerChange || $hasGroupChange) {
        $procInfo = Platform::processUserInfo();
        if (empty($procInfo['isRoot'])) {
            Response::error('access_denied', 403);
        }
    }

    $resolvedOwner = null;
    if ($hasOwnerChange) {
        $resolvedOwner = resolve_chown_target_user($ownerRaw);
        if ($resolvedOwner === null) {
            Response::error('perm.invalid_owner', 400, ['owner' => $ownerRaw]);
        }
    }

    $resolvedGroup = null;
    if ($hasGroupChange) {
        $resolvedGroup = resolve_chown_target_group($groupRaw);
        if ($resolvedGroup === null) {
            Response::error('perm.invalid_group', 400, ['group' => $groupRaw]);
        }
    }

    $basePath = $pathSec->resolveExistingDir($input['path']);

    $completed = 0;
    $failed = [];

    foreach ($input['items'] as $name) {
        if (!is_string($name) || $name === '') continue;

        try {
            $itemPath = $pathSec->resolveItemIn($basePath, $name);
        } catch (\Throwable $e) {
            $failed[] = ['name' => $name, 'error' => 'invalid_filename'];
            continue;
        }

        if ($pathSec->isIgnored($itemPath) || (!file_exists($itemPath) && !is_link($itemPath))) {
            $failed[] = ['name' => $name, 'error' => 'not_found.file'];
            continue;
        }

        if (is_link($itemPath)) {
            $failed[] = ['name' => $name, 'error' => 'access_denied'];
            continue;
        }

        $itemBaseName = basename($itemPath);
        $isDir = is_dir($itemPath);

        if (!$isDir && $pathSec->isFilenameBlocked($itemBaseName)) {
            $failed[] = ['name' => $name, 'error' => 'filename_blocked'];
            continue;
        }

        if ($isDir && $recursive && $pathSec->hasProtectedDescendants($itemPath)) {
            $failed[] = ['name' => $name, 'error' => 'protected_items'];
            continue;
        }

        if (!apply_unix_perm_single($itemPath, $hasModeChange, $setMask, $clearMask, $hasOwnerChange, $resolvedOwner, $hasGroupChange, $resolvedGroup)) {
            $failed[] = ['name' => $name, 'error' => 'io_error'];
            continue;
        }

        if ($isDir && $recursive) {
            apply_unix_perm_recursive(
                $itemPath,
                $name,
                $pathSec,
                $hasModeChange,
                $setMask,
                $clearMask,
                $hasOwnerChange,
                $resolvedOwner,
                $hasGroupChange,
                $resolvedGroup,
                $failed
            );
        }

        $completed++;
    }

    clearstatcache();
    Response::ok(['completed' => $completed, 'failed' => $failed]);
}

/**
 * Resolve an owner string to a username or numeric UID suitable for chown().
 * Returns null if invalid or non-existent.
 *
 * @return string|int|null
 */
function resolve_chown_target_user(string $owner) {
    if (preg_match('/[\x00-\x1F\x7F:]/', $owner)) {
        return null;
    }
    if (function_exists('posix_getpwnam')) {
        $info = @posix_getpwnam($owner);
        if (is_array($info) && isset($info['uid'])) {
            return (int)$info['uid'];
        }
        if (ctype_digit($owner)) {
            $uid = (int)$owner;
            return $uid >= 0 ? $uid : null;
        }
        return null;
    }
    if (ctype_digit($owner)) {
        return (int)$owner;
    }
    return $owner;
}

/**
 * Resolve a group string to a group name or numeric GID suitable for chgrp().
 * Returns null if invalid or non-existent.
 *
 * @return string|int|null
 */
function resolve_chown_target_group(string $group) {
    if (preg_match('/[\x00-\x1F\x7F:]/', $group)) {
        return null;
    }
    if (function_exists('posix_getgrnam')) {
        $info = @posix_getgrnam($group);
        if (is_array($info) && isset($info['gid'])) {
            return (int)$info['gid'];
        }
        if (ctype_digit($group)) {
            $gid = (int)$group;
            return $gid >= 0 ? $gid : null;
        }
        return null;
    }
    if (ctype_digit($group)) {
        return (int)$group;
    }
    return $group;
}

/**
 * Apply chmod / chown / chgrp to a single resolved filesystem path.
 *
 * @param string|int|null $owner
 * @param string|int|null $group
 */
function apply_unix_perm_single(
    string $absPath,
    bool $hasModeChange,
    int $setMask,
    int $clearMask,
    bool $hasOwnerChange,
    $owner,
    bool $hasGroupChange,
    $group
): bool {
        if ($hasOwnerChange || $hasGroupChange) {
            $origPerms = @fileperms($absPath);
            if ($origPerms !== false) {
                $currentMode = $origPerms & 07777;
                if ($hasOwnerChange) {
                    if (!function_exists('chown') || !@chown($absPath, $owner)) {
                        return false;
                    }
                }
                if ($hasGroupChange) {
                    if (!function_exists('chgrp') || !@chgrp($absPath, $group)) {
                        return false;
                    }
                }
                if (!$hasModeChange && (($currentMode & 06000) !== 0)) {
                    // chown/chgrp on Linux may clear SUID/SGID bits; restore original mode if mode wasn't being changed
                    @chmod($absPath, $currentMode);
                }
            } else {
                if ($hasOwnerChange && (!function_exists('chown') || !@chown($absPath, $owner))) {
                    return false;
                }
                if ($hasGroupChange && (!function_exists('chgrp') || !@chgrp($absPath, $group))) {
                    return false;
                }
            }
        }
        if ($hasModeChange) {
            clearstatcache(true, $absPath);
            $currentPerms = @fileperms($absPath);
            if ($currentPerms === false) {
                return false;
            }
            $currentMode = $currentPerms & 07777;
            $newMode = ($currentMode | $setMask) & (~$clearMask & 07777);
            if (!@chmod($absPath, $newMode)) {
                return false;
            }
        }
        return true;
}

/**
 * Recursively apply permission changes inside a directory, enforcing PathSecurity rules.
 *
 * @param string|int|null $owner
 * @param string|int|null $group
 */
function apply_unix_perm_recursive(
    string $dirAbs,
    string $relPrefix,
    PathSecurity $pathSec,
    bool $hasModeChange,
    int $setMask,
    int $clearMask,
    bool $hasOwnerChange,
    $owner,
    bool $hasGroupChange,
    $group,
    array &$failed
): void {
    $entries = @scandir($dirAbs);
    if ($entries === false) {
        $failed[] = ['name' => $relPrefix, 'error' => 'io_error'];
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $childAbs = $dirAbs . '/' . $entry;
        $childRel = $relPrefix . '/' . $entry;

        if (is_link($childAbs)) {
            continue;
        }

        $realChild = realpath($childAbs);
        if ($realChild === false) {
            continue;
        }
        $normalizedChild = str_replace('\\', '/', $realChild);
        try {
            $pathSec->assertWithinRoot($normalizedChild);
        } catch (\Throwable $e) {
            continue;
        }

        if ($pathSec->isIgnored($normalizedChild)) {
            continue;
        }

        $isDir = is_dir($normalizedChild);
        if (!$isDir && $pathSec->isFilenameBlocked($entry)) {
            $failed[] = ['name' => $childRel, 'error' => 'filename_blocked'];
            continue;
        }

        if (!apply_unix_perm_single($normalizedChild, $hasModeChange, $setMask, $clearMask, $hasOwnerChange, $owner, $hasGroupChange, $group)) {
            $failed[] = ['name' => $childRel, 'error' => 'io_error'];
        }

        if ($isDir) {
            apply_unix_perm_recursive(
                $normalizedChild,
                $childRel,
                $pathSec,
                $hasModeChange,
                $setMask,
                $clearMask,
                $hasOwnerChange,
                $owner,
                $hasGroupChange,
                $group,
                $failed
            );
        }
    }
}
