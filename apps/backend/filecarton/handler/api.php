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

$action = $_GET['action'] ?? '';
$pathSec = new PathSecurity(FILECARTON_ROOT_PATH);
$fileOps = new FileOps();

$binaryActions = ['raw', 'download'];
if (!in_array($action, $binaryActions, true)) {
    header('Content-Type: application/json; charset=utf-8');
}

ob_start();

$validActions = [
    'list', 'tree_node', 'read', 'write', 'raw', 'download',
    'search', 'create', 'delete', 'rename', 'paste', 'upload',
    'upload_chunk', 'upload_complete', 'archive', 'archive_list',
    'check_upload_conflicts',
];

$postActions = [
    'write', 'create', 'delete', 'rename', 'paste', 'upload',
    'upload_chunk', 'upload_complete', 'archive', 'check_upload_conflicts',
];

try {
    if (!in_array($action, $validActions, true)) {
        Response::error('Unknown action: ' . $action, 400);
    }

    if (in_array($action, $postActions, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        Response::error('Method not allowed', 405);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Csrf::validate();
        if (FILECARTON_READONLY) {
            Response::error('Read-only mode', 403);
        }
    }

    require_once FILECARTON_SCRIPT_DIR . '/api/' . $action . '.php';
    $actionFn = __NAMESPACE__ . '\\api_' . $action;
    $actionFn($pathSec, $fileOps);
} catch (\InvalidArgumentException $e) {
    Response::error($e->getMessage(), 400);
} catch (\RuntimeException $e) {
    $code = $e->getCode();
    Response::error($e->getMessage(), ($code >= 400 && $code < 600) ? $code : 500);
} catch (\Throwable $e) {
    error_log('FileCarton error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    Response::error('Internal server error', 500);
}
}
