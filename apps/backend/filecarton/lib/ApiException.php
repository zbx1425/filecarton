<?php

namespace FileCarton;

/**
 * Exception carrying a structured error code for JSON API responses.
 *
 * Thrown by PathSecurity, API handlers, etc. Caught centrally by handle_api()
 * and converted to a JSON error response via Response::error().
 */
class ApiException extends \RuntimeException {
    /** @var string Dot-separated error code, e.g. 'not_found.dir' */
    public $errorCode;
    /** @var array Interpolation params for i18n, e.g. ['maxMB' => '4.0'] */
    public $params;

    public function __construct(string $errorCode, int $httpStatus = 400, array $params = []) {
        $this->errorCode = $errorCode;
        $this->params = $params;
        parent::__construct($errorCode, $httpStatus);
    }
}
