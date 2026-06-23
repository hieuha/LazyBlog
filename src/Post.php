<?php

declare(strict_types=1);

namespace App;

/**
 * Immutable post value object — single markdown file mapped to typed fields.
 *
 * Body is kept as raw markdown; rendering happens lazily via MarkdownRenderer
 * so list views can skip the expensive parse step entirely.
 */
final class Post
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly string $date,
        public readonly array $tags,
        public readonly bool $draft,
        public readonly string $bodyMarkdown,
        public readonly ?string $icon = null,
        public readonly ?string $summary = null,
        public readonly ?string $author = null,
        public readonly ?string $image = null,
        public readonly ?string $series = null,
        public readonly ?int $part = null,
    ) {
    }

    /**
     * Pull the first `![alt](url)` URL out of the body. Used as a fallback
     * og:image source when the post has no frontmatter `image:` and no
     * site-wide SITE_OG_IMAGE — so social previews still get a thumbnail
     * the moment the post has any image at all.
     *
     * The regex extract bypasses CommonMark's `allow_unsafe_links`
     * filter (which operates on rendered HTML, not raw markdown), so
     * run the captured URL through `PostRepository::safeImage()` to
     * keep `javascript:` / `data:` schemes out of `<meta property="og:image">`
     * and the admin form's Social-image pre-fill.
     */
    public function firstBodyImage(): ?string
    {
        if (preg_match('/!\[[^\]]*\]\(([^)\s]+)/u', $this->bodyMarkdown, $m)) {
            return PostRepository::safeImage($m[1]);
        }
        return null;
    }

    /**
     * First ~200 chars of body with markdown punctuation stripped, used for
     * listings, RSS descriptions, and llms.txt entries.
     */
    public function excerpt(int $max = 200): string
    {
        if ($this->summary !== null && $this->summary !== '') {
            return $this->summary;
        }

        $plain = (string) preg_replace('/[`*_>#\-\[\]()]/', '', $this->bodyMarkdown);
        $plain = (string) preg_replace('/\s+/', ' ', $plain);
        $plain = trim($plain);

        if (mb_strlen($plain) <= $max) {
            return $plain;
        }

        return mb_substr($plain, 0, $max) . '…';
    }

    public function url(): string
    {
        return '/posts/' . $this->slug;
    }

    public function rawUrl(): string
    {
        return '/posts/' . $this->slug . '.md';
    }

    public function pubDateRfc2822(): string
    {
        $tz = Config::get('TIMEZONE', 'UTC');
        try {
            $dt = new \DateTimeImmutable($this->date, new \DateTimeZone((string) $tz));
            return $dt->format(\DateTimeInterface::RFC2822);
        } catch (\Throwable) {
            return $this->date;
        }
    }

    /**
     * Date-only display form. ISO datetime entries collapse to their
     * `YYYY-MM-DD` prefix so visible date labels don't suddenly grow a
     * timezone tail; metadata sinks (RSS, OG, JSON-LD) keep using
     * `$date` directly to preserve the precision when present.
     */
    public function displayDate(): string
    {
        return substr($this->date, 0, 10);
    }

    /**
     * Parsed datetime for the post. Falls back to midday on the date part
     * when the frontmatter holds only `YYYY-MM-DD` (no time). Gamification
     * features that need wall-clock time should also gate on
     * `hasExplicitTime()` so legacy date-only posts don't accidentally
     * trigger e.g. NIGHT-OWL via the midday fallback.
     */
    public function dateTime(): \DateTimeImmutable
    {
        $tz = new \DateTimeZone((string) Config::get('TIMEZONE', 'UTC'));
        try {
            return new \DateTimeImmutable($this->date, $tz);
        } catch (\Throwable) {
            $dateOnly = substr($this->date, 0, 10);
            return new \DateTimeImmutable($dateOnly . 'T12:00:00', $tz);
        }
    }

    /**
     * True when the frontmatter `date:` carries an explicit time component
     * (ISO datetime form like `2024-03-15T02:30:00+07:00`), false when it's
     * a bare `YYYY-MM-DD`. Gates time-of-day-sensitive features.
     */
    public function hasExplicitTime(): bool
    {
        return preg_match('/\d{2}:\d{2}/', $this->date) === 1;
    }
}
