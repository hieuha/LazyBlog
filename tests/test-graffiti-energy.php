<?php

declare(strict_types=1);

/**
 * Graffiti plugin — Phase 3: EnergyLedger.
 *
 * Run: php tests/test-graffiti-energy.php
 *
 * Covers:
 *   - mint() is idempotent for `post:{slug}` reasons (resave never inflates)
 *   - mint() rejects non-positive amounts and empty reasons
 *   - spend() returns false on insufficient balance and writes nothing
 *   - spend() succeeds when balance is enough, decrementing it
 *   - ledger() returns most-recent first, capped
 *   - reconcile() mints any published post not already in minted_slugs
 *   - reconcile() skips drafts (anti-game: draft farming impossible)
 *   - reconcile() is no-op when ledger is fully caught up
 *
 * Exits non-zero on any failure; prints `ALL OK` on full pass.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../plugins/graffiti/src/EnergyLedger.php';

use App\PostRepository;
use Plugins\Graffiti\EnergyLedger;

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
    $p = sys_get_temp_dir() . "/graffiti-energy-{$label}-" . posix_getpid() . '-' . bin2hex(random_bytes(4));
    @mkdir($p, 0o755, recursive: true);
    return $p;
}

// ---------------------------------------------------------------------------
section('Empty ledger basics');

$l = new EnergyLedger(makeStorage('empty'));
assertEq(0, $l->balance(), 'fresh balance = 0');
assertEq([], $l->ledger(), 'fresh ledger empty');
assertTrue($l->canSpend(0), 'canSpend(0) true');
assertTrue(!$l->canSpend(1), 'canSpend(1) false when empty');

// ---------------------------------------------------------------------------
section('mint() — idempotent for post:slug reasons');

$l = new EnergyLedger(makeStorage('mint'));
$l->mint(10, 'post:2026-06-22-foo');
assertEq(10, $l->balance(), 'first mint of slug → +10');

$l->mint(10, 'post:2026-06-22-foo');
assertEq(10, $l->balance(), 'second mint of same slug → no change');

$l->mint(10, 'post:2026-06-23-bar');
assertEq(20, $l->balance(), 'mint different slug → +10');

// Non-post reasons always append (admin grants, etc.).
$l->mint(5, 'admin-grant');
assertEq(25, $l->balance(), 'non-post reasons always append');
$l->mint(5, 'admin-grant');
assertEq(30, $l->balance(), 'non-post reasons not deduped');

// Defensive rejects.
$l->mint(0, 'post:zero');
assertEq(30, $l->balance(), 'mint amount=0 rejected');
$l->mint(-5, 'post:neg');
assertEq(30, $l->balance(), 'mint amount<0 rejected');
$l->mint(5, '');
assertEq(30, $l->balance(), 'mint empty reason rejected');

// ---------------------------------------------------------------------------
section('spend() — guard insufficient + decrement on success');

$l = new EnergyLedger(makeStorage('spend'));
$l->mint(10, 'post:a');

assertEq(false, $l->spend(20, 'graffiti:o_too_big'), 'spend > balance rejected');
assertEq(10,    $l->balance(),                       'balance unchanged after failed spend');

assertEq(true,  $l->spend(3, 'graffiti:o_ok'), 'spend within balance succeeds');
assertEq(7,     $l->balance(),                 'balance decremented after spend');

assertEq(false, $l->spend(0, 'graffiti:zero'), 'spend amount=0 rejected');
assertEq(false, $l->spend(-1, 'graffiti:neg'), 'spend amount<0 rejected');
assertEq(false, $l->spend(1, ''),              'spend empty reason rejected');

// ---------------------------------------------------------------------------
section('ledger() — most-recent first');

$l = new EnergyLedger(makeStorage('ledger'));
$l->mint(10, 'post:first');
sleep(0); // tests run fast enough that ts may tie; ordering doesn't rely on ts alone
$l->mint(10, 'post:second');
$rows = $l->ledger();
assertEq(2, count($rows), 'ledger has 2 rows');
assertEq('post:second', $rows[0]['reason'], 'newest first');
assertEq('post:first',  $rows[1]['reason'], 'oldest last');

// ---------------------------------------------------------------------------
section('reconcile() — catches drift, skips drafts');

// PostRepository test fixture: point at a temp content dir with 3 posts:
// 1 published (should mint), 1 draft (should NOT), 1 published already in
// minted_slugs (should not double).
$tmpContent = sys_get_temp_dir() . '/graffiti-energy-reconcile-' . posix_getpid() . '-' . bin2hex(random_bytes(4));
$postsDir   = $tmpContent . '/posts';
@mkdir($postsDir, 0o755, recursive: true);

function writePost(string $dir, string $filename, string $title, string $date, bool $draft): void
{
    $draftStr = $draft ? 'true' : 'false';
    $contents = <<<MD
---
title: {$title}
date: {$date}
draft: {$draftStr}
---

body of {$title}
MD;
    file_put_contents("{$dir}/{$filename}", $contents);
}

// Slug in index = kebab part after the YYYY-MM-DD- prefix in filename, not
// the full filename. Pre-seed with the bare slug so the dedup actually hits.
writePost($postsDir, '2026-06-20-already-counted.md', 'Already',  '2026-06-20', false);
writePost($postsDir, '2026-06-21-fresh.md',           'Fresh',    '2026-06-21', false);
writePost($postsDir, '2026-06-22-draft.md',           'Draft',    '2026-06-22', true);

$repo = new PostRepository($tmpContent);

$l = new EnergyLedger(makeStorage('reconcile'));
$l->mint(10, 'post:already-counted'); // pre-seed → reconcile should NOT re-mint
assertEq(10, $l->balance(), 'pre-seeded balance');

$l->reconcile($repo);
assertEq(20, $l->balance(), 'reconcile minted fresh post only (+10), draft skipped, already-counted not re-minted');

// Run reconcile again: no-op
$l->reconcile($repo);
assertEq(20, $l->balance(), 'reconcile is no-op when fully caught up');

// Cleanup temp content dir
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
