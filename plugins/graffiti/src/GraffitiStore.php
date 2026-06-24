<?php

declare(strict_types=1);

namespace Plugins\Graffiti;

use App\FileWriter;

/**
 * CRUD over `content/plugins/graffiti/graffiti.json`.
 *
 * Append-only by design: items are never deleted, just toggled with the
 * `hidden` flag. Preserves an audit trail of who-graffiti'd-what even
 * after the owner cleans up. `seen_by_owner` drives the navbar count
 * chip (Phase 8) and resets to true once the owner views the Received
 * tab.
 *
 * Row shape:
 *
 *   {
 *     "id": "g_xxxxxxxx",
 *     "from_friend_id": "f_xxxxxxxx",
 *     "post_slug": "<bare slug, no date prefix>",
 *     "type": "text|sticker|spray",
 *     "payload": { ... },
 *     "nonce": "...",
 *     "received_at": 1719240000,
 *     "hidden": false,
 *     "seen_by_owner": false
 *   }
 */
final class GraffitiStore
{
    private string $path;

    public function __construct(string $storagePath)
    {
        $this->path = $storagePath . '/graffiti.json';
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($this->path), true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    public function find(string $id): ?array
    {
        foreach ($this->all() as $row) {
            if (($row['id'] ?? null) === $id) {
                return $row;
            }
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    public function forSlug(string $slug): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (array $r): bool => ($r['post_slug'] ?? '') === $slug,
        ));
    }

    /**
     * Append a new row. Caller passes the validated payload; we generate
     * id, received_at, and the moderation flags. Returns the new id.
     *
     * Callers should also snapshot `from_handle` + `from_blog_url` so the
     * row survives later revoke + hard-delete of the friend row — the
     * `from_friend_id` lookup goes orphan otherwise and the row renders as
     * "unknown". Snapshot keeps history readable forever.
     *
     * @param array{from_friend_id:string,post_slug:string,type:string,payload:array<string,mixed>,nonce:string,from_handle?:string,from_blog_url?:string} $row
     */
    public function append(array $row): string
    {
        $rows = $this->all();
        $id = 'g_' . bin2hex(random_bytes(4));
        $rows[] = array_merge($row, [
            'id' => $id,
            'received_at' => time(),
            'hidden' => false,
            'seen_by_owner' => false,
        ]);
        $this->write($rows);
        return $id;
    }

    /** @param array<string,mixed> $patch */
    public function update(string $id, array $patch): void
    {
        $rows = $this->all();
        foreach ($rows as &$row) {
            if (($row['id'] ?? null) === $id) {
                $row = array_merge($row, $patch);
                break;
            }
        }
        unset($row);
        $this->write($rows);
    }

    /** Count of rows still unread by owner (visible only, hidden excluded). */
    public function unreadCount(): int
    {
        $n = 0;
        foreach ($this->all() as $row) {
            if (!(bool) ($row['hidden'] ?? false) && !(bool) ($row['seen_by_owner'] ?? false)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Mark a batch of rows as seen by the owner. Idempotent — already-seen
     * rows are untouched. Used when the operator visits the Received tab.
     *
     * @param list<string> $ids
     */
    public function markSeen(array $ids): void
    {
        if ($ids === []) return;
        $set = array_flip($ids);
        $rows = $this->all();
        $changed = false;
        foreach ($rows as &$row) {
            $id = (string) ($row['id'] ?? '');
            if (isset($set[$id]) && !(bool) ($row['seen_by_owner'] ?? false)) {
                $row['seen_by_owner'] = true;
                $changed = true;
            }
        }
        unset($row);
        if ($changed) {
            $this->write($rows);
        }
    }

    public function setHidden(string $id, bool $hidden): bool
    {
        $rows = $this->all();
        $found = false;
        foreach ($rows as &$row) {
            if (($row['id'] ?? null) === $id) {
                $row['hidden'] = $hidden;
                $found = true;
                break;
            }
        }
        unset($row);
        if ($found) {
            $this->write($rows);
        }
        return $found;
    }

    /** Hard delete — row is physically removed. Use sparingly; prefer hide. */
    public function delete(string $id): bool
    {
        $rows = $this->all();
        $kept = array_values(array_filter(
            $rows,
            static fn (array $r): bool => ($r['id'] ?? null) !== $id,
        ));
        if (count($kept) === count($rows)) {
            return false;
        }
        $this->write($kept);
        return true;
    }

    /** @param list<array<string,mixed>> $rows */
    private function write(array $rows): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o755, recursive: true);
        }
        FileWriter::writeAtomic(
            $this->path,
            (string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            0o600,
        );
    }
}
