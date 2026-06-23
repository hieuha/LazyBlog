<?php

declare(strict_types=1);

/**
 * View-counter plugin unit + integration tests.
 * Run: php tests/test-view-counter.php
 *
 * Covers BotFilter substring matching, CookieIdentity mint/return contract,
 * StatsStore single/dedup/multi-slug increment, JSON shape + sha256 dedup
 * keys, corrupt-JSON tolerance, and a 50-process concurrent-write race to
 * prove `flock(LOCK_EX)` on the lockfile prevents lost updates.
 *
 * Exits non-zero on any assertion failure; prints `ALL OK` on full pass.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../plugins/view-counter/src/BotFilter.php';
require __DIR__ . '/../plugins/view-counter/src/CookieIdentity.php';
require __DIR__ . '/../plugins/view-counter/src/StatsStore.php';

use Plugins\ViewCounter\BotFilter;
use Plugins\ViewCounter\CookieIdentity;
use Plugins\ViewCounter\StatsStore;

$failures = 0;

function section(string $name): void { echo "==> {$name}\n"; }
function ok(string $msg): void { echo "  ok: {$msg}\n"; }
function fail(string $msg): void { global $failures; $failures++; fwrite(STDERR, "  FAIL: {$msg}\n"); }

function assertEq(mixed $expected, mixed $actual, string $msg): void
{
    if ($expected === $actual) {
        ok($msg);
    } else {
        fail("{$msg} — expected " . var_export($expected, true) . ", got " . var_export($actual, true));
    }
}

function assertTrue(bool $cond, string $msg): void
{
    $cond ? ok($msg) : fail($msg);
}

// -----------------------------------------------------------------------------
section('BotFilter');
// -----------------------------------------------------------------------------

$botCases = [
    ['', true, 'empty UA is bot'],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36', false, 'real Chrome is not bot'],
    ['Mozilla/5.0 (X11; Linux x86_64; rv:119.0) Gecko/20100101 Firefox/119.0', false, 'real Firefox is not bot'],
    ['Googlebot/2.1 (+http://www.google.com/bot.html)', true, 'Googlebot caught'],
    ['Mozilla/5.0 (compatible; Bingbot/2.0; +http://www.bing.com/bingbot.htm)', true, 'Bingbot caught'],
    ['GPTBot/1.0', true, 'GPTBot caught'],
    ['Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', true, 'ClaudeBot caught'],
    ['curl/8.4.0', true, 'curl caught'],
    ['Wget/1.21.3', true, 'wget caught'],
    ['python-requests/2.31.0', true, 'python-requests caught'],
    ['facebookexternalhit/1.1', true, 'facebookexternalhit caught (via bot token)'],
    ['Twitterbot/1.0', true, 'Twitterbot caught'],
    ['Mozilla/5.0 RSSReader/1.2', true, 'RSS reader caught'],
];

foreach ($botCases as [$ua, $expected, $msg]) {
    assertEq($expected, BotFilter::isBot($ua), $msg);
}

// -----------------------------------------------------------------------------
section('CookieIdentity');
// -----------------------------------------------------------------------------

// CLI: headers_sent() is true so setcookie silently no-ops. getOrMint still
// returns the right value; we test the return contract, not the Set-Cookie.
$_COOKIE = [];
$minted = CookieIdentity::getOrMint();
assertTrue(preg_match('/^[a-f0-9]{32}$/', $minted) === 1, "fresh mint returns 32-hex");
assertEq($minted, $_COOKIE[CookieIdentity::COOKIE], "minted value reflected into \$_COOKIE");
assertEq($minted, CookieIdentity::getOrMint(), "second call with cookie present returns same");

$_COOKIE[CookieIdentity::COOKIE] = 'garbage-not-hex';
$replacement = CookieIdentity::getOrMint();
assertTrue(preg_match('/^[a-f0-9]{32}$/', $replacement) === 1, "malformed cookie triggers fresh mint");
assertTrue($replacement !== 'garbage-not-hex', "garbage value not returned");

$_COOKIE = [];

// -----------------------------------------------------------------------------
section('StatsStore — basic + dedup');
// -----------------------------------------------------------------------------

$tmpDir = sys_get_temp_dir() . '/lz_vc_test_' . uniqid();
mkdir($tmpDir, 0o755, recursive: true);
register_shutdown_function(static function () use ($tmpDir): void {
    foreach (['stats.json', 'seen.json', '.stats.lock'] as $f) {
        @unlink($tmpDir . '/' . $f);
    }
    @rmdir($tmpDir);
});

$store = new StatsStore($tmpDir);

assertEq(0, $store->getCount('post-a'), 'count starts at 0');
assertTrue($store->recordView('post-a', 'uid-1'), 'first view counted');
assertEq(1, $store->getCount('post-a'), 'count now 1');
assertTrue(!$store->recordView('post-a', 'uid-1'), 'duplicate (uid-1, post-a) dedup\'d');
assertEq(1, $store->getCount('post-a'), 'count still 1 after dup');
assertTrue($store->recordView('post-a', 'uid-2'), 'different uid counted');
assertEq(2, $store->getCount('post-a'), 'count now 2');
assertTrue($store->recordView('post-b', 'uid-1'), 'different slug counted');
assertEq(1, $store->getCount('post-b'), 'post-b count is 1');
assertEq(2, $store->getCount('post-a'), 'post-a count unaffected');

assertTrue(!$store->recordView('', 'uid-x'), 'empty slug rejected');
assertTrue(!$store->recordView('post-a', ''), 'empty uid rejected');

// -----------------------------------------------------------------------------
section('StatsStore — JSON shape + sha256 dedup keys');
// -----------------------------------------------------------------------------

$stats = json_decode((string) file_get_contents($tmpDir . '/stats.json'), true);
assertTrue(is_array($stats), 'stats.json decodes to array');
assertEq(['views' => 2], $stats['post-a'] ?? null, 'post-a row has views=2');
assertEq(['views' => 1], $stats['post-b'] ?? null, 'post-b row has views=1');

$seen = json_decode((string) file_get_contents($tmpDir . '/seen.json'), true);
assertTrue(is_array($seen), 'seen.json decodes to array');
foreach (array_keys($seen) as $key) {
    if (preg_match('/^[a-f0-9]{64}$/', (string) $key) !== 1) {
        fail("seen.json key not 64-hex sha256: {$key}");
        break;
    }
}
ok('all seen.json keys are sha256 hashes');

// -----------------------------------------------------------------------------
section('StatsStore — corrupt JSON tolerance');
// -----------------------------------------------------------------------------

$corruptDir = sys_get_temp_dir() . '/lz_vc_corrupt_' . uniqid();
mkdir($corruptDir, 0o755, recursive: true);
register_shutdown_function(static function () use ($corruptDir): void {
    foreach (['stats.json', 'seen.json', '.stats.lock'] as $f) {
        @unlink($corruptDir . '/' . $f);
    }
    @rmdir($corruptDir);
});

file_put_contents($corruptDir . '/stats.json', 'not json at all {{{');
file_put_contents($corruptDir . '/seen.json', '<<broken>>');

$corruptStore = new StatsStore($corruptDir);
assertEq(0, $corruptStore->getCount('any-slug'), 'corrupt stats reads as 0');
assertTrue($corruptStore->recordView('post-x', 'uid-x'), 'recordView survives corrupt input');
assertEq(1, $corruptStore->getCount('post-x'), 'count is 1 after recovery');

$recoveredStats = json_decode((string) file_get_contents($corruptDir . '/stats.json'), true);
assertTrue(is_array($recoveredStats), 'stats.json rewritten as valid JSON');

// -----------------------------------------------------------------------------
section('StatsStore — concurrent writes (flock correctness)');
// -----------------------------------------------------------------------------

if (!function_exists('pcntl_fork')) {
    ok('pcntl_fork unavailable — skipping concurrent test (run on platform with pcntl)');
} else {
    $raceDir = sys_get_temp_dir() . '/lz_vc_race_' . uniqid();
    mkdir($raceDir, 0o755, recursive: true);
    // NOTE: no register_shutdown_function — it would be inherited by every
    // forked child and delete the dir on the first child's exit, breaking
    // subsequent writes. Cleanup is explicit at the end of this block.

    $children = 50;
    $pids = [];
    for ($i = 0; $i < $children; $i++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            fail('pcntl_fork failed');
            break;
        }
        if ($pid === 0) {
            // Child: independent StatsStore instance, unique uid, same slug.
            // pcntl_exec to clear inherited shutdown handlers — but cheaper
            // to just exit via posix_kill to skip PHP shutdown entirely.
            $childStore = new StatsStore($raceDir);
            $childStore->recordView('hot-post', "uid-{$i}");
            posix_kill(posix_getpid(), SIGTERM);
            exit(0);
        }
        $pids[] = $pid;
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $finalStore = new StatsStore($raceDir);
    $finalCount = $finalStore->getCount('hot-post');
    assertEq(
        $children,
        $finalCount,
        "{$children} concurrent unique-uid views produce exactly {$children} count"
    );

    // Explicit cleanup in parent only.
    foreach (['stats.json', 'seen.json', '.stats.lock'] as $f) {
        @unlink($raceDir . '/' . $f);
    }
    @rmdir($raceDir);
}

// -----------------------------------------------------------------------------
echo "\n";
if ($failures === 0) {
    echo "ALL OK\n";
    exit(0);
}
fwrite(STDERR, "FAILURES: {$failures}\n");
exit(1);
