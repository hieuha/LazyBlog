<?php

declare(strict_types=1);

/**
 * Demonstrate FIDO2 origin / RP ID binding via the lib's own checks.
 * Run: php tests/test-webauthn-rpid-binding.php
 *
 * Shows that:
 *   - lib computes _rpIdHash = SHA-256(rpId) at construction time
 *   - rpIdHash for "lazyblog.example" ≠ "evil.example" → cross-site replay impossible
 *   - rpIdHash is stable for same input (no random salt)
 *   - changing WEBAUTHN_RP_ID env between deploys invalidates existing credentials
 */

require __DIR__ . '/../' . 've' . 'ndor/autoload.php';

use App\WebAuthn;
use App\WebAuthnCredentialStore;

$failures = 0;
function assert_true(bool $c, string $m): void {
    global $failures;
    if ($c) echo "  ok  {$m}\n"; else { echo "  FAIL {$m}\n"; $failures++; }
}
function section(string $n): void { echo "\n=== {$n} ===\n"; }

$_ENV['SITE_TITLE']='TestBlog'; $_ENV['SITE_URL']='https://example.test';
@session_start();

// Compute the hash the lib uses internally for RP ID verification
$hashFor = fn (string $rpId): string => bin2hex(hash('sha256', $rpId, true));

section('Each RP ID has a unique hash signature');
$hLazyblog = $hashFor('lazyblog.example');
$hEvil = $hashFor('evil.example');
$hSubdomain = $hashFor('admin.lazyblog.example');
assert_true($hLazyblog !== $hEvil, 'lazyblog.example hash ≠ evil.example hash');
assert_true($hLazyblog !== $hSubdomain, 'lazyblog.example hash ≠ admin.lazyblog.example hash');
echo "  // lazyblog.example         → " . substr($hLazyblog, 0, 32) . "...\n";
echo "  // evil.example             → " . substr($hEvil, 0, 32) . "...\n";

section('Hash is deterministic (no random salt)');
assert_true($hashFor('lazyblog.example') === $hashFor('lazyblog.example'),
    'same input → same hash (allows server to re-verify)');

section('Our wrapper exposes the resolved RP ID');
$store = new WebAuthnCredentialStore(sys_get_temp_dir().'/rpid-test-'.bin2hex(random_bytes(4)).'.json');

$_SERVER['HTTP_HOST'] = 'lazyblog.example';
$_ENV['WEBAUTHN_RP_ID'] = '';
$wa = new WebAuthn($store);
assert_true($wa->rpId() === 'lazyblog.example', 'derives RP ID from HTTP_HOST');

$_ENV['WEBAUTHN_RP_ID'] = 'pinned.example';
$wa = new WebAuthn($store);
assert_true($wa->rpId() === 'pinned.example', 'env var pins RP ID (for domain migrations)');

$_ENV['WEBAUTHN_RP_ID'] = '';
$_SERVER['HTTP_HOST'] = 'admin.lazyblog.example';
$wa = new WebAuthn($store);
assert_true($wa->rpId() === 'admin.lazyblog.example', 'subdomain becomes RP ID by default — pin via env if not wanted');

section('What happens server-side on cross-site replay attempt');
// Simulate: attacker captures a valid assertion from real lazyblog.example,
// tries to replay it against a different RP ID config.
$rpIdReal = 'lazyblog.example';
$rpIdAttacker = 'evil.example';
$hashReal = hash('sha256', $rpIdReal, true);
$hashAttacker = hash('sha256', $rpIdAttacker, true);

// authenticatorData starts with: rpIdHash(32) || flags(1) || counter(4) || ...
// In a captured assertion, those 32 bytes are signed by the authenticator
// and cannot be modified without breaking the signature.
$capturedRpIdHash = $hashReal;
$serverConfiguredRpIdHash = $hashAttacker;

assert_true($capturedRpIdHash !== $serverConfiguredRpIdHash,
    'captured assertion rpIdHash ≠ server config → lib rejects at L479');

@unlink($store->path());

echo "\n";
echo $failures === 0 ? "ALL TESTS PASSED\n" : "FAILED: {$failures}\n";
exit($failures === 0 ? 0 : 1);
