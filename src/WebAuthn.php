<?php

declare(strict_types=1);

namespace App;

use RuntimeException;
use lbuchs\WebAuthn\WebAuthn as LbuchsWebAuthn;
use lbuchs\WebAuthn\WebAuthnException;

/**
 * High-level wrapper around lbuchs/WebAuthn for LazyBlog admin auth.
 *
 * Hides session challenge plumbing + binary↔base64url serialization so
 * controllers only deal with JSON-friendly strings. Username-less:
 * one operator → one stable user_handle (kept by the store).
 *
 * RP ID resolution priority:
 *   1. env WEBAUTHN_RP_ID (pin for domain migrations)
 *   2. $_SERVER['HTTP_HOST'] stripped of port
 *   3. 'localhost' (dev fallback)
 *
 * Challenges live in the session under separate keys for register vs
 * login so a tab juggling both flows doesn't cross the streams. TTL is
 * enforced at processCreate/processGet time by the lib's challenge
 * comparison; we additionally drop expired entries before reuse.
 */
final class WebAuthn
{
    private const SESSION_REGISTER_CHALLENGE = 'webauthn_register_challenge';
    private const SESSION_LOGIN_CHALLENGE = 'webauthn_login_challenge';
    private const CHALLENGE_TTL_SEC = 60;

    public function __construct(
        private readonly WebAuthnCredentialStore $store,
        private readonly ?string $rpIdOverride = null,
        private readonly ?string $rpNameOverride = null,
    ) {
    }

    public function rpId(): string
    {
        if ($this->rpIdOverride !== null && $this->rpIdOverride !== '') {
            return $this->rpIdOverride;
        }
        $env = (string) Config::get('WEBAUTHN_RP_ID', '');
        if ($env !== '') {
            return $env;
        }
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = preg_replace('/:\d+$/', '', $host) ?? 'localhost';
        return $host === '' ? 'localhost' : $host;
    }

    public function rpName(): string
    {
        if ($this->rpNameOverride !== null && $this->rpNameOverride !== '') {
            return $this->rpNameOverride;
        }
        return (string) Config::get('SITE_TITLE', 'LazyBlog');
    }

    /**
     * Build registration options for the browser + stash the challenge in
     * the session. The JS side passes the returned `publicKey` straight
     * into `navigator.credentials.create({ publicKey })`.
     *
     * @return array<string,mixed>
     */
    public function beginRegister(string $nickname): array
    {
        Auth::start();
        $nickname = trim($nickname);
        if ($nickname === '') {
            throw new RuntimeException('Nickname is required.');
        }
        if (mb_strlen($nickname) > 64) {
            throw new RuntimeException('Nickname too long (max 64).');
        }

        $lib = $this->lib();
        $userHandle = WebAuthnCredentialStore::b64uDecode($this->store->userHandle());

        // Block re-registering an authenticator already enrolled.
        $excludeIds = [];
        foreach ($this->store->all() as $existing) {
            $excludeIds[] = WebAuthnCredentialStore::b64uDecode($existing->id);
        }

        $opts = $lib->getCreateArgs(
            $userHandle,                       // userId (binary)
            'admin',                           // userName (single-operator)
            $this->rpName() . ' admin',        // userDisplayName
            20,                                // timeout (seconds)
            'preferred',                       // requireResidentKey (discoverable) - iOS compatible
            'preferred',                       // requireUserVerification
            null,                              // crossPlatformAttachment (allow both)
            $excludeIds,
        );

        $_SESSION[self::SESSION_REGISTER_CHALLENGE] = [
            'challenge' => base64_encode($lib->getChallenge()->getBinaryString()),
            'nickname' => $nickname,
            'expires_at' => time() + self::CHALLENGE_TTL_SEC,
        ];

        return (array) $opts;
    }

