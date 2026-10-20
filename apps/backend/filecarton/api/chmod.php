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
        $resolvedOwner = resolve_chown_target($ownerRaw, 'posix_getpwnam', 'uid');
        if ($resolvedOwner === null) {
            Response::error('perm.invalid_owner', 400, ['owner' => $ownerRaw]);
        }
    }

    $resolvedGroup = null;
    if ($hasGroupChange) {
        $resolvedGroup = resolve_chown_target($groupRaw, 'posix_getgrnam', 'gid');
        if ($resolvedGroup === null) {
            Response::error('perm.invalid_group', 400, ['group' => $groupRaw]);
        }
    }

    $basePath = $pathSec->resolveExistingDir($input['path']);

    $completed = 0;
    $failed = [];

    foreach ($input['items'] as $name) {
        if (!is_string($name) || $name === '') continue;

        if ($name === '.' || $name === '..' || str_contains($name, "\0") || str_contains($name, '/') || str_contains($name, '\\')) {
            $failed[] = ['name' => $name, 'error' => 'invalid_filename'];
            continue;
        }

        $rawTarget = $basePath . '/' . $name;
        if (is_link($rawTarget)) {
            $failed[] = ['name' => $name, 'error' => 'access_denied'];
            continue;
        }

        try {
            $itemPath = $pathSec->resolveItemIn($basePath, $name);
        } catch (ApiException $e) {
            $failed[] = ['name' => $name, 'error' => $e->errorCode === 'path_traversal' ? 'path_traversal' : 'invalid_filename'];
            continue;
        } catch (\Throwable $e) {
            $failed[] = ['name' => $name, 'error' => 'invalid_filename'];
            continue;
        }

        if ($pathSec->isIgnored($itemPath) || !file_exists($itemPath)) {
            $failed[] = ['name' => $name, 'error' => 'not_found.file'];
            continue;
        }

        $isDir = is_dir($itemPath);

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
                $completed,
                $failed
            );
        }

        if (!apply_unix_perm_single($itemPath, $hasModeChange, $setMask, $clearMask, $hasOwnerChange, $resolvedOwner, $hasGroupChange, $resolvedGroup)) {
            $failed[] = ['name' => $name, 'error' => 'io_error'];
            continue;
        }

        $completed++;
    }

    clearstatcache();
    Response::ok(['completed' => $completed, 'failed' => $failed]);
}

/**
 * Resolve an owner/group string to a name or numeric ID suitable for chown()/chgrp().
 * Returns null if invalid or non-existent.
 *
 * @return string|int|null
 */
function resolve_chown_target(string $target, string $posixFn, string $idKey) {
    if (preg_match('/[\x00-\x1F\x7F:]/', $target)) {
        return null;
    }
    if (function_exists($posixFn)) {
        $info = @$posixFn($target);
        if (is_array($info) && isset($info[$idKey])) {
            return (int)$info[$idKey];
        }
        return ctype_digit($target) ? (int)$target : null;
    }
    return ctype_digit($target) ? (int)$target : $target;
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
        if ($hasOwnerChange && (!function_exists('chown') || !@chown($absPath, $owner))) {
            return false;
        }
        if ($hasGroupChange && (!function_exists('chgrp') || !@chgrp($absPath, $group))) {
            return false;
        }
        if (!$hasModeChange && $origPerms !== false && (($origPerms & 06000) !== 0)) {
            // chown/chgrp on Linux may clear SUID/SGID bits; restore original mode if mode wasn't being changed
            @chmod($absPath, $origPerms & 07777);
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
 * Applies to children before parent directories so removing read/exec bits on directories
 * does not block traversing their contents.
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
    int &$completed,
    array &$failed
): void {
    $entries = @scandir($dirAbs);
    if ($entries === false) {
        $failed[] = ['name' => $relPrefix . '/*', 'error' => 'io_error'];
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
                $completed,
                $failed
            );
        }

        if (apply_unix_perm_single($normalizedChild, $hasModeChange, $setMask, $clearMask, $hasOwnerChange, $owner, $hasGroupChange, $group)) {
            $completed++;
        } else {
            $failed[] = ['name' => $childRel, 'error' => 'io_error'];
        }
    }
}
