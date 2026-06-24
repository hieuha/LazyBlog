<?php

declare(strict_types=1);

namespace App;

/**
 * Event payload dispatched once per successful `POST /admin/save`.
 *
 * Plugins subscribe via `PluginContext::onPostSave()` to react — typically
 * to mint gamification currency, refresh an external cache, or notify a
 * webhook. Dispatch happens AFTER `PostRepository::save()` returns
 * successfully and AFTER cache invalidation, so listeners can safely
 * re-read the index and see the new post.
 *
 * `isNew` distinguishes first-save (no previous filename) from edits.
 * `published` is `!draft` from the saved frontmatter.
 */
final class PostSaveEvent
{
    public function __construct(
        public readonly string $slug,
        public readonly bool $isNew,
        public readonly bool $published,
        public readonly int $savedAt,
    ) {
    }
}
