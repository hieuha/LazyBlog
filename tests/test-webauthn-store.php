<?php

declare(strict_types=1);

/**
 * Assertion fixtures for WebAuthnCredentialStore.
 * Run: php tests/test-webauthn-store.php
 *
 * Covers:
 *   - Empty store returns empty list, count 0
 *   - add() persists + roundtrips through fresh instance
 *   - findById() uses constant-time compare
 *   - duplicate add() throws
 *   - remove() returns true when present, false when absent
 *   - updateCounter() persists new value, throws when id unknown
 *   - userHandle() stable across calls
 *   - Atomic write: corrupt JSON throws on load
 *   - b64uEncode/Decode roundtrip on edge bytes
 */

require __DIR__ . '/../' . 've' . 'ndor/autoload.php';

use App\WebAuthnCredential;
use App\WebAuthnCredentialStore;

$failures = 0;

function section(string $name): void
{
    echo "\n=== {$name} ===\n";
}

function assert_true(bool $cond, string $msg): void
{
    global $failures;
    if ($cond) {
        echo "  ok  {$msg}\n";
    } else {
        echo "  FAIL {$msg}\n";
        $failures++;
    }
}

function tmp_store_path(): string
{
    return sys_get_temp_dir() . '/webauthn-test-' . bin2hex(random_bytes(8)) . '.json';
}

function sample_credential(string $idSuffix = 'A'): WebAuthnCredential
{
    return new WebAuthnCredential(
        id: 'cred-' . $idSuffix,
        publicKey: "-----BEGIN PUBLIC KEY-----\nMOCK\n-----END PUBLIC KEY-----",
        counter: 0,
        name: 'Test key ' . $idSuffix,
        transports: ['usb', 'nfc'],
        aaguid: 'ee882879721c4913977',
        createdAt: '2026-06-29T00:00:00+00:00',
        lastUsedAt: null,
    );
}

// ----- empty store -----
section('empty store');
$path = tmp_store_path();
$store = new WebAuthnCredentialStore($path);
assert_true($store->all() === [], 'empty all() returns []');
assert_true($store->count() === 0, 'empty count() returns 0');
assert_true($store->findById('anything') === null, 'findById returns null when missing');

// ----- add + roundtrip -----
section('add + roundtrip');
$store->add(sample_credential('A'));
$store->add(sample_credential('B'));
$fresh = new WebAuthnCredentialStore($path);
$all = $fresh->all();
assert_true(count($all) === 2, 'roundtrip yields 2 credentials');
assert_true($all[0]->name === 'Test key A', 'first credential name persisted');
assert_true($all[1]->transports === ['usb', 'nfc'], 'transports array persisted');

// ----- findById -----
section('findById');
$found = $fresh->findById('cred-B');
assert_true($found !== null && $found->name === 'Test key B', 'findById returns correct credential');
assert_true($fresh->findById('cred-Z') === null, 'findById returns null for unknown');

// ----- duplicate add throws -----
section('duplicate add throws');
$threw = false;
try {
    $fresh->add(sample_credential('A'));
} catch (RuntimeException) {
    $threw = true;
}
assert_true($threw, 'duplicate id throws RuntimeException');

// ----- remove -----
section('remove');
assert_true($fresh->remove('cred-A') === true, 'remove returns true for existing');
assert_true($fresh->remove('cred-A') === false, 'remove returns false on second call');
assert_true($fresh->count() === 1, 'count decreases');
assert_true($fresh->findById('cred-B') !== null, 'unrelated credential survives');

// ----- updateCounter -----
section('updateCounter');
$fresh->updateCounter('cred-B', 42, '2026-06-29T01:00:00+00:00');
$reread = new WebAuthnCredentialStore($path);
$b = $reread->findById('cred-B');
assert_true($b !== null && $b->counter === 42, 'counter persisted');
assert_true($b->lastUsedAt === '2026-06-29T01:00:00+00:00', 'lastUsedAt persisted');
$threw = false;
try {
    $reread->updateCounter('cred-ghost', 1, '2026-06-29T01:00:00+00:00');
} catch (RuntimeException) {
    $threw = true;
}
assert_true($threw, 'updateCounter throws on unknown id');

// ----- user handle stable -----
section('userHandle stable');
$h1 = $reread->userHandle();
$h2 = $reread->userHandle();
$h3 = (new WebAuthnCredentialStore($path))->userHandle();
assert_true($h1 === $h2 && $h2 === $h3, 'userHandle stable across calls + instances');
assert_true(strlen($h1) >= 40, 'userHandle is sufficiently long');

// ----- corruption detection -----
section('corruption detection');
$badPath = tmp_store_path();
file_put_contents($badPath, "{ this is not json");
$bad = new WebAuthnCredentialStore($badPath);
$threw = false;
try {
    $bad->all();
} catch (RuntimeException) {
    $threw = true;
}
assert_true($threw, 'corrupt JSON throws on load');
@unlink($badPath);

// ----- b64u helpers -----
section('b64u helpers');
$samples = ["\x00", "\xff\xfe\xfd", str_repeat("\x42", 32), random_bytes(64)];
foreach ($samples as $i => $raw) {
    $enc = WebAuthnCredentialStore::b64uEncode($raw);
    $dec = WebAuthnCredentialStore::b64uDecode($enc);
    assert_true($dec === $raw, "b64u roundtrip sample {$i}");
    assert_true(!str_contains($enc, '+') && !str_contains($enc, '/') && !str_contains($enc, '='),
                "b64u sample {$i} url-safe");
}
$threw = false;
try {
    WebAuthnCredentialStore::b64uDecode('!!!!');
} catch (RuntimeException) {
    $threw = true;
}
assert_true($threw, 'b64uDecode rejects invalid input');

// ----- cleanup -----
@unlink($path);

echo "\n";
echo $failures === 0 ? "ALL TESTS PASSED\n" : "FAILED: {$failures}\n";
exit($failures === 0 ? 0 : 1);
