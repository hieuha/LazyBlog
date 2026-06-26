<?php

declare(strict_types=1);

namespace Plugins\Stalk;

use App\FileWriter;
use InvalidArgumentException;

/**
 * `content/plugins/stalk/config.json` — single source of truth for the
 * GLOBAL fetch gate plus user-tweakable limits.
 *
 * Shape:
 *   refresh_interval     ∈ {"3h","10h","1d"}        default "10h"
 *   max_friends          int 1..100                  default 13
 *   max_items_per_friend int 1..10                   default 3
 *   last_refresh_at      int unix ts                 default 0
 *
 * Invalid stored fields are silently replaced with defaults on read
 * (corrupt-recovery, matches view-counter StatsStore style). Setters
 * throw on out-of-range input — UI is responsible for surfacing the error.
 */
final class Config
{
    public const ALLOWED_INTERVAL = ['3h', '10h', '1d'];
    public const DEFAULT_INTERVAL = '10h';

    public const DEFAULT_MAX_FRIENDS = 13;
    public const MAX_FRIENDS_CEILING = 100;

    public const DEFAULT_MAX_ITEMS_PER_FRIEND = 3;
    public const MAX_ITEMS_CEILING = 10;

    private string $path;

    public function __construct(string $storagePath)
    {
        $this->path = $storagePath . '/config.json';
    }

    /** @return array{refresh_interval:string,max_friends:int,max_items_per_friend:int,last_refresh_at:int,previous_refresh_at:int} */
    public function get(): array
    {
        $raw = $this->readRaw();

        $interval = (string) ($raw['refresh_interval'] ?? self::DEFAULT_INTERVAL);
        if (!in_array($interval, self::ALLOWED_INTERVAL, true)) {
            error_log("[stalk] invalid refresh_interval={$interval} — using default");
            $interval = self::DEFAULT_INTERVAL;
        }

        $maxFriends = (int) ($raw['max_friends'] ?? self::DEFAULT_MAX_FRIENDS);
        if ($maxFriends < 1 || $maxFriends > self::MAX_FRIENDS_CEILING) {
            error_log("[stalk] invalid max_friends={$maxFriends} — using default");
            $maxFriends = self::DEFAULT_MAX_FRIENDS;
        }

        $maxItems = (int) ($raw['max_items_per_friend'] ?? self::DEFAULT_MAX_ITEMS_PER_FRIEND);
        if ($maxItems < 1 || $maxItems > self::MAX_ITEMS_CEILING) {
            error_log("[stalk] invalid max_items_per_friend={$maxItems} — using default");
            $maxItems = self::DEFAULT_MAX_ITEMS_PER_FRIEND;
        }

        $lastRefresh = (int) ($raw['last_refresh_at'] ?? 0);
        if ($lastRefresh < 0) {
            $lastRefresh = 0;
        }
        $prevRefresh = (int) ($raw['previous_refresh_at'] ?? 0);
        if ($prevRefresh < 0) {
            $prevRefresh = 0;
        }

        return [
            'refresh_interval'     => $interval,
            'max_friends'          => $maxFriends,
            'max_items_per_friend' => $maxItems,
            'last_refresh_at'      => $lastRefresh,
            'previous_refresh_at'  => $prevRefresh,
        ];
    }

    public function setInterval(string $value): void
    {
        if (!in_array($value, self::ALLOWED_INTERVAL, true)) {
            throw new InvalidArgumentException(
                "refresh_interval must be one of: " . implode(', ', self::ALLOWED_INTERVAL),
            );
        }
        $this->merge(['refresh_interval' => $value]);
    }

    public function setMaxFriends(int $value): void
    {
        if ($value < 1 || $value > self::MAX_FRIENDS_CEILING) {
            throw new InvalidArgumentException(
                "max_friends must be between 1 and " . self::MAX_FRIENDS_CEILING,
            );
        }
        $this->merge(['max_friends' => $value]);
    }

    public function setMaxItemsPerFriend(int $value): void
    {
        if ($value < 1 || $value > self::MAX_ITEMS_CEILING) {
            throw new InvalidArgumentException(
                "max_items_per_friend must be between 1 and " . self::MAX_ITEMS_CEILING,
            );
        }
        $this->merge(['max_items_per_friend' => $value]);
    }

    /**
     * Shift the gate timestamps. The PREVIOUS batch's `last_refresh_at`
     * becomes `previous_refresh_at` so we can answer "what was added since
     * the last batch?" — used by /stalk to tag NEW items.
     */
    public function markRefreshed(int $ts): void
    {
        $current = $this->get()['last_refresh_at'];
        $this->merge([
            'previous_refresh_at' => $current,
            'last_refresh_at'     => max(0, $ts),
        ]);
    }

    /** Newest batch boundary — useful for "new since" comparisons. */
    public function previousRefreshAt(): int
    {
        return $this->get()['previous_refresh_at'];
    }

    public function intervalSeconds(): int
    {
        return match ($this->get()['refresh_interval']) {
            '3h'  => 10800,
            '10h' => 36000,
            '1d'  => 86400,
        };
    }

    public function maxFriends(): int
    {
        return $this->get()['max_friends'];
    }

    public function maxItemsPerFriend(): int
    {
        return $this->get()['max_items_per_friend'];
    }

    public function isStale(): bool
    {
        return (time() - $this->get()['last_refresh_at']) >= $this->intervalSeconds();
    }

    /** @param array<string,mixed> $patch */
    private function merge(array $patch): void
    {
        $current = $this->get();
        $next = array_merge($current, $patch);

        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o755, recursive: true);
        }
        FileWriter::writeAtomic(
            $this->path,
            (string) json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            0o600,
        );
    }

    /** @return array<string,mixed> */
    private function readRaw(): array
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
            error_log("[stalk] corrupt config.json — using defaults");
            return [];
        }
        return $data;
    }
}
