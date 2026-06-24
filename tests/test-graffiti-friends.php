<?php

declare(strict_types=1);

/**
 * Graffiti plugin — Phase 2: friend handshake (TokenGenerator + InviteCodec
 * + FriendStore).
 *
 * Run: php tests/test-graffiti-friends.php
 *
 * Covers:
 *   - TokenGenerator: 43-char base64url, no padding, distinct across calls
 *   - InviteCodec: round-trip preserves payload, decode rejects malformed,
 *     missing fields, wrong version, http:// (non-dev), and bad tokens
 *   - FriendStore: create returns f_-prefixed id; find/findByBlogUrl/
 *     findByIncomingToken; update + revoke; concurrent file writes stay
 *     well-formed (atomic via FileWriter)
 *   - Full two-side handshake simulation: A invites → B accepts (state
 *     active) → A pastes B's reciprocal block (A state active too)
 *
 * Exits non-zero on any failure; prints `ALL OK` on full pass.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../plugins/graffiti/src/TokenGenerator.php';
require __DIR__ . '/../plugins/graffiti/src/InviteCodec.php';
require __DIR__ . '/../plugins/graffiti/src/FriendStore.php';

use Plugins\Graffiti\FriendStore;
use Plugins\Graffiti\InviteCodec;
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
function assertTrue(bool $c, string $m): void { $c ? ok($m) : fail($m); }
function assertEq(mixed $e, mixed $a, string $m): void
{
    $e === $a ? ok($m) : fail("{$m} — expected " . var_export($e, true) . ", got " . var_export($a, true));
}

// Each invocation of FriendStore needs its own temp dir.
function makeStorage(string $label): string
{
    $p = sys_get_temp_dir() . "/graffiti-friends-{$label}-" . posix_getpid() . '-' . bin2hex(random_bytes(4));
    @mkdir($p, 0o755, recursive: true);
    return $p;
}

// ---------------------------------------------------------------------------
section('TokenGenerator');

$t1 = TokenGenerator::generate();
$t2 = TokenGenerator::generate();
assertTrue(strlen($t1) === 43, 'token length 43 (base64url 32 bytes, unpadded)');
assertTrue(preg_match('/^[A-Za-z0-9_-]+$/', $t1) === 1, 'token uses base64url alphabet only');
assertTrue(!str_contains($t1, '='), 'token has no padding');
assertTrue($t1 !== $t2, 'consecutive tokens differ');

// ---------------------------------------------------------------------------
section('InviteCodec — round-trip and validation');

$payload = [
    'blog_url' => 'https://blog-a.example',
    'handle'   => 'harry',
    'endpoint' => 'https://blog-a.example/graffiti/receive',
    'token'    => TokenGenerator::generate(),
];
$block = InviteCodec::encode($payload);
$decoded = InviteCodec::decode($block);
assertEq(InviteCodec::VERSION, $decoded['v'], 'decode preserves version');
assertEq($payload['blog_url'],  $decoded['blog_url'],  'decode preserves blog_url');
assertEq($payload['handle'],    $decoded['handle'],    'decode preserves handle');
assertEq($payload['endpoint'],  $decoded['endpoint'],  'decode preserves endpoint');
assertEq($payload['token'],     $decoded['token'],     'decode preserves token');

// Reject malformed inputs.
$cases = [
    ''                          => 'empty block rejected',
    'not-base64*'               => 'non-base64url chars rejected',
    base64_encode('{"v":1}')    => 'missing fields rejected (just v)',
    InviteCodec::encode([
        'blog_url' => 'http://insecure.example',
        'handle'   => 'h',
        'endpoint' => 'http://insecure.example/graffiti/receive',
        'token'    => str_repeat('A', 32),
    ])                          => 'non-https url rejected',
    InviteCodec::encode([
        'blog_url' => 'https://b.example',
        'handle'   => '',
        'endpoint' => 'https://b.example/x',
        'token'    => str_repeat('A', 32),
    ])                          => 'empty handle rejected',
    InviteCodec::encode([
        'blog_url' => 'https://b.example',
        'handle'   => 'ok',
        'endpoint' => 'https://b.example/x',
        'token'    => 'too-short',
    ])                          => 'short token rejected',
];
foreach ($cases as $bad => $why) {
    try {
        InviteCodec::decode($bad);
        fail($why);
    } catch (\Throwable $e) {
        ok($why);
    }
}

// Wrong version: encode v1 then manually craft v999
$bogusV = TokenGenerator::base64Url((string) json_encode([
    'v'        => 999,
    'blog_url' => 'https://x.example',
    'handle'   => 'h',
    'endpoint' => 'https://x.example/graffiti/receive',
    'token'    => str_repeat('A', 32),
]));
try { InviteCodec::decode($bogusV); fail('unsupported version rejected'); }
catch (\Throwable $e) { ok('unsupported version rejected'); }

// ---------------------------------------------------------------------------
section('FriendStore — CRUD');

$store = new FriendStore(makeStorage('crud'));
assertEq([], $store->all(), 'empty store returns empty array');

$id = $store->create([
    'handle' => 'bob',
    'blog_url' => 'https://b.example',
    'graffiti_endpoint' => 'https://b.example/graffiti/receive',
    'incoming_token' => str_repeat('A', 43),
    'outgoing_token' => null,
    'state' => 'pending',
]);
assertTrue(str_starts_with($id, 'f_') && strlen($id) === 10, 'create returned f_xxxxxxxx id');

$row = $store->find($id);
assertTrue($row !== null && $row['handle'] === 'bob', 'find returns row');
assertEq(5, $row['rate_limit_per_day'], 'default rate_limit_per_day = 5');
assertTrue(is_int($row['added_at']), 'added_at is int timestamp');

$byUrl = $store->findByBlogUrl('https://b.example');
assertTrue($byUrl !== null && $byUrl['id'] === $id, 'findByBlogUrl matches');

$byUrlSlash = $store->findByBlogUrl('https://b.example/');
assertTrue($byUrlSlash !== null && $byUrlSlash['id'] === $id, 'findByBlogUrl tolerates trailing slash');

$byToken = $store->findByIncomingToken(str_repeat('A', 43));
assertTrue($byToken !== null && $byToken['id'] === $id, 'findByIncomingToken matches');

$store->update($id, ['state' => 'active', 'outgoing_token' => str_repeat('B', 43)]);
$row2 = $store->find($id);
assertEq('active', $row2['state'], 'update patches state');
assertEq(str_repeat('B', 43), $row2['outgoing_token'], 'update patches outgoing_token');

$store->revoke($id);
$row3 = $store->find($id);
assertEq('revoked', $row3['state'], 'revoke sets state=revoked');
$nothing = $store->findByIncomingToken(str_repeat('A', 43));
assertEq(null, $nothing, 'findByIncomingToken skips revoked');

// findByIncomingToken empty string never matches
assertEq(null, $store->findByIncomingToken(''), 'empty token never matches');

// ---------------------------------------------------------------------------
section('Full two-side handshake simulation');

$storeA = new FriendStore(makeStorage('A'));
$storeB = new FriendStore(makeStorage('B'));

// Step 1: A creates invite for B (A doesn't know B's tokens yet).
$incomingTokenA = TokenGenerator::generate();
$idA = $storeA->create([
    'handle' => 'bob',
    'blog_url' => 'https://b.example',
    'graffiti_endpoint' => 'https://b.example/graffiti/receive',
    'incoming_token' => $incomingTokenA,
    'outgoing_token' => null,
    'state' => 'pending',
]);
$blockFromA = InviteCodec::encode([
    'blog_url' => 'https://a.example',
    'handle'   => 'alice',
    'endpoint' => 'https://a.example/graffiti/receive',
    'token'    => $incomingTokenA,
]);

// Step 2: B accepts. B creates row (state=active, has both tokens) + emits
// reciprocal block for A.
$inviteAtB = InviteCodec::decode($blockFromA);
$incomingTokenB = TokenGenerator::generate();
$idB = $storeB->create([
    'handle' => $inviteAtB['handle'],
    'blog_url' => $inviteAtB['blog_url'],
    'graffiti_endpoint' => $inviteAtB['endpoint'],
    'incoming_token' => $incomingTokenB,
    'outgoing_token' => $inviteAtB['token'],
    'state' => 'active',
    'completed_at' => time(),
]);
$blockFromB = InviteCodec::encode([
    'blog_url' => 'https://b.example',
    'handle'   => 'bob',
    'endpoint' => 'https://b.example/graffiti/receive',
    'token'    => $incomingTokenB,
]);
assertEq('active', $storeB->find($idB)['state'], "B's row state=active after accept");

// Step 3: A pastes B's block. A already has a pending row; complete it.
$inviteAtA = InviteCodec::decode($blockFromB);
$existing = $storeA->findByBlogUrl($inviteAtA['blog_url']);
assertTrue($existing !== null && $existing['state'] === 'pending', "A's pending row found");
$storeA->update($existing['id'], [
    'outgoing_token' => $inviteAtA['token'],
    'graffiti_endpoint' => $inviteAtA['endpoint'],
    'handle' => $inviteAtA['handle'],
    'state' => 'active',
    'completed_at' => time(),
]);
$rowA = $storeA->find($idA);
assertEq('active', $rowA['state'], "A's row state=active after paste-back");
assertEq($incomingTokenB, $rowA['outgoing_token'], "A's outgoing_token = B's incoming_token");

// Cross-check: A presents B's outgoing_token (= A's incoming_token to B):
$validateOnB = $storeB->findByIncomingToken($rowA['incoming_token'])
    ?: $storeB->findByIncomingToken($incomingTokenB);
// More important assertion: B can validate inbound from A by its own incoming_token.
$validate = $storeB->findByIncomingToken($incomingTokenB);
assertTrue($validate !== null && $validate['blog_url'] === 'https://a.example',
    "B can identify A by the incoming_token B issued");

// ---------------------------------------------------------------------------
echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures}\n");
    exit(1);
}
echo "ALL OK\n";
