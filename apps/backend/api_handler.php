<?php
/**
 * FileCarton API Handler
 *
 * Validates action, enforces CSRF + readonly for POST, dispatches to api/*.php.
 * Global try-catch converts exceptions into JSON error responses.
 *
 * Available from parent scope: $auCrntRepo, $auRepos, FM_ROOT_PATH, etc.
 */

require_once __DIR__ . '/lib/PathSecurity.php';
require_once __DIR__ . '/lib/FileOps.php';
require_once __DIR__ . '/lib/MimeType.php';
require_once __DIR__ . '/lib/Csrf.php';
require_once __DIR__ . '/lib/Response.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$pathSec = new PathSecurity(FM_ROOT_PATH);
$fileOps = new FileOps();

$validActions = [
    'list', 'tree_node', 'read', 'write', 'raw', 'download',
    'search', 'create', 'delete', 'rename', 'paste', 'upload',
    'upload_chunk', 'upload_complete', 'archive', 'archive_list',
];

try {
    if (!in_array($action, $validActions, true)) {
        Response::error('Unknown action: ' . $action, 400);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Csrf::validate();
        if (FM_GLOBAL_READONLY) {
            Response::error('Read-only mode', 403);
        }
    }

    require __DIR__ . '/api/' . $action . '.php';
} catch (\InvalidArgumentException $e) {
    Response::error($e->getMessage(), 400);
} catch (\RuntimeException $e) {
    $code = $e->getCode();
    Response::error($e->getMessage(), ($code >= 400 && $code < 600) ? $code : 500);
} catch (\Throwable $e) {
    error_log('FileCarton error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    Response::error('Internal server error', 500);
}
