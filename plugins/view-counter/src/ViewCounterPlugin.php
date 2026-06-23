<?php

declare(strict_types=1);

namespace Plugins\ViewCounter;

use App\Plugin;
use App\PluginContext;
use App\PluginManifest;
use App\PostViewEvent;

/**
 * View counter plugin: dedups views per anonymous `lz_uid` cookie, filters
 * obvious bots by User-Agent, stores running totals in a sidecar JSON,
 * contributes a `N lượt xem` badge to the public post page.
 *
 * Storage lives at `content/plugins/view-counter/` so the post `.md` files —
 * and their mtime-driven cache pyramid — stay untouched.
 */
final class ViewCounterPlugin implements Plugin
{
    public function manifest(): PluginManifest
    {
        /** @var array<string,mixed> $data */
        $data = json_decode((string) file_get_contents(__DIR__ . '/../manifest.json'), true);
        return PluginManifest::fromArray($data);
    }

    public function register(PluginContext $ctx): void
    {
        require_once __DIR__ . '/BotFilter.php';
        require_once __DIR__ . '/CookieIdentity.php';
        require_once __DIR__ . '/StatsStore.php';

        $store = new StatsStore($ctx->storagePath());

        $ctx->onPostView(function (PostViewEvent $event) use ($store): void {
            if (BotFilter::isBot($event->userAgent)) {
                return;
            }
            $uid = CookieIdentity::getOrMint();
            $store->recordView($event->slug, $uid);
        });

        $ctx->onPostMeta(function (array $context) use ($store): ?string {
            $slug = (string) ($context['slug'] ?? '');
            if ($slug === '') {
                return null;
            }
            $views = $store->getCount($slug);
            ob_start();
            require __DIR__ . '/../views/badge.php';
            return (string) ob_get_clean();
        });
    }
}
