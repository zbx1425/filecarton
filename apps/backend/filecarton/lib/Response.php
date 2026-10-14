<?php

namespace FileCarton;

class Response {

    private static function discardOutputBuffer(): void {
        if (ob_get_level()) {
            $buffered = ob_get_clean();
            if ($buffered !== '' && $buffered !== false) {
                error_log('FC(response): dirty OB: ' . substr($buffered, 0, 500));
            }
        }
    }

    public static function ok($data = null) {
        self::discardOutputBuffer();
        http_response_code(200);
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $code, int $httpStatus = 400, array $params = [], ?string $authError = null) {
        self::discardOutputBuffer();
        http_response_code($httpStatus);
        $body = ['ok' => false, 'error' => $code];
        if (!empty($params)) {
            $body['params'] = $params;
        }
        if ($authError !== null) {
            $body['authError'] = $authError;
        }
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Serve a file inline with correct Content-Type, ETag revalidation, and HTTP Range (206) support.
     * Used by the `raw` endpoint for image/audio/video preview.
     * Supports X-Sendfile / X-Accel-Redirect offload via FILECARTON_SENDFILE.
     */
    public static function stream(string $absPath, string $mime) {
        self::discardOutputBuffer();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $real = realpath($absPath);
        $canonicalPath = str_replace('\\', '/', $real !== false ? $real : $absPath);
        $mtime = (int)filemtime($absPath);
        $size = (int)filesize($absPath);
        // Use MD5 here because this is not very security critical.
        $etag = '"' . substr(hash('md5', $canonicalPath . "\0" . $mtime . "\0" . $size), 0, 32) . '"';

        header('ETag: ' . $etag);
        header('Cache-Control: private, no-cache, must-revalidate');
        header('Vary: Cookie');
        header('Accept-Ranges: bytes');
        header('X-Content-Type-Options: nosniff');

        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            http_response_code(304);
            exit;
        }

        header('Content-Type: ' . $mime);

        if (self::trySendfile($absPath)) {
            exit;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        @ini_set('zlib.output_compression', 'Off');

        $rangeHeader = $_SERVER['HTTP_RANGE'] ?? null;
        if ($rangeHeader !== null && isset($_SERVER['HTTP_IF_RANGE'])) {
            $ifRange = trim($_SERVER['HTTP_IF_RANGE']);
            if ($ifRange !== $etag && ($mtime <= 0 || @strtotime($ifRange) !== $mtime)) {
                $rangeHeader = null;
            }
        }

        $start = 0;
        $end = $size - 1;
        $length = $size;
        $isPartial = false;

        if ($rangeHeader !== null && str_starts_with(trim($rangeHeader), 'bytes=')) {
            if ($size === 0 || !preg_match('/^bytes=(\d*)-(\d*)$/', trim($rangeHeader), $matches)) {
                http_response_code(416);
                header("Content-Range: bytes */{$size}");
                exit;
            }

            $startStr = $matches[1];
            $endStr = $matches[2];

            if ($startStr === '' && $endStr === '') {
                http_response_code(416);
                header("Content-Range: bytes */{$size}");
                exit;
            } elseif ($startStr === '') {
                $suffixLen = (int)$endStr;
                if ($suffixLen <= 0) {
                    http_response_code(416);
                    header("Content-Range: bytes */{$size}");
                    exit;
                }
                $start = max(0, $size - $suffixLen);
                $end = $size - 1;
            } else {
                $start = (int)$startStr;
                $end = ($endStr === '') ? ($size - 1) : min((int)$endStr, $size - 1);
                if ($start >= $size || ($endStr !== '' && (int)$endStr < $start)) {
                    http_response_code(416);
                    header("Content-Range: bytes */{$size}");
                    exit;
                }
            }

            $length = $end - $start + 1;
            $isPartial = true;
        }

        if ($isPartial) {
            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$size}");
        } else {
            http_response_code(200);
        }
        header('Content-Length: ' . $length);

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' || $length <= 0) {
            exit;
        }

