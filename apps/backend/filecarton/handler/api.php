<?php

namespace FileCarton;

/**
 * FileCarton API Handler
 *
 * Validates action, enforces CSRF + readonly for POST, dispatches to api/*.php.
 * Global try-catch converts exceptions into JSON error responses.
 */

function handle_api(): void {
require_once FILECARTON_SCRIPT_DIR . '/lib/Platform.php';
require_once FILECARTON_SCRIPT_DIR . '/lib/PathSecurity.php';
require_once FILECARTON_SCRIPT_DIR . '/lib/FileOps.php';
require_once FILECARTON_SCRIPT_DIR . '/lib/MimeType.php';
require_once FILECARTON_SCRIPT_DIR . '/lib/Csrf.php';
require_once FILECARTON_SCRIPT_DIR . '/lib/Response.php';
require_once FILECARTON_SCRIPT_DIR . '/lib/ApiException.php';
require_once FILECARTON_SCRIPT_DIR . '/lib/ArchiveReader.php';
require_once FILECARTON_SCRIPT_DIR . '/lib/TempStorage.php';
require_once FILECARTON_SCRIPT_DIR . '/lib/StreamingZipWriter.php';
require_once FILECARTON_SCRIPT_DIR . '/lib/ArchiveEntries.php';

$action = $_GET['fcapi'] ?? '';
$authPublic = ['auth_login', 'auth_logout'];
$isAuthAction = in_array($action, $authPublic, true);

ob_start();

// Action registry: [flags, feature?]
// flags: 0 = GET, 1 = POST, 3 = POST+mutates (readonly-blocked)
$actions = [
    'list'                   => [0],
    'tree_node'              => [0],
    'read'                   => [0],
    'raw'                    => [0],
    'download'               => [0],
    'search'                 => [0],
    'write'                  => [3],
    'create'                 => [3],
    'delete'                 => [3],
    'rename'                 => [3],
    'paste'                  => [3],
    'chmod'                  => [3, 'unixPermModify'],
    'tus'                    => [3],
    'archive_create'         => [3, 'archive'],
    'archive_extract'        => [3, 'archive'],
    'archive_list'           => [0, 'archive'],
    'archive_read'           => [0, 'archive'],
    'archive_raw'            => [0, 'archive'],
    'archive_download'       => [0, 'archive'],
    'batch_download'         => [0, 'archive'],
    'check_upload_conflicts' => [1],
    'remote_download'        => [3, 'remoteDownload'],
    'checksum'               => [0, 'checksum'],
    'auth_login'             => [1],
    'auth_logout'            => [1],
];

try {
    $desc = $actions[$action] ?? null;
    if ($desc === null) {
        Response::error('unknown_action', 400);
    }

    assert_api_access($isAuthAction);

    if (($desc[0] & 1) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        Response::error('method_not_allowed', 405);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Csrf::validate();
        if (($desc[0] & 2) && RepoSetting::current()->readonly) {
            Response::error('readonly', 403);
        }
    }

    if (isset($desc[1])) {
        $repo = RepoSetting::current();
        switch ($desc[1]) {
            case 'archive':
                if (!$repo->featureArchive) Response::error('archive.disabled', 403);
                break;
            case 'unixPermModify':
                if (!$repo->unixPermModifyEnabled()) Response::error('perm.disabled', 403);
                break;
            case 'remoteDownload':
                if (!$repo->remoteDownloadEnabled()) Response::error('remote_download.disabled', 403);
                break;
            case 'checksum':
                if (!$repo->featureChecksum) Response::error('checksum.disabled', 403);
                break;
        }
    }

    $pathSec = null;
    $fileOps = null;
    if (!$isAuthAction) {
        $pathSec = new PathSecurity(RepoSetting::current()->rootPath);
        $fileOps = new FileOps();
    }

    require_once FILECARTON_SCRIPT_DIR . '/api/' . $action . '.php';
    $actionFn = __NAMESPACE__ . '\\api_' . $action;
    $actionFn($pathSec, $fileOps);
} catch (ApiException $e) {
    Response::error($e->errorCode, $e->getCode(), $e->params);
} catch (\RuntimeException $e) {
    $code = $e->getCode();
    Response::error('server_error', ($code >= 400 && $code < 600) ? $code : 500);
} catch (\Throwable $e) {
    error_log('FC(api): ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    Response::error('server_error', 500);
}
}

/**
 * Check authentication and authorization state before API dispatch.
 * Sends an error response and exits if the request is not authorized.
 */
function assert_api_access(bool $isAuthAction): void {
    if ($isAuthAction) {
        if (RepoSetting::embed()) {
            Response::error('auth.disabled', 404);
        }
        return;
    }

    if (!RepoSetting::embed() && Auth::configProblems() !== []) {
        Response::error('auth.config', 500);
    }

    if (!RepoSetting::filesOpen()) {
        if (!RepoSetting::embed() && Auth::identity() === null) {
            Response::error('auth.required', 401, [], 'expired');
        }
        if (!RepoSetting::embed()) {
            $authError = Grants::current() === null ? 'allowlist' : 'bad_root';
            $params = ($authError === 'allowlist' && Auth::identity() !== null)
                ? ['id' => Auth::identity()->id] : [];
            Response::error('auth.' . $authError, $authError === 'bad_root' ? 403 : 401, $params, $authError);
        }
        Response::error('not_configured', 403);
    }
}
