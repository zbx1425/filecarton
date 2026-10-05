<?php

namespace FileCarton;

/**
 * FileCarton Entry Point
 */

define("FILECARTON_SCRIPT_DIR", __DIR__ . '/filecarton');
require_once FILECARTON_SCRIPT_DIR . '/lib/Polyfill.php';
require_once __DIR__ . '/filecarton.config.php';

/**
 * SCRIPT_NAME only, for asset URLs so they stay globally cacheable
 * regardless of PATH_INFO or passthrough parameters.
 */
function script_url(): string {
    return $_SERVER['SCRIPT_NAME'];
}

/**
 * SCRIPT_NAME + PATH_INFO, used as apiBase so that wrappers' PATH_INFO
 * context is preserved across subsequent requests.
 */
function entry_url(): string {
    return $_SERVER['SCRIPT_NAME'] . ($_SERVER['PATH_INFO'] ?? '');
}

function dispatch(): void {
    // Handle static assets before ROOT_PATH check so that wrappers
    // can serve assets without configuring a managed directory.
    if (isset($_GET['fcres'])) {
        require_once FILECARTON_SCRIPT_DIR . '/handler/asset.php';
        handle_asset($_GET['fcres']);
        exit;
    }

    if (FILECARTON_ROOT_PATH === '') {
        http_response_code(403);
        echo 'FileCarton: FILECARTON_ROOT_PATH is not configured.';
        exit;
    }

    if (isset($_GET['fcapi'])) {
        require_once FILECARTON_SCRIPT_DIR . '/handler/api.php';
        handle_api();
    } else {
        require_once FILECARTON_SCRIPT_DIR . '/handler/page.php';
        handle_page();
    }
}

dispatch();
