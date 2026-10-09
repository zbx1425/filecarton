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
    // Handle static assets before auth — these are public and cacheable.
    if (isset($_GET['fcres'])) {
        require_once FILECARTON_SCRIPT_DIR . '/handler/asset.php';
        handle_asset($_GET['fcres']);
        exit;
    }

    require_once FILECARTON_SCRIPT_DIR . '/lib/Settings.php';

    if (!Settings::embed()) {
        require_once FILECARTON_SCRIPT_DIR . '/lib/Csrf.php';
        require_once FILECARTON_SCRIPT_DIR . '/lib/Auth.php';
        require_once FILECARTON_SCRIPT_DIR . '/lib/AuthPassword.php';
        require_once FILECARTON_SCRIPT_DIR . '/lib/AuthImplicit.php';
        require_once FILECARTON_SCRIPT_DIR . '/lib/AuthRedirect.php';
        require_once FILECARTON_SCRIPT_DIR . '/lib/HttpClient.php';
        require_once FILECARTON_SCRIPT_DIR . '/lib/Grant.php';
        Auth::boot();

        if (isset($_GET['fcauth'])) {
            require_once FILECARTON_SCRIPT_DIR . '/handler/auth.php';
            handle_auth();
            exit;
        }
    }

    if (isset($_GET['fcapi'])) {
        require_once FILECARTON_SCRIPT_DIR . '/handler/api.php';
        handle_api();
        return;
    }

    // Standalone without identity → serve HTML shell (login page).
    if (!Settings::embed() && Auth::identity() === null) {
        require_once FILECARTON_SCRIPT_DIR . '/handler/page.php';
        handle_page();
        return;
    }

    // Identity exists but no usable grant or bad root.
    if (!Settings::embed() && !Settings::filesOpen()) {
        $token = Grants::current() === null ? 'allowlist' : 'bad_root';
        if (Auth::hasImplicit()) {
            // Implicit login: identity is synthesized each request, logout is meaningless.
            // Fall through to the self-check page with a descriptive error.
            require_once FILECARTON_SCRIPT_DIR . '/lib/StartupCheck.php';
            http_response_code(403);
            $msg = $token === 'bad_root'
                ? 'The root directory for this account is not a valid directory.'
                : 'No grant found for the current identity.';
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>FileCarton</title></head><body>'
                . '<div style="margin:4em auto;max-width:60em;font-family:Arial,sans-serif">'
                . '<h1>FileCarton Configuration Error</h1><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
                . '</div></body></html>';
            return;
        }
        // Interactive login: clear session once, show login page with error.
        Auth::setInlineError($token);
        Auth::logout();
        Settings::resetForRequest();
        if (class_exists(__NAMESPACE__ . '\\Grants', false)) {
            Grants::resetForRequest();
        }
        require_once FILECARTON_SCRIPT_DIR . '/handler/page.php';
        handle_page();
        return;
    }

    if (!Settings::filesOpen()) {
        http_response_code(403);
        echo 'FileCarton: FILECARTON_ROOT_PATH is not configured.';
        exit;
    }

    require_once FILECARTON_SCRIPT_DIR . '/handler/page.php';
    handle_page();
}

dispatch();
