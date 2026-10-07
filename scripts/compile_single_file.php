<?php
/**
 * FileCarton Single-File Compiler
 *
 * Merges all backend PHP files (except filecarton.config.php) into a single
 * distributable PHP file with embedded frontend assets.
 *
 * Usage: php scripts/compile_single_file.php
 */

$projectRoot = dirname(__DIR__);
$backendDir  = $projectRoot . '/apps/backend';
$fcDir       = $backendDir  . '/filecarton';
$distDir     = $projectRoot . '/apps/frontend/dist';
$outputDir   = $projectRoot . '/dist/singlefile';
$outputFile  = $outputDir   . '/filecarton.php';

// ---------------------------------------------------------------------------
// 1. Validate prerequisites
// ---------------------------------------------------------------------------

if (!is_dir($distDir)) {
    fwrite(STDERR, "Error: Frontend dist not found at $distDir\n");
    fwrite(STDERR, "Run 'pnpm build' first to build the frontend.\n");
    exit(1);
}

$manifestPath = $distDir . '/.vite/manifest.json';
if (!is_file($manifestPath)) {
    fwrite(STDERR, "Error: Vite manifest not found at $manifestPath\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 2. Collect PHP source files in dependency order
// ---------------------------------------------------------------------------

$libFiles = [
    $fcDir . '/lib/Polyfill.php',
    $fcDir . '/lib/Platform.php',
    $fcDir . '/lib/PathSecurity.php',
    $fcDir . '/lib/FileOps.php',
    $fcDir . '/lib/MimeType.php',
    $fcDir . '/lib/Csrf.php',
    $fcDir . '/lib/Response.php',
    $fcDir . '/lib/StartupCheck.php',
    $fcDir . '/lib/Settings.php',
    $fcDir . '/lib/Auth.php',
    $fcDir . '/lib/AuthPassword.php',
    $fcDir . '/lib/AuthImplicit.php',
    $fcDir . '/lib/AuthRedirect.php',
    $fcDir . '/lib/HttpClient.php',
    $fcDir . '/lib/Grant.php',
];

$apiFiles = glob($fcDir . '/api/*.php');
sort($apiFiles);

$handlerFiles = [
    $fcDir . '/handler/asset.php',
    $fcDir . '/handler/api.php',
    $fcDir . '/handler/auth.php',
    $fcDir . '/handler/page.php',
];

$routerFile = $backendDir . '/filecarton.php';

// ---------------------------------------------------------------------------
// 3. Strip per-file boilerplate and merge
// ---------------------------------------------------------------------------

function stripBoilerplate(string $code, bool $isRouter = false): string {
    // Remove opening <?php tag (only the first one)
    $code = preg_replace('/\A<\?php\s*/', '', $code, 1);

    // Remove namespace declaration (first occurrence only)
    $code = preg_replace('/^\s*namespace\s+FileCarton\s*;\s*$/m', '', $code, 1);

    // Remove all require / require_once statements
    $code = preg_replace('/^\s*require(?:_once)?\s+[^;]+;\s*$/m', '', $code);

    if ($isRouter) {
        // Remove define("FILECARTON_SCRIPT_DIR", ...)
        $code = preg_replace(
            '/^\s*define\s*\(\s*["\']FILECARTON_SCRIPT_DIR["\'][^;]+;\s*$/m',
            '', $code
        );
        // Remove standalone dispatch(); call (not the function definition)
        $code = preg_replace('/^\s*dispatch\(\)\s*;\s*$/m', '', $code);
    }

    return trim($code);
}

$mergedCode = '';

foreach ($libFiles as $f) {
    $mergedCode .= "\n" . stripBoilerplate(file_get_contents($f));
}
foreach ($apiFiles as $f) {
    $mergedCode .= "\n" . stripBoilerplate(file_get_contents($f));
}
foreach ($handlerFiles as $f) {
    $mergedCode .= "\n" . stripBoilerplate(file_get_contents($f));
}
$mergedCode .= "\n" . stripBoilerplate(file_get_contents($routerFile), true);

// ---------------------------------------------------------------------------
// 4. Read frontend metadata and collect asset files
// ---------------------------------------------------------------------------


function var_export_square_brackets($expression, bool $return=false) {
    $export = var_export($expression, TRUE);
    $patterns = [
        "/array \(/" => '[',
        "/^([ ]*)\)(,?)$/m" => '$1]$2',
        "/=>[ ]?\n[ ]+\[/" => '=> [',
        "/([ ]*)(\'[^\']+\') => ([\[\'])/" => '$1$2 => $3',
    ];
    $export = preg_replace(array_keys($patterns), array_values($patterns), $export);
    if ((bool)$return) return $export; else echo $export;
}

$manifest       = json_decode(file_get_contents($manifestPath), true);
$manifestExport = var_export_square_brackets($manifest, true);

$cdnPath   = $distDir . '/cdn.json';
$cdn       = is_file($cdnPath) ? json_decode(file_get_contents($cdnPath), true) : null;
$cdnExport = var_export_square_brackets($cdn, true);

// Collect servable asset files (exclude metadata files already embedded as constants)
function collectAssets(string $baseDir, string $prefix = ''): array {
    $result  = [];
    $entries = scandir($baseDir);
    if ($entries === false) return $result;
    sort($entries);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $fullPath = $baseDir . '/' . $entry;
        $relPath  = $prefix === '' ? $entry : $prefix . '/' . $entry;
        if (is_dir($fullPath)) {
            $result += collectAssets($fullPath, $relPath);
        } elseif (is_file($fullPath)) {
            $result[$relPath] = $fullPath;
        }
    }
    return $result;
}

$excludeMetadata = ['.vite', 'cdn.json'];
$allDistFiles    = collectAssets($distDir);
$assetFiles      = array_diff_key(
    $allDistFiles,
    array_flip($excludeMetadata)
);
// Also remove anything under .vite/
foreach ($assetFiles as $relPath => $_) {
    if (str_starts_with($relPath, '.vite/')) {
        unset($assetFiles[$relPath]);
    }
}

$assetData    = '';
$assetOffsets = [];
$offset       = 0;
$rawTotal     = 0;
foreach ($assetFiles as $relPath => $absPath) {
    $raw     = file_get_contents($absPath);
    $rawTotal += strlen($raw);
    $content = gzencode($raw, 9);
    $size    = strlen($content);
    $assetOffsets[$relPath] = [$offset, $size];
    $assetData .= $content;
    $offset    += $size;
}

$offsetsExport = var_export($assetOffsets, true);

// ---------------------------------------------------------------------------
// 5. Assemble the complete PHP source
// ---------------------------------------------------------------------------

$phpSource = "<?php\nnamespace FileCarton;\n"
    . "define('FILECARTON_SINGLE_FILE',true);\n"
    . "require_once __DIR__.'/filecarton.config.php';\n"
    . "define('FILECARTON_MANIFEST',{$manifestExport});\n"
    . "define('FILECARTON_CDN',{$cdnExport});\n"
    . $mergedCode . "\n"
    . "define('FILECARTON_ASSET_OFFSETS',{$offsetsExport});\n"
    . "dispatch();\n"
    . "__halt_compiler();";

// ---------------------------------------------------------------------------
// 6. Minify
// ---------------------------------------------------------------------------

// Adapted from php.net user comment by gelamu@gmail.com
function compressPhpSrc(string $src): string {
    // Tokens whose surrounding whitespace can be dropped
    $IW = [
        T_CONCAT_EQUAL, T_DOUBLE_ARROW, T_BOOLEAN_AND, T_BOOLEAN_OR,
        T_IS_EQUAL, T_IS_NOT_EQUAL, T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL,
        T_INC, T_DEC, T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL,
        T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_DOUBLE_COLON, T_OBJECT_OPERATOR,
        T_DOLLAR_OPEN_CURLY_BRACES, T_AND_EQUAL, T_MOD_EQUAL,
        T_XOR_EQUAL, T_OR_EQUAL, T_SL, T_SR, T_SL_EQUAL, T_SR_EQUAL,
        T_COALESCE,             // ??
        T_SPACESHIP,            // <=>
        T_COALESCE_EQUAL,       // ??=
        T_POW,                  // **
        T_POW_EQUAL,            // **=
    ];
    if (defined('T_NULLSAFE_OBJECT_OPERATOR')) {
        $IW[] = T_NULLSAFE_OBJECT_OPERATOR; // ?->
    }

    $tokens = token_get_all($src);
    $new = '';
    $c   = count($tokens);
    $iw  = false; // previous token already ignored trailing whitespace
    $ih  = false; // inside HEREDOC
    $ls  = '';    // last sign (for dedup of ; and :)
    $ot  = null;  // current open-tag type

    for ($i = 0; $i < $c; $i++) {
        $token = $tokens[$i];

        if (is_array($token)) {
            [$tn, $ts] = $token;

            if ($tn === T_INLINE_HTML) {
                // Pretty-printed HTML keeps one newline between tags; that is
                // a single \n, so \n{2,} would not touch it. Collapse first.
                // Leave trailing spaces on a line: they can be significant
                // before a short-echo (e.g. "window.__FILECARTON__ = " + echo).
                $ts = preg_replace('/>\s+</', '><', $ts);
                $ts = preg_replace('/^[ \t]+/m', '', $ts);
                $ts = preg_replace('/\n+/', "\n", $ts);
                $ts = trim($ts, "\n");
                $new .= $ts;
                $iw = false;
            } elseif ($tn === T_OPEN_TAG) {
                $ts = rtrim($ts) . ' ';
                $new .= $ts;
                $ot = T_OPEN_TAG;
                $iw = true;
            } elseif ($tn === T_OPEN_TAG_WITH_ECHO) {
                $new .= $ts;
                $ot = T_OPEN_TAG_WITH_ECHO;
                $iw = true;
            } elseif ($tn === T_CLOSE_TAG) {
                // PHP attaches the following newline to the close tag token.
                // Drop it so mixed PHP/HTML does not re-insert a line break
                // between a close tag and the next HTML fragment.
                $ts = rtrim($ts);
                if ($ot === T_OPEN_TAG_WITH_ECHO) {
                    $new = rtrim($new, '; ');
                } else {
                    $ts = ' ' . $ts;
                }
                $new .= $ts;
                $ot = null;
                $iw = false;
            } elseif (in_array($tn, $IW, true)) {
                $new .= $ts;
                $iw = true;
            } elseif ($tn === T_CONSTANT_ENCAPSED_STRING
                   || $tn === T_ENCAPSED_AND_WHITESPACE) {
                if ($ts[0] === '"') {
                    $ts = addcslashes($ts, "\n\t\r");
                }
                $new .= $ts;
                $iw = true;
            } elseif ($tn === T_WHITESPACE) {
                $nt = $tokens[$i + 1] ?? null;
                if (!$iw
                    && (!is_string($nt) || $nt === '$')
                    && !in_array($nt[0] ?? null, $IW, true)
                ) {
                    $new .= ' ';
                }
                $iw = false;
            } elseif ($tn === T_START_HEREDOC) {
                $new .= "<<<S\n";
                $iw = false;
                $ih = true;
            } elseif ($tn === T_END_HEREDOC) {
                $new .= 'S;';
                $iw = true;
                $ih = false;
                for ($j = $i + 1; $j < $c; $j++) {
                    if (is_string($tokens[$j]) && $tokens[$j] === ';') {
                        $i = $j;
                        break;
                    } elseif (is_array($tokens[$j]) && $tokens[$j][0] === T_CLOSE_TAG) {
                        break;
                    }
                }
            } elseif ($tn === T_COMMENT || $tn === T_DOC_COMMENT) {
                $iw = true;
            } else {
                $new .= $ts;
                $iw = false;
            }
            $ls = '';
        } else {
            // String token (single char: ; { } ( ) etc.)
            if (($token !== ';' && $token !== ':') || $ls !== $token) {
                $new .= $token;
                $ls = $token;
            }
            $iw = true;
        }
    }
    return $new;
}

$minified = compressPhpSrc($phpSource);

// ---------------------------------------------------------------------------
// 7. Write output
// ---------------------------------------------------------------------------

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

file_put_contents($outputFile, $minified . $assetData);

// ---------------------------------------------------------------------------
// 8. Report
// ---------------------------------------------------------------------------

$phpSize   = strlen($minified);
$assetSize = strlen($assetData);
$totalSize = $phpSize + $assetSize;
$assetCount = count($assetOffsets);
$ratio = $rawTotal > 0 ? round(100 - $assetSize / $rawTotal * 100, 1) : 0;

echo "Single-file build complete: $outputFile\n";
echo "  PHP code:  " . number_format($phpSize)  . " bytes\n";
echo "  Assets:    " . number_format($assetSize) . " bytes gzipped from "
     . number_format($rawTotal) . " bytes ($assetCount files, {$ratio}% smaller)\n";
echo "  Total:     " . number_format($totalSize) . " bytes\n";
