<?php

declare(strict_types=1);

/**
 * Graffiti plugin — Phase 5: RateLimiter (24h sliding window per friend).
 *
 * Run: php tests/test-graffiti-rate-limit.php
 *
 * Covers:
 *   - Below limit: check() ok=true
 *   - At limit:     ok=false, retry_after > 0 and ≤ 86400
 *   - Hidden rows still count (no bypass-by-hide)
 *   - Sliding window: rows older than 24h NOT counted
 *   - Per-friend override (`rate_limit_per_day` field) respected
 *   - rate_limit_per_day = 0 → first inbound rejected (soft block)
 *   - Inbox::process emits 402 + retry_after when limited
 *
 * Exits non-zero on any failure; prints `ALL OK` on full pass.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../plugins/graffiti/src/TokenGenerator.php';
require __DIR__ . '/../plugins/graffiti/src/InviteCodec.php';
require __DIR__ . '/../plugins/graffiti/src/FriendStore.php';
require __DIR__ . '/../plugins/graffiti/src/StickerCatalogue.php';
require __DIR__ . '/../plugins/graffiti/src/GraffitiStore.php';
require __DIR__ . '/../plugins/graffiti/src/NonceCache.php';
require __DIR__ . '/../plugins/graffiti/src/PayloadValidator.php';
require __DIR__ . '/../plugins/graffiti/src/RateLimiter.php';
require __DIR__ . '/../plugins/graffiti/src/Inbox.php';

use App\PostRepository;
use Plugins\Graffiti\FriendStore;
use Plugins\Graffiti\GraffitiStore;
use Plugins\Graffiti\Inbox;
use Plugins\Graffiti\NonceCache;
use Plugins\Graffiti\RateLimiter;
use Plugins\Graffiti\StickerCatalogue;
use Plugins\Graffiti\TokenGenerator;

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
    $p = sys_get_temp_dir() . "/graffiti-rl-{$label}-" . posix_getpid() . '-' . bin2hex(random_bytes(4));
    @mkdir($p, 0o755, recursive: true);
    return $p;
}

// Seed a friend row + N graffiti rows pre-stamped to known ages.
function seed(FriendStore $friends, GraffitiStore $store, int $limit, int $countWithinWindow, int $extraOlderThanWindow): string
{
    $friendId = $friends->create([
        'handle' => 'alice',
        'blog_url' => 'https://a.example',
        'graffiti_endpoint' => 'https://a.example/graffiti/receive',
        'incoming_token' => str_repeat('A', 43),
        'outgoing_token' => str_repeat('B', 43),
        'state' => 'active',
        'rate_limit_per_day' => $limit,
    ]);
    $now = time();
    // Within-window rows: stamp them 1h ago each (well inside 24h).
    for ($i = 0; $i < $countWithinWindow; $i++) {
        $id = $store->append([
            'from_friend_id' => $friendId,
            'post_slug' => "p{$i}",
            'type' => 'sticker',
            'payload' => ['sticker_id' => 'ufo-1'],
            'nonce' => bin2hex(random_bytes(8)),
        ]);
        // Backdate the received_at to a known age (newest at -1h, increasingly older).
        $store->update($id, ['received_at' => $now - 3600 - $i * 60]);
    }
    // Outside-window rows: 25h old (well past the 24h cap).
    for ($i = 0; $i < $extraOlderThanWindow; $i++) {
        $id = $store->append([
            'from_friend_id' => $friendId,
            'post_slug' => "old{$i}",
            'type' => 'sticker',
            'payload' => ['sticker_id' => 'ufo-1'],
            'nonce' => bin2hex(random_bytes(8)),
        ]);
        $store->update($id, ['received_at' => $now - (25 * 3600)]);
    }
    return $friendId;
}

// ---------------------------------------------------------------------------
section('Below limit → ok=true');

$s = tempStorage('below');
$friends = new FriendStore($s);
$store   = new GraffitiStore($s);
$friendId = seed($friends, $store, limit: 5, countWithinWindow: 3, extraOlderThanWindow: 0);
$rl = new RateLimiter($friends, $store);
$r = $rl->check($friendId);
assertEq(true, $r['ok'], 'ok=true');
assertEq(3, $r['count'], 'count=3');
assertEq(5, $r['limit'], 'limit=5');
assertEq(0, $r['retry_after'], 'retry_after=0 when ok');

// ---------------------------------------------------------------------------
section('At limit → ok=false with retry_after');

$s = tempStorage('at');
$friends = new FriendStore($s);
$store   = new GraffitiStore($s);
$friendId = seed($friends, $store, limit: 5, countWithinWindow: 5, extraOlderThanWindow: 0);
$rl = new RateLimiter($friends, $store);
$r = $rl->check($friendId);
assertEq(false, $r['ok'], 'ok=false at limit');
assertTrue($r['retry_after'] > 0, 'retry_after > 0');
assertTrue($r['retry_after'] <= 86400, 'retry_after ≤ 86400');

// ---------------------------------------------------------------------------
section('Hidden rows still count');

$s = tempStorage('hidden');
$friends = new FriendStore($s);
$store   = new GraffitiStore($s);
$friendId = seed($friends, $store, limit: 5, countWithinWindow: 5, extraOlderThanWindow: 0);
// Hide all 5
foreach ($store->all() as $row) {
    $store->update((string) $row['id'], ['hidden' => true]);
}
$rl = new RateLimiter($friends, $store);
$r = $rl->check($friendId);
assertEq(false, $r['ok'], 'hidden rows count toward limit');
assertEq(5, $r['count'], 'count=5 even though all hidden');

// ---------------------------------------------------------------------------
section('Sliding window: rows older than 24h excluded');

$s = tempStorage('window');
$friends = new FriendStore($s);
$store   = new GraffitiStore($s);
$friendId = seed($friends, $store, limit: 5, countWithinWindow: 2, extraOlderThanWindow: 10);
$rl = new RateLimiter($friends, $store);
$r = $rl->check($friendId);
assertEq(2, $r['count'], 'old rows (25h ago) excluded; only 2 in window');
assertEq(true, $r['ok'], 'ok=true under limit despite total of 12 rows');

// ---------------------------------------------------------------------------
section('Per-friend override respected');

$s = tempStorage('override');
$friends = new FriendStore($s);
$store   = new GraffitiStore($s);
$friendId = seed($friends, $store, limit: 2, countWithinWindow: 2, extraOlderThanWindow: 0);
$rl = new RateLimiter($friends, $store);
$r = $rl->check($friendId);
assertEq(false, $r['ok'], 'limit=2 reached at 2 rows');

// ---------------------------------------------------------------------------
section('rate_limit_per_day = 0 → first inbound rejected (soft block)');

$s = tempStorage('zero');
$friends = new FriendStore($s);
$store   = new GraffitiStore($s);
$friendId = seed($friends, $store, limit: 0, countWithinWindow: 0, extraOlderThanWindow: 0);
$rl = new RateLimiter($friends, $store);
$r = $rl->check($friendId);
assertEq(false, $r['ok'], 'limit=0 → ok=false even with no inbound yet');
assertEq(0, $r['count'], 'count=0');

// ---------------------------------------------------------------------------
section('Inbox emits 402 + retry_after when at limit');

// Reuse the inbox fixture pattern: temp content with 1 published post,
// reusing the existing seeded friend (5/5 in window).
$tmpContent = sys_get_temp_dir() . '/graffiti-rl-inbox-' . posix_getpid() . '-' . bin2hex(random_bytes(4));
@mkdir($tmpContent . '/posts', 0o755, recursive: true);
file_put_contents($tmpContent . '/posts/2026-06-22-hello.md',
    "---\ntitle: Hello\ndate: 2026-06-22\ndraft: false\n---\n\nhi"
);

$s = tempStorage('inbox');
$friends = new FriendStore($s);
$store   = new GraffitiStore($s);
$nonces  = new NonceCache($s);
$cat     = new StickerCatalogue($s, realpath(__DIR__ . '/../plugins/graffiti'));

// Bootstrap catalogue + saturate the limit.
copy(realpath(__DIR__ . '/../plugins/graffiti') . '/content/stickers.json', $s . '/stickers.json');
$token = TokenGenerator::generate();
$friendId = $friends->create([
    'handle' => 'alice',
    'blog_url' => 'https://a.example',
    'graffiti_endpoint' => 'https://a.example/graffiti/receive',
    'incoming_token' => $token,
    'outgoing_token' => str_repeat('B', 43),
    'state' => 'active',
    'rate_limit_per_day' => 2,
]);
foreach ([1, 2] as $i) {
    $id = $store->append([
        'from_friend_id' => $friendId,
        'post_slug' => "p{$i}",
        'type' => 'sticker',
        'payload' => ['sticker_id' => 'ufo-1'],
        'nonce' => "seed-{$i}",
    ]);
    $store->update($id, ['received_at' => time() - 60]);
}

$inbox = new Inbox($friends, $store, $nonces, $cat, new PostRepository($tmpContent));
$body = [
    'from' => ['blog_url' => 'https://a.example', 'handle' => 'alice'],
    'token' => $token,
    'post_slug' => 'hello',
    'type' => 'sticker',
    'payload' => ['sticker_id' => 'ufo-1'],
    'nonce' => 'fresh-' . bin2hex(random_bytes(4)),
    'client_version' => '0.1.0',
];
[$status, $resp] = $inbox->process((string) json_encode($body), true);
assertEq(402, $status, 'inbox returns 402 when limit hit');
assertEq('rate_limit_exceeded', $resp['reason'] ?? null, 'reason=rate_limit_exceeded');
assertTrue(is_int($resp['retry_after'] ?? null) && $resp['retry_after'] > 0,
    'response includes positive integer retry_after');

// Cleanup temp content
function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        $p = "{$dir}/{$e}";
        is_dir($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}
rrmdir($tmpContent);

// ---------------------------------------------------------------------------
echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures}\n");
    exit(1);
}
echo "ALL OK\n";
