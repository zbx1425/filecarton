<?php

namespace FileCarton;

/**
 * API: remote_download — Fetch a file from a remote HTTP/HTTPS URL into a directory.
 * POST ?fcapi=remote_download
 * Body: { path: string, url: string, name?: string, overwrite?: bool }
 */

function api_remote_download(PathSecurity $pathSec, FileOps $fileOps): void {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['path'], $input['url']) || !is_string($input['path']) || !is_string($input['url'])) {
        Response::error('missing_fields', 400, ['fields' => 'path, url']);
    }

    $url = trim($input['url']);
    if (
        $url === ''
        || !preg_match('#^https?://#i', $url)
        || preg_match('/[\x00-\x1F\x7F]/', $url)
        || filter_var($url, FILTER_VALIDATE_URL) === false
    ) {
        Response::error('remote_download.invalid_url', 400);
    }

    $dirAbs = $pathSec->resolveExistingDir($input['path']);
    $overwrite = !empty($input['overwrite']);
    $customName = isset($input['name']) && is_string($input['name']) ? trim($input['name']) : '';

    // Release the PHP session file lock before network I/O so concurrent requests aren't blocked.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $resolvedName = '';
    $finalPath = '';
    $preChecked = false;

    if ($customName !== '') {
        if (!$pathSec->isValidFileName($customName)) {
            Response::error('invalid_filename', 400);
        }
        $resolvedName = $customName;
        $finalPath = $dirAbs . '/' . $resolvedName;
        $pathSec->assertWithinRoot($finalPath);
        $pathSec->assertCanCreateAt($finalPath, false);

        if (is_dir($finalPath)) {
            Response::error('already_exists', 409);
        }
        if (file_exists($finalPath) || is_link($finalPath)) {
            $pathSec->assertNotIgnored($finalPath);
            if (!$overwrite) {
                Response::ok([
                    'completed' => false,
                    'name'      => $resolvedName,
                    'conflict'  => $resolvedName,
                ]);
            }
        }
        $preChecked = true;
    }

    $maxSize = (int)FILECARTON_UPLOAD_MAX_FILE_SIZE;
    // Deliberately not use a hidden file, to allow easier cleanup for aborted downloads
    $tempPath = $dirAbs . '/__fc_download_' . bin2hex(random_bytes(6)) . '.tmp';
    $tempFp = null;

    $contentDisposition = '';
    $contentLength = null;
    $bodyStarted = false;
    $downloadedBytes = 0;
    $abortReason = null;
    $abortStatus = 0;
    $abortException = null;
    $conflictName = null;

    $prepareTarget = function (string $effectiveUrl) use (
        $pathSec,
        $dirAbs,
        $overwrite,
        &$preChecked,
        &$resolvedName,
        &$finalPath,
        &$contentDisposition,
        &$conflictName,
        &$abortReason,
        &$abortException
    ): bool {
        if (!$preChecked) {
            $resolvedName = infer_remote_filename($contentDisposition, $effectiveUrl, $pathSec);
            if (!$pathSec->isValidFileName($resolvedName)) {
                $abortException = new ApiException('invalid_filename', 400);
                return false;
            }
            $finalPath = $dirAbs . '/' . $resolvedName;
            try {
                $pathSec->assertWithinRoot($finalPath);
                $pathSec->assertCanCreateAt($finalPath, false);
            } catch (ApiException $e) {
                $abortException = $e;
                return false;
            }
            if (is_dir($finalPath)) {
                $abortException = new ApiException('already_exists', 409);
                return false;
            }
            if (file_exists($finalPath) || is_link($finalPath)) {
                if ($pathSec->isIgnored($finalPath)) {
                    $abortException = new ApiException('not_found.path', 404);
                    return false;
                }
                if (!$overwrite) {
                    $conflictName = $resolvedName;
                    $abortReason = 'conflict';
                    return false;
                }
            }
            $preChecked = true;
        }
        return true;
    };

    $ch = curl_init($url);
    if ($ch === false) {
        Response::error('remote_download.failed', 502);
    }

    $opts = [
        CURLOPT_CUSTOMREQUEST   => 'GET',
        CURLOPT_RETURNTRANSFER  => false,
        CURLOPT_HEADER          => false,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 5,
        CURLOPT_CONNECTTIMEOUT  => 15,
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER      => [
            'User-Agent: FileCarton/0.0.999 (https://github.com/zbx1425/filecarton)',
        ],
        CURLOPT_HEADERFUNCTION  => function ($ch, string $headerLine) use (&$contentDisposition, &$contentLength): int {
            if (preg_match('#^HTTP/\d+(?:\.\d+)?\s+\d{3}#i', $headerLine)) {
                $contentDisposition = '';
                $contentLength = null;
            } elseif (stripos($headerLine, 'Content-Disposition:') === 0) {
                $contentDisposition = trim(substr($headerLine, strlen('Content-Disposition:')));
            } elseif (stripos($headerLine, 'Content-Length:') === 0) {
                $val = trim(substr($headerLine, strlen('Content-Length:')));
                if (ctype_digit($val)) {
                    $contentLength = (int)$val;
                }
            }
            return strlen($headerLine);
        },
        CURLOPT_WRITEFUNCTION   => function ($ch, string $data) use (
            $url,
            $maxSize,
            $tempPath,
            $prepareTarget,
            &$tempFp,
            &$bodyStarted,
            &$contentLength,
            &$downloadedBytes,
            &$abortReason,
            &$abortStatus
        ): int {
            if (!$bodyStarted) {
                $bodyStarted = true;
                $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($status < 200 || $status >= 300) {
                    $abortReason = 'http_error';
                    $abortStatus = $status;
                    return 0;
                }
                if ($maxSize > 0 && $contentLength !== null && $contentLength > $maxSize) {
                    $abortReason = 'too_large';
                    return 0;
                }
                $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                if (!$prepareTarget($effectiveUrl !== '' ? $effectiveUrl : $url)) {
                    return 0;
                }
                $tempFp = @fopen($tempPath, 'wb');
                if ($tempFp === false) {
                    $abortReason = 'io_error';
                    return 0;
                }
            }

            $chunkLen = strlen($data);
            if ($chunkLen === 0) {
                return 0;
            }

            $downloadedBytes += $chunkLen;
            if ($maxSize > 0 && $downloadedBytes > $maxSize) {
                $abortReason = 'too_large';
                return 0;
            }

            $written = fwrite($tempFp, $data);
            if ($written === false || $written !== $chunkLen) {
                $abortReason = 'io_error';
                return 0;
            }

            return $chunkLen;
        },
    ];

    if (FILECARTON_HTTP_CLIENT_PROXY !== '') {
        $opts[CURLOPT_PROXY] = FILECARTON_HTTP_CLIENT_PROXY;
    }

    curl_setopt_array($ch, $opts);

    try {
        $execOk = curl_exec($ch);
        $curlErrNo = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($tempFp !== null) {
            fflush($tempFp);
            fclose($tempFp);
            $tempFp = null;
        }

        if ($abortException !== null) {
            throw $abortException;
        }
        if ($abortReason === 'conflict' && $conflictName !== null) {
            Response::ok([
                'completed' => false,
                'name'      => $conflictName,
                'conflict'  => $conflictName,
            ]);
        }
        if ($abortReason === 'http_error') {
            Response::error('remote_download.http_error', 502, ['status' => $abortStatus]);
        }
        if ($abortReason === 'too_large') {
            Response::error('remote_download.too_large', 413, ['maxMB' => round($maxSize / 1024 / 1024)]);
        }
        if ($abortReason === 'io_error') {
            Response::error('server_error', 500);
        }

        if ($execOk === false || $curlErrNo !== 0) {
            Response::error('remote_download.failed', 502);
        }

        // Handle empty (0-byte) 2xx responses where CURLOPT_WRITEFUNCTION was not invoked
        if (!$bodyStarted) {
            if ($status < 200 || $status >= 300) {
                Response::error('remote_download.http_error', 502, ['status' => $status]);
            }
            if (!$prepareTarget($effectiveUrl !== '' ? $effectiveUrl : $url)) {
                if ($abortException !== null) {
                    throw $abortException;
                }
                if ($abortReason === 'conflict' && $conflictName !== null) {
                    Response::ok([
                        'completed' => false,
                        'name'      => $conflictName,
                        'conflict'  => $conflictName,
                    ]);
                }
                Response::error('server_error', 500);
            }
            if (@file_put_contents($tempPath, '') === false) {
                Response::error('server_error', 500);
            }
        }

        if ($overwrite && (file_exists($finalPath) || is_link($finalPath))) {
            if (is_dir($finalPath)) {
                Response::error('already_exists', 409);
            }
            $pathSec->assertNotIgnored($finalPath);
            @unlink($finalPath);
        }

        if (!@rename($tempPath, $finalPath)) {
            Response::error('server_error', 500);
        }
    } finally {
        if ($tempFp !== null) {
            @fclose($tempFp);
        }
        if (is_file($tempPath)) {
            @unlink($tempPath);
        }
    }

    clearstatcache(true, $finalPath);
    Response::ok([
        'completed' => true,
        'name'      => $resolvedName,
        'size'      => (int)@filesize($finalPath),
    ]);
}