        @set_time_limit(0);
        $fp = fopen($absPath, 'rb');
        if ($fp === false) {
            exit;
        }
        if ($start > 0) {
            fseek($fp, $start);
        }
        $remaining = $length;
        $chunkSize = 65536;
        while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
            $buffer = fread($fp, min($chunkSize, $remaining));
            if ($buffer === false || $buffer === '') {
                break;
            }
            echo $buffer;
            flush();
            $remaining -= strlen($buffer);
        }
        fclose($fp);
        exit;
    }

    /**
     * Force-download a file (Content-Disposition: attachment).
     * Supports X-Sendfile / X-Accel-Redirect offload via FILECARTON_SENDFILE.
     */
    public static function file(string $absPath, ?string $filename = null) {
        self::discardOutputBuffer();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $filename = $filename ?? basename($absPath);
        $size = filesize($absPath);

        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $filename);
        $encoded = rawurlencode($filename);
        header('Content-Type: application/octet-stream');
        header("Content-Disposition: attachment; filename=\"" . addcslashes($ascii, '"\\') . "\"; filename*=UTF-8''" . $encoded);
        header('Content-Length: ' . $size);
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');

        if (self::trySendfile($absPath)) {
            exit;
        }

        readfile($absPath);
        exit;
    }

    /**
     * Serve an open stream resource inline with ETag revalidation and Content-Type.
     * Used by `archive_raw` for previewing files inside archives.
     *
     * @param resource $stream
     */
    public static function streamFromResource($stream, int $size, string $mime, string $etagSeed, ?callable $cleanup = null) {
        self::discardOutputBuffer();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $etag = '"' . substr(hash('md5', $etagSeed), 0, 32) . '"';

        header('ETag: ' . $etag);
        header('Cache-Control: private, no-cache, must-revalidate');
        header('Vary: Cookie');
        header('X-Content-Type-Options: nosniff');

        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            if ($cleanup) $cleanup();
            http_response_code(304);
            exit;
        }

        header('Content-Type: ' . $mime);
        http_response_code(200);
        if ($size >= 0) {
            header('Content-Length: ' . $size);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        @ini_set('zlib.output_compression', 'Off');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' || $size === 0) {
            if ($cleanup) $cleanup();
            exit;
        }

        @set_time_limit(0);
        $maxBytes = defined('FILECARTON_ARCHIVE_MAX_SIZE') ? (int)FILECARTON_ARCHIVE_MAX_SIZE : (1024 * 1024 * 1024);
        $limit = $size > 0 ? min($size, $maxBytes) : $maxBytes;
        $remaining = $limit;
        $chunkSize = 65536;
        while ($remaining > 0 && !feof($stream) && !connection_aborted()) {
            $buffer = fread($stream, min($chunkSize, $remaining));
            if ($buffer === false || $buffer === '') {
                break;
            }
            echo $buffer;
            flush();
            $remaining -= strlen($buffer);
        }
        if ($cleanup) $cleanup();
        exit;
    }

    /**
     * Force-download from an open stream resource (Content-Disposition: attachment).
     * Used by `archive_download` for downloading a single file from an archive.
     *
     * @param resource $stream
     */
    public static function fileFromResource($stream, int $size, string $filename, ?callable $cleanup = null) {
        self::discardOutputBuffer();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $filename);
        $encoded = rawurlencode($filename);
        header('Content-Type: application/octet-stream');
        header("Content-Disposition: attachment; filename=\"" . addcslashes($ascii, '"\\') . "\"; filename*=UTF-8''" . $encoded);
        if ($size >= 0) {
            header('Content-Length: ' . $size);
        }
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        @ini_set('zlib.output_compression', 'Off');

        @set_time_limit(0);
        $maxBytes = defined('FILECARTON_ARCHIVE_MAX_SIZE') ? (int)FILECARTON_ARCHIVE_MAX_SIZE : (1024 * 1024 * 1024);
        $limit = $size > 0 ? min($size, $maxBytes) : $maxBytes;
        $remaining = $limit;
        $chunkSize = 65536;
        while ($remaining > 0 && !feof($stream) && !connection_aborted()) {
            $buffer = fread($stream, min($chunkSize, $remaining));
            if ($buffer === false || $buffer === '') {
                break;
            }
            echo $buffer;
            flush();
            $remaining -= strlen($buffer);
        }
        if ($cleanup) $cleanup();
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
