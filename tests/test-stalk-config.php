<?php

declare(strict_types=1);

/**
 * Stalk plugin — Config (interval + caps + global gate timestamp).
 *
 * Run: php tests/test-stalk-config.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../plugins/stalk/src/Config.php';

use Plugins\Stalk\Config;

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
function assertThrows(callable $fn, string $m): void
{
    try { $fn(); fail($m); } catch (\Throwable $e) { ok($m); }
}

function makeStorage(string $label): string
{
    $p = sys_get_temp_dir() . "/stalk-config-{$label}-" . posix_getpid() . '-' . bin2hex(random_bytes(4));
    @mkdir($p, 0o755, recursive: true);
    return $p;
}

// ---------------------------------------------------------------------------
section('Config — defaults');

$c = new Config(makeStorage('defaults'));
$g = $c->get();
assertEq('10h', $g['refresh_interval'], 'default refresh_interval=10h');
assertEq(13, $g['max_friends'], 'default max_friends=13');
assertEq(3, $g['max_items_per_friend'], 'default max_items_per_friend=3');
assertEq(0, $g['last_refresh_at'], 'default last_refresh_at=0');
assertEq(36000, $c->intervalSeconds(), 'intervalSeconds for 10h = 36000');
assertEq(13, $c->maxFriends(), 'maxFriends() shortcut');
assertEq(3, $c->maxItemsPerFriend(), 'maxItemsPerFriend() shortcut');
assertTrue($c->isStale(), 'isStale() true when last_refresh_at=0');

// ---------------------------------------------------------------------------
section('Config — setInterval');

$c->setInterval('3h');
assertEq('3h', $c->get()['refresh_interval'], 'setInterval persisted');
assertEq(10800, $c->intervalSeconds(), 'intervalSeconds for 3h = 10800');
$c->setInterval('1d');
assertEq(86400, $c->intervalSeconds(), 'intervalSeconds for 1d = 86400');

assertThrows(fn () => $c->setInterval('99h'), 'setInterval("99h") throws');
assertThrows(fn () => $c->setInterval(''), 'setInterval("") throws');

// other fields preserved across setInterval
assertEq(13, $c->maxFriends(), 'max_friends untouched by setInterval');

// ---------------------------------------------------------------------------
section('Config — setMaxFriends');

$c->setMaxFriends(20);
assertEq(20, $c->maxFriends(), 'setMaxFriends(20) persisted');
$c->setMaxFriends(1);
assertEq(1, $c->maxFriends(), 'setMaxFriends(1) — lower edge');
$c->setMaxFriends(100);
assertEq(100, $c->maxFriends(), 'setMaxFriends(100) — upper edge');

assertThrows(fn () => $c->setMaxFriends(0), 'setMaxFriends(0) throws');
assertThrows(fn () => $c->setMaxFriends(-1), 'setMaxFriends(-1) throws');
assertThrows(fn () => $c->setMaxFriends(101), 'setMaxFriends(101) throws');

// ---------------------------------------------------------------------------
section('Config — setMaxItemsPerFriend');

$c->setMaxItemsPerFriend(5);
assertEq(5, $c->maxItemsPerFriend(), 'setMaxItemsPerFriend(5) persisted');
$c->setMaxItemsPerFriend(1);
assertEq(1, $c->maxItemsPerFriend(), 'setMaxItemsPerFriend(1) — lower edge');
$c->setMaxItemsPerFriend(10);
assertEq(10, $c->maxItemsPerFriend(), 'setMaxItemsPerFriend(10) — upper edge');

assertThrows(fn () => $c->setMaxItemsPerFriend(0), 'setMaxItemsPerFriend(0) throws');
assertThrows(fn () => $c->setMaxItemsPerFriend(11), 'setMaxItemsPerFriend(11) throws');

// ---------------------------------------------------------------------------
section('Config — markRefreshed + isStale');

$c2 = new Config(makeStorage('stale'));
assertTrue($c2->isStale(), 'fresh config is stale');
$c2->markRefreshed(time());
assertTrue(!$c2->isStale(), 'isStale=false right after markRefreshed(time())');

// Skip forward — pretend interval passed
$c2->markRefreshed(time() - 36001);  // > 10h ago
assertTrue($c2->isStale(), 'isStale=true after interval elapsed');

// Other fields preserved
assertEq(13, $c2->maxFriends(), 'max_friends preserved across markRefreshed');
assertEq('10h', $c2->get()['refresh_interval'], 'interval preserved across markRefreshed');

// Negative ts clamped to 0
$c2->markRefreshed(-100);
assertEq(0, $c2->get()['last_refresh_at'], 'negative ts clamped to 0');

// ---------------------------------------------------------------------------
section('Config — corrupt config.json');

$dir = makeStorage('corrupt');
file_put_contents($dir . '/config.json', 'not json at all');
$c3 = new Config($dir);
$g3 = $c3->get();
assertEq('10h', $g3['refresh_interval'], 'corrupt → default interval');
assertEq(13, $g3['max_friends'], 'corrupt → default max_friends');
assertEq(3, $g3['max_items_per_friend'], 'corrupt → default max_items_per_friend');

// Recovery: setter rewrites file with valid defaults + change
$c3->setInterval('3h');
assertEq('3h', (new Config($dir))->get()['refresh_interval'], 'setter rewrites corrupt file cleanly');

// ---------------------------------------------------------------------------
section('Config — out-of-range fields in stored file fall back to defaults');

$dir2 = makeStorage('badvals');
file_put_contents($dir2 . '/config.json', json_encode([
    'refresh_interval'     => '99h',
    'max_friends'          => 9999,
    'max_items_per_friend' => -5,
    'last_refresh_at'      => 1700000000,
]));
$c4 = new Config($dir2);
$g4 = $c4->get();
assertEq('10h', $g4['refresh_interval'], 'invalid interval → default');
assertEq(13, $g4['max_friends'], 'out-of-range max_friends → default');
assertEq(3, $g4['max_items_per_friend'], 'out-of-range max_items_per_friend → default');
assertEq(1700000000, $g4['last_refresh_at'], 'valid last_refresh_at preserved');

// ---------------------------------------------------------------------------
echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures}\n");
    exit(1);
}
echo "ALL OK\n";
