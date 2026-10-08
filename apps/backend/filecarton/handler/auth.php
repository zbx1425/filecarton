<?php

namespace FileCarton;

function handle_auth(): void {
    $action = $_GET['fcauth'] ?? '';
    $pluginId = isset($_GET['plugin']) && is_string($_GET['plugin']) ? $_GET['plugin'] : '';
    if ($action === 'callback') {
        $token = $_GET['state'] ?? $_GET['fc_nonce'] ?? '';
        $token = is_string($token) ? $token : '';
        $peeked = ($pluginId !== '' && $token !== '')
            ? Auth::peekPending($pluginId, $token) : null;
        $pendingForCatch = $peeked !== null
            ? $peeked : Auth::emptyReturnContext();
    } else {
        $pendingForCatch = Auth::captureReturnFallback();
    }
    try {
        $problems = Auth::configProblems();
        if ($problems !== []) {
            throw new AuthException('config', 500, 'Auth misconfigured');
        }
        if ($action === 'start') {
            auth_start($pluginId);
            return;
        }
        if ($action === 'callback') {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
                throw new AuthException('unknown', 405, 'Callback must be GET');
            }
            auth_callback($pluginId);
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
        $loc = Auth::returnLocation($pendingForCatch, $token);
        header('Location: ' . $loc, true, 302);
        exit;
    }
}

function auth_start($pluginId): void {
    $plugin = Auth::pluginById($pluginId);
    if (!$plugin instanceof RedirectAuth) {
        throw new AuthException('config', 400, 'Unknown redirect plugin');
    }
    $nonce = Auth::createPending($plugin->id());
    $ctx = AuthContext::forStart($plugin, $nonce);
    $url = $plugin->start($ctx);
    if (!is_string($url) || strpbrk($url, "\r\n\0") !== false
        || !preg_match('#^https?://#i', $url)) {
        throw new AuthException('config', 500, 'Plugin start URL rejected');
    }
    header('Location: ' . $url, true, 302);
    exit;
}

function auth_callback($pluginId): void {
    $plugin = Auth::pluginById($pluginId);
    if (!$plugin instanceof RedirectAuth) {
        throw new AuthException('config', 400, 'Unknown redirect plugin');
    }
    $token = '';
    if (isset($_GET['state']) && is_string($_GET['state']) && $_GET['state'] !== '') {
        $token = $_GET['state'];
    } elseif (isset($_GET['fc_nonce']) && is_string($_GET['fc_nonce']) && $_GET['fc_nonce'] !== '') {
        $token = $_GET['fc_nonce'];
    }
    $pending = Auth::consumePending($plugin->id(), $token);
    if ($pending === null) {
        throw new AuthException('expired', 401, 'Pending login missing');
    }
    $ctx = AuthContext::forComplete($plugin, $pending);
    $identity = $plugin->complete($ctx);
    Auth::establish($identity);
    header('Location: ' . Auth::returnLocation($pending), true, 302);
    exit;
}