    /**
     * Verify the attestation, persist the credential, return it for the
     * controller's flash/response payload.
     */
    public function completeRegister(string $clientResponseJson): WebAuthnCredential
    {
        Auth::start();
        $stash = $_SESSION[self::SESSION_REGISTER_CHALLENGE] ?? null;
        unset($_SESSION[self::SESSION_REGISTER_CHALLENGE]);
        if (!is_array($stash) || !isset($stash['challenge'], $stash['expires_at']) || $stash['expires_at'] < time()) {
            throw new RuntimeException('Registration challenge expired or missing.');
        }

        $payload = json_decode($clientResponseJson, true);
        if (!is_array($payload)) {
            throw new RuntimeException('Malformed register response.');
        }
        $clientDataJSON = self::decodeBinary($payload['clientDataJSON'] ?? null, 'clientDataJSON');
        $attestationObject = self::decodeBinary($payload['attestationObject'] ?? null, 'attestationObject');
        $transports = array_values(array_filter(
            (array) ($payload['transports'] ?? []),
            static fn ($t): bool => is_string($t) && $t !== '',
        ));

        $lib = $this->lib();
        try {
            $data = $lib->processCreate(
                $clientDataJSON,
                $attestationObject,
                base64_decode((string) $stash['challenge'], true),
                false,    // requireUserVerification (we asked 'preferred' — lib treats this param as bool, so 'preferred' would coerce to true and reject UV=0 attestations from iOS Touch ID)
                true,     // requireUserPresent
                false,    // failIfRootMismatch — Yubikey + iOS Passkey may use unrecognized roots
            );
        } catch (WebAuthnException $e) {
            throw new RuntimeException('Attestation failed: ' . $e->getMessage(), 0, $e);
        }

        $cred = new WebAuthnCredential(
            id: WebAuthnCredentialStore::b64uEncode((string) $data->credentialId),
            publicKey: (string) $data->credentialPublicKey,
            counter: (int) ($data->signatureCounter ?? 0),
            name: (string) $stash['nickname'],
            transports: $transports,
            aaguid: bin2hex((string) ($data->AAGUID ?? '')),
            createdAt: gmdate('c'),
            lastUsedAt: null,
        );
        $this->store->add($cred);
        return $cred;
    }

    /** @return array<string,mixed> */
    public function beginLogin(): array
    {
        Auth::start();
        $lib = $this->lib();
        $ids = [];
        foreach ($this->store->all() as $cred) {
            $ids[] = WebAuthnCredentialStore::b64uDecode($cred->id);
        }
        $opts = $lib->getGetArgs($ids, 20, true, true, true, true, true, 'preferred');
        $_SESSION[self::SESSION_LOGIN_CHALLENGE] = [
            'challenge' => base64_encode($lib->getChallenge()->getBinaryString()),
            'expires_at' => time() + self::CHALLENGE_TTL_SEC,
        ];
        return (array) $opts;
    }

    /**
     * Verify the assertion, update the credential's counter + last_used_at,
     * return the matched credential. Throws on any verification failure
     * (replay, signature mismatch, unknown credential, expired challenge).
     */
    public function completeLogin(string $clientResponseJson): WebAuthnCredential
    {
        Auth::start();
        $stash = $_SESSION[self::SESSION_LOGIN_CHALLENGE] ?? null;
        unset($_SESSION[self::SESSION_LOGIN_CHALLENGE]);
        if (!is_array($stash) || !isset($stash['challenge'], $stash['expires_at']) || $stash['expires_at'] < time()) {
            throw new RuntimeException('Login challenge expired or missing.');
        }

        $payload = json_decode($clientResponseJson, true);
        if (!is_array($payload)) {
            throw new RuntimeException('Malformed login response.');
        }
        $credentialId = (string) ($payload['id'] ?? '');
        if ($credentialId === '') {
            throw new RuntimeException('Missing credential id.');
        }
        $clientDataJSON = self::decodeBinary($payload['clientDataJSON'] ?? null, 'clientDataJSON');
        $authenticatorData = self::decodeBinary($payload['authenticatorData'] ?? null, 'authenticatorData');
        $signature = self::decodeBinary($payload['signature'] ?? null, 'signature');

        $stored = $this->store->findById($credentialId);
        if ($stored === null) {
            throw new RuntimeException('Unknown credential.');
        }

        $lib = $this->lib();
        try {
            $ok = $lib->processGet(
                $clientDataJSON,
                $authenticatorData,
                $signature,
                $stored->publicKey,
                base64_decode((string) $stash['challenge'], true),
                $stored->counter,
                false,   // requireUserVerification
                true,    // requireUserPresent
            );
        } catch (WebAuthnException $e) {
            throw new RuntimeException('Assertion failed: ' . $e->getMessage(), 0, $e);
        }
        if (!$ok) {
            throw new RuntimeException('Assertion verification returned false.');
        }

        $newCounter = (int) ($lib->getSignatureCounter() ?? $stored->counter);
        // Lib already throws on counter regression when prevSignatureCnt was
        // passed; this is belt-and-braces — never persist a backwards counter.
        if ($newCounter > 0 && $newCounter <= $stored->counter && $stored->counter > 0) {
            throw new RuntimeException('Counter did not advance (possible replay).');
        }
        $this->store->updateCounter($credentialId, $newCounter, gmdate('c'));
        return $stored->withCounter($newCounter, gmdate('c'));
    }

    private function lib(): LbuchsWebAuthn
    {
        return new LbuchsWebAuthn($this->rpName(), $this->rpId(), null, true);
    }

    private static function decodeBinary(mixed $v, string $field): string
    {
        if (!is_string($v) || $v === '') {
            throw new RuntimeException("Missing or invalid {$field}.");
        }
        return WebAuthnCredentialStore::b64uDecode($v);
    }
}
