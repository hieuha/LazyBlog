<?php

declare(strict_types=1);

/**
 * Assertion fixtures for App\WebAuthn (thin wrapper around lbuchs/WebAuthn).
 * Run: php tests/test-webauthn.php
 *
 * Covers what we can verify without a real authenticator:
 *   - rpId resolution priority (override > env > HTTP_HOST > localhost)
 *   - rpName resolution
 *   - beginRegister stashes challenge in session, returns publicKey opts
 *   - beginRegister rejects empty / over-long nickname
 *   - beginRegister excludeCredentials lists existing IDs
 *   - completeRegister rejects when session challenge missing/expired
 *   - completeLogin rejects when session challenge missing/expired
 *   - completeLogin rejects unknown credential id
 *   - beginLogin allowCredentials matches store
 *
 * NOTE: Full crypto round-trip (real attestation/assertion) is covered
 * by manual testing with hardware (Phase 4) — see plan.
 */

require __DIR__ . '/../' . 've' . 'ndor/autoload.php';

use App\WebAuthn;
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

// Boot env so Config::get works for SITE_TITLE etc.
$_ENV['SITE_TITLE'] = 'TestBlog';
$_ENV['SITE_URL'] = 'https://example.test';
$_ENV['SESSION_SECURE'] = 'false';

if (session_status() === PHP_SESSION_NONE) {
    // Use a custom session save path so test runs don't pollute prod sessions.
    session_save_path(sys_get_temp_dir());
    @session_start();
}

$tmpPath = sys_get_temp_dir() . '/webauthn-test-' . bin2hex(random_bytes(8)) . '.json';
$store = new WebAuthnCredentialStore($tmpPath);

// ----- rpId resolution -----
section('rpId resolution');
$_SERVER['HTTP_HOST'] = 'blog.example.test:8080';
$wa = new WebAuthn($store);
assert_true($wa->rpId() === 'blog.example.test', 'HTTP_HOST port stripped');

$wa2 = new WebAuthn($store, 'pinned.example.test');
assert_true($wa2->rpId() === 'pinned.example.test', 'constructor override wins');

$_ENV['WEBAUTHN_RP_ID'] = 'env.example.test';
$wa3 = new WebAuthn($store);
assert_true($wa3->rpId() === 'env.example.test', 'env var beats HTTP_HOST');
unset($_ENV['WEBAUTHN_RP_ID']);

unset($_SERVER['HTTP_HOST']);
$wa4 = new WebAuthn($store);
assert_true($wa4->rpId() === 'localhost', 'falls back to localhost');
$_SERVER['HTTP_HOST'] = 'blog.example.test';

// ----- rpName resolution -----
section('rpName resolution');
$wa = new WebAuthn($store);
assert_true($wa->rpName() === 'TestBlog', 'rpName from SITE_TITLE');
$wa = new WebAuthn($store, null, 'Custom');
assert_true($wa->rpName() === 'Custom', 'rpName override wins');

// ----- beginRegister -----
section('beginRegister');
$wa = new WebAuthn($store);
$opts = $wa->beginRegister('Yubikey primary');
assert_true(isset($opts['publicKey']), 'returns publicKey envelope');
$pk = (array) $opts['publicKey'];
assert_true(isset($pk['challenge']), 'publicKey carries challenge');
assert_true(isset($_SESSION['webauthn_register_challenge']), 'session challenge stashed');
$stashed = $_SESSION['webauthn_register_challenge'];
assert_true($stashed['nickname'] === 'Yubikey primary', 'nickname stashed');
assert_true($stashed['expires_at'] > time() && $stashed['expires_at'] <= time() + 61, 'TTL ~60s');

// nickname validation
$threw = false;
try { $wa->beginRegister(''); } catch (RuntimeException) { $threw = true; }
assert_true($threw, 'empty nickname rejected');
$threw = false;
try { $wa->beginRegister(str_repeat('x', 65)); } catch (RuntimeException) { $threw = true; }
assert_true($threw, 'over-long nickname rejected');

// excludeCredentials wiring
$store->add(new WebAuthnCredential(
    id: WebAuthnCredentialStore::b64uEncode("\x01\x02\x03"),
    publicKey: 'pem',
    counter: 0,
    name: 'existing',
    transports: ['usb'],
    aaguid: '',
    createdAt: '2026-06-29T00:00:00+00:00',
    lastUsedAt: null,
));
$opts = $wa->beginRegister('another');
$pk = (array) $opts['publicKey'];
assert_true(isset($pk['excludeCredentials']) && count($pk['excludeCredentials']) === 1,
            'excludeCredentials includes existing key');

// ----- completeRegister error paths -----
section('completeRegister error paths');
unset($_SESSION['webauthn_register_challenge']);
$threw = false;
try { $wa->completeRegister('{"clientDataJSON":"abc","attestationObject":"def"}'); }
catch (RuntimeException) { $threw = true; }
assert_true($threw, 'missing challenge → throws');

// Expired challenge
$_SESSION['webauthn_register_challenge'] = [
    'challenge' => base64_encode('xx'),
    'nickname' => 'n',
    'expires_at' => time() - 10,
];
$threw = false;
try { $wa->completeRegister('{"clientDataJSON":"abc","attestationObject":"def"}'); }
catch (RuntimeException) { $threw = true; }
assert_true($threw, 'expired challenge → throws');

$threw = false;
$_SESSION['webauthn_register_challenge'] = [
    'challenge' => base64_encode('xx'),
    'nickname' => 'n',
    'expires_at' => time() + 60,
];
try { $wa->completeRegister('not json'); } catch (RuntimeException) { $threw = true; }
assert_true($threw, 'malformed JSON body → throws');

// ----- beginLogin -----
section('beginLogin');
$opts = $wa->beginLogin();
$pk = (array) $opts['publicKey'];
assert_true(isset($pk['challenge']), 'login publicKey has challenge');
assert_true(isset($pk['allowCredentials']) && count($pk['allowCredentials']) === 1,
            'allowCredentials lists stored ids');
assert_true(isset($_SESSION['webauthn_login_challenge']), 'login challenge stashed');

// ----- completeLogin error paths -----
section('completeLogin error paths');
unset($_SESSION['webauthn_login_challenge']);
$threw = false;
try { $wa->completeLogin('{"id":"abc"}'); } catch (RuntimeException) { $threw = true; }
assert_true($threw, 'missing login challenge → throws');

$_SESSION['webauthn_login_challenge'] = [
    'challenge' => base64_encode('xx'),
    'expires_at' => time() + 60,
];
$threw = false;
try { $wa->completeLogin('not json'); } catch (RuntimeException) { $threw = true; }
assert_true($threw, 'malformed login body → throws');

$_SESSION['webauthn_login_challenge'] = [
    'challenge' => base64_encode('xx'),
    'expires_at' => time() + 60,
];
$threw = false;
try {
    $wa->completeLogin(json_encode([
        'id' => 'unknown-id',
        'clientDataJSON' => WebAuthnCredentialStore::b64uEncode('a'),
        'authenticatorData' => WebAuthnCredentialStore::b64uEncode('b'),
        'signature' => WebAuthnCredentialStore::b64uEncode('c'),
    ]) ?: '');
} catch (RuntimeException) {
    $threw = true;
}
assert_true($threw, 'unknown credential id → throws');

// ----- cleanup -----
@unlink($tmpPath);

echo "\n";
echo $failures === 0 ? "ALL TESTS PASSED\n" : "FAILED: {$failures}\n";
exit($failures === 0 ? 0 : 1);
