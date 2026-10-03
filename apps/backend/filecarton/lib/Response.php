<?php

namespace FileCarton;

class Response {

    private static function discardOutputBuffer(): void {
        if (ob_get_level()) {
            $buffered = ob_get_clean();
            if ($buffered !== '' && $buffered !== false) {
                error_log('FileCarton: suppressed output: ' . substr($buffered, 0, 500));
            }
        }
    }

    public static function ok($data = null) {
        self::discardOutputBuffer();
        http_response_code(200);
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $msg, int $code = 400) {
        self::discardOutputBuffer();
        http_response_code($code);
        echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Serve a file inline with correct Content-Type and ETag caching.
     * Used by the `raw` endpoint for image/audio preview.
     * Supports X-Sendfile / X-Accel-Redirect offload via FILECARTON_SENDFILE.
     */
    public static function stream(string $absPath, string $mime) {
        self::discardOutputBuffer();
        $mtime = filemtime($absPath);
        $size = filesize($absPath);
        $etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';

        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            http_response_code(304);
            exit;
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $size);
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=3600');
        header('X-Content-Type-Options: nosniff');

        if (self::trySendfile($absPath)) {
            exit;
        }

        readfile($absPath);
        exit;
    }

    /**
     * Force-download a file (Content-Disposition: attachment).
     * Supports X-Sendfile / X-Accel-Redirect offload via FILECARTON_SENDFILE.
     */
    public static function file(string $absPath, ?string $filename = null) {
        self::discardOutputBuffer();
        $filename = $filename ?? basename($absPath);
        $size = filesize($absPath);

        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $filename);
        $encoded = rawurlencode($filename);
        header('Content-Type: application/octet-stream');
        header("Content-Disposition: attachment; filename=\"" . addcslashes($ascii, '"\\') . "\"; filename*=UTF-8''" . $encoded);
        header('Content-Length: ' . $size);
        header('Cache-Control: no-cache');
        header('X-Content-Type-Options: nosniff');

        if (self::trySendfile($absPath)) {
            exit;
        }

        readfile($absPath);
        exit;
    }

    /**
     * Offload file serving to the web server via X-Sendfile or X-Accel-Redirect.
     * Returns true if the header was sent (caller should exit without body).
     *
     * Configured via FILECARTON_SENDFILE:
     *   false        — disabled (default)
     *   'xsendfile'  — Apache mod_xsendfile (absolute path)
     *   [[phys, uri], ...] — Nginx X-Accel-Redirect (prefix mapping)
     */
    public static function trySendfile(string $absPath): bool {
        if (!defined('FILECARTON_SENDFILE') || !FILECARTON_SENDFILE) {
            return false;
        }

        $config = FILECARTON_SENDFILE;

        if ($config === 'xsendfile') {
            $realPath = realpath($absPath);
            if ($realPath === false) return false;
            header('X-Sendfile: ' . $realPath);
            return true;
        }

        if (is_array($config)) {
            $normalizedAbs = str_replace('\\', '/', realpath($absPath) ?: $absPath);

            foreach ($config as $rule) {
                if (!is_array($rule) || count($rule) < 2) continue;
                [$physicalPrefix, $uriPrefix] = $rule;

                $resolvedPrefix = realpath($physicalPrefix);
                if ($resolvedPrefix === false) continue;
                $resolvedPrefix = rtrim(str_replace('\\', '/', $resolvedPrefix), '/');
                $uriPrefix = rtrim($uriPrefix, '/');

                if (str_starts_with($normalizedAbs, $resolvedPrefix . '/')) {
                    $relativePath = substr($normalizedAbs, strlen($resolvedPrefix) + 1);
                    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $relativePath)));
                    header('X-Accel-Redirect: ' . $uriPrefix . '/' . $encodedPath);
                    return true;
                }
            }
        }

        return false;
    }
}
