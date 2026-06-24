<?php

declare(strict_types=1);

namespace Plugins\Graffiti;

/**
 * Receiver-side rate limit: a 24h sliding window per friend.
 *
 * Independent of the sender's energy ledger — even if a friend forges
 * unlimited energy on their side, this caps how many of their items B
 * accepts per day. Defaults to 5/24h, overrideable per friend via the
 * `rate_limit_per_day` field on their `friends.json` row.
 *
 * Hidden items still count: a spammer cannot bypass the cap by sending
 * stuff the owner subsequently hides. `retry_after` reports the seconds
 * until the oldest in-window item falls out, so a polite sender can wait
 * and try again rather than blindly retrying.
 */
final class RateLimiter
{
    private const WINDOW_SECONDS = 86400;
    public const DEFAULT_LIMIT  = 5;

    private FriendStore $friends;
    private GraffitiStore $store;

    public function __construct(FriendStore $friends, GraffitiStore $store)
    {
        $this->friends = $friends;
        $this->store = $store;
    }

    /**
     * @return array{ok:bool,count:int,limit:int,retry_after:int}
     */
    public function check(string $friendId): array
    {
        $friend = $this->friends->find($friendId);
        $limit = (int) (($friend['rate_limit_per_day'] ?? self::DEFAULT_LIMIT));
        if ($limit < 0) {
            $limit = 0;
        }

        $now = time();
        $windowStart = $now - self::WINDOW_SECONDS;
        $inWindow = [];
        foreach ($this->store->all() as $row) {
            if (($row['from_friend_id'] ?? null) !== $friendId) {
                continue;
            }
            $ts = (int) ($row['received_at'] ?? 0);
            if ($ts >= $windowStart) {
                $inWindow[] = $ts;
            }
        }
        sort($inWindow);

        $count = count($inWindow);
        if ($count < $limit) {
            return ['ok' => true, 'count' => $count, 'limit' => $limit, 'retry_after' => 0];
        }

        $oldest = $inWindow[0] ?? $now;
        $retryAfter = max(1, ($oldest + self::WINDOW_SECONDS) - $now);
        return ['ok' => false, 'count' => $count, 'limit' => $limit, 'retry_after' => $retryAfter];
    }
}
