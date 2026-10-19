<?php

namespace FileCarton;

class HttpClient {
    const MAX_BODY = 1048576;

    /**
     * @param array $headers name => value
     * @return array{status: int, body: string}
     */
    public static function get($url, array $headers = [], $timeout = 15): array {
        return self::request('GET', $url, null, $headers, $timeout);
    }

    /**
     * @param array $headers name => value
     * @return array{status: int, body: string}
     */
    public static function post($url, $body, array $headers = [], $timeout = 15): array {
        return self::request('POST', $url, $body, $headers, $timeout);
    }

    /**
     * Assert that an OAuth response is 2xx. Logs the status only, then throws.
     *
     * @param array{status: int, body: string} $resp
     * @param string $context Description for log messages (e.g. "GitHub user lookup")
     */
    public static function assertSuccess(array $resp, string $context = 'HTTP request'): void {
        if ($resp['status'] >= 200 && $resp['status'] < 300) return;
        error_log('FC(http): ' . $context . ': ' . $resp['status']);
        throw new AuthException('exchange', 401, $context . ' HTTP ' . $resp['status']);
    }

    private static function request($method, $url, $body, array $headers, $timeout): array {
        if (!is_string($url) || !preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Invalid URL scheme', 502);
        }
        $headers = array_merge(['User-Agent' => 'FileCarton/0.0.999 (https://github.com/zbx1425/filecarton)'], $headers);
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
            // NOTE: In certain network environments, the timeout might actually reach ~10s.
            CURLOPT_TIMEOUT        => (int)$timeout,
            CURLOPT_CONNECTTIMEOUT => (int)$timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => $headerLines
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        if (FILECARTON_HTTP_CLIENT_PROXY !== '') {
            $opts[CURLOPT_PROXY] = FILECARTON_HTTP_CLIENT_PROXY;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        if ($raw === false) {
            error_log('FC(http): ' . curl_error($ch));
            curl_close($ch);
            throw new \RuntimeException('HTTP request failed', 502);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (strlen($raw) > self::MAX_BODY) {
            $raw = substr($raw, 0, self::MAX_BODY);
        }
        return ['status' => $status, 'body' => $raw];
    }
}
