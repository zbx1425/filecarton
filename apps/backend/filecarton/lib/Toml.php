<?php

namespace FileCarton;

/**
 * Lightweight, zero-dependency TOML parser for FileCarton configuration.
 *
 * Supports tables ([a.b]), arrays of tables ([[a.b]]), inline tables ({ k = v }),
 * arrays (including nested arrays and inline tables), basic/literal single- and
 * multi-line strings, booleans, integers (dec/hex/oct/bin), and floats.
 */
class Toml {
    /** @var string */
    private $src;
    /** @var int */
    private $len;
    /** @var int */
    private $pos = 0;
    /** @var int */
    private $line = 1;
    /** @var array */
    private $currentTable = [];
    /** @var array */
    private $currentMeta = [];

    /**
     * Parse a TOML string into an associative PHP array.
     *
     * @throws \RuntimeException If the input is not valid TOML.
     */
    public static function parse(string $input): array {
        if (preg_match('//u', $input) === false) {
            throw new \RuntimeException('TOML input is not valid UTF-8.');
        }
        // Strip UTF-8 BOM if present
        if ( substr($input, 0, 3) === "\xEF\xBB\xBF" ) {
            $input = substr($input, 3);
        }
        $input = str_replace(["\r\n", "\r"], "\n", $input);
        $parser = new self($input);
        return $parser->doParse();
    }

    /**
     * Parse a TOML file into an associative PHP array.
     *
     * @throws \RuntimeException If the file cannot be read or is not valid TOML.
     */
    public static function parseFile(string $filename): array {
        if (!is_file($filename) || !is_readable($filename)) {
            throw new \RuntimeException("Configuration file cannot be read: {$filename}");
        }
        $content = @file_get_contents($filename);
        if (!is_string($content)) {
            throw new \RuntimeException("Failed to read configuration file: {$filename}");
        }
        return self::parse($content);
    }

    private function __construct(string $src) {
        $this->src = $src;
        $this->len = strlen($src);
    }

    private function doParse(): array {
        $root = [];
        $metaRoot = self::newTableMeta(true);

        $this->currentTable = &$root;
        $this->currentMeta  = &$metaRoot;

        while ($this->pos < $this->len) {
            $this->skipWhitespaceAndComments(true);
            if ($this->pos >= $this->len) {
                break;
            }

            $ch = $this->src[$this->pos];
            if ($ch === '[') {
                if ($this->pos + 1 < $this->len && $this->src[$this->pos + 1] === '[') {
                    $this->parseArrayOfTablesHeader($root, $metaRoot);
                } else {
                    $this->parseTableHeader($root, $metaRoot);
                }
            } else {
                $this->parseKeyValueInto($this->currentTable, $this->currentMeta);
                $this->skipSpaces();
                $this->skipComment();
                $this->expectNewlineOrEof();
            }
        }

        return $root;
    }

    private static function newTableMeta(bool $explicit = false, bool $dotted = false, bool $frozen = false): array {
        return [
            'type'     => 'table',
            'explicit' => $explicit,
            'dotted'   => $dotted,
            'frozen'   => $frozen,
            'children' => [],
        ];
    }

