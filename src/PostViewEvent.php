<?php

declare(strict_types=1);

namespace App;

/**
 * Event payload dispatched once per `GET /posts/{slug}` render.
 *
 * Plugins subscribe via `PluginContext::onPostView()` to react. The dispatch
 * happens BEFORE the response body is flushed so listeners may call
 * `setcookie()` or modify headers (e.g. view-counter plugin minting `lz_uid`).
 */
final class PostViewEvent
{
    public function __construct(
        public readonly string $slug,
        public readonly string $userAgent,
        public readonly int $requestTime,
    ) {
    }
}
