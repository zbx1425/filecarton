<?php

namespace FileCarton;

function handle_auth(): void {
    $action = $_GET['fcauth'] ?? '';
    if ($action === 'callback') {
        $state = isset($_GET['state']) && is_string($_GET['state']) ? $_GET['state'] : '';
        $peeked = ($state !== '') ? AuthPendingBag::peek($state) : null;
        $pendingForCatch = $peeked !== null
            ? $peeked : AuthPendingBag::emptyReturnContext();
    } else {
        $pendingForCatch = AuthPendingBag::captureReturnContext();
    }
    try {
        $problems = Auth::configProblems();
        if ($problems !== []) {
            throw new AuthException('config', 500, 'Auth misconfigured');
        }
        if ($action === 'start') {
            $pluginId = isset($_GET['plugin']) && is_string($_GET['plugin']) ? $_GET['plugin'] : '';
            auth_start($pluginId);
            return;
        }
        if ($action === 'callback') {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
                throw new AuthException('unknown', 405, 'Callback must be GET');
            }
            auth_callback();
            return;
        }
        throw new AuthException('unknown', 400, 'Unknown auth action');
    } catch (\Throwable $e) {
        $code = (int)$e->getCode();
        if ($code < 400 || $code >= 600) $code = 500;
        if ($code >= 500) {
            error_log('FileCarton auth: ' . $e->getMessage());
        }
        $token = Auth::errorToken($e);
        $errorId = ($e instanceof AuthException) ? $e->identityId : null;
        $loc = AuthPendingBag::returnLocation($pendingForCatch, $token, $errorId);
        header('Location: ' . $loc, true, 302);
        exit;
    }
}

function auth_start($pluginId): void {
    $plugin = Auth::pluginById($pluginId);
    if (!$plugin instanceof RedirectAuth) {
        throw new AuthException('config', 400, 'Unknown redirect plugin');
    }
    $nonce = AuthPendingBag::create($plugin->id());
    $ctx = AuthContext::forStart($plugin, $nonce);
    $url = $plugin->start($ctx);
    if (!is_string($url) || strpbrk($url, "\r\n\0") !== false) {
        // NOTE: URL does not have to be full, e.g. LinkAuth can provide a path on the same domain
        throw new AuthException('config', 500, 'Plugin start URL rejected');
    }
    header('Location: ' . $url, true, 302);
    exit;
}

function auth_callback(): void {
    $state = isset($_GET['state']) && is_string($_GET['state']) ? $_GET['state'] : '';
    $pending = AuthPendingBag::consume($state);
    if ($pending === null) {
        throw new AuthException('expired', 401, 'Pending login missing');
    }
    $pluginId = $pending['plugin'] ?? '';
    $plugin = Auth::pluginById($pluginId);
    if (!$plugin instanceof RedirectAuth) {
        throw new AuthException('config', 400, 'Unknown redirect plugin');
    }
    if ($plugin->isLinkOnly()) {
        header('Location: ' . AuthPendingBag::returnLocation($pending), true, 302);
        exit;
    }
    $ctx = AuthContext::forComplete($plugin, $pending);
    $identity = $plugin->complete($ctx);
    Auth::establish($identity);
    header('Location: ' . AuthPendingBag::returnLocation($pending), true, 302);
    exit;
}