    /**
     * Parse [table.header] and bind $this->currentTable / $this->currentMeta.
     */
    private function parseTableHeader(array &$root, array &$metaRoot): void {
        $headerLine = $this->line;
        $this->pos += 1; // skip '['
        $keys = $this->parseKeyPath();
        $this->skipSpaces();
        if ($this->pos >= $this->len || $this->src[$this->pos] !== ']') {
            $this->error("Expected ']' to close table header");
        }
        $this->pos += 1; // skip ']'
        $this->skipSpaces();
        $this->skipComment();
        $this->expectNewlineOrEof();

        $curr = &$root;
        $meta = &$metaRoot;
        $n = count($keys);

        for ($i = 0; $i < $n - 1; $i++) {
            $seg = $keys[$i];
            if (!isset($meta['children'][$seg])) {
                $curr[$seg] = [];
                $meta['children'][$seg] = self::newTableMeta(false);
            } else {
                $childMeta = &$meta['children'][$seg];
                if ($childMeta['type'] === 'value' || !empty($childMeta['frozen'])) {
                    $this->error("Cannot extend non-table key '{$seg}'", $headerLine);
                }
                if ($childMeta['type'] === 'array_table') {
                    $lastIdx = count($curr[$seg]) - 1;
                    $curr = &$curr[$seg][$lastIdx];
                    $meta = &$childMeta['items'][$lastIdx];
                    continue;
                }
            }
            $curr = &$curr[$seg];
            $meta = &$meta['children'][$seg];
        }

        $last = $keys[$n - 1];
        if (!isset($meta['children'][$last])) {
            $curr[$last] = [];
            $meta['children'][$last] = self::newTableMeta(true);
        } else {
            $targetMeta = &$meta['children'][$last];
            if ($targetMeta['type'] !== 'table' || $targetMeta['explicit'] || $targetMeta['dotted'] || $targetMeta['frozen']) {
                $this->error('Table [' . implode('.', $keys) . '] has already been defined', $headerLine);
            }
            $targetMeta['explicit'] = true;
        }

        $this->currentTable = &$curr[$last];
        $this->currentMeta  = &$meta['children'][$last];
    }

    /**
     * Parse [[array.of.tables]] and bind $this->currentTable / $this->currentMeta.
     */
    private function parseArrayOfTablesHeader(array &$root, array &$metaRoot): void {
        $headerLine = $this->line;
        $this->pos += 2; // skip '[['
        $keys = $this->parseKeyPath();
        $this->skipSpaces();
        if ($this->pos + 1 >= $this->len || $this->src[$this->pos] !== ']' || $this->src[$this->pos + 1] !== ']') {
            $this->error("Expected ']]' to close array of tables header");
        }
        $this->pos += 2; // skip ']]'
        $this->skipSpaces();
        $this->skipComment();
        $this->expectNewlineOrEof();

        $curr = &$root;
        $meta = &$metaRoot;
        $n = count($keys);

        for ($i = 0; $i < $n - 1; $i++) {
            $seg = $keys[$i];
            if (!isset($meta['children'][$seg])) {
                $curr[$seg] = [];
                $meta['children'][$seg] = self::newTableMeta(false);
            } else {
                $childMeta = &$meta['children'][$seg];
                if ($childMeta['type'] === 'value' || !empty($childMeta['frozen'])) {
                    $this->error("Cannot extend non-table key '{$seg}'", $headerLine);
                }
                if ($childMeta['type'] === 'array_table') {
                    $lastIdx = count($curr[$seg]) - 1;
                    $curr = &$curr[$seg][$lastIdx];
                    $meta = &$childMeta['items'][$lastIdx];
                    continue;
                }
            }
            $curr = &$curr[$seg];
            $meta = &$meta['children'][$seg];
        }

        $last = $keys[$n - 1];
        if (!isset($meta['children'][$last])) {
            $curr[$last] = [[]];
            $meta['children'][$last] = [
                'type'  => 'array_table',
                'items' => [self::newTableMeta(true)],
            ];
        } else {
            $targetMeta = &$meta['children'][$last];
            if ($targetMeta['type'] !== 'array_table') {
                $this->error('Cannot redefine [' . implode('.', $keys) . '] as array of tables', $headerLine);
            }
            $curr[$last][] = [];
            $targetMeta['items'][] = self::newTableMeta(true);
        }

        $lastIdx = count($curr[$last]) - 1;
        $this->currentTable = &$curr[$last][$lastIdx];
        $this->currentMeta  = &$meta['children'][$last]['items'][$lastIdx];
    }

