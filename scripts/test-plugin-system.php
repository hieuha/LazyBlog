<?php

declare(strict_types=1);

/**
 * Assertion fixtures for the plugin system core.
 * Run: php scripts/test-plugin-system.php
 *
 * Covers PluginManifest validation, PluginAssetRegistry route matching,
 * PluginRegistry slug parsing + reserved-path collision logic, and
 * end-to-end boot of a temp fixture plugin. Exits non-zero on any
 * failure; prints `ALL OK` on full pass.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\PluginAssetRegistry;
use App\PluginManifest;
use App\PluginRegistry;
use App\Router;

$failures = 0;

function section(string $name): void
{
    echo "==> {$name}\n";
}

function ok(string $msg): void
{
    echo "  ok: {$msg}\n";
}

function fail(string $msg): void
{
    global $failures;
    $failures++;
    fwrite(STDERR, "  FAIL: {$msg}\n");
}

// Capture warning logs to a file so the test can assert specific messages.
$logFile = sys_get_temp_dir() . '/plugin-test-' . posix_getpid() . '.log';
ini_set('error_log', $logFile);
register_shutdown_function(static function () use ($logFile): void {
    @unlink($logFile);
});

function logContains(string $needle): bool
{
    global $logFile;
    if (!is_file($logFile)) {
        return false;
    }
    return str_contains((string) file_get_contents($logFile), $needle);
}

function clearLog(): void
{
    global $logFile;
    @file_put_contents($logFile, '');
}

// -----------------------------------------------------------------------------
section('PluginManifest::fromArray');
// -----------------------------------------------------------------------------

$validData = [
    'slug' => 'foo',
    'name' => 'Foo',
    'version' => '1.0.0',
    'api_version' => 1,
    'namespace' => 'Plugins\\Foo',
];

$manifest = PluginManifest::fromArray($validData);
$manifest->slug === 'foo' or fail('slug field');
$manifest->apiVersion === 1 or fail('apiVersion field');
str_ends_with($manifest->namespace, '\\') or fail('namespace trailing separator');
ok('valid manifest parses');

// Trailing backslashes get normalised.
$manifest2 = PluginManifest::fromArray($validData + ['namespace' => '\\\\Plugins\\\\Foo\\\\']);
str_starts_with($manifest2->namespace, '\\') === false or fail('namespace leading backslash stripped');
ok('namespace normalisation');

foreach (['slug', 'name', 'version', 'api_version', 'namespace'] as $required) {
    $partial = $validData;
    unset($partial[$required]);
    $threw = false;
    try {
        PluginManifest::fromArray($partial);
    } catch (\InvalidArgumentException) {
        $threw = true;
    }
    $threw or fail("missing {$required} should throw");
}
ok('missing required fields throw');

$badSlugs = ['BadCase', 'bad_underscore', '1-leading-digit', 'has space', ''];
foreach ($badSlugs as $bad) {
    $threw = false;
    try {
        PluginManifest::fromArray(['slug' => $bad] + array_diff_key($validData, ['slug' => '']));
    } catch (\InvalidArgumentException) {
        $threw = true;
    }
    $threw or fail("bad slug {$bad} should throw");
}
ok('bad slug casing throws');

// -----------------------------------------------------------------------------
section('PluginAssetRegistry::forPath');
// -----------------------------------------------------------------------------

$assets = new PluginAssetRegistry();
$assets->css('hello', 'style.css');
$assets->js('hello', 'script.js');
$assets->prefix('hello', '/hello');

$match = $assets->forPath('/hello');
$match['css'] === ['/plugin-assets/hello/style.css'] or fail('exact path matches');
$match['js'] === ['/plugin-assets/hello/script.js'] or fail('js exact match');
ok('exact path matches prefix');

$nested = $assets->forPath('/hello/sub');
$nested['css'] === ['/plugin-assets/hello/style.css'] or fail('nested path matches');
ok('nested path matches prefix');

$miss = $assets->forPath('/helloworld');
$miss === ['css' => [], 'js' => []] or fail('false-prefix /helloworld should NOT match /hello');
ok('false-prefix rejected (/helloworld does not match /hello)');

$other = $assets->forPath('/other');
$other === ['css' => [], 'js' => []] or fail('unrelated path matched');
ok('unrelated path returns empty');

// -----------------------------------------------------------------------------
section('PluginRegistry::canRegister — reserved-path collisions');
// -----------------------------------------------------------------------------

$tempPluginsDir = sys_get_temp_dir() . '/lazyblog-test-plugins-' . posix_getpid();
$tempContentDir = sys_get_temp_dir() . '/lazyblog-test-content-' . posix_getpid();
mkdir($tempPluginsDir, 0o755, true);
mkdir($tempContentDir, 0o755, true);

register_shutdown_function(static function () use ($tempPluginsDir, $tempContentDir): void {
    $rm = static function (string $dir) use (&$rm): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $rm($path) : @unlink($path);
        }
        @rmdir($dir);
    };
    $rm($tempPluginsDir);
    $rm($tempContentDir);
});

$registry = new PluginRegistry($tempPluginsDir, '', $tempContentDir);

$cases = [
    // [pattern, slug, expectedAllowed, label]
    ['/', 'foo', false, 'root path reserved'],
    ['/posts', 'foo', false, 'core /posts reserved'],
    ['/posts/abc', 'foo', false, 'core /posts/* reserved'],
    ['/tags', 'foo', false, 'core /tags reserved'],
    ['/feed.xml', 'foo', false, 'core /feed.xml reserved'],
    ['/llms.txt', 'foo', false, 'core /llms.txt reserved'],
    ['/llms-full.txt', 'foo', false, 'core /llms-full.txt reserved'],
    ['/plugin-assets', 'foo', false, 'plugin-assets prefix reserved'],
    ['/plugin-assets/foo/x.css', 'foo', false, 'plugin-assets/* reserved'],
    ['/admin', 'foo', false, 'bare /admin reserved'],
    ['/admin/login', 'foo', false, 'core admin route reserved'],
    ['/admin/edit/abc', 'foo', false, 'core admin /admin/edit reserved'],
    ['/healthz', 'foo', false, 'liveness probe reserved'],
    ['/about', 'foo', false, '/about reserved'],
    ['/series', 'foo', false, '/series reserved'],

    // allowed: plugin's own non-admin path
    ['/foo', 'foo', true, 'plugin can claim /foo'],
    ['/hello', 'hello-world', true, 'plugin can claim /hello'],
    ['/foo/sub', 'foo', true, 'plugin can claim /foo/sub'],

    // allowed: plugin's own admin namespace
    ['/admin/foo', 'foo', true, 'plugin can claim /admin/foo'],
    ['/admin/foo/sub', 'foo', true, 'plugin can claim /admin/foo/sub'],
    ['/admin/hello-world', 'hello-world', true, 'plugin can claim /admin/hello-world'],
];

foreach ($cases as [$pattern, $slug, $expected, $label]) {
    // Reset duplicate-detection by giving each case a fresh registry.
    $r = new PluginRegistry($tempPluginsDir, '', $tempContentDir);
    $actual = $r->canRegister($slug, $pattern);
    if ($actual !== $expected) {
        fail("{$label}: expected " . ($expected ? 'allowed' : 'rejected') . " for {$pattern}");
    }
}
ok('reserved-path matrix (' . count($cases) . ' cases)');

// Duplicate-registration detection.
$r = new PluginRegistry($tempPluginsDir, '', $tempContentDir);
$r->canRegister('foo', '/foo') === true or fail('first /foo allowed');
$r->canRegister('foo', '/foo') === false or fail('duplicate /foo rejected');
$r->canRegister('bar', '/foo') === false or fail('cross-plugin duplicate /foo rejected');
ok('duplicate registration rejected');

// -----------------------------------------------------------------------------
section('PluginRegistry — boot fixture');
// -----------------------------------------------------------------------------

// Fixture plugin: minimal Plugin implementation written to disk on the fly.
$fixtureSlug = 'test-fixture';
$fixtureDir = $tempPluginsDir . '/' . $fixtureSlug;
mkdir($fixtureDir . '/src', 0o755, true);
file_put_contents($fixtureDir . '/manifest.json', json_encode([
    'slug' => $fixtureSlug,
    'name' => 'Test Fixture',
    'version' => '1.0.0',
    'api_version' => 1,
    'namespace' => 'Plugins\\TestFixture',
]));
file_put_contents($fixtureDir . '/src/TestFixturePlugin.php', <<<'PHP'
<?php
namespace Plugins\TestFixture;
final class TestFixturePlugin implements \App\Plugin
{
    public function manifest(): \App\PluginManifest {
        return \App\PluginManifest::fromArray([
            'slug' => 'test-fixture', 'name' => 'Test', 'version' => '1.0.0',
            'api_version' => 1, 'namespace' => 'Plugins\\TestFixture',
        ]);
    }
    public function register(\App\PluginContext $ctx): void {
        $ctx->get('/test-fixture', static fn () => null);
    }
}
PHP);
file_put_contents($fixtureDir . '/plugin.php', <<<PHP
<?php
require_once __DIR__ . '/src/TestFixturePlugin.php';
return new Plugins\\TestFixture\\TestFixturePlugin();
PHP);

clearLog();
$router = new Router();
$registry = new PluginRegistry($tempPluginsDir, $fixtureSlug, $tempContentDir);
$registry->boot($router);
in_array($fixtureSlug, $registry->enabledSlugs(), true) or fail('fixture plugin booted');
$registry->isEnabled($fixtureSlug) or fail('fixture isEnabled');
$registry->manifest($fixtureSlug)?->slug === $fixtureSlug or fail('manifest accessor');
ok('fixture plugin boots cleanly');

// Bad api_version → skipped
clearLog();
$badSlug = 'bad-api';
mkdir($tempPluginsDir . '/' . $badSlug, 0o755);
file_put_contents($tempPluginsDir . '/' . $badSlug . '/manifest.json', json_encode([
    'slug' => $badSlug,
    'name' => 'Bad',
    'version' => '1.0.0',
    'api_version' => 999,
    'namespace' => 'Plugins\\Bad',
]));
file_put_contents($tempPluginsDir . '/' . $badSlug . '/plugin.php', '<?php return null;');

$registry2 = new PluginRegistry($tempPluginsDir, "{$fixtureSlug},{$badSlug}", $tempContentDir);
$registry2->boot(new Router());
$registry2->isEnabled($badSlug) === false or fail('bad api_version plugin should be skipped');
logContains('unsupported api_version') or fail('expected api_version warning in error_log');
ok('bad api_version plugin skipped + logged');

// Missing folder → logged + skipped
clearLog();
$registry3 = new PluginRegistry($tempPluginsDir, 'no-such-plugin', $tempContentDir);
$registry3->boot(new Router());
$registry3->isEnabled('no-such-plugin') === false or fail('missing-folder plugin should be skipped');
logContains('directory missing') or fail('expected missing-folder warning');
ok('missing plugin folder skipped + logged');

// Invalid slug in PLUGINS env → logged + skipped
clearLog();
$registry4 = new PluginRegistry($tempPluginsDir, "BadSlug,{$fixtureSlug}", $tempContentDir);
$registry4->boot(new Router());
$registry4->isEnabled($fixtureSlug) === true or fail('good slug should still load after bad sibling');
logContains('invalid slug') or fail('expected invalid-slug warning');
ok('invalid slug in env skipped, good siblings still load');

// Empty PLUGINS env → zero enabled
$registry5 = new PluginRegistry($tempPluginsDir, '', $tempContentDir);
$registry5->boot(new Router());
$registry5->enabledSlugs() === [] or fail('empty PLUGINS should yield zero enabled');
ok('empty PLUGINS env = zero enabled');

// -----------------------------------------------------------------------------
echo "\n";
if ($failures === 0) {
    echo "ALL OK\n";
    exit(0);
}
fwrite(STDERR, "\n{$failures} FAILURE(S)\n");
exit(1);
