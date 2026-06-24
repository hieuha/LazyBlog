<?php

declare(strict_types=1);

namespace Plugins\Graffiti;

use App\FileWriter;

/**
 * 24h rolling replay-protection per friend.
 *
 * Nonce dedup keeps the inbox from accepting the same payload twice when
 * a friend's outbox retries after a partial success. We scope nonces per
 * friend so namespace clashes between friends are impossible.
 *
 * Storage shape (content/plugins/graffiti/nonces.json):
 *
 *   {
 *     "f_abc12345": [
 *       {"nonce": "uuid-1", "expires_at": 1719326400}
 *     ]
 *   }
 *
 * Expired entries are pruned on every read so the file size stays bounded
 * by recent traffic rather than growing forever.
 */
final class NonceCache
{
    private const DEFAULT_TTL = 86400;

    private string $path;

    public function __construct(string $storagePath)
    {
        $this->path = $storagePath . '/nonces.json';
    }

    public function seen(string $friendId, string $nonce): bool
    {
        if ($friendId === '' || $nonce === '') {
            return false;
        }
        $data = $this->loadAndPrune();
        foreach ($data[$friendId] ?? [] as $row) {
            if (hash_equals((string) ($row['nonce'] ?? ''), $nonce)) {
                return true;
            }
        }
        return false;
    }

    public function record(string $friendId, string $nonce, int $ttl = self::DEFAULT_TTL): void
    {
        if ($friendId === '' || $nonce === '') {
            return;
        }
        $data = $this->loadAndPrune();
        $data[$friendId] ??= [];
        $data[$friendId][] = ['nonce' => $nonce, 'expires_at' => time() + $ttl];
        $this->save($data);
    }

    /** @return array<string,list<array{nonce:string,expires_at:int}>> */
    private function loadAndPrune(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($this->path), true);
        if (!is_array($decoded)) {
            return [];
        }
        $now = time();
        $changed = false;
        foreach ($decoded as $fid => $rows) {
            $kept = array_values(array_filter(
                (array) $rows,
                static fn (array $r): bool => (int) ($r['expires_at'] ?? 0) > $now,
            ));
            if ($kept !== $rows) {
                $changed = true;
            }
            if ($kept === []) {
                unset($decoded[$fid]);
            } else {
                $decoded[$fid] = $kept;
            }
        }
        if ($changed) {
            $this->save($decoded);
        }
        return $decoded;
    }

    /** @param array<string,list<array{nonce:string,expires_at:int}>> $data */
    private function save(array $data): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o755, recursive: true);
        }
        FileWriter::writeAtomic(
            $this->path,
            (string) json_encode($data, JSON_UNESCAPED_SLASHES),
            0o600,
        );
    }
}
