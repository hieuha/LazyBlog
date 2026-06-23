<?php

declare(strict_types=1);

namespace Plugins\ViewCounter;

use App\FileWriter;
use Throwable;

/**
 * Locked read-modify-write counter store.
 *
 * `FileWriter::writeAtomic` is rename-atomic but does NOT prevent lost
 * updates on a read → mutate → write sequence. We add an explicit
 * `flock(LOCK_EX)` on a separate `.stats.lock` file so concurrent post
 * views (many requests, one counter) serialise correctly.
 *
 * Files on disk:
 *   stats.json   — {"<slug>": {"views": <int>}}
 *   seen.json    — {"<sha256(uid|slug)>": <unix-ts>}  (dedup index)
 *   .stats.lock  — zero-byte flock target
 *
 * Corrupt JSON is treated as an empty array + logged; the file gets
 * rewritten on the next successful increment.
 */
final class StatsStore
{
    private string $statsPath;
    private string $seenPath;
    private string $lockPath;

    public function __construct(string $dir)
    {
        $this->statsPath = $dir . '/stats.json';
        $this->seenPath  = $dir . '/seen.json';
        $this->lockPath  = $dir . '/.stats.lock';
    }

    /**
     * Record a view if the (uid, slug) pair has not been seen before.
     * Returns true if the counter was incremented, false if dedup'd.
     */
    public function recordView(string $slug, string $userId): bool
    {
        if ($slug === '' || $userId === '') {
            return false;
        }

        $lockFp = @fopen($this->lockPath, 'c');
        if ($lockFp === false) {
            error_log('[view-counter] cannot open lockfile: ' . $this->lockPath);
            return false;
        }

        try {
            if (!flock($lockFp, LOCK_EX)) {
                error_log('[view-counter] flock LOCK_EX failed');
                return false;
            }

            $seen = $this->readJson($this->seenPath);
            $key = hash('sha256', $userId . '|' . $slug);
            if (isset($seen[$key])) {
                return false;
            }

            $stats = $this->readJson($this->statsPath);
            $current = (int) ($stats[$slug]['views'] ?? 0);
            $stats[$slug] = ['views' => $current + 1];
            $seen[$key] = time();

            FileWriter::writeAtomic(
                $this->statsPath,
                json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n",
            );
            FileWriter::writeAtomic(
                $this->seenPath,
                json_encode($seen, JSON_UNESCAPED_UNICODE) . "\n",
            );

            return true;
        } catch (Throwable $e) {
            error_log('[view-counter] recordView failed: ' . $e->getMessage());
            return false;
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    /**
     * Read-only count for display. No lock — stale-by-one-write is fine
     * for a UI badge and avoids contention on the hot path.
     */
    public function getCount(string $slug): int
    {
        if ($slug === '') {
            return 0;
        }
        $stats = $this->readJson($this->statsPath);
        return (int) ($stats[$slug]['views'] ?? 0);
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            error_log("[view-counter] corrupt JSON at {$path} — resetting");
            return [];
        }
        return $decoded;
    }
}
