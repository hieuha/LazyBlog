<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Atomic JSON storage for registered WebAuthn credentials.
 *
 * One operator → one file → many credentials. No DB. Matches the
 * existing LazyBlog "plain PHP, files on disk" ethos used by
 * SeriesManifest and the badges catalogue.
 *
 * Layout:
 *   {
 *     "user_handle": "<base64url-32>",
 *     "credentials": [ { id, publicKey, counter, ... }, ... ]
 *   }
 *
 * `user_handle` is stable across credential lifetime — generated on
 * first write, never rotated. WebAuthn ties an authenticator to this
 * handle, so changing it would invalidate every existing credential.
 */
final class WebAuthnCredentialStore
{
    private readonly string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? __DIR__ . '/../content/admin/webauthn-credentials.json';
    }

    public function path(): string
    {
        return $this->path;
    }

    /** Stable per-operator user handle (32 raw bytes, base64url-encoded). */
    public function userHandle(): string
    {
        $data = $this->load();
        if (!isset($data['user_handle']) || !is_string($data['user_handle']) || $data['user_handle'] === '') {
            $data['user_handle'] = self::b64uEncode(random_bytes(32));
            $this->persist($data);
        }
        return (string) $data['user_handle'];
    }

    /** @return list<WebAuthnCredential> */
    public function all(): array
    {
        $data = $this->load();
        $rows = (array) ($data['credentials'] ?? []);
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = WebAuthnCredential::fromArray($row);
            }
        }
        return $out;
    }

    public function count(): int
    {
        return count($this->all());
    }

    public function findById(string $id): ?WebAuthnCredential
    {
        foreach ($this->all() as $cred) {
            if (hash_equals($cred->id, $id)) {
                return $cred;
            }
        }
        return null;
    }

    public function add(WebAuthnCredential $cred): void
    {
        $data = $this->load();
        $rows = (array) ($data['credentials'] ?? []);

        foreach ($rows as $existing) {
            if (is_array($existing) && hash_equals((string) ($existing['id'] ?? ''), $cred->id)) {
                throw new RuntimeException('Credential ID already registered.');
            }
        }

        $rows[] = $cred->toArray();
        $data['credentials'] = array_values($rows);
        $this->persist($data);
    }

    public function remove(string $id): bool
    {
        $data = $this->load();
        $rows = (array) ($data['credentials'] ?? []);
        $kept = [];
        $removed = false;
        foreach ($rows as $row) {
            if (is_array($row) && hash_equals((string) ($row['id'] ?? ''), $id)) {
                $removed = true;
                continue;
            }
            $kept[] = $row;
        }
        if ($removed) {
            $data['credentials'] = array_values($kept);
            $this->persist($data);
        }
        return $removed;
    }

    public function updateCounter(string $id, int $counter, string $lastUsedAt): void
    {
        $data = $this->load();
        $rows = (array) ($data['credentials'] ?? []);
        $found = false;
        foreach ($rows as $i => $row) {
            if (is_array($row) && hash_equals((string) ($row['id'] ?? ''), $id)) {
                $rows[$i]['counter'] = $counter;
                $rows[$i]['last_used_at'] = $lastUsedAt;
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw new RuntimeException('updateCounter: credential not found.');
        }
        $data['credentials'] = array_values($rows);
        $this->persist($data);
    }

    /** @return array<string,mixed> */
    private function load(): array
    {
        if (!is_file($this->path)) {
            return ['user_handle' => '', 'credentials' => []];
        }
        $raw = @file_get_contents($this->path);
        if (!is_string($raw) || $raw === '') {
            return ['user_handle' => '', 'credentials' => []];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('webauthn-credentials.json is corrupt (not JSON).');
        }
        return $decoded;
    }

    /** @param array<string,mixed> $data */
    private function persist(array $data): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException("Cannot create credential dir: {$dir}");
            }
            @chmod($dir, 0700);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Failed to encode credentials JSON.');
        }
        $tmp = @tempnam($dir, '.webauthn.');
        if ($tmp === false) {
            throw new RuntimeException('Cannot create temp file in credential dir.');
        }
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('Failed to write credentials temp file.');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to swap credentials file into place.');
        }
    }

    public static function b64uEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $encoded): string
    {
        $pad = strlen($encoded) % 4;
        if ($pad !== 0) {
            $encoded .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($out === false) {
            throw new RuntimeException('Invalid base64url input.');
        }
        return $out;
    }
}
