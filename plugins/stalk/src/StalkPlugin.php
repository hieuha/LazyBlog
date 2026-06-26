<?php

declare(strict_types=1);

namespace Plugins\Stalk;

use App\Csrf;
use App\Http;
use App\Plugin;
use App\PluginContext;
use App\PluginManifest;
use InvalidArgumentException;
use Throwable;

/**
 * Stalk plugin entry point.
 *
 * Public surface:
 *   GET  /stalk                       — aggregated feed (visitor-facing,
 *                                       triggers refreshStale opportunistically)
 *
 * Admin surface (auto-wrapped with Auth::requireAuth() + must POST with CSRF):
 *   GET  /admin/stalk                 — pure management UI (no refresh)
 *   POST /admin/stalk/add             — add a friend URL (probe-validate-create)
 *   POST /admin/stalk/remove/{id}     — drop a friend + purge their cache
 *   POST /admin/stalk/refresh-now     — force refreshAll
 *   POST /admin/stalk/config          — update interval / max_friends / max_items
 */
final class StalkPlugin implements Plugin
{
    private const FLASH_KEY = 'stalk_flash';

    /** Server-side cap on the friend handle string (UI also enforces). */
    private const HANDLE_MAX_LEN = 60;

    public function manifest(): PluginManifest
    {
        /** @var array<string,mixed> $data */
        $data = json_decode((string) file_get_contents(__DIR__ . '/../manifest.json'), true);
        return PluginManifest::fromArray($data);
    }

    public function register(PluginContext $ctx): void
    {
        require_once __DIR__ . '/FriendStore.php';
        require_once __DIR__ . '/PostCache.php';
        require_once __DIR__ . '/Config.php';
        require_once __DIR__ . '/HostGuard.php';
        require_once __DIR__ . '/FeedFetcher.php';
        require_once __DIR__ . '/FeedParser.php';
        require_once __DIR__ . '/RefreshService.php';

        $storage   = $ctx->storagePath();
        $store     = new FriendStore($storage);
        $cache     = new PostCache($storage);
        $config    = new Config($storage);
        $fetcher   = new FeedFetcher();
        $parser    = new FeedParser();
        $refresher = new RefreshService($store, $cache, $config, $fetcher, $parser);

        $ctx->css('stalk.css');
        $ctx->nav('Stalk', '/stalk', 'header');

        $ctx->get('/stalk', function () use ($ctx, $store, $cache, $refresher): void {
            $this->showPublic($ctx, $store, $cache, $refresher);
        });

        $ctx->adminGet('/admin/stalk', function () use ($ctx, $store, $config): void {
            $this->showAdmin($ctx, $store, $config);
        });

        $ctx->adminPost('/admin/stalk/add', function () use ($store, $config, $fetcher, $parser, $refresher): void {
            $this->handleAdd($store, $config, $fetcher, $parser, $refresher);
        });

        $ctx->adminPost('/admin/stalk/remove/{id}', function (array $params) use ($store, $cache): void {
            $this->handleRemove($store, $cache, (string) ($params['id'] ?? ''));
        });

        $ctx->adminPost('/admin/stalk/refresh-now', function () use ($refresher): void {
            $this->handleRefreshNow($refresher);
        });

        $ctx->adminPost('/admin/stalk/config', function () use ($config): void {
            $this->handleConfig($config);
        });
    }

    private function showPublic(PluginContext $ctx, FriendStore $store, PostCache $cache, RefreshService $refresher): void
    {
        // Visitor-facing opportunistic refresh; gate decides whether to actually fetch.
        try {
            $refresher->refreshStale();
        } catch (Throwable $e) {
            error_log('[stalk] /stalk refresh failed: ' . $e->getMessage());
        }

        $friends = $store->all();
        $handles = [];
        foreach ($friends as $f) {
            $handles[(string) $f['id']] = [
                'handle'   => (string) ($f['handle'] ?? ''),
                'blog_url' => (string) ($f['blog_url'] ?? ''),
            ];
        }

        // Resolve the Config singleton from the plugin's shared state so the
        // view can show "N new since last refresh". We grab a fresh instance
        // here — Config is just a thin file wrapper, allocation is cheap.
        $config = new Config($ctx->storagePath());

        $cfg = $config->get();
        $ctx->view('public-index', [
            'title'               => 'Stalk',
            'items'               => $cache->all(),
            'handles'             => $handles,
            'friend_count'        => count($friends),
            'previous_refresh_at' => (int) $cfg['previous_refresh_at'],
            'last_refresh_at'     => (int) $cfg['last_refresh_at'],
        ]);
    }

    private function showAdmin(PluginContext $ctx, FriendStore $store, Config $config): void
    {
        $flash = $_SESSION[self::FLASH_KEY] ?? null;
        unset($_SESSION[self::FLASH_KEY]);

        $ctx->view('admin-index', [
            'title'    => 'Stalk · Admin',
            'friends'  => $store->all(),
            'config'   => $config->get(),
            'allowed_intervals' => Config::ALLOWED_INTERVAL,
            'max_friends_ceiling' => Config::MAX_FRIENDS_CEILING,
            'max_items_ceiling'   => Config::MAX_ITEMS_CEILING,
            'flash'    => $flash,
            'csrf'     => Csrf::token(),
        ]);
    }

