<?php

declare(strict_types=1);

namespace App;

/**
 * Current LazyBlog release version, read from the `VERSION` file at the
 * repo root. Single source of truth — the file is bumped manually as
 * part of the release commit, alongside the git tag.
 *
 * Cached on first read so subsequent calls (footer, meta tag,
 * `/healthz`) are free. Falls back to "unknown" if the file is
 * unreadable; never throws because version is a presentational concern.
 */
final class Version
{
    private static ?string $cached = null;

    public static function get(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $raw = @file_get_contents(__DIR__ . '/../VERSION');
        $value = is_string($raw) ? trim($raw) : '';

        self::$cached = $value !== '' ? $value : 'unknown';
        return self::$cached;
    }
}
