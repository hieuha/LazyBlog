<?php

declare(strict_types=1);

/**
 * Graffiti plugin — Phase 6: Outbox + retry queue.
 *
 * Run: php tests/test-graffiti-outbox.php
 *
 * Uses an injectable HttpSender callable so tests drive deterministic
 * responses (200, 5xx, 4xx, transport_failed) without real network IO.
 *
 * Covers:
 *   - Enqueue immediately attempts; 200 → status=sent, attempts=1
 *   - Network failure → status=failed_retry, next_retry_at scheduled
 *   - 5 failures (or attempts >= MAX_ATTEMPTS) → status=failed_permanent
 *   - 4xx non-rate-limit → failed_permanent (no retry)
 *   - 402 rate_limited → failed_retry (target may free up)
 *   - processBatch respects maxItems cap + skips terminal rows + skips
 *     future-scheduled retries
 *   - Backoff schedule monotonically increasing
 *   - Revoked friend → failed_permanent
 *
 * Exits non-zero on any failure; prints `ALL OK` on full pass.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../plugins/graffiti/src/TokenGenerator.php';
require __DIR__ . '/../plugins/graffiti/src/InviteCodec.php';
require __DIR__ . '/../plugins/graffiti/src/FriendStore.php';
require __DIR__ . '/../plugins/graffiti/src/HttpSender.php';
require __DIR__ . '/../plugins/graffiti/src/Outbox.php';

use Plugins\Graffiti\FriendStore;
use Plugins\Graffiti\Outbox;
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
    $p = sys_get_temp_dir() . "/graffiti-outbox-{$label}-" . posix_getpid() . '-' . bin2hex(random_bytes(4));
    @mkdir($p, 0o755, recursive: true);
    return $p;
}

function makeFriend(FriendStore $friends, string $blog = 'https://b.example', string $state = 'active'): string
{
    return $friends->create([
        'handle' => 'bob',
        'blog_url' => $blog,
        'graffiti_endpoint' => $blog . '/graffiti/receive',
        'incoming_token' => str_repeat('A', 43),
        'outgoing_token' => TokenGenerator::generate(),
        'state' => $state,
    ]);
}

function sender(int $status, bool $transport = false): callable
{
    return static fn (string $url, array $body): array => [
        'status' => $status,
        'body' => '',
        'error' => $transport ? 'simulated_transport_failure' : null,
        'transport_failed' => $transport,
    ];
}

$payload = ['from' => ['blog_url' => 'a', 'handle' => 'a'], 'token' => 'x', 'post_slug' => 'p',
            'type' => 'sticker', 'payload' => ['sticker_id' => 'ufo-1'], 'nonce' => 'n', 'client_version' => '0.1.0'];

// ---------------------------------------------------------------------------
section('Happy path — 200 → status=sent');

$s = tempStorage('happy'); $friends = new FriendStore($s);
$fid = makeFriend($friends);
$outbox = new Outbox($friends, $s, sender(200));
$id = $outbox->enqueue($fid, $payload);
$row = $outbox->find($id);
assertEq(Outbox::STATUS_SENT, $row['status'], '200 → sent');
assertEq(1, (int) $row['attempts'], 'attempts=1');
assertTrue((int) $row['completed_at'] > 0, 'completed_at populated');

// ---------------------------------------------------------------------------
section('Transport failure → failed_retry with backoff');

$s = tempStorage('transport'); $friends = new FriendStore($s);
$fid = makeFriend($friends);
$outbox = new Outbox($friends, $s, sender(0, transport: true));
$id = $outbox->enqueue($fid, $payload);
$row = $outbox->find($id);
assertEq(Outbox::STATUS_FAILED_RETRY, $row['status'], 'transport fail → failed_retry');
assertEq(1, (int) $row['attempts'], 'attempts=1');
assertTrue((int) $row['next_retry_at'] > time(), 'next_retry_at in future');
assertEq(time() + Outbox::BACKOFF_SECONDS[0], (int) $row['next_retry_at'], 'first backoff slot used');

// ---------------------------------------------------------------------------
section('5 attempts exhausted → failed_permanent');

$s = tempStorage('exhaust'); $friends = new FriendStore($s);
$fid = makeFriend($friends);
$outbox = new Outbox($friends, $s, sender(503));
$id = $outbox->enqueue($fid, $payload);
for ($i = 0; $i < 4; $i++) {
    $outbox->attempt($id);
}
$row = $outbox->find($id);
assertEq(Outbox::STATUS_FAILED_PERM, $row['status'], 'attempts cap reached → failed_permanent');
assertEq(Outbox::MAX_ATTEMPTS, (int) $row['attempts'], 'attempts = MAX_ATTEMPTS');

// ---------------------------------------------------------------------------
section('Non-retryable 4xx → failed_permanent immediately');

foreach ([400, 403, 404, 409, 422] as $code) {
    $s = tempStorage("perm{$code}"); $friends = new FriendStore($s);
    $fid = makeFriend($friends);
    $outbox = new Outbox($friends, $s, sender($code));
    $id = $outbox->enqueue($fid, $payload);
    $row = $outbox->find($id);
    assertEq(Outbox::STATUS_FAILED_PERM, $row['status'], "{$code} → failed_permanent (no retry)");
    assertEq(1, (int) $row['attempts'], "{$code}: only 1 attempt");
}

// ---------------------------------------------------------------------------
section('402 rate_limited → failed_retry');

$s = tempStorage('402'); $friends = new FriendStore($s);
$fid = makeFriend($friends);
$outbox = new Outbox($friends, $s, sender(402));
$id = $outbox->enqueue($fid, $payload);
$row = $outbox->find($id);
assertEq(Outbox::STATUS_FAILED_RETRY, $row['status'], '402 → failed_retry (target may free up)');

// ---------------------------------------------------------------------------
section('Revoked friend → failed_permanent (no HTTP attempt)');

$s = tempStorage('revoked'); $friends = new FriendStore($s);
$fid = makeFriend($friends, state: 'revoked');
$callCount = 0;
$noisy = static function (string $u, array $b) use (&$callCount): array {
    $callCount++;
    return ['status' => 200, 'body' => '', 'error' => null, 'transport_failed' => false];
};
$outbox = new Outbox($friends, $s, $noisy);
$id = $outbox->enqueue($fid, $payload);
$row = $outbox->find($id);
assertEq(Outbox::STATUS_FAILED_PERM, $row['status'], 'revoked friend → failed_permanent');
assertEq(0, $callCount, 'no HTTP attempt made for revoked friend');

// ---------------------------------------------------------------------------
section('processBatch — cap, terminal skip, future-retry skip');

$s = tempStorage('batch'); $friends = new FriendStore($s);
$fid = makeFriend($friends);
$callCount = 0;
$counting = static function (string $u, array $b) use (&$callCount): array {
    $callCount++;
    return ['status' => 503, 'body' => '', 'error' => null, 'transport_failed' => false]; // retryable
};
$outbox = new Outbox($friends, $s, $counting);

// 5 pending rows; enqueue immediately attempts each (counted as 5 calls).
$ids = [];
for ($i = 0; $i < 5; $i++) {
    $ids[] = $outbox->enqueue($fid, $payload + ['n' => $i]);
}
$initialAttempts = $callCount;
assertEq(5, $initialAttempts, 'enqueue attempted 5 times');

// Force all 5 to be "due" again (override next_retry_at to past).
foreach ($ids as $id) {
    $outbox->update($id, ['next_retry_at' => time() - 10]);
}
$callCount = 0;
$processed = $outbox->processBatch(3);
assertEq(3, $processed, 'processBatch capped at 3');
assertEq(3, $callCount, 'only 3 HTTP calls made');

// One row marked sent should be skipped on next batch.
$outbox->update($ids[0], ['status' => Outbox::STATUS_SENT, 'next_retry_at' => 0]);
// Push remaining due rows again.
foreach (array_slice($ids, 1) as $id) {
    $outbox->update($id, ['next_retry_at' => time() - 10]);
}
$callCount = 0;
$processed = $outbox->processBatch(10);
// We have 4 retryable (status=failed_retry) rows now. processBatch attempts
// up to maxItems; sent row is excluded.
$retryableNow = 0;
foreach ($outbox->all() as $r) {
    if (in_array($r['status'], [Outbox::STATUS_FAILED_RETRY, Outbox::STATUS_PENDING], true)
        && (int) $r['next_retry_at'] <= time()) {
        $retryableNow++;
    }
}
// retryableNow reflects pre-processing snapshot we set above
assertTrue($processed >= 1 && $processed <= 4, "batch handled between 1 and 4 rows (got {$processed})");

// Future-scheduled row should be skipped.
foreach ($ids as $id) {
    $outbox->update($id, ['next_retry_at' => time() + 9999, 'status' => Outbox::STATUS_FAILED_RETRY]);
}
$callCount = 0;
$processed = $outbox->processBatch(10);
assertEq(0, $processed, 'all future-scheduled → batch processes 0');
assertEq(0, $callCount, 'no HTTP calls for future rows');

// ---------------------------------------------------------------------------
section('Backoff schedule monotonically increasing');

$prev = -1;
foreach (Outbox::BACKOFF_SECONDS as $i => $sec) {
    assertTrue($sec > $prev, "BACKOFF[{$i}]={$sec} > prev={$prev}");
    $prev = $sec;
}

// ---------------------------------------------------------------------------
echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures}\n");
    exit(1);
}
echo "ALL OK\n";
