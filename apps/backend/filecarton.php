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
            require_once FILECARTON_SCRIPT_DIR . '/handler/page.php';
            $msg = $token === 'bad_root'
                ? 'The root directory for this account is not a valid directory.'
                : 'No grant found for the current identity.';
            render_error_page('FileCarton Self-Check Failed', [$msg], 403);
            return;
        }
        // Interactive login: clear session once, show login page with error.
        $errorParams = ($token === 'allowlist' && Auth::identity() !== null)
            ? ['id' => Auth::identity()->id] : [];
        Auth::setInlineError($token, $errorParams);
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
        require_once FILECARTON_SCRIPT_DIR . '/handler/page.php';
        render_error_page('FileCarton Self-Check Failed', ['FILECARTON_ROOT_PATH is not configured.'], 403);
        exit;
    }

    require_once FILECARTON_SCRIPT_DIR . '/handler/page.php';
    handle_page();
}

dispatch();
