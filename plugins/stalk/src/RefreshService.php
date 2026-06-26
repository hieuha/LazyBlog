<?php

declare(strict_types=1);

namespace Plugins\Stalk;

use Throwable;

/**
 * Orchestrates "fetch a batch of friend feeds, update cache, record status".
 *
 * Gate is GLOBAL: a single `Config::last_refresh_at` timestamp decides
 * whether the entire batch fires or skips. There is no per-friend gating.
 *
 * Mental model: "every <refresh_interval>, one batch refreshes all N
 * friends in parallel. Between batches, every visitor reads cache."
 *
 * Three entry points:
 *
 *   refreshStale()  — gated. Called by `GET /stalk` (visitor-facing) and
 *                     the CLI cron script. Skips entire batch if not stale.
 *   refreshAll()    — unconditional. Called by admin `[ REFRESH NOW ]`.
 *   refreshOne()    — single-friend, no gate. Called by `handleAdd` to
 *                     populate the cache the moment a friend is added.
 *
 * Within a batch, per-friend exceptions are isolated (try/catch) so one
 * down friend cannot abort fetching the rest. A friend's cached posts
 * are preserved untouched when its fetch fails — visitors keep seeing
 * the last known good items until the next successful refresh.
 */
final class RefreshService
{
    public function __construct(
        private FriendStore $store,
        private PostCache $cache,
        private Config $config,
        private FeedFetcher $fetcher,
        private FeedParser $parser,
    ) {
    }

    /** @return array{refreshed:int,errored:int,skipped:int,gated:bool} */
    public function refreshStale(): array
    {
        if (!$this->config->isStale()) {
            return [
                'refreshed' => 0,
                'errored'   => 0,
                'skipped'   => count($this->store->all()),
                'gated'     => true,
            ];
        }
        return $this->runBatch();
    }

    /** @return array{refreshed:int,errored:int,skipped:int,gated:bool} */
    public function refreshAll(): array
    {
        return $this->runBatch();
    }

    /**
     * Single-friend refresh used by `handleAdd` for first-time cache
     * population. Does NOT touch the global gate timestamp — a single
     * friend isn't a batch.
     *
     * @param array<string,mixed> $friend
     * @return array{ok:bool,count?:int,error?:string}
     */
    public function refreshOne(array $friend): array
    {
        $id = (string) ($friend['id'] ?? '');
        if ($id === '') {
            return ['ok' => false, 'error' => 'missing friend id'];
        }

        try {
            $url    = $this->feedUrl((string) ($friend['blog_url'] ?? ''));
            $body   = $this->fetcher->fetch($url);
            $parsed = $this->parser->parse($body);
            $items  = array_slice($parsed['items'], 0, $this->itemsCap($friend));
            $this->cache->replaceForFriend($id, $items);
            $this->store->update($id, [
                'last_fetched_at' => time(),
                'last_status'     => 'ok',
                'last_http_code'  => 200, // fetch() only returns on 200
                'last_error'      => null,
            ]);
            return ['ok' => true, 'count' => count($items)];
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            // Try to extract the HTTP code out of "unexpected HTTP NNN" so
            // the admin row's [HTTP NNN] badge stays accurate for the
            // first-time-fetch (handleAdd) error path too.
            $code = 0;
            if (preg_match('/HTTP (\d+)/', $msg, $m) === 1) {
                $code = (int) $m[1];
            }
            $this->store->update($id, [
                'last_status'    => 'error',
                'last_http_code' => $code,
                'last_error'     => $msg,
            ]);
            return ['ok' => false, 'error' => $msg];
        }
    }

    /** @return array{refreshed:int,errored:int,skipped:int,gated:bool} */
    private function runBatch(): array
    {
        $friends = $this->store->all();
        if ($friends === []) {
            // Still mark the gate so empty installs don't trigger fetch
            // every page view forever.
            $this->config->markRefreshed(time());
            return ['refreshed' => 0, 'errored' => 0, 'skipped' => 0, 'gated' => false];
        }

        // Bump the gate BEFORE the network call: a second visitor arriving
        // mid-fetch sees `isStale() === false` and skips. Accept the rare
        // duplicate batch when two requests cross within the same write.
        $this->config->markRefreshed(time());

        $urls = [];
        foreach ($friends as $f) {
            $id = (string) ($f['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $urls[$id] = $this->feedUrl((string) ($f['blog_url'] ?? ''));
        }

        $results = $this->fetcher->fetchMany($urls);

        $refreshed = 0;
        $errored   = 0;
        foreach ($friends as $f) {
            $id = (string) ($f['id'] ?? '');
            if ($id === '' || !isset($results[$id])) {
                continue;
            }
            $r = $results[$id];

            $httpCode = (int) ($r['http_code'] ?? 0);
            try {
                if (!($r['ok'] ?? false)) {
                    throw new \RuntimeException((string) ($r['error'] ?? 'unknown error'));
                }
                $parsed = $this->parser->parse((string) $r['body']);
                $items  = array_slice($parsed['items'], 0, $this->itemsCap($f));
                $this->cache->replaceForFriend($id, $items);
                $this->store->update($id, [
                    'last_fetched_at' => time(),
                    'last_status'     => 'ok',
                    'last_http_code'  => $httpCode,
                    'last_error'      => null,
                ]);
                $refreshed++;
            } catch (Throwable $e) {
                // Per-friend isolation — record + carry on. Cache UNTOUCHED
                // so the friend's last known good items stay visible.
                $this->store->update($id, [
                    'last_status'    => 'error',
                    'last_http_code' => $httpCode,
                    'last_error'     => $e->getMessage(),
                ]);
                $errored++;
            }
        }

        return [
            'refreshed' => $refreshed,
            'errored'   => $errored,
            'skipped'   => 0,
            'gated'     => false,
        ];
    }

    private function feedUrl(string $blogUrl): string
    {
        return rtrim($blogUrl, '/') . '/feed.xml';
    }

    /**
     * Per-friend item cap. Friend row's `max_items` wins when set; otherwise
     * fall back to the Config default. Defense-in-depth clamp to the
     * MAX_ITEMS_CEILING in case someone hand-edited friends.json.
     *
     * @param array<string,mixed> $friend
     */
    private function itemsCap(array $friend): int
    {
        $perFriend = $friend['max_items'] ?? null;
        $cap = is_int($perFriend) && $perFriend >= 1
            ? min($perFriend, Config::MAX_ITEMS_CEILING)
            : $this->config->maxItemsPerFriend();
        return max(1, $cap);
    }
}
