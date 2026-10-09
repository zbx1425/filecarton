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

$action = $_GET['fcapi'] ?? '';
$authPublic = ['auth_login', 'auth_logout'];
$isAuthAction = in_array($action, $authPublic, true);

$binaryActions = ['raw', 'download'];
if (!in_array($action, $binaryActions, true)) {
    header('Content-Type: application/json; charset=utf-8');
}

ob_start();

$validActions = [
    'list', 'tree_node', 'read', 'write', 'raw', 'download',
    'search', 'create', 'delete', 'rename', 'paste', 'upload',
    'upload_chunk', 'upload_complete', 'archive_create', 'archive_extract',
    'archive_list', 'check_upload_conflicts',
    'auth_login', 'auth_logout',
];

$postActions = [
    'write', 'create', 'delete', 'rename', 'paste', 'upload',
    'upload_chunk', 'upload_complete', 'archive_create', 'archive_extract',
    'check_upload_conflicts',
    'auth_login', 'auth_logout',
];

try {
    if (!in_array($action, $validActions, true)) {
        Response::error('unknown_action', 400);
    }

    assert_api_access($isAuthAction);

    if (in_array($action, $postActions, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        Response::error('method_not_allowed', 405);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Csrf::validate();
        if (!$isAuthAction && RepoSetting::current()->readonly) {
            Response::error('readonly', 403);
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
    error_log('FileCarton error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
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