    private function handleAdd(FriendStore $store, Config $config, FeedFetcher $fetcher, FeedParser $parser, RefreshService $refresher): void
    {
        Csrf::requireValid();

        if (count($store->all()) >= $config->maxFriends()) {
            $this->flashErr("friend limit reached (max {$config->maxFriends()}) — raise it in Config or remove a friend");
            $this->redirectAdmin();
        }

        $url    = rtrim(trim((string) ($_POST['blog_url'] ?? '')), '/');
        $handle = trim((string) ($_POST['handle'] ?? ''));

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->flashErr('blog URL is required and must be a valid http(s) URL');
            $this->redirectAdmin();
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            $this->flashErr('only http:// and https:// URLs are accepted');
            $this->redirectAdmin();
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (HostGuard::isForbidden($host)) {
            $this->flashErr('loopback / private / link-local addresses are not allowed');
            $this->redirectAdmin();
        }
        if ($store->findByBlogUrl($url) !== null) {
            $this->flashErr('already added');
            $this->redirectAdmin();
        }

        try {
            $body   = $fetcher->fetch($url . '/feed.xml');
            $parsed = $parser->parse($body);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'not a LazyBlog blog')) {
                $this->flashErr('not a LazyBlog blog (generator tag missing or wrong)');
            } else {
                $this->flashErr('could not fetch: ' . $msg);
            }
            $this->redirectAdmin();
        }

        if ($handle === '') {
            $handle = $parsed['channel_title'] !== '' ? $parsed['channel_title'] : $host;
        }
        // Server-side handle cap (UI maxlength is a hint, not a guarantee).
        if (function_exists('mb_substr')) {
            $handle = mb_substr($handle, 0, self::HANDLE_MAX_LEN);
        } else {
            $handle = substr($handle, 0, self::HANDLE_MAX_LEN);
        }

        // Per-friend max_items: operator picks at add time. Defaults to the
        // global Config value; null means "fall back to config at refresh
        // time", so omitting the form field also works.
        $maxItems = null;
        if (isset($_POST['max_items']) && $_POST['max_items'] !== '') {
            $candidate = (int) $_POST['max_items'];
            if ($candidate >= 1 && $candidate <= Config::MAX_ITEMS_CEILING) {
                $maxItems = $candidate;
            }
        }

        $id = $store->create([
            'blog_url'  => $url,
            'handle'    => $handle,
            'max_items' => $maxItems,
        ]);

        // Populate cache immediately (separate from the batch gate).
        $newRow = $store->find($id);
        if ($newRow !== null) {
            $refresher->refreshOne($newRow);
        }

        $this->flashOk("added {$handle}");
        $this->redirectAdmin();
    }

    private function handleRemove(FriendStore $store, PostCache $cache, string $id): void
    {
        Csrf::requireValid();

        if ($id === '' || $store->find($id) === null) {
            $this->flashErr('friend not found');
            $this->redirectAdmin();
        }
        $cache->removeByFriend($id);
        $store->delete($id);
        $this->flashOk('removed');
        $this->redirectAdmin();
    }

    private function handleRefreshNow(RefreshService $refresher): void
    {
        Csrf::requireValid();
        try {
            $r = $refresher->refreshAll();
            $this->flashOk("refreshed={$r['refreshed']} errored={$r['errored']}");
        } catch (Throwable $e) {
            $this->flashErr('refresh failed: ' . $e->getMessage());
        }
        $this->redirectAdmin();
    }

    private function handleConfig(Config $config): void
    {
        Csrf::requireValid();

        $errors = [];

        if (isset($_POST['refresh_interval'])) {
            try {
                $config->setInterval((string) $_POST['refresh_interval']);
            } catch (InvalidArgumentException $e) {
                $errors[] = 'interval: ' . $e->getMessage();
            }
        }
        if (isset($_POST['max_friends']) && $_POST['max_friends'] !== '') {
            try {
                $config->setMaxFriends((int) $_POST['max_friends']);
            } catch (InvalidArgumentException $e) {
                $errors[] = 'max_friends: ' . $e->getMessage();
            }
        }
        if (isset($_POST['max_items_per_friend']) && $_POST['max_items_per_friend'] !== '') {
            try {
                $config->setMaxItemsPerFriend((int) $_POST['max_items_per_friend']);
            } catch (InvalidArgumentException $e) {
                $errors[] = 'max_items_per_friend: ' . $e->getMessage();
            }
        }

        if ($errors !== []) {
            $this->flashErr(implode(' · ', $errors));
        } else {
            $this->flashOk('config saved');
        }
        $this->redirectAdmin();
    }

    private function flashOk(string $msg): void
    {
        $_SESSION[self::FLASH_KEY] = ['type' => 'ok', 'msg' => $msg];
    }

    private function flashErr(string $msg): void
    {
        $_SESSION[self::FLASH_KEY] = ['type' => 'err', 'msg' => $msg];
    }

    private function redirectAdmin(): never
    {
        Http::redirect('/admin/stalk');
    }
}