    /**
     * Parse a key = value pair into the given table & meta.
     */
    private function parseKeyValueInto(array &$table, array &$meta): void {
        $keyLine = $this->line;
        $keys = $this->parseKeyPath();
        $this->skipSpaces();
        if ($this->pos >= $this->len || $this->src[$this->pos] !== '=') {
            $this->error("Expected '=' after key");
        }
        $this->pos += 1; // skip '='
        $this->skipSpaces();

        [$val, $valMeta] = $this->parseValue();

        $curr = &$table;
        $currMeta = &$meta;
        $n = count($keys);

        for ($i = 0; $i < $n - 1; $i++) {
            $seg = $keys[$i];
            if (!isset($currMeta['children'][$seg])) {
                $curr[$seg] = [];
                $currMeta['children'][$seg] = self::newTableMeta(false, true, false);
            } else {
                $cm = &$currMeta['children'][$seg];
                if ($cm['type'] !== 'table' || $cm['explicit'] || $cm['frozen']) {
                    $this->error("Cannot define dotted key through '{$seg}'", $keyLine);
                }
                $cm['dotted'] = true;
            }
            $curr = &$curr[$seg];
            $currMeta = &$currMeta['children'][$seg];
        }

        $last = $keys[$n - 1];
        if (isset($currMeta['children'][$last])) {
            $this->error("Duplicate key '{$last}'", $keyLine);
        }
        $curr[$last] = $val;
        $currMeta['children'][$last] = $valMeta;
    }

    /**
     * Parse a dotted or simple key path into an array of key segments.
     *
     * @return string[]
     */
    private function parseKeyPath(): array {
        $segments = [];
        $this->skipSpaces();
        $segments[] = $this->parseKeySegment();
        $this->skipSpaces();

        while ($this->pos < $this->len && $this->src[$this->pos] === '.') {
            $this->pos += 1;
            $this->skipSpaces();
            $segments[] = $this->parseKeySegment();
            $this->skipSpaces();
        }

        return $segments;
    }

