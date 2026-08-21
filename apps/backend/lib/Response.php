<?php

class Response {

    public static function ok(mixed $data = null): never {
        if (ob_get_level()) ob_end_clean();
        http_response_code(200);
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $msg, int $code = 400): never {
        if (ob_get_level()) ob_end_clean();
        http_response_code($code);
        echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Serve a file inline with correct Content-Type and ETag caching.
     * Used by the `raw` endpoint for image/audio preview.
     */
    public static function stream(string $absPath, string $mime): never {
        if (ob_get_level()) ob_end_clean();
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
        readfile($absPath);
        exit;
    }

    /**
     * Force-download a file (Content-Disposition: attachment).
     */
    public static function file(string $absPath, ?string $filename = null): never {
        if (ob_get_level()) ob_end_clean();
        $filename = $filename ?? basename($absPath);
        $size = filesize($absPath);

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . addcslashes($filename, '"\\') . '"');
        header('Content-Length: ' . $size);
        header('Cache-Control: no-cache');
        readfile($absPath);
        exit;
    }
}
