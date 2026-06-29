<?php

declare(strict_types=1);

namespace App;

/**
 * Readonly DTO for a single registered FIDO2/WebAuthn credential.
 *
 * Persisted as one entry in content/admin/webauthn-credentials.json.
 * `id` and `publicKey` are the only fields lbuchs/WebAuthn needs to verify
 * an assertion; everything else is operator-facing metadata.
 */
final class WebAuthnCredential
{
    public function __construct(
        /** base64url-encoded credential ID returned by the authenticator */
        public readonly string $id,
        /** PEM-encoded COSE public key */
        public readonly string $publicKey,
        /** Monotonic counter from the authenticator. Replay defense. */
        public readonly int $counter,
        /** Operator-chosen nickname (e.g. "Yubikey 5C primary"). */
        public readonly string $name,
        /** Transports advertised by the authenticator (usb / nfc / ble / internal / hybrid). */
        public readonly array $transports,
        /** AAGUID hex string. Empty when authenticator omits attestation (Passkey on iCloud). */
        public readonly string $aaguid,
        /** ISO-8601 UTC of first registration. */
        public readonly string $createdAt,
        /** ISO-8601 UTC of last successful assertion. Null when never used. */
        public readonly ?string $lastUsedAt,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            id: (string) ($row['id'] ?? ''),
            publicKey: (string) ($row['publicKey'] ?? ''),
            counter: (int) ($row['counter'] ?? 0),
            name: (string) ($row['name'] ?? ''),
            transports: array_values(array_filter(
                (array) ($row['transports'] ?? []),
                static fn ($t): bool => is_string($t) && $t !== '',
            )),
            aaguid: (string) ($row['aaguid'] ?? ''),
            createdAt: (string) ($row['created_at'] ?? ''),
            lastUsedAt: isset($row['last_used_at']) && $row['last_used_at'] !== '' ? (string) $row['last_used_at'] : null,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'publicKey' => $this->publicKey,
            'counter' => $this->counter,
            'name' => $this->name,
            'transports' => $this->transports,
            'aaguid' => $this->aaguid,
            'created_at' => $this->createdAt,
            'last_used_at' => $this->lastUsedAt,
        ];
    }

    public function withCounter(int $counter, string $lastUsedAt): self
    {
        return new self(
            id: $this->id,
            publicKey: $this->publicKey,
            counter: $counter,
            name: $this->name,
            transports: $this->transports,
            aaguid: $this->aaguid,
            createdAt: $this->createdAt,
            lastUsedAt: $lastUsedAt,
        );
    }
}
