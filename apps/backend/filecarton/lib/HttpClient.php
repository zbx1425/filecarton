<?php

namespace FileCarton;

class HttpClient {
    const MAX_BODY = 1048576;

    /**
     * @param array $headers name => value
     * @return array{status: int, body: string}
     */
    public static function get($url, array $headers = [], $timeout = 10): array {
        return self::request('GET', $url, null, $headers, $timeout);
    }

    /**
     * @param array $headers name => value
     * @return array{status: int, body: string}
     */
    public static function post($url, $body, array $headers = [], $timeout = 10): array {
        return self::request('POST', $url, $body, $headers, $timeout);
    }

    private static function request($method, $url, $body, array $headers, $timeout): array {
        if (!is_string($url) || !preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Invalid URL scheme', 502);
        }
        $headers = array_merge(['User-Agent' => 'FileCarton'], $headers);
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $k = str_replace(["\r", "\n"], '', (string)$k);
            $v = str_replace(["\r", "\n"], '', (string)$v);
            $headerLines[] = $k . ': ' . $v;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('HTTP request failed', 502);
        }
        $opts = [
            CURLOPT_CUSTOMREQUEST  => strtoupper((string)$method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_TIMEOUT        => (int)$timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headerLines,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) {
            error_log('FileCarton auth: HTTP transport failed');
            throw new \RuntimeException('HTTP request failed', 502);
        }
        if (strlen($raw) > self::MAX_BODY) {
            $raw = substr($raw, 0, self::MAX_BODY);
        }
        return ['status' => $status, 'body' => $raw];
    }
}