    private function parseKeySegment(): string {
        if ($this->pos >= $this->len) {
            $this->error('Unexpected end of input while expecting a key');
        }
        $ch = $this->src[$this->pos];
        if ($ch === '"') {
            if ($this->pos + 2 < $this->len && $this->src[$this->pos + 1] === '"' && $this->src[$this->pos + 2] === '"') {
                $this->error('Multiline strings are not allowed as keys');
            }
            return $this->parseBasicString();
        }
        if ($ch === "'") {
            if ($this->pos + 2 < $this->len && $this->src[$this->pos + 1] === "'" && $this->src[$this->pos + 2] === "'") {
                $this->error('Multiline strings are not allowed as keys');
            }
            return $this->parseLiteralString();
        }
        if (preg_match('/\G[A-Za-z0-9_-]+/', $this->src, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            return $m[0];
        }
        $this->error('Invalid or empty key');
    }

    /**
     * Parse any TOML value and return [$value, $metaNode].
     */
    private function parseValue(): array {
        if ($this->pos >= $this->len) {
            $this->error('Unexpected end of input while expecting a value');
        }

        $ch = $this->src[$this->pos];

        if ($ch === '"') {
            if ($this->pos + 2 < $this->len && $this->src[$this->pos + 1] === '"' && $this->src[$this->pos + 2] === '"') {
                return [$this->parseMultilineBasicString(), ['type' => 'value']];
            }
            return [$this->parseBasicString(), ['type' => 'value']];
        }

        if ($ch === "'") {
            if ($this->pos + 2 < $this->len && $this->src[$this->pos + 1] === "'" && $this->src[$this->pos + 2] === "'") {
                return [$this->parseMultilineLiteralString(), ['type' => 'value']];
            }
            return [$this->parseLiteralString(), ['type' => 'value']];
        }

        if ($ch === '[') {
            return [$this->parseArray(), ['type' => 'value']];
        }

        if ($ch === '{') {
            return $this->parseInlineTable();
        }

        if (preg_match('/\G(true|false)(?=[\s,#\]}]|$)/', $this->src, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            return [$m[1] === 'true', ['type' => 'value']];
        }

        return [$this->parseNumber(), ['type' => 'value']];
    }

    private function parseBasicString(): string {
        $this->pos += 1; // skip opening '"'
        $out = '';

        while ($this->pos < $this->len) {
            $ch = $this->src[$this->pos];
            if ($ch === '"') {
                $this->pos += 1;
                return $out;
            }
            if ($ch === "\n") {
                $this->error('Unterminated basic string');
            }
            if ($ch === '\\') {
                $this->pos += 1;
                $out .= $this->parseEscapeSequence();
                continue;
            }
            $ord = ord($ch);
            if (($ord < 0x20 && $ch !== "\t") || $ord === 0x7F) {
                $this->error('Control characters are not allowed in basic strings');
            }
            $out .= $ch;
            $this->pos += 1;
        }

        $this->error('Unterminated basic string');
    }

    private function parseMultilineBasicString(): string {
        $this->pos += 3; // skip '"""'
        if ($this->pos < $this->len && $this->src[$this->pos] === "\n") {
            $this->pos += 1;
            $this->line += 1;
        }

        $out = '';
        while ($this->pos < $this->len) {
            if (
                $this->src[$this->pos] === '"'
                && $this->pos + 2 < $this->len
                && $this->src[$this->pos + 1] === '"'
                && $this->src[$this->pos + 2] === '"'
            ) {
                // Up to two extra quotes are allowed before the closing """
                $quotes = 0;
                while ($this->pos < $this->len && $this->src[$this->pos] === '"') {
                    $quotes++;
                    $this->pos++;
                }
                if ($quotes < 3 || $quotes > 5) {
                    $this->error('Invalid quotation mark sequence in multiline basic string');
                }
                $out .= str_repeat('"', $quotes - 3);
                return $out;
            }

            $ch = $this->src[$this->pos];
            if ($ch === '\\') {
                $this->pos += 1;
                // Line-ending backslash trims whitespace and newlines
                if (preg_match('/\G[ \t]*\n/', $this->src, $m, 0, $this->pos)) {
                    $this->pos += strlen($m[0]);
                    $this->line += 1;
                    while ($this->pos < $this->len) {
                        $c = $this->src[$this->pos];
                        if ($c === ' ' || $c === "\t") {
                            $this->pos += 1;
                        } elseif ($c === "\n") {
                            $this->pos += 1;
                            $this->line += 1;
                        } else {
                            break;
                        }
                    }
                    continue;
                }
                $out .= $this->parseEscapeSequence();
                continue;
            }

            if ($ch === "\n") {
                $this->line += 1;
                $out .= "\n";
                $this->pos += 1;
                continue;
            }

            $ord = ord($ch);
            if (($ord < 0x20 && $ch !== "\t") || $ord === 0x7F) {
                $this->error('Control characters are not allowed in multiline basic strings');
            }
            $out .= $ch;
            $this->pos += 1;
        }

        $this->error('Unterminated multiline basic string');
    }

    private function parseLiteralString(): string {
        $this->pos += 1; // skip "'"
        $out = '';

        while ($this->pos < $this->len) {
            $ch = $this->src[$this->pos];
            if ($ch === "'") {
                $this->pos += 1;
                return $out;
            }
            if ($ch === "\n") {
                $this->error('Unterminated literal string');
            }
            $ord = ord($ch);
            if (($ord < 0x20 && $ch !== "\t") || $ord === 0x7F) {
                $this->error('Control characters are not allowed in literal strings');
            }
            $out .= $ch;
            $this->pos += 1;
        }

        $this->error('Unterminated literal string');
    }

    private function parseMultilineLiteralString(): string {
        $this->pos += 3; // skip "'''"
        if ($this->pos < $this->len && $this->src[$this->pos] === "\n") {
            $this->pos += 1;
            $this->line += 1;
        }

        $out = '';
        while ($this->pos < $this->len) {
            if (
                $this->src[$this->pos] === "'"
                && $this->pos + 2 < $this->len
                && $this->src[$this->pos + 1] === "'"
                && $this->src[$this->pos + 2] === "'"
            ) {
                $quotes = 0;
                while ($this->pos < $this->len && $this->src[$this->pos] === "'") {
                    $quotes++;
                    $this->pos++;
                }
                if ($quotes < 3 || $quotes > 5) {
                    $this->error('Invalid apostrophe sequence in multiline literal string');
                }
                $out .= str_repeat("'", $quotes - 3);
                return $out;
            }

            $ch = $this->src[$this->pos];
            if ($ch === "\n") {
                $this->line += 1;
                $out .= "\n";
                $this->pos += 1;
                continue;
            }

            $ord = ord($ch);
            if (($ord < 0x20 && $ch !== "\t") || $ord === 0x7F) {
                $this->error('Control characters are not allowed in multiline literal strings');
            }
            $out .= $ch;
            $this->pos += 1;
        }

        $this->error('Unterminated multiline literal string');
    }

    private function parseEscapeSequence(): string {
        if ($this->pos >= $this->len) {
            $this->error('Unexpected end of string after escape character');
        }
        $ch = $this->src[$this->pos];
        $this->pos += 1;

        switch ($ch) {
            case 'b':  return "\x08";
            case 't':  return "\t";
            case 'n':  return "\n";
            case 'f':  return "\f";
            case 'r':  return "\r";
            case '"':  return '"';
            case '\\': return '\\';
            case 'u':
                if (!preg_match('/\G[0-9a-fA-F]{4}/', $this->src, $m, 0, $this->pos)) {
                    $this->error('Invalid \uXXXX escape sequence');
                }
                $this->pos += 4;
                return $this->codepointToUtf8((int)hexdec($m[0]));
            case 'U':
                if (!preg_match('/\G[0-9a-fA-F]{8}/', $this->src, $m, 0, $this->pos)) {
                    $this->error('Invalid \UXXXXXXXX escape sequence');
                }
                $this->pos += 8;
                return $this->codepointToUtf8((int)hexdec($m[0]));
            default:
                $this->error("Invalid escape sequence '\\{$ch}'");
        }
    }

    private function codepointToUtf8(int $cp): string {
        if (($cp >= 0xD800 && $cp <= 0xDFFF) || $cp < 0 || $cp > 0x10FFFF) {
            $this->error('Invalid Unicode scalar value in escape sequence');
        }
        if ($cp <= 0x7F) {
            return chr($cp);
        }
        if ($cp <= 0x7FF) {
            return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        }
        if ($cp <= 0xFFFF) {
            return chr(0xE0 | ($cp >> 12))
                . chr(0x80 | (($cp >> 6) & 0x3F))
                . chr(0x80 | ($cp & 0x3F));
        }
        return chr(0xF0 | ($cp >> 18))
            . chr(0x80 | (($cp >> 12) & 0x3F))
            . chr(0x80 | (($cp >> 6) & 0x3F))
            . chr(0x80 | ($cp & 0x3F));
    }

    private function parseArray(): array {
        $this->pos += 1; // skip '['
        $result = [];

        while (true) {
            $this->skipWhitespaceAndComments(true);
            if ($this->pos >= $this->len) {
                $this->error("Unterminated array (expected ']')");
            }
            if ($this->src[$this->pos] === ']') {
                $this->pos += 1;
                return $result;
            }

            [$val] = $this->parseValue();
            $result[] = $val;

            $this->skipWhitespaceAndComments(true);
            if ($this->pos >= $this->len) {
                $this->error("Unterminated array (expected ',' or ']')");
            }
            if ($this->src[$this->pos] === ',') {
                $this->pos += 1;
                continue;
            }
            if ($this->src[$this->pos] === ']') {
                $this->pos += 1;
                return $result;
            }
            $this->error("Expected ',' or ']' in array");
        }
    }

    /**
     * Parse { k = v, ... } and return [$table, $metaNode].
     */
    private function parseInlineTable(): array {
        $this->pos += 1; // skip '{'
        $table = [];
        $meta = self::newTableMeta(true, false, false);

        while (true) {
            $this->skipWhitespaceAndComments(true);
            if ($this->pos >= $this->len) {
                $this->error("Unterminated inline table (expected '}')");
            }
            if ($this->src[$this->pos] === '}') {
                $this->pos += 1;
                $meta['frozen'] = true;
                return [$table, $meta];
            }

            $this->parseKeyValueInto($table, $meta);

            $this->skipWhitespaceAndComments(true);
            if ($this->pos >= $this->len) {
                $this->error("Unterminated inline table (expected ',' or '}')");
            }
            if ($this->src[$this->pos] === ',') {
                $this->pos += 1;
                continue;
            }
            if ($this->src[$this->pos] === '}') {
                $this->pos += 1;
                $meta['frozen'] = true;
                return [$table, $meta];
            }
            $this->error("Expected ',' or '}' in inline table");
        }
    }

    /**
     * @return int|float
     */
    private function parseNumber() {
        if (!preg_match('/\G([^\s,#\]}]+)/', $this->src, $m, 0, $this->pos)) {
            $this->error('Expected a value');
        }
        $raw = $m[1];
        $this->pos += strlen($raw);

        // Special float values
        if ($raw === 'inf' || $raw === '+inf') {
            return INF;
        }
        if ($raw === '-inf') {
            return -INF;
        }
        if ($raw === 'nan' || $raw === '+nan' || $raw === '-nan') {
            return NAN;
        }

        // Hex / Octal / Binary integers
        if (preg_match('/^0x([0-9a-fA-F]+(?:_[0-9a-fA-F]+)*)$/', $raw, $hm)) {
            return (int)hexdec(str_replace('_', '', $hm[1]));
        }
        if (preg_match('/^0o([0-7]+(?:_[0-7]+)*)$/', $raw, $om)) {
            return (int)octdec(str_replace('_', '', $om[1]));
        }
        if (preg_match('/^0b([01]+(?:_[01]+)*)$/', $raw, $bm)) {
            return (int)bindec(str_replace('_', '', $bm[1]));
        }

        // Float (contains '.' or exponent)
        if (strpos($raw, '.') !== false || stripos($raw, 'e') !== false) {
            if (!preg_match('/^[+-]?(?:0|[1-9](?:_?[0-9])*)(?:\.[0-9](?:_?[0-9])*)?(?:[eE][+-]?[0-9](?:_?[0-9])*)?$/', $raw)) {
                $this->error("Invalid float literal '{$raw}'");
            }
            return (float)str_replace('_', '', $raw);
        }

        // Decimal integer
        if (preg_match('/^[+-]?(?:0|[1-9](?:_?[0-9])*)$/', $raw)) {
            return (int)str_replace('_', '', $raw);
        }

        $this->error("Invalid value '{$raw}'");
    }

    private function skipSpaces(): void {
        while ($this->pos < $this->len) {
            $ch = $this->src[$this->pos];
            if ($ch === ' ' || $ch === "\t") {
                $this->pos += 1;
            } else {
                break;
            }
        }
    }

    private function skipComment(): void {
        if ($this->pos < $this->len && $this->src[$this->pos] === '#') {
            $this->pos += 1;
            while ($this->pos < $this->len && $this->src[$this->pos] !== "\n") {
                $ch = $this->src[$this->pos];
                $ord = ord($ch);
                if (($ord < 0x20 && $ch !== "\t") || $ord === 0x7F) {
                    $this->error('Control characters are not allowed in comments');
                }
                $this->pos += 1;
            }
        }
    }

    private function skipWhitespaceAndComments(bool $allowNewlines): void {
        while ($this->pos < $this->len) {
            $ch = $this->src[$this->pos];
            if ($ch === ' ' || $ch === "\t") {
                $this->pos += 1;
            } elseif ($allowNewlines && $ch === "\n") {
                $this->pos += 1;
                $this->line += 1;
            } elseif ($ch === '#') {
                $this->skipComment();
            } else {
                break;
            }
        }
    }

    private function expectNewlineOrEof(): void {
        if ($this->pos >= $this->len) {
            return;
        }
        if ($this->src[$this->pos] === "\n") {
            $this->pos += 1;
            $this->line += 1;
            return;
        }
        $this->error('Expected newline or end of file');
    }

    /**
     * @return never
     */
    private function error(string $message, ?int $line = null): void {
        $ln = $line ?? $this->line;
        throw new \RuntimeException("TOML syntax error at line {$ln}: {$message}.");
    }
}
