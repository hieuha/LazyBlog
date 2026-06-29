<?php

declare(strict_types=1);

/**
 * Assertion fixtures for /admin/login state-machine branching:
 *   - WEBAUTHN=false              → password view, no bootstrap hint
 *   - WEBAUTHN=true + 0 keys      → password view + bootstrap hint
 *   - WEBAUTHN=true + ≥1 key      → login-webauthn view
 *
 * Run: php tests/test-login-branching.php
 */

require __DIR__ . '/../' . 've' . 'ndor/autoload.php';

use App\Auth;
use App\WebAuthnCredential;
use App\WebAuthnCredentialStore;

$failures = 0;
function assert_true(bool $c, string $m): void {
    global $failures;
    if ($c) echo "  ok  {$m}\n";
    else { echo "  FAIL {$m}\n"; $failures++; }
}
function section(string $n): void { echo "\n=== {$n} ===\n"; }

$_ENV['SITE_TITLE'] = 'TestBlog';
$_ENV['SITE_URL'] = 'https://example.test';

// Render the login view in isolation by capturing output.
function renderLogin(): string
{
    ob_start();
    $title = 'Admin';
    $next = '/admin';
    $error = null;
    include __DIR__ . '/../views/admin/login.php';
    return (string) ob_get_clean();
}

// Swap real store with an empty/seeded path via a global temp redirect.
// Since Auth::webauthnKeyCount() reads the default store path, we need to
// neutralise the prod credentials file by pointing the project's content
// admin dir at a temp dir we control for this test.
$origCwd = getcwd();
$tmpProject = sys_get_temp_dir() . '/lazyblog-login-test-' . bin2hex(random_bytes(8));
mkdir($tmpProject . '/content/admin', 0755, true);
mkdir($tmpProject . '/src');

// We don't actually need to fork the project — Auth::webauthnKeyCount uses
// WebAuthnCredentialStore() with a relative __DIR__-based default. The
// prod credentials file may or may not exist at that path. To make this
// deterministic, we just delete it for the duration of the test.
$prodPath = __DIR__ . '/../content/admin/webauthn-credentials.json';
$backup = null;
if (is_file($prodPath)) {
    $backup = $prodPath . '.test-backup';
    rename($prodPath, $backup);
}

// ----- WEBAUTHN=false -----
section('WEBAUTHN=false');
$_ENV['WEBAUTHN'] = 'false';
$html = renderLogin();
assert_true(str_contains($html, 'type="password"'), 'renders password input');
assert_true(!str_contains($html, 'webauthn-tap'), 'no WebAuthn tap button');
assert_true(!str_contains($html, 'Bootstrap mode'), 'no bootstrap hint');

// ----- WEBAUTHN=true + 0 keys (bootstrap fallback) -----
section('WEBAUTHN=true + 0 keys → bootstrap');
$_ENV['WEBAUTHN'] = 'true';
@unlink($prodPath);  // ensure 0 keys
$html = renderLogin();
assert_true(str_contains($html, 'type="password"'), 'still renders password input');
assert_true(str_contains($html, 'Bootstrap mode'), 'shows bootstrap hint');
assert_true(!str_contains($html, 'webauthn-tap'), 'no WebAuthn button yet');

// ----- WEBAUTHN=true + 1 key -----
section('WEBAUTHN=true + 1 key → tap-key view');
$store = new WebAuthnCredentialStore();
$store->add(new WebAuthnCredential(
    id: 'demo-key',
    publicKey: 'pem',
    counter: 0,
    name: 'Yubikey primary',
    transports: ['usb'],
    aaguid: '',
    createdAt: gmdate('c'),
    lastUsedAt: null,
));
$html = renderLogin();
assert_true(str_contains($html, 'webauthn-tap'), 'tap button present');
assert_true(str_contains($html, 'fa-fingerprint'), 'fingerprint icon present');
assert_true(!str_contains($html, 'type="password"'), 'password input gone');

// cleanup
@unlink($prodPath);
if ($backup !== null) {
    rename($backup, $prodPath);
}

echo "\n";
echo $failures === 0 ? "ALL TESTS PASSED\n" : "FAILED: {$failures}\n";
exit($failures === 0 ? 0 : 1);
