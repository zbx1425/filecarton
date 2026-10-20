<?php

namespace FileCarton\Tests\Unit;

use FileCarton\Toml;
use PHPUnit\Framework\TestCase;

final class TomlTest extends TestCase
{
    public function testEmptyAndCommentsOnly(): void
    {
        self::assertSame([], Toml::parse(''));
        self::assertSame([], Toml::parse("#<?php exit;?>\n# Just comments\n\n"));
        self::assertSame([], Toml::parse("\xEF\xBB\xBF# UTF-8 BOM + comment\n"));
    }

    public function testBasicKeyValueTypes(): void
    {
        $toml = <<<'TOML'
str_basic = "hello\nworld\t\"quoted\"\\slash"
str_unicode = "\u0041\U0001F600"
str_literal = 'C:\Users\admin\path\to\file'
bool_true = true
bool_false = false
int_dec = 42
int_neg = -17
int_plus = +99
int_zero = 0
int_underscore = 1_000_000
int_hex = 0xDEADBEEF
int_oct = 0o755
int_bin = 0b11010110
float_dec = 3.1415
float_neg = -0.01
float_exp = 5e+22
float_exp2 = 6.626e-34
float_underscore = 224_617.445_991_228
TOML;

        $res = Toml::parse($toml);
        self::assertSame("hello\nworld\t\"quoted\"\\slash", $res['str_basic']);
        self::assertSame("A\u{1F600}", $res['str_unicode']);
        self::assertSame('C:\Users\admin\path\to\file', $res['str_literal']);
        self::assertTrue($res['bool_true']);
        self::assertFalse($res['bool_false']);
        self::assertSame(42, $res['int_dec']);
        self::assertSame(-17, $res['int_neg']);
        self::assertSame(99, $res['int_plus']);
        self::assertSame(0, $res['int_zero']);
        self::assertSame(1000000, $res['int_underscore']);
        self::assertSame(0xDEADBEEF, $res['int_hex']);
        self::assertSame(0755, $res['int_oct']);
        self::assertSame(0b11010110, $res['int_bin']);
        self::assertEqualsWithDelta(3.1415, $res['float_dec'], 1e-9);
        self::assertEqualsWithDelta(-0.01, $res['float_neg'], 1e-9);
        self::assertEqualsWithDelta(5e22, $res['float_exp'], 1e10);
        self::assertEqualsWithDelta(6.626e-34, $res['float_exp2'], 1e-45);
        self::assertEqualsWithDelta(224617.445991228, $res['float_underscore'], 1e-9);
    }

    public function testMultilineStrings(): void
    {
        $toml = <<<'TOML'
ml_basic = """
Roses are red
Violets are blue"""

ml_trimmed = """
The quick brown \

  fox jumps over \
    the lazy dog."""

ml_literal = '''
The first newline is
trimmed in raw strings.
   All other whitespace
   is preserved.
'''
TOML;

        $res = Toml::parse($toml);
        self::assertSame("Roses are red\nViolets are blue", $res['ml_basic']);
        self::assertSame('The quick brown fox jumps over the lazy dog.', $res['ml_trimmed']);
        self::assertSame(
            "The first newline is\ntrimmed in raw strings.\n   All other whitespace\n   is preserved.\n",
            $res['ml_literal']
        );
    }

    public function testTablesAndDottedKeys(): void
    {
        $toml = <<<'TOML'
top = "val"
dotted.a.b = 1
"quoted.key".sub = 2

[section]
name = "sec"
nested.flag = true

[section.sub]
count = 10
TOML;

        $res = Toml::parse($toml);
        self::assertSame('val', $res['top']);
        self::assertSame(1, $res['dotted']['a']['b']);
        self::assertSame(2, $res['quoted.key']['sub']);
        self::assertSame('sec', $res['section']['name']);
        self::assertTrue($res['section']['nested']['flag']);
        self::assertSame(10, $res['section']['sub']['count']);
    }

    public function testArraysAndNestedInlineTables(): void
    {
        $toml = <<<'TOML'
[auth]
auth_providers = [
    { class = 'FileCarton\StaticPasswordAuth' },
    { class = 'FileCarton\GitHubOAuth', client_id = "gh_id", client_secret = "gh_sec" },
    { class = 'FileCarton\LinkAuth', id = "portal", extra = { nested = [1, 2, 3] } },
]
static_user_list = [
    # Comment inside array
    { id = "admin", password_hash = "$2y$10$hash", root_path = "/data/admin", readonly = false },
    { id = "guest", password_hash = "", root_path = "/data/public", readonly = true },
]
nested_arrays = [
    [1, 2],
    [3, 4],
]
TOML;

        $res = Toml::parse($toml);
        self::assertCount(3, $res['auth']['auth_providers']);
        self::assertSame('FileCarton\StaticPasswordAuth', $res['auth']['auth_providers'][0]['class']);
        self::assertSame('gh_id', $res['auth']['auth_providers'][1]['client_id']);
        self::assertSame([1, 2, 3], $res['auth']['auth_providers'][2]['extra']['nested']);
        self::assertCount(2, $res['auth']['static_user_list']);
        self::assertSame('admin', $res['auth']['static_user_list'][0]['id']);
        self::assertFalse($res['auth']['static_user_list'][0]['readonly']);
        self::assertTrue($res['auth']['static_user_list'][1]['readonly']);
        self::assertSame([[1, 2], [3, 4]], $res['auth']['nested_arrays']);
    }

    public function testArrayOfTables(): void
    {
        $toml = <<<'TOML'
[[products]]
name = "Hammer"
sku = 738594937

[[products]]

[[products]]
name = "Nail"
sku = 284758393
color = "gray"
TOML;

        $res = Toml::parse($toml);
        self::assertCount(3, $res['products']);
        self::assertSame('Hammer', $res['products'][0]['name']);
        self::assertSame([], $res['products'][1]);
        self::assertSame('Nail', $res['products'][2]['name']);
        self::assertSame('gray', $res['products'][2]['color']);
    }

    public function testDuplicateKeyThrowsWithLineNumber(): void
    {
        $toml = <<<'TOML'
[repo]
readonly = false
readonly = true
TOML;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/line 3/i');
        Toml::parse($toml);
    }

    public function testDuplicateTableThrows(): void
    {
        $toml = <<<'TOML'
[repo]
readonly = false

[repo]
branding = "x"
TOML;

        $this->expectException(\RuntimeException::class);
        Toml::parse($toml);
    }

    public function testUnterminatedStringThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        Toml::parse("key = \"unterminated\n");
    }

    public function testInvalidSyntaxThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        Toml::parse("invalid_line_without_equals");
    }
}
