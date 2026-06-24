<?php

declare(strict_types=1);

/**
 * Graffiti plugin — Phase 4: Inbox webhook (`POST /graffiti/receive`).
 *
 * Run: php tests/test-graffiti-inbox.php
 *
 * Tests Inbox::process() directly (the pure-logic core). handle() is the
 * thin IO shell around it; testing through process() keeps the test off
 * php://input and HTTP response globals.
 *
 * Covered rejection paths:
 *   400 empty/malformed body, missing top-level fields, body too large
 *   403 https_required (when not GRAFFITI_DEV), invalid_token, blog_url_mismatch
 *   404 post_not_found
 *   409 replay nonce
 *   422 invalid_payload, sticker_disabled, invalid_type
 *   200 valid sticker stored + nonce recorded
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

// ---------------------------------------------------------------------------
// Fixture: temp content dir with 1 published post, temp plugin storage with
// one active friend, shipped sticker catalogue.

$tmpRoot     = sys_get_temp_dir() . '/graffiti-inbox-' . posix_getpid() . '-' . bin2hex(random_bytes(4));
$contentRoot = $tmpRoot . '/content';
$storage     = $tmpRoot . '/plugins/graffiti';
$postsDir    = $contentRoot . '/posts';
@mkdir($postsDir, 0o755, recursive: true);
@mkdir($storage, 0o755, recursive: true);

register_shutdown_function(static function () use ($tmpRoot): void {
    if (!is_dir($tmpRoot)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $e) {
        $e->isDir() ? @rmdir($e->getPathname()) : @unlink($e->getPathname());
    }
    @rmdir($tmpRoot);
});

// Published post (slug stored = "hello").
file_put_contents($postsDir . '/2026-06-22-hello.md',
    "---\ntitle: Hello\ndate: 2026-06-22\ndraft: false\n---\n\nhi"
);

// Copy shipped catalogue into storage so StickerCatalogue resolves it.
$pluginRoot = realpath(__DIR__ . '/../plugins/graffiti');
copy($pluginRoot . '/content/stickers.json', $storage . '/stickers.json');

$friends = new FriendStore($storage);
$store   = new GraffitiStore($storage);
$nonces  = new NonceCache($storage);
$cat     = new StickerCatalogue($storage, $pluginRoot);
$repo    = new PostRepository($contentRoot);

// Two friends: an active one A and a revoked one R.
$incomingA = TokenGenerator::generate();
$idA = $friends->create([
    'handle' => 'alice',
    'blog_url' => 'https://a.example',
    'graffiti_endpoint' => 'https://a.example/graffiti/receive',
    'incoming_token' => $incomingA,
    'outgoing_token' => str_repeat('Z', 43),
    'state' => 'active',
]);
$incomingR = TokenGenerator::generate();
$idR = $friends->create([
    'handle' => 'mallory',
    'blog_url' => 'https://r.example',
    'graffiti_endpoint' => 'https://r.example/graffiti/receive',
    'incoming_token' => $incomingR,
    'outgoing_token' => str_repeat('Y', 43),
    'state' => 'revoked',
]);

$inbox = new Inbox($friends, $store, $nonces, $cat, $repo);

// Helper: build a baseline valid sticker payload and apply overrides.
function payload(array $overrides = []): array
{
    global $incomingA;
    $base = [
        'from' => ['blog_url' => 'https://a.example', 'handle' => 'alice'],
        'token' => $incomingA,
        'post_slug' => 'hello',
        'type' => 'sticker',
        'payload' => ['sticker_id' => 'ufo-1'],
        'nonce' => bin2hex(random_bytes(8)),
        'client_version' => '0.1.0',
    ];
    return array_replace_recursive($base, $overrides);
}

function call(Inbox $inbox, array $body, bool $isHttps = true): array
{
    return $inbox->process((string) json_encode($body), $isHttps);
}

// ---------------------------------------------------------------------------
section('Happy path');
[$s, $b] = call($inbox, payload());
assertEq(200, $s, 'valid sticker → 200');
assertEq('accepted', $b['status'] ?? null, 'response status=accepted');
assertTrue(isset($b['id']) && str_starts_with((string) $b['id'], 'g_'), 'response includes g_xxx id');

// Stored row visible in GraffitiStore.
$rows = $store->all();
assertEq(1, count($rows), 'graffiti.json now has 1 row');
assertEq($idA, $rows[0]['from_friend_id'] ?? null, 'row attributed to friend A');
assertEq('hello', $rows[0]['post_slug'] ?? null, 'post_slug stored');

// ---------------------------------------------------------------------------
section('Token / blog_url / state failures');
[$s] = call($inbox, payload(['token' => 'totally-bogus-token']));
assertEq(403, $s, 'unknown token → 403');

[$s] = call($inbox, payload(['token' => $incomingR]));
assertEq(403, $s, 'revoked friend → 403');

[$s] = call($inbox, payload(['from' => ['blog_url' => 'https://attacker.example', 'handle' => 'a']]));
assertEq(403, $s, 'blog_url mismatch → 403');

// ---------------------------------------------------------------------------
section('Slug + replay + payload');
[$s] = call($inbox, payload(['post_slug' => 'does-not-exist']));
assertEq(404, $s, 'unknown post_slug → 404');

// Replay the very first happy-path nonce.
$replayBody = payload(['nonce' => $rows[0]['nonce']]);
[$s] = call($inbox, $replayBody);
assertEq(409, $s, 'replay nonce → 409');

// Oversized text (>140).
[$s, $b] = call($inbox, payload([
    'type' => 'text',
    'payload' => ['text' => str_repeat('a', 200)],
]));
assertEq(422, $s, 'text >140 → 422');
assertEq('invalid_payload', $b['reason'] ?? null, '422 reason=invalid_payload');

// Unknown sticker.
[$s, $b] = call($inbox, payload(['payload' => ['sticker_id' => 'nonexistent']]));
assertEq(422, $s, 'unknown sticker_id → 422');

// Invalid type.
[$s, $b] = call($inbox, payload(['type' => 'weird-type', 'payload' => ['sticker_id' => 'ufo-1']]));
assertEq(422, $s, 'invalid type → 422');
assertEq('invalid_type', $b['reason'] ?? null, 'reason=invalid_type');

// Bad position bounds.
[$s] = call($inbox, payload([
    'payload' => ['sticker_id' => 'ufo-1', 'position' => ['x' => 2, 'y' => 0.5, 'rotation' => 0]]
]));
assertEq(422, $s, 'x out of range → 422');

// ---------------------------------------------------------------------------
section('Body shape failures');
[$s, $b] = call($inbox, [], true);
assertEq(400, $s, 'empty top-level (no fields) → 400');
assertTrue(str_starts_with((string) ($b['reason'] ?? ''), 'missing_field:'), 'reason describes missing field');

[$s] = $inbox->process('not-json-at-all', true);
assertEq(400, $s, 'unparseable body → 400');

[$s] = $inbox->process('', true);
assertEq(400, $s, 'empty body → 400');

// Oversized body (just over the cap).
$big = str_repeat('x', Inbox::MAX_BODY_BYTES + 10);
[$s] = $inbox->process($big, true);
assertEq(400, $s, 'body over MAX_BODY_BYTES → 400');

// ---------------------------------------------------------------------------
section('HTTPS gate');
[$s, $b] = call($inbox, payload(), isHttps: false);
assertEq(403, $s, 'http (non-https) without GRAFFITI_DEV → 403');
assertEq('https_required', $b['reason'] ?? null, 'reason=https_required');

$_ENV['GRAFFITI_DEV'] = '1';
[$s] = call($inbox, payload(), isHttps: false);
assertEq(200, $s, 'http accepted when GRAFFITI_DEV=1');
unset($_ENV['GRAFFITI_DEV']);

// ---------------------------------------------------------------------------
echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures}\n");
    exit(1);
}
echo "ALL OK\n";
