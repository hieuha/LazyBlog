<?php

declare(strict_types=1);

namespace Plugins\Stalk;

use App\FileWriter;

/**
 * Cached post items across all friends.
 *
 * Authoritative model: each refresh WIPES the rows for one friend and
 * inserts the freshly-parsed (and already-sliced) item list. No dedup,
 * no merge, no global cap. Per-friend cap is the caller's responsibility
 * — `RefreshService` slices `parser->parse(...)` output to
 * `Config::maxItemsPerFriend()` before calling `replaceForFriend()`.
 *
 * Row shape: { id, friend_id, title, link, pub_date, guid }.
 */
final class PostCache
{
    private string $path;

    public function __construct(string $storagePath)
    {
        $this->path = $storagePath . '/posts.json';
    }

    /**
     * Sorted by pub_date DESC for direct view consumption.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $rows = $this->read();
        usort(
            $rows,
            static fn (array $a, array $b): int =>
                ((int) ($b['pub_date'] ?? 0)) <=> ((int) ($a['pub_date'] ?? 0)),
        );
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function forFriend(string $friendId): array
    {
        return array_values(array_filter(
            $this->read(),
            static fn (array $r): bool => ($r['friend_id'] ?? null) === $friendId,
        ));
    }

    /**
     * Replace this friend's cached items wholesale. Returns the count of
     * items written for the friend (== input length).
     *
     * Caller is responsible for slicing to the user-configured per-friend
     * cap. PostCache trusts the input and just persists.
     *
     * @param list<array<string,mixed>> $items
     */
    public function replaceForFriend(string $friendId, array $items): int
    {
        $rows = $this->read();

        // Index this friend's existing rows by guid so we can carry over
        // each item's original `first_seen_at`. Without this preservation,
        // every refresh would mark every item as freshly-seen and the "NEW"
        // badge on /stalk would never settle.
        $priorByGuid = [];
        foreach ($rows as $r) {
            if (($r['friend_id'] ?? null) !== $friendId) {
                continue;
            }
            $g = (string) ($r['guid'] ?? '');
            if ($g !== '') {
                $priorByGuid[$g] = $r;
            }
        }

        $kept = array_values(array_filter(
            $rows,
            static fn (array $r): bool => ($r['friend_id'] ?? null) !== $friendId,
        ));

        $now = time();
        foreach ($items as $item) {
            $guid  = (string) ($item['guid'] ?? ($item['link'] ?? ''));
            $prior = $priorByGuid[$guid] ?? null;
            $kept[] = [
                'id'            => 'p_' . bin2hex(random_bytes(4)),
                'friend_id'     => $friendId,
                'title'         => (string) ($item['title'] ?? ''),
                'link'          => (string) ($item['link'] ?? ''),
                'pub_date'      => (int)    ($item['pub_date_ts'] ?? $item['pub_date'] ?? 0),
                'guid'          => $guid,
                'first_seen_at' => (int)    ($prior['first_seen_at'] ?? $now),
            ];
        }
        $this->write($kept);
        return count($items);
    }

    public function removeByFriend(string $friendId): int
    {
        return $this->replaceForFriend($friendId, []);
    }

    /** @return list<array<string,mixed>> */
    private function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $raw = @file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            error_log("[stalk] corrupt posts.json — treating as empty");
            return [];
        }
        return array_values($data);
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
            (string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            0o600,
        );
    }
}
