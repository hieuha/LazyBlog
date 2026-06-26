<?php

declare(strict_types=1);

/**
 * Stalk plugin — FriendStore CRUD.
 *
 * Run: php tests/test-stalk-friend-store.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../plugins/stalk/src/FriendStore.php';

use Plugins\Stalk\FriendStore;

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
    $p = sys_get_temp_dir() . "/stalk-friend-{$label}-" . posix_getpid() . '-' . bin2hex(random_bytes(4));
    @mkdir($p, 0o755, recursive: true);
    return $p;
}

// ---------------------------------------------------------------------------
section('FriendStore — empty + create');

$dir = makeStorage('basic');
$store = new FriendStore($dir);
assertEq([], $store->all(), 'empty store returns []');

$id = $store->create([
    'blog_url' => 'https://blog-a.example',
    'handle'   => 'alice',
]);
assertTrue(str_starts_with($id, 'ff_') && strlen($id) === 11, 'create returns ff_xxxxxxxx id');

$row = $store->find($id);
assertTrue($row !== null, 'find returns row');
assertEq('alice', $row['handle'], 'handle persisted');
assertEq('https://blog-a.example', $row['blog_url'], 'blog_url persisted');
assertTrue(is_int($row['added_at']) && $row['added_at'] > 0, 'added_at is unix ts');
assertEq(0, $row['last_fetched_at'], 'default last_fetched_at=0');
assertEq(null, $row['last_status'], 'default last_status=null');
assertEq(null, $row['last_error'], 'default last_error=null');

// ---------------------------------------------------------------------------
section('FriendStore — find variants');

$byUrl = $store->findByBlogUrl('https://blog-a.example');
assertTrue($byUrl !== null && $byUrl['id'] === $id, 'findByBlogUrl matches');

$byUrlSlash = $store->findByBlogUrl('https://blog-a.example/');
assertTrue($byUrlSlash !== null && $byUrlSlash['id'] === $id, 'findByBlogUrl tolerates trailing slash');

$miss = $store->findByBlogUrl('https://nope.example');
assertEq(null, $miss, 'findByBlogUrl misses on unknown URL');

assertEq(null, $store->find('ff_deadbeef'), 'find returns null for unknown id');

// ---------------------------------------------------------------------------
section('FriendStore — update');

$store->update($id, [
    'last_fetched_at' => 1700000000,
    'last_status'     => 'ok',
    'last_error'      => null,
]);
$row2 = $store->find($id);
assertEq(1700000000, $row2['last_fetched_at'], 'update patches last_fetched_at');
assertEq('ok', $row2['last_status'], 'update patches last_status');
assertEq('alice', $row2['handle'], 'update preserves untouched fields');

// ---------------------------------------------------------------------------
section('FriendStore — delete');

$store->delete($id);
assertEq(null, $store->find($id), 'delete removes row');
assertEq([], $store->all(), 'store empty after delete');

// Delete non-existent is no-op
$store->delete('ff_nope1234');
assertEq([], $store->all(), 'delete on missing id is no-op');

// ---------------------------------------------------------------------------
section('FriendStore — multi-friend ordering');

$store2 = new FriendStore(makeStorage('multi'));
$a = $store2->create(['blog_url' => 'https://a.example', 'handle' => 'a']);
$b = $store2->create(['blog_url' => 'https://b.example', 'handle' => 'b']);
$c = $store2->create(['blog_url' => 'https://c.example', 'handle' => 'c']);
assertEq(3, count($store2->all()), 'three rows present');

$store2->delete($b);
$ids = array_column($store2->all(), 'id');
assertEq([$a, $c], $ids, 'delete preserves order of survivors');

// ---------------------------------------------------------------------------
section('FriendStore — corrupt JSON');

$dir3 = makeStorage('corrupt');
file_put_contents($dir3 . '/friends.json', '{this is not json');
$store3 = new FriendStore($dir3);
assertEq([], $store3->all(), 'corrupt JSON → empty array');

// recover by creating new row
$store3->create(['blog_url' => 'https://x.example', 'handle' => 'x']);
assertEq(1, count($store3->all()), 'create on corrupt file rewrites it');

// ---------------------------------------------------------------------------
echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures}\n");
    exit(1);
}
echo "ALL OK\n";
