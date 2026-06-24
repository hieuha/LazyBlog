<?php

declare(strict_types=1);

/**
 * Graffiti plugin — Phase 8: moderation primitives on GraffitiStore.
 *
 * Run: php tests/test-graffiti-moderation.php
 *
 * Covers:
 *   - unreadCount() ignores hidden + already-seen rows
 *   - markSeen() is idempotent and only touches matching ids
 *   - setHidden() returns false for unknown ids, true on success; toggles flag
 *   - hiding a row drops it out of unreadCount
 *   - label format produced by GraffitiPlugin::register() shape: with/without count
 *
 * Exits non-zero on any failure; prints `ALL OK` on full pass.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../plugins/graffiti/src/GraffitiStore.php';

use Plugins\Graffiti\GraffitiStore;

$failures = 0;
function section(string $n): void { echo "==> {$n}\n"; }
function ok(string $m): void      { echo "  ok: {$m}\n"; }
function fail(string $m): void
{
    global $failures;
    $failures++;
    fwrite(STDERR, "  FAIL: {$m}\n");
}
function assertEq(mixed $e, mixed $a, string $m): void
{
    $e === $a ? ok($m) : fail("{$m} — expected " . var_export($e, true) . ", got " . var_export($a, true));
}
function assertTrue(bool $c, string $m): void { $c ? ok($m) : fail($m); }

function tempStorage(string $label): string
{
    $p = sys_get_temp_dir() . "/graffiti-mod-{$label}-" . posix_getpid() . '-' . bin2hex(random_bytes(4));
    @mkdir($p, 0o755, recursive: true);
    return $p;
}

// Seed: 3 visible-unread, 1 hidden, 1 already-seen → unreadCount should be 3.
function seedMixed(GraffitiStore $store): array
{
    $ids = [];
    for ($i = 0; $i < 3; $i++) {
        $ids[] = $store->append([
            'from_friend_id' => 'f_one',
            'post_slug' => "slug-{$i}",
            'type' => 'sticker',
            'payload' => ['sticker_id' => 'ufo-1'],
            'nonce' => "n-{$i}",
        ]);
    }
    $hiddenId = $store->append([
        'from_friend_id' => 'f_one', 'post_slug' => 'h', 'type' => 'sticker',
        'payload' => ['sticker_id' => 'ufo-1'], 'nonce' => 'nh',
    ]);
    $store->update($hiddenId, ['hidden' => true]);

    $seenId = $store->append([
        'from_friend_id' => 'f_one', 'post_slug' => 's', 'type' => 'sticker',
        'payload' => ['sticker_id' => 'ufo-1'], 'nonce' => 'ns',
    ]);
    $store->update($seenId, ['seen_by_owner' => true]);

    return ['unread' => $ids, 'hidden' => $hiddenId, 'seen' => $seenId];
}

// ---------------------------------------------------------------------------
section('unreadCount — visible + unseen only');

$store = new GraffitiStore(tempStorage('count'));
assertEq(0, $store->unreadCount(), 'empty store → 0');

$ids = seedMixed($store);
assertEq(3, $store->unreadCount(), '3 unread (hidden + seen excluded)');

// ---------------------------------------------------------------------------
section('markSeen — idempotent, scoped to ids');

$store = new GraffitiStore(tempStorage('seen'));
$ids = seedMixed($store);
$store->markSeen($ids['unread']);
assertEq(0, $store->unreadCount(), 'after marking all unread ids seen → 0');

// Re-running with same ids: idempotent (no exceptions, count unchanged).
$store->markSeen($ids['unread']);
assertEq(0, $store->unreadCount(), 'idempotent on second call');

// Empty list → no-op.
$store->markSeen([]);
assertEq(0, $store->unreadCount(), 'empty ids list = no-op');

// Unknown id: ignored silently.
$store->markSeen(['g_does_not_exist']);
ok('unknown id silently ignored');

// ---------------------------------------------------------------------------
section('setHidden — toggle + return value');

$store = new GraffitiStore(tempStorage('hide'));
$ids = seedMixed($store);

$beforeCount = $store->unreadCount();
$ok = $store->setHidden($ids['unread'][0], true);
assertTrue($ok, 'setHidden returns true on known id');
assertEq($beforeCount - 1, $store->unreadCount(), 'hidden row drops out of unread');

$ok = $store->setHidden($ids['unread'][0], false);
assertTrue($ok, 'unhide returns true');
assertEq($beforeCount, $store->unreadCount(), 'unhide restores to unread');

$ok = $store->setHidden('g_nope', true);
assertEq(false, $ok, 'setHidden returns false for unknown id');

// ---------------------------------------------------------------------------
section('Navbar label format (mirrors plugin code)');

$mkLabel = static fn (int $n): string => $n > 0 ? "Graffiti ({$n})" : 'Graffiti';
assertEq('Graffiti',       $mkLabel(0), '0 unread → plain label');
assertEq('Graffiti (1)',   $mkLabel(1), '1 unread → (1)');
assertEq('Graffiti (99)',  $mkLabel(99), '99 unread → (99)');

// ---------------------------------------------------------------------------
echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures}\n");
    exit(1);
}
echo "ALL OK\n";
