<?php

namespace FileCarton;

function api_auth_login($pathSec = null, $fileOps = null) {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    $username = is_array($body) && isset($body['username']) ? $body['username'] : '';
    $password = is_array($body) && isset($body['password']) ? $body['password'] : '';
    $extra = [];
    if (is_array($body) && isset($body['extra']) && is_array($body['extra'])) {
        $allowedNames = [];
        foreach (Auth::sanitizeLoginFields(
            defined('FILECARTON_LOGIN_UI_EXTRA_FIELDS') ? FILECARTON_LOGIN_UI_EXTRA_FIELDS : []
        ) as $field) {
            $allowedNames[$field['name']] = true;
        }
        foreach ($body['extra'] as $k => $v) {
            if (!is_string($k) || !isset($allowedNames[$k])) continue;
            if (!is_string($v) && !is_numeric($v)) continue;
            $v = (string)$v;
            if (strlen($v) > 1024) continue;
            $extra[$k] = $v;
        }
    }
    try {
        $user = Auth::loginPassword($username, $password, $extra);
    } catch (AuthException $e) {
        if ($e->token === 'allowlist') {
            Response::error('auth.allowlist', 403, ['id' => $e->identityId ?? ''], 'allowlist');
        } else if ($e->token === 'not_set_up') {
            Response::error('auth.not_set_up', 403);
        }
        throw $e;
    }
    if ($user === null) {
        Response::error('auth.invalid_credentials', 401);
    }
    Response::ok([
        'user'      => $user->toPublicArray(),
        'csrfToken' => Csrf::getToken(),
    ]);
}
