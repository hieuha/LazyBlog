<?php

declare(strict_types=1);

namespace Plugins\Stalk;

use App\FileWriter;

/**
 * CRUD over `content/plugins/stalk/friends.json`.
 *
 * One row per friend (= a LazyBlog blog URL the operator follows).
 *
 * Row shape:
 *   id              — internal `ff_<8 hex>` identifier.
 *   blog_url        — canonical root URL, no trailing slash.
 *   handle          — operator-supplied or auto-derived from channel title.
 *   added_at        — unix ts of the original `create()` call.
 *   last_fetched_at — unix ts of the most recent SUCCESSFUL parse. Stays at
 *                     the previous value on error so admin UI shows "last
 *                     seen N ago" even when the latest attempt failed.
 *   last_status     — null | 'ok' | 'error'. Display-only signal.
 *   last_error      — null | short error message from latest attempt.
 *
 * The fetch-interval gate is GLOBAL (lives in Config::last_refresh_at).
 * These per-friend fields exist purely for the admin UI.
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
        $raw = @file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            error_log("[stalk] corrupt friends.json — treating as empty");
            return [];
        }
        return array_values($data);
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
     * Insert a row. Caller supplies blog_url + handle; defaults filled here.
     *
     * @param array<string,mixed> $row
     */
    public function create(array $row): string
    {
        $rows = $this->all();
        $id = 'ff_' . bin2hex(random_bytes(4));
        $rows[] = array_merge([
            'id'              => $id,
            'blog_url'        => '',
            'handle'          => '',
            'max_items'       => null,   // null → fall back to Config::maxItemsPerFriend()
            'added_at'        => time(),
            'last_fetched_at' => 0,
            'last_status'     => null,
            'last_http_code'  => 0,      // 0 = no attempt yet OR transport-level fail
            'last_error'      => null,
        ], $row, ['id' => $id]);
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

    public function delete(string $id): void
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
            (string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            0o600,
        );
    }
}
