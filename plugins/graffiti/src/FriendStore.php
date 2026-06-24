<?php

declare(strict_types=1);

namespace Plugins\Graffiti;

use App\FileWriter;

/**
 * CRUD over `content/plugins/graffiti/friends.json`.
 *
 * One row per friendship. State machine:
 *
 *   pending  — we have ONE of the two tokens. Either we created the invite
 *              (waiting for friend's paste-back) or accepted a one-shot
 *              invite without finishing the reciprocal step.
 *   active   — both `incoming_token` and `outgoing_token` set. Can both
 *              receive from and send to this friend.
 *   revoked  — soft-deleted. Webhook rejects with 403; row stays around
 *              for audit so the operator can see who used to be a friend.
 *
 * Tokens are interpreted from the LOCAL blog's perspective:
 *   incoming_token = secret WE issued; friend presents it on inbound.
 *   outgoing_token = secret FRIEND issued; we present it on outbound.
 */
final class FriendStore
{
    private string $path;

    public function __construct(string $storagePath)
    {
        $this->path = $storagePath . '/friends.json';
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->path), true);
        return is_array($data) ? array_values($data) : [];
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

    public function findByBlogUrl(string $blogUrl): ?array
    {
        $blogUrl = rtrim($blogUrl, '/');
        foreach ($this->all() as $row) {
            if (rtrim((string) ($row['blog_url'] ?? ''), '/') === $blogUrl) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Used by inbox auth: look up an active friend by the secret we issued.
     * Constant-time match against every row to avoid leaking which token
     * value is "close" to a real one through timing.
     */
    public function findByIncomingToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $hit = null;
        foreach ($this->all() as $row) {
            if (($row['state'] ?? '') === 'revoked') {
                continue;
            }
            $candidate = (string) ($row['incoming_token'] ?? '');
            if ($candidate !== '' && hash_equals($candidate, $token)) {
                $hit = $row;
            }
        }
        return $hit;
    }

    /**
     * Insert a new row. Caller supplies the partial payload — id, added_at
     * and the default per-friend rate limit are filled here. Returns the
     * generated id.
     *
     * @param array<string,mixed> $row
     */
    public function create(array $row): string
    {
        $rows = $this->all();
        $id = 'f_' . bin2hex(random_bytes(4));
        $rows[] = array_merge([
            'id' => $id,
            'state' => 'pending',
            'rate_limit_per_day' => 5,
            'added_at' => time(),
            'completed_at' => null,
        ], $row);
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

    /**
     * Hard-delete the friendship row. We deliberately do NOT soft-revoke
     * (state=revoked): keeping ghost rows clutters the UI, and orphan
     * `from_friend_id` refs in `graffiti.json` already degrade gracefully
     * — OverlayRenderer + Received tab fall back to "anon" attribution.
     */
    public function revoke(string $id): void
    {
        $rows = $this->all();
        $kept = array_values(array_filter(
            $rows,
            static fn (array $r): bool => ($r['id'] ?? null) !== $id,
        ));
        if (count($kept) !== count($rows)) {
            $this->write($kept);
        }
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
