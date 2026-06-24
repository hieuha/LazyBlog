<?php

declare(strict_types=1);

/**
 * Phase 0 — new hook surfaces: PostSaveEvent dispatch + nav() admin auth.
 *
 * Validates:
 *   - PluginRegistry::dispatchPostSave invokes registered listeners with the
 *     correct PostSaveEvent payload.
 *   - Listener exceptions are isolated (try/catch wrapper, same as post.view).
 *   - PluginNavRegistry::add accepts and normalises the new $auth param;
 *     default stays 'public' so existing plugins keep behaving identically.
 *
 * Run: php tests/test-plugin-events.php
 * Exits non-zero on any failure; prints `ALL OK` on full pass.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\PluginNavRegistry;
use App\PluginRegistry;
use App\PostSaveEvent;

$failures = 0;

function section(string $name): void { echo "==> {$name}\n"; }
function ok(string $msg): void       { echo "  ok: {$msg}\n"; }
function fail(string $msg): void
{
    global $failures;
    $failures++;
    fwrite(STDERR, "  FAIL: {$msg}\n");
}

// Silence error_log noise from the intentional-throw listener test.
$logFile = sys_get_temp_dir() . '/plugin-events-test-' . posix_getpid() . '.log';
ini_set('error_log', $logFile);
register_shutdown_function(static function () use ($logFile): void {
    @unlink($logFile);
});

// ----------------------------------------------------------------------
section('PostSaveEvent shape');

$evt = new PostSaveEvent(slug: '2026-06-22-foo', isNew: true, published: true, savedAt: 1719240000);
$evt->slug === '2026-06-22-foo'  ? ok('slug stored')      : fail('slug wrong');
$evt->isNew === true             ? ok('isNew stored')     : fail('isNew wrong');
$evt->published === true         ? ok('published stored') : fail('published wrong');
$evt->savedAt === 1719240000     ? ok('savedAt stored')   : fail('savedAt wrong');

// ----------------------------------------------------------------------
section('PluginRegistry::dispatchPostSave invokes listeners');

$registry = new PluginRegistry(__DIR__ . '/fixtures-nonexistent', '', sys_get_temp_dir());
$received = [];
$registry->addPostSaveListener(function (PostSaveEvent $e) use (&$received): void {
    $received[] = ['slug' => $e->slug, 'isNew' => $e->isNew, 'published' => $e->published];
});
$registry->dispatchPostSave(new PostSaveEvent('s', true, false, 1));
$registry->dispatchPostSave(new PostSaveEvent('t', false, true, 2));

count($received) === 2 ? ok('both events delivered') : fail('expected 2, got ' . count($received));
$received[0]['slug'] === 's' && $received[0]['isNew'] === true && $received[0]['published'] === false
    ? ok('first payload intact')
    : fail('first payload mismatched');
$received[1]['slug'] === 't' && $received[1]['isNew'] === false && $received[1]['published'] === true
    ? ok('second payload intact')
    : fail('second payload mismatched');

// ----------------------------------------------------------------------
section('Listener exceptions are isolated');

$registry2 = new PluginRegistry(__DIR__ . '/fixtures-nonexistent', '', sys_get_temp_dir());
$afterThrowCalled = false;
$registry2->addPostSaveListener(function (): void { throw new \RuntimeException('boom'); });
$registry2->addPostSaveListener(function () use (&$afterThrowCalled): void { $afterThrowCalled = true; });

try {
    $registry2->dispatchPostSave(new PostSaveEvent('x', true, true, 0));
    ok('dispatch did not propagate exception');
} catch (\Throwable $e) {
    fail('dispatch leaked exception: ' . $e->getMessage());
}
$afterThrowCalled ? ok('subsequent listener still invoked after throw') : fail('throw aborted listener chain');

// ----------------------------------------------------------------------
section('PluginNavRegistry::add accepts $auth and defaults to public');

$nav = new PluginNavRegistry();
$nav->add('p1', 'public-link', '/foo', 'header');                 // default
$nav->add('p2', 'admin-link', '/admin/bar', 'header', 'admin');   // explicit admin
$nav->add('p3', 'odd-auth', '/baz', 'header', 'something-else');  // normalised → public

$items = $nav->all();
count($items) === 3 ? ok('three items registered') : fail('expected 3 items');

$items[0]['auth'] === 'public' ? ok('default auth is public') : fail("default auth got: {$items[0]['auth']}");
$items[1]['auth'] === 'admin'  ? ok('explicit admin preserved') : fail("admin auth got: {$items[1]['auth']}");
$items[2]['auth'] === 'public' ? ok('unknown auth normalised to public') : fail("normalised auth got: {$items[2]['auth']}");

// header()/footer() projections keep the auth field intact (layout filter relies on it)
$header = $nav->header();
isset($header[0]['auth']) ? ok('header() includes auth key') : fail('header() dropped auth key');

// ----------------------------------------------------------------------
echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures}\n");
    exit(1);
}
echo "ALL OK\n";
