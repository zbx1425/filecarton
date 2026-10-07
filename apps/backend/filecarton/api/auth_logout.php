<?php

namespace FileCarton;

function api_auth_logout($pathSec = null, $fileOps = null) {
    Auth::logout();
    Response::ok(['csrfToken' => Csrf::getToken()]);
}
