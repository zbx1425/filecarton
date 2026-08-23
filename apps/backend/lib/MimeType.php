<?php

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
        'zip'        => 'application/zip',
        'tar'        => 'application/x-tar',
        'gz'         => 'application/gzip',
        'woff'       => 'font/woff',
        'woff2'      => 'font/woff2',
        'ttf'        => 'font/ttf',
        'otf'        => 'font/otf',
        'pdf'        => 'application/pdf',
    ];

    private const EDITABLE_EXTENSIONS = [
        'json', 'txt', 'cfg', 'xml', 'mcmeta', 'js', 'mjs', 'css', 'ini',
        'properties', 'lang', 'md', 'html', 'htm', 'csv', 'yml', 'yaml',
        'toml', 'svg', 'htaccess', 'gitignore', 'env',
    ];

    private const EDITABLE_MIMES = [
        'application/json',
        'application/xml',
        'application/javascript',
    ];

    private const MONACO_LANGUAGE_MAP = [
        'json'       => 'json',
        'mcmeta'     => 'json',
        'xml'        => 'xml',
        'svg'        => 'xml',
        'js'         => 'javascript',
        'mjs'        => 'javascript',
        'css'        => 'css',
        'html'       => 'html',
        'htm'        => 'html',
        'md'         => 'markdown',
        'yml'        => 'yaml',
        'yaml'       => 'yaml',
        'ini'        => 'ini',
        'cfg'        => 'ini',
        'properties' => 'ini',
    ];

    public static function detect(string $absPath): string {
        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        return self::fromExtension($ext);
    }

    public static function fromExtension(string $ext): string {
        $ext = strtolower($ext);
        return self::EXTENSION_MAP[$ext] ?? 'application/octet-stream';
    }

    public static function isEditable(string $path): bool {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, self::EDITABLE_EXTENSIONS, true)) return true;

        $mime = self::fromExtension($ext);
        if (str_starts_with($mime, 'text/')) return true;
        if (in_array($mime, self::EDITABLE_MIMES, true)) return true;

        return false;
    }

    public static function monacoLanguage(string $ext): string {
        $ext = strtolower($ext);
        return self::MONACO_LANGUAGE_MAP[$ext] ?? 'plaintext';
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
