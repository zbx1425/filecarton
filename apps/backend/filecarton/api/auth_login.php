<?php

namespace FileCarton;

function api_auth_login($pathSec = null, $fileOps = null) {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    $username = is_array($body) && isset($body['username']) ? $body['username'] : '';
    $password = is_array($body) && isset($body['password']) ? $body['password'] : '';
    try {
        $user = Auth::loginPassword($username, $password);
    } catch (AuthException $e) {
        if ($e->token === 'allowlist') {
            Response::error('This account is not allowed to log in', 403, 'allowlist');
        }
        throw $e;
    }
    if ($user === null) {
        Response::error('Invalid username or password', 401);
    }
    Response::ok([
        'user'      => $user->toPublicArray(),
        'csrfToken' => Csrf::getToken(),
    ]);
}
