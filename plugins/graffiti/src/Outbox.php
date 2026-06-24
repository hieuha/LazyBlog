<?php

declare(strict_types=1);

namespace Plugins\Graffiti;

use App\FileWriter;

/**
 * Send queue with retry. No cron available — workers piggyback on admin
 * page requests via `processBatch()`.
 *
 * Status machine:
 *
 *   pending         — never attempted yet (briefly; set during enqueue)
 *   failed_retry    — last attempt was transient (5xx, 402, network); will
 *                     retry once `next_retry_at` is in the past
 *   sent            — target returned 200; terminal
 *   failed_permanent— target returned non-retryable 4xx or attempts hit cap;
 *                     terminal. Energy NOT refunded — user lesson: don't
 *                     graffiti dead blogs.
 *
 * Backoff schedule (seconds between attempts):
 *   attempt 1 → 60s, attempt 2 → 300s, attempt 3 → 1800s,
 *   attempt 4 → 7200s, attempt 5 → 21600s, then failed_permanent.
 *
 * HttpSender is injectable as a callable so tests can drive deterministic
 * responses without real network IO.
 */
final class Outbox
{
    public const STATUS_PENDING        = 'pending';
    public const STATUS_FAILED_RETRY   = 'failed_retry';
    public const STATUS_SENT           = 'sent';
    public const STATUS_FAILED_PERM    = 'failed_permanent';

    public const MAX_ATTEMPTS = 5;
    public const BACKOFF_SECONDS = [60, 300, 1800, 7200, 21600];

    private FriendStore $friends;
    private string $path;
    /** @var callable(string,array<string,mixed>):array<string,mixed> */
    private $sender;

    /** @param callable(string,array<string,mixed>):array<string,mixed>|null $sender */
    public function __construct(FriendStore $friends, string $storagePath, ?callable $sender = null)
    {
        $this->friends = $friends;
        $this->path = $storagePath . '/outbox.json';
        $this->sender = $sender ?? static fn (string $url, array $body): array => HttpSender::postJson($url, $body);
    }

    /**
     * Enqueue a new send and attempt it immediately. Returns the row id.
     *
     * @param array<string,mixed> $payload  The exact JSON body to POST.
     */
    public function enqueue(string $friendId, array $payload): string
    {
        $rows = $this->all();
        $id = 'o_' . bin2hex(random_bytes(4));
        $rows[] = [
            'id'             => $id,
            'to_friend_id'   => $friendId,
            'payload'        => $payload,
            'status'         => self::STATUS_PENDING,
            'attempts'       => 0,
            'next_retry_at'  => 0,
            'last_error'     => null,
            'created_at'     => time(),
            'completed_at'   => null,
        ];
        $this->write($rows);
        $this->attempt($id);
        return $id;
    }

    /**
     * Perform a single send attempt for the given outbox row. Classifies
     * the response and updates the row state. Safe to call repeatedly —
     * already-terminal rows are no-ops.
     */
    public function attempt(string $id): void
    {
        $row = $this->find($id);
        if ($row === null) return;
        if (in_array($row['status'], [self::STATUS_SENT, self::STATUS_FAILED_PERM], true)) return;

        $friend = $this->friends->find((string) $row['to_friend_id']);
        if ($friend === null || ($friend['state'] ?? '') === 'revoked') {
            $this->update($id, [
                'status' => self::STATUS_FAILED_PERM,
                'last_error' => 'friend_revoked_or_missing',
                'completed_at' => time(),
            ]);
            return;
        }

        $endpoint = (string) ($friend['graffiti_endpoint'] ?? '');
        if ($endpoint === '') {
            $this->update($id, [
                'status' => self::STATUS_FAILED_PERM,
                'last_error' => 'no_endpoint',
                'completed_at' => time(),
            ]);
            return;
        }

        $payload = (array) ($row['payload'] ?? []);
        $result = ($this->sender)($endpoint, $payload);

        $attempts = (int) $row['attempts'] + 1;
        $status   = (int) ($result['status'] ?? 0);
        $transportFailed = (bool) ($result['transport_failed'] ?? false);

        // Happy path
        if ($status === 200) {
            $this->update($id, [
                'status' => self::STATUS_SENT,
                'attempts' => $attempts,
                'last_error' => null,
                'completed_at' => time(),
            ]);
            return;
        }

        // Retryable: transport failure OR 5xx OR 402 (rate limited — try later)
        $retryable = $transportFailed || $status >= 500 || $status === 402;

        if (!$retryable) {
            // 4xx other than 402: target rejected on content grounds. No retry.
            $this->update($id, [
                'status' => self::STATUS_FAILED_PERM,
                'attempts' => $attempts,
                'last_error' => self::briefError($result, "http:{$status}"),
                'completed_at' => time(),
            ]);
            return;
        }

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->update($id, [
                'status' => self::STATUS_FAILED_PERM,
                'attempts' => $attempts,
                'last_error' => self::briefError($result, 'max_attempts'),
                'completed_at' => time(),
            ]);
            return;
        }

        $delay = self::BACKOFF_SECONDS[$attempts - 1] ?? end(self::BACKOFF_SECONDS);
        $this->update($id, [
            'status' => self::STATUS_FAILED_RETRY,
            'attempts' => $attempts,
            'next_retry_at' => time() + $delay,
            'last_error' => self::briefError($result, $transportFailed ? 'transport' : "http:{$status}"),
        ]);
    }

    /**
     * Drain up to $maxItems retryable rows whose next_retry_at is in the
     * past. Designed to run inline on admin page hits — bounded so a
     * backlog never blocks the admin UI.
     */
    public function processBatch(int $maxItems = 3): int
    {
        $now = time();
        $candidates = [];
        foreach ($this->all() as $row) {
            if (!in_array($row['status'], [self::STATUS_PENDING, self::STATUS_FAILED_RETRY], true)) {
                continue;
            }
            if ((int) ($row['next_retry_at'] ?? 0) > $now) {
                continue;
            }
            $candidates[] = $row;
        }
        usort($candidates, static fn (array $a, array $b): int =>
            ((int) ($a['next_retry_at'] ?? 0)) <=> ((int) ($b['next_retry_at'] ?? 0))
        );
        $candidates = array_slice($candidates, 0, max(0, $maxItems));

        $processed = 0;
        foreach ($candidates as $row) {
            $this->attempt((string) $row['id']);
            $processed++;
        }
        return $processed;
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

    /** @param array<string,mixed> $result */
    private static function briefError(array $result, string $fallback): string
    {
        $err = (string) ($result['error'] ?? '');
        if ($err !== '') {
            return substr($err, 0, 200);
        }
        return $fallback;
    }
}
