<?php

namespace FileCarton;

class MimeType {

    private const EXTENSION_MAP = [
        'json'       => 'application/json',
        'mcmeta'     => 'application/json',
        'js'         => 'application/javascript',
        'mjs'        => 'application/javascript',
        'css'        => 'text/css',
        'html'       => 'text/html',
        'htm'        => 'text/html',
        'xml'        => 'application/xml',
        'svg'        => 'image/svg+xml',
        'txt'        => 'text/plain',
        'md'         => 'text/markdown',
        'csv'        => 'text/csv',
        'ini'        => 'text/plain',
        'cfg'        => 'text/plain',
        'properties' => 'text/plain',
        'lang'       => 'text/plain',
        'yml'        => 'text/yaml',
        'yaml'       => 'text/yaml',
        'toml'       => 'text/plain',
        'env'        => 'text/plain',
        'htaccess'   => 'text/plain',
        'gitignore'  => 'text/plain',
        'png'        => 'image/png',
        'jpg'        => 'image/jpeg',
        'jpeg'       => 'image/jpeg',
        'gif'        => 'image/gif',
        'webp'       => 'image/webp',
        'avif'       => 'image/avif',
        'bmp'        => 'image/bmp',
        'ico'        => 'image/x-icon',
        'ogg'        => 'audio/ogg',
        'mp3'        => 'audio/mpeg',
        'wav'        => 'audio/wav',
        'flac'       => 'audio/flac',
        'aac'        => 'audio/aac',
        'm4a'        => 'audio/mp4',
        'mp4'        => 'video/mp4',
        'm4v'        => 'video/mp4',
        'webm'       => 'video/webm',
        'ogv'        => 'video/ogg',
        'mov'        => 'video/quicktime',
        'mkv'        => 'video/x-matroska',
        'avi'        => 'video/x-msvideo',
        'flv'        => 'video/x-flv',
        'zip'        => 'application/zip',
        'tar'        => 'application/x-tar',
        'gz'         => 'application/gzip',
        'woff'       => 'font/woff',
        'woff2'      => 'font/woff2',
        'ttf'        => 'font/ttf',
        'otf'        => 'font/otf',
        'pdf'        => 'application/pdf',
    ];

    public static function detect(string $absPath): string {
        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        return self::fromExtension($ext);
    }

    public static function fromExtension(string $ext): string {
        $ext = strtolower($ext);
        return self::EXTENSION_MAP[$ext] ?? 'application/octet-stream';
    }

    /**
     * Content-Type to use when serving static assets (Vite build output).
     */
    public static function contentTypeForServing(string $ext): string {
        $ext = strtolower($ext);
        return self::EXTENSION_MAP[$ext] ?? 'application/octet-stream';
    }

    /**
     * Whether a MIME type is safe for inline serving (no script execution risk).
     * Unsafe types (HTML, SVG, XML) are either sandboxed or force-downloaded.
     */
    public static function isSafeForInline(string $mime): bool {
        static $unsafeMimes = [
            'text/html', 'application/xhtml+xml',
            'image/svg+xml',
            'application/xml',
        ];
        return !in_array($mime, $unsafeMimes, true) && $mime !== 'application/octet-stream';
    }
}