/**
 * Infer a valid target filename from Content-Disposition header or effective URL.
 */
function infer_remote_filename(string $contentDisposition, string $effectiveUrl, PathSecurity $pathSec): string {
    if ($contentDisposition !== '') {
        $fromHeader = parse_content_disposition_filename($contentDisposition);
        if ($fromHeader !== '') {
            try {
                return $pathSec->sanitizeFileName($fromHeader);
            } catch (\Throwable $e) {
                // Fall through to URL inference
            }
        }
    }

    $pathPart = parse_url($effectiveUrl, PHP_URL_PATH);
    if (is_string($pathPart) && $pathPart !== '' && $pathPart !== '/') {
        $decoded = rawurldecode(basename(rtrim($pathPart, '/')));
        if ($decoded !== '' && $decoded !== '.' && $decoded !== '..') {
            try {
                return $pathSec->sanitizeFileName($decoded);
            } catch (\Throwable $e) {
                // Fall through to default
            }
        }
    }

    return 'download';
}

/**
 * Extract filename from an HTTP Content-Disposition header value.
 * Supports RFC 5987 filename*=UTF-8''... as well as quoted/unquoted filename=...
 */
function parse_content_disposition_filename(string $header): string {
    if (preg_match('/filename\*\s*=\s*([^;\r\n]+)/i', $header, $m)) {
        $val = trim($m[1], " \t\"'");
        if (preg_match("/^(?:[A-Za-z0-9_-]+)'[^']*'(.*)$/", $val, $parts)) {
            $decoded = rawurldecode($parts[1]);
            if ($decoded !== '') {
                return basename(str_replace('\\', '/', $decoded));
            }
        }
    }

    if (preg_match('/filename\s*=\s*"((?:\\\\.|[^"\\\\])*)"/i', $header, $m)) {
        $val = stripcslashes($m[1]);
        if ($val !== '') {
            return basename(str_replace('\\', '/', $val));
        }
    }

    if (preg_match('/filename\s*=\s*([^;\s"\r\n]+)/i', $header, $m)) {
        $val = rawurldecode(trim($m[1], " \t'"));
        if ($val !== '') {
            return basename(str_replace('\\', '/', $val));
        }
    }

    return '';
}
