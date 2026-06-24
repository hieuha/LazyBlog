<?php

declare(strict_types=1);

/**
 * Graffiti plugin — Phase 1 scaffold tests.
 * Run: php tests/test-graffiti-boot.php
 *
 * Covers:
 *   - manifest.json parses to a valid PluginManifest (slug, version, ns, api_version)
 *   - GraffitiPlugin boots through the real PluginRegistry (no error_log noise)
 *   - All 5 admin GET routes registered
 *   - Navbar item present with auth='admin'
 *   - Bootstrap copies stickers.json on first run; idempotent on second
 *   - Shipped catalogue is well-formed and references SVG files that exist
 *
 * Exits non-zero on any failure; prints `ALL OK` on full pass.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\PluginManifest;
use App\PluginRegistry;
use App\Router;
use Plugins\Graffiti\Bootstrap;

$failures = 0;

function section(string $name): void { echo "==> {$name}\n"; }
function ok(string $msg): void       { echo "  ok: {$msg}\n"; }
function fail(string $msg): void
{
    global $failures;
    $failures++;
    fwrite(STDERR, "  FAIL: {$msg}\n");
}

// Capture warning logs to assert clean boot.
$logFile = sys_get_temp_dir() . '/graffiti-boot-test-' . posix_getpid() . '.log';
ini_set('error_log', $logFile);

$tmpContent = sys_get_temp_dir() . '/graffiti-content-' . posix_getpid();
@mkdir($tmpContent, 0o755, recursive: true);

register_shutdown_function(static function () use ($logFile, $tmpContent): void {
    @unlink($logFile);
    // Best-effort recursive clean of the temp content dir.
    if (is_dir($tmpContent)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmpContent, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($tmpContent);
    }
});

function logContents(): string
{
    global $logFile;
    return is_file($logFile) ? (string) file_get_contents($logFile) : '';
}

// -----------------------------------------------------------------------------
section('manifest.json parses');

$manifestPath = __DIR__ . '/../plugins/graffiti/manifest.json';
$manifestRaw = file_get_contents($manifestPath);
$manifestRaw !== false ? ok('manifest.json readable') : fail('manifest.json missing');

$manifestData = json_decode((string) $manifestRaw, true);
is_array($manifestData) ? ok('manifest.json valid JSON') : fail('manifest.json not parseable');

$manifest = PluginManifest::fromArray($manifestData);
$manifest->slug === 'graffiti' ? ok('slug = graffiti') : fail("slug got {$manifest->slug}");
$manifest->apiVersion === 1   ? ok('api_version = 1')   : fail('api_version wrong');
$manifest->namespace === 'Plugins\\Graffiti\\' ? ok('namespace = Plugins\\Graffiti\\') : fail("namespace got {$manifest->namespace}");

// -----------------------------------------------------------------------------
section('Boot through real PluginRegistry');

$pluginsDir = realpath(__DIR__ . '/../plugins');
$registry = new PluginRegistry($pluginsDir, 'graffiti', $tmpContent);
$router = new Router();
$registry->boot($router);

logContents() === '' ? ok('clean boot, no error_log noise') : fail("error_log: \n" . logContents());

in_array('graffiti', $registry->enabledSlugs(), true) ? ok('graffiti in enabledSlugs')
    : fail('graffiti missing from enabledSlugs');

$registry->hasAdminRoute('graffiti') ? ok('admin route recorded')
    : fail('hasAdminRoute(graffiti) false');

// -----------------------------------------------------------------------------
section('All 5 admin GET routes registered');

// Router has no public introspection; reflect into the private `$routes`
// array. Test-only — keeps Router's runtime surface lean.
$routerReflection = new ReflectionClass($router);
$routesProp = $routerReflection->getProperty('routes');
$routesProp->setAccessible(true);
/** @var list<array{0:string,1:string,2:callable}> $registeredRoutes */
$registeredRoutes = $routesProp->getValue($router);
$registeredPatterns = array_map(
    static fn (array $r): string => $r[0] . ' ' . $r[1],
    $registeredRoutes,
);

$expectedRoutes = [
    '/admin/graffiti',
    '/admin/graffiti/friends',
    '/admin/graffiti/stickers',
    '/admin/graffiti/energy',
    '/admin/graffiti/send',
];
foreach ($expectedRoutes as $pattern) {
    in_array('GET ' . $pattern, $registeredPatterns, true)
        ? ok("GET {$pattern} registered")
        : fail("GET {$pattern} NOT registered");
}

// -----------------------------------------------------------------------------
section('Navbar item: admin-only graffiti link');

$header = $registry->nav()->header();
$graffitiItem = null;
foreach ($header as $item) {
    if (($item['slug'] ?? '') === 'graffiti') {
        $graffitiItem = $item;
        break;
    }
}
$graffitiItem !== null ? ok('graffiti nav item present in header')
    : fail('graffiti nav item missing');

if ($graffitiItem !== null) {
    ($graffitiItem['auth'] ?? null) === 'admin' ? ok("auth = admin")
        : fail("auth got " . var_export($graffitiItem['auth'] ?? null, true));
    $graffitiItem['href'] === '/admin/graffiti' ? ok("href = /admin/graffiti")
        : fail("href got {$graffitiItem['href']}");
}

// -----------------------------------------------------------------------------
section('Bootstrap: stickers.json copied on first run, idempotent on second');

$storagePath = $tmpContent . '/graffiti';
$storedCatalogue = $storagePath . '/stickers.json';
is_file($storedCatalogue) ? ok('stickers.json copied to storage') : fail('stickers.json not copied');

$firstHash = hash_file('sha256', $storedCatalogue);
// Mutate storage copy to prove second-run does NOT overwrite operator edits.
file_put_contents($storedCatalogue, '[]');
Bootstrap::ensureDefaults($storagePath, $pluginsDir . '/graffiti');
file_get_contents($storedCatalogue) === '[]'
    ? ok('Bootstrap is idempotent (operator edits preserved)')
    : fail('Bootstrap overwrote operator edits');

// -----------------------------------------------------------------------------
section('Shipped catalogue references existing SVG files');

$shippedCatalogue = $pluginsDir . '/graffiti/content/stickers.json';
$entries = json_decode((string) file_get_contents($shippedCatalogue), true);
is_array($entries) && $entries !== [] ? ok('catalogue parses to non-empty array')
    : fail('catalogue empty or invalid');

foreach ($entries as $entry) {
    $svgPath = $pluginsDir . '/graffiti/assets/' . ($entry['svg_filename'] ?? '');
    is_file($svgPath)
        ? ok("SVG exists for {$entry['id']}")
        : fail("SVG missing for {$entry['id']} at {$svgPath}");
    is_int($entry['default_price']) && $entry['default_price'] >= 1
        ? ok("price valid for {$entry['id']}")
        : fail("invalid price for {$entry['id']}");
}

// -----------------------------------------------------------------------------
echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures}\n");
    exit(1);
}
echo "ALL OK\n";
