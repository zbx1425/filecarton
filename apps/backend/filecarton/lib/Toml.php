<?php

namespace FileCarton;

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
                $isArray = ($this->pos + 1 < $this->len && $this->src[$this->pos + 1] === '[');
                $this->parseTableHeader($root, $metaRoot, $isArray);
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

    private function parseTableHeader(array &$root, array &$metaRoot, bool $isArray): void {
        $headerLine = $this->line;
        $step = $isArray ? 2 : 1;
        $close = $isArray ? ']]' : ']';
        $this->pos += $step;
        $keys = $this->parseKeyPath();
        $this->skipSpaces();
        if (substr($this->src, $this->pos, $step) !== $close) {
            $this->error("Expected '{$close}' to close table header");
        }
        $this->pos += $step;
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
        if ($isArray) {
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
        } else {
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
            $this->error('Expected key');
        }
        $ch = $this->src[$this->pos];
        if ($ch === '"' || $ch === "'") {
            if (substr($this->src, $this->pos, 3) === str_repeat($ch, 3)) {
                $this->error('Multiline key not allowed');
            }
            return $this->parseString($ch, false);
        }
        if (preg_match('/\G[A-Za-z0-9_-]+/', $this->src, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            return $m[0];
        }
        $this->error('Invalid key');
    }

    /**
     * Parse any TOML value and return [$value, $metaNode].
     */
    private function parseValue(): array {
        if ($this->pos >= $this->len) {
            $this->error('Expected value');
        }

        $ch = $this->src[$this->pos];

        if ($ch === '"' || $ch === "'") {
            $ml = substr($this->src, $this->pos, 3) === str_repeat($ch, 3);
            return [$this->parseString($ch, $ml), ['type' => 'value']];
        }

        if ($ch === '[') {
            return $this->parseCollection(']');
        }

        if ($ch === '{') {
            return $this->parseCollection('}');
        }

        if (preg_match('/\G(true|false)(?=[\s,#\]}]|$)/', $this->src, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            return [$m[1] === 'true', ['type' => 'value']];
        }

        return [$this->parseNumber(), ['type' => 'value']];
    }

    private function parseString(string $quote, bool $multiline): string {
        $delim = $multiline ? str_repeat($quote, 3) : $quote;
        $basic = ($quote === '"');
        $this->pos += strlen($delim);

        if ($multiline && $this->pos < $this->len && $this->src[$this->pos] === "\n") {
            $this->pos += 1;
            $this->line += 1;
        }

        $out = '';
        while ($this->pos < $this->len) {
            if ($multiline) {
                if (substr($this->src, $this->pos, 3) === $delim) {
                    $quotes = 0;
                    while ($this->pos < $this->len && $this->src[$this->pos] === $quote) {
                        $quotes++;
                        $this->pos++;
                    }
                    if ($quotes > 5) {
                        $this->error('Invalid quote sequence');
                    }
                    return $out . str_repeat($quote, $quotes - 3);
                }
            } elseif ($this->src[$this->pos] === $quote) {
                $this->pos += 1;
                return $out;
            } elseif ($this->src[$this->pos] === "\n") {
                $this->error('Unterminated string');
            }

            $ch = $this->src[$this->pos];
            if ($basic && $ch === '\\') {
                $this->pos += 1;
                if ($multiline && preg_match('/\G[ \t]*\n([ \t\n]*)/', $this->src, $m, 0, $this->pos)) {
                    $this->pos += strlen($m[0]);
                    $this->line += 1 + substr_count($m[1], "\n");
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

            $this->checkControlChar($ch);
            $out .= $ch;
            $this->pos += 1;
        }

        $this->error('Unterminated string');
    }

    private function parseEscapeSequence(): string {
        if ($this->pos >= $this->len) {
            $this->error('Unterminated escape');
        }
        $ch = $this->src[$this->pos++];
        $map = ['b' => "\x08", 't' => "\t", 'n' => "\n", 'f' => "\f", 'r' => "\r", '"' => '"', '\\' => '\\'];
        if (isset($map[$ch])) {
            return $map[$ch];
        }
        if ($ch === 'u' || $ch === 'U') {
            $n = $ch === 'u' ? 4 : 8;
            if (!preg_match("/\\G[0-9a-fA-F]{{$n}}/", $this->src, $m, 0, $this->pos)) {
                $this->error('Invalid unicode escape');
            }
            $this->pos += $n;
            return $this->codepointToUtf8((int)hexdec($m[0]));
        }
        $this->error("Invalid escape '\\{$ch}'");
    }

    private function codepointToUtf8(int $cp): string {
        if (($cp >= 0xD800 && $cp <= 0xDFFF) || $cp < 0 || $cp > 0x10FFFF) {
            $this->error('Invalid unicode scalar');
        }
        if ($cp <= 0x7F) return chr($cp);
        if ($cp <= 0x7FF) return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        if ($cp <= 0xFFFF) {
            return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
        }
        return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F))
            . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }

    private function parseCollection(string $close): array {
        $this->pos += 1;
        $out = [];
        $isTable = ($close === '}');
        $meta = $isTable ? self::newTableMeta(true, false, false) : ['type' => 'value'];

        while (true) {
            $this->skipWhitespaceAndComments(true);
            if ($this->pos < $this->len && $this->src[$this->pos] === $close) {
                $this->pos += 1;
                if ($isTable) $meta['frozen'] = true;
                return [$out, $meta];
            }

            if ($isTable) {
                $this->parseKeyValueInto($out, $meta);
            } else {
                $out[] = $this->parseValue()[0];
            }

            $this->skipWhitespaceAndComments(true);
            if ($this->pos < $this->len && $this->src[$this->pos] === ',') {
                $this->pos += 1;
                continue;
            }
            if ($this->pos < $this->len && $this->src[$this->pos] === $close) {
                $this->pos += 1;
                if ($isTable) $meta['frozen'] = true;
                return [$out, $meta];
            }
            $this->error("Expected ',' or '{$close}'");
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
                $this->error('Invalid float literal');
            }
            return (float)str_replace('_', '', $raw);
        }

        // Decimal integer
        if (preg_match('/^[+-]?(?:0|[1-9](?:_?[0-9])*)$/', $raw)) {
            return (int)str_replace('_', '', $raw);
        }

        $this->error('Invalid value');
    }

    private function checkControlChar(string $ch): void {
        $ord = ord($ch);
        if (($ord < 0x20 && $ch !== "\t") || $ord === 0x7F) {
            $this->error('Invalid control character');
        }
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
                $this->checkControlChar($this->src[$this->pos]);
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
