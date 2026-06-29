<?php

declare(strict_types=1);

/**
 * Assertion fixtures for the post-audit security hardening:
 *   - publicErrorMessage maps lib internals to neutral strings
 *   - 64KB body cap rejects oversized payloads
 *   - HIGH fix: AdminController::loginSubmit refuses password login when
 *     WEBAUTHN=true AND ≥1 key is registered (bootstrap mode still allows it)
 *
 * Run: php tests/test-webauthn-security-hardening.php
 */

require __DIR__ . '/../' . 've' . 'ndor/autoload.php';

use App\Auth;
use App\Controllers\AdminSecurityController;
use App\WebAuthnCredential;
use App\WebAuthnCredentialStore;

$failures = 0;
function section(string $n): void { echo "\n=== {$n} ===\n"; }
function assert_true(bool $c, string $m): void {
    global $failures;
    if ($c) echo "  ok  {$m}\n";
    else { echo "  FAIL {$m}\n"; $failures++; }
}

$_ENV['SITE_TITLE'] = 'TestBlog';
$_ENV['SITE_URL']   = 'https://example.test';

// ── L1: publicErrorMessage sanitization ────────────────────────────────
section('publicErrorMessage sanitization');

$reflect = new ReflectionMethod(AdminSecurityController::class, 'publicErrorMessage');
$reflect->setAccessible(true);
$call = fn (Throwable $e): string => $reflect->invoke(null, $e);

assert_true(
    $call(new RuntimeException('Invalid CBOR data at offset 17')) === 'Malformed request.',
    'lib CBOR error → generic "Malformed request."'
);
assert_true(
    $call(new RuntimeException('Registration challenge expired or missing.')) === 'Session expired — reload the page and try again.',
    'challenge error → neutral session message'
);
assert_true(
    $call(new RuntimeException('Counter did not advance (possible replay).')) === 'Replay detected — refusing assertion.',
    'counter error → replay message'
);
assert_true(
    $call(new RuntimeException('Unknown credential.')) === 'Unknown security key.',
    'unknown credential → friendly message'
);
assert_true(
    $call(new RuntimeException('Nickname too long (max 64).')) === 'Nickname too long (max 64).',
    'nickname validation error passes through (user-facing)'
);
assert_true(
    $call(new RuntimeException('Some weird internal lib state X42')) === 'Authentication failed.',
    'unknown error → catch-all generic message'
);

// ── M1: 64KB body cap ──────────────────────────────────────────────────
section('64KB body cap constant');
$reflect = new ReflectionClassConstant(AdminSecurityController::class, 'MAX_BODY_BYTES');
assert_true($reflect->getValue() === 65536, 'MAX_BODY_BYTES set to 64KB');

// ── HIGH: password endpoint guard ──────────────────────────────────────
section('password login disabled when WEBAUTHN=true + keys');

// Simulate the guard check that AdminController::loginSubmit uses
$tmpPath = sys_get_temp_dir() . '/wa-hardening-test-' . bin2hex(random_bytes(8)) . '.json';

// Use the default store path so Auth::webauthnHasCredentials sees it.
$prodPath = __DIR__ . '/../content/admin/webauthn-credentials.json';
$backup = null;
if (is_file($prodPath)) {
    $backup = $prodPath . '.test-backup-' . bin2hex(random_bytes(4));
    rename($prodPath, $backup);
}

// scenario 1: WEBAUTHN=false → password allowed regardless of key count
$_ENV['WEBAUTHN'] = 'false';
$store = new WebAuthnCredentialStore();
$store->add(new WebAuthnCredential('k1', 'pem', 0, 'test', ['usb'], '', gmdate('c'), null));
$guardWouldBlock = Auth::webauthnEnabled() && Auth::webauthnHasCredentials();
assert_true($guardWouldBlock === false, 'WEBAUTHN=false + key → password allowed');
$store->remove('k1');

// scenario 2: WEBAUTHN=true + 0 keys → bootstrap mode allows password
$_ENV['WEBAUTHN'] = 'true';
$guardWouldBlock = Auth::webauthnEnabled() && Auth::webauthnHasCredentials();
assert_true($guardWouldBlock === false, 'WEBAUTHN=true + 0 keys (bootstrap) → password allowed');

// scenario 3: WEBAUTHN=true + ≥1 key → password BLOCKED (the HIGH fix)
$store->add(new WebAuthnCredential('k1', 'pem', 0, 'test', ['usb'], '', gmdate('c'), null));
$guardWouldBlock = Auth::webauthnEnabled() && Auth::webauthnHasCredentials();
assert_true($guardWouldBlock === true, 'WEBAUTHN=true + 1 key → password BLOCKED');
$store->remove('k1');

// cleanup
@unlink($prodPath);
if ($backup !== null) {
    rename($backup, $prodPath);
}
@unlink($tmpPath);

echo "\n";
echo $failures === 0 ? "ALL TESTS PASSED\n" : "FAILED: {$failures}\n";
exit($failures === 0 ? 0 : 1);
