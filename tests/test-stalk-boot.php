<?php

declare(strict_types=1);

/**
 * Stalk plugin — boot smoke test.
 *
 * Verifies:
 *   - manifest.json parses
 *   - PluginRegistry boots stalk cleanly (no error_log noise)
 *   - All 6 expected routes (1 public + 5 admin) end up on the router
 *   - Header nav contributed
 *
 * Run: php tests/test-stalk-boot.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\PluginManifest;
use App\PluginRegistry;
use App\Router;

$failures = 0;
function section(string $n): void { echo "==> {$n}\n"; }
function ok(string $m): void      { echo "  ok: {$m}\n"; }
function fail(string $m): void
{
    global $failures;
    $failures++;
    fwrite(STDERR, "  FAIL: {$m}\n");
}

// Capture warning logs to assert clean boot.
$logFile = sys_get_temp_dir() . '/stalk-boot-test-' . posix_getpid() . '.log';
ini_set('error_log', $logFile);

$tmpContent = sys_get_temp_dir() . '/stalk-content-' . posix_getpid() . '-' . bin2hex(random_bytes(4));
@mkdir($tmpContent, 0o755, recursive: true);

register_shutdown_function(static function () use ($logFile, $tmpContent): void {
    @unlink($logFile);
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

$manifestPath = __DIR__ . '/../plugins/stalk/manifest.json';
$raw = (string) file_get_contents($manifestPath);
$data = json_decode($raw, true);
is_array($data) ? ok('manifest.json parseable') : fail('manifest.json bad JSON');

$manifest = PluginManifest::fromArray($data);
$manifest->slug === 'stalk' ? ok('slug = stalk') : fail("slug={$manifest->slug}");
$manifest->apiVersion === 1 ? ok('api_version = 1') : fail('api_version wrong');
$manifest->namespace === 'Plugins\\Stalk\\' ? ok('namespace = Plugins\\Stalk\\')
    : fail("namespace={$manifest->namespace}");

// -----------------------------------------------------------------------------
section('Boot through real PluginRegistry');

$pluginsDir = realpath(__DIR__ . '/../plugins');
$registry = new PluginRegistry($pluginsDir, 'stalk', $tmpContent);
$router   = new Router();
$registry->boot($router);

logContents() === '' ? ok('clean boot, no error_log noise') : fail("error_log: \n" . logContents());

in_array('stalk', $registry->enabledSlugs(), true) ? ok('stalk in enabledSlugs')
    : fail('stalk missing from enabledSlugs');

$registry->hasAdminRoute('stalk') ? ok('admin route recorded') : fail('hasAdminRoute(stalk) false');

// -----------------------------------------------------------------------------
section('Expected routes registered');

$routerReflection = new ReflectionClass($router);
$routesProp = $routerReflection->getProperty('routes');
$routesProp->setAccessible(true);
/** @var list<array{0:string,1:string,2:callable}> $registeredRoutes */
$registeredRoutes = $routesProp->getValue($router);
$patterns = array_map(
    static fn (array $r): string => $r[0] . ' ' . $r[1],
    $registeredRoutes,
);

$expected = [
    'GET /stalk',
    'GET /admin/stalk',
    'POST /admin/stalk/add',
    'POST /admin/stalk/remove/{id}',
    'POST /admin/stalk/refresh-now',
    'POST /admin/stalk/config',
];
foreach ($expected as $p) {
    in_array($p, $patterns, true) ? ok("{$p} registered") : fail("{$p} NOT registered");
}

// -----------------------------------------------------------------------------
section('Header nav contributed');

$header = $registry->nav()->header();
$stalkItem = null;
foreach ($header as $item) {
    if (($item['slug'] ?? '') === 'stalk') {
        $stalkItem = $item;
        break;
    }
}
$stalkItem !== null ? ok('stalk nav present') : fail('stalk nav missing');
if ($stalkItem !== null) {
    $stalkItem['href'] === '/stalk' ? ok('href=/stalk') : fail("href={$stalkItem['href']}");
    ($stalkItem['label'] ?? '') === 'Stalk' ? ok('label=Stalk') : fail("label={$stalkItem['label']}");
}

// -----------------------------------------------------------------------------
echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures}\n");
    exit(1);
}
echo "ALL OK\n";
