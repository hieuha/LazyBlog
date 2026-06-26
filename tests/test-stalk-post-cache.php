<?php

declare(strict_types=1);

/**
 * Stalk plugin — PostCache replace + read.
 *
 * Run: php tests/test-stalk-post-cache.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../plugins/stalk/src/PostCache.php';

use Plugins\Stalk\PostCache;

$failures = 0;
function section(string $n): void { echo "==> {$n}\n"; }
function ok(string $m): void      { echo "  ok: {$m}\n"; }
function fail(string $m): void
{
    global $failures;
    $failures++;
    fwrite(STDERR, "  FAIL: {$m}\n");
}
function assertTrue(bool $c, string $m): void { $c ? ok($m) : fail($m); }
function assertEq(mixed $e, mixed $a, string $m): void
{
    $e === $a ? ok($m) : fail("{$m} — expected " . var_export($e, true) . ", got " . var_export($a, true));
}

function makeStorage(string $label): string
{
    $p = sys_get_temp_dir() . "/stalk-cache-{$label}-" . posix_getpid() . '-' . bin2hex(random_bytes(4));
    @mkdir($p, 0o755, recursive: true);
    return $p;
}

function item(string $title, int $ts, string $guid = ''): array
{
    return [
        'title'       => $title,
        'link'        => 'https://blog.example/' . urlencode($title),
        'pub_date_ts' => $ts,
        'guid'        => $guid !== '' ? $guid : 'g-' . $title,
    ];
}

// ---------------------------------------------------------------------------
section('PostCache — empty + replace');

$cache = new PostCache(makeStorage('basic'));
assertEq([], $cache->all(), 'empty cache returns []');

$cache->replaceForFriend('ff_alice', [
    item('A1', 100),
    item('A2', 200),
    item('A3', 150),
]);
assertEq(3, count($cache->all()), 'three rows after replaceForFriend');
assertEq(3, count($cache->forFriend('ff_alice')), 'forFriend returns alice rows');
assertEq([], $cache->forFriend('ff_other'), 'forFriend miss returns []');

// ---------------------------------------------------------------------------
section('PostCache — replace semantics (wipe-and-rewrite)');

$cache->replaceForFriend('ff_alice', [
    item('A4', 300),
    item('A5', 250),
]);
$aliceRows = $cache->forFriend('ff_alice');
assertEq(2, count($aliceRows), 'replace overwrites previous alice rows');
$titles = array_column($aliceRows, 'title');
sort($titles);
assertEq(['A4', 'A5'], $titles, 'old alice rows wiped, new ones present');

// ---------------------------------------------------------------------------
section('PostCache — multi-friend isolation');

$cache->replaceForFriend('ff_bob', [
    item('B1', 50),
    item('B2', 400),
]);
assertEq(2, count($cache->forFriend('ff_alice')), 'alice rows untouched by bob replace');
assertEq(2, count($cache->forFriend('ff_bob')), 'bob has 2 rows');
assertEq(4, count($cache->all()), 'all rows = alice + bob');

// ---------------------------------------------------------------------------
section('PostCache — all() sorts pub_date DESC');

$rows = $cache->all();
$dates = array_column($rows, 'pub_date');
$sorted = $dates;
rsort($sorted);
assertEq($sorted, $dates, 'all() returns pub_date DESC');
assertEq(400, $dates[0], 'newest first = B2 (ts=400)');

// ---------------------------------------------------------------------------
section('PostCache — removeByFriend');

$cache->removeByFriend('ff_alice');
assertEq([], $cache->forFriend('ff_alice'), 'alice rows gone');
assertEq(2, count($cache->forFriend('ff_bob')), 'bob rows preserved');

// ---------------------------------------------------------------------------
section('PostCache — guid fallback to link when missing');

$cache2 = new PostCache(makeStorage('guid'));
$cache2->replaceForFriend('ff_x', [
    ['title' => 'no-guid', 'link' => 'https://x.example/post', 'pub_date_ts' => 10],
]);
$rows2 = $cache2->forFriend('ff_x');
assertEq('https://x.example/post', $rows2[0]['guid'], 'guid falls back to link when absent');

// ---------------------------------------------------------------------------
section('PostCache — corrupt JSON');

$dir4 = makeStorage('corrupt');
file_put_contents($dir4 . '/posts.json', '<<<not json>>>');
$cache3 = new PostCache($dir4);
assertEq([], $cache3->all(), 'corrupt JSON → empty');
$cache3->replaceForFriend('ff_x', [item('X1', 1)]);
assertEq(1, count($cache3->all()), 'recover on next write');

// ---------------------------------------------------------------------------
echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures}\n");
    exit(1);
}
echo "ALL OK\n";
