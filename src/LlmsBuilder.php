<?php

declare(strict_types=1);

namespace App;

/**
 * Generates llmstxt.org-style index for AI agent consumption.
 *
 * Single file: `llms.txt` = site description + per-post bullet (one-line
 * summary) + per-series bullet (manifest-driven or derived title +
 * description) + per-tag bullet.
 *
 * Cached under `content/.llms.txt` and invalidated by PostRepository
 * whenever any post is saved or deleted.
 */
final class LlmsBuilder
{
    public function __construct(
        private readonly PostRepository $repo,
        private readonly string $contentDir,
    ) {
    }

    public function indexPath(): string
    {
        return $this->contentDir . '/.llms.txt';
    }

    public function buildIndex(): string
    {
        $siteTitle = (string) Config::get('SITE_TITLE');
        $siteUrl = rtrim((string) Config::get('SITE_URL'), '/');
        $siteDesc = (string) Config::get('SITE_DESCRIPTION', '');

        $out = "# {$siteTitle}\n\n";
        if ($siteDesc !== '') {
            $out .= "> {$siteDesc}\n\n";
        }

        $out .= "## Posts\n\n";
        foreach ($this->repo->published() as $entry) {
            // Password-protected posts are dropped from the LLM-facing index
            // entirely — title, URL, and summary all stay out of the corpus.
            // The whole point of the feature dies if a crawler can still find
            // the post here.
            if (!empty($entry['protected'])) {
                continue;
            }
            $summary = $entry['summary'] ?? '';
            if ($summary === '') {
                // Lightweight excerpt: read body and trim. Acceptable cost here
                // — this builder runs only on save, not per request.
                $post = $this->repo->bySlug($entry['slug']);
                $summary = $post ? $post->excerpt(120) : '';
            }
            $url = "{$siteUrl}/posts/{$entry['slug']}.md";
            // Escape characters that would break the markdown list line:
            // `]` and `)` close the link/text spans, newlines split the bullet.
            $safeTitle = self::escapeListField($entry['title']);
            $safeSummary = self::escapeListField($summary);
            $out .= "- [{$safeTitle}]({$url}): {$safeSummary}\n";
        }

        $series = $this->repo->allSeries();
        if ($series !== []) {
            $out .= "\n## Series\n\n";
            foreach ($series as $s) {
                $title = self::escapeListField((string) $s['title']);
                $desc  = self::escapeListField((string) ($s['description'] ?? ''));
                $count = (int) $s['count'];
                $url   = "{$siteUrl}/series/{$s['slug']}";
                $line  = "- [{$title}]({$url}) ({$count} post" . ($count === 1 ? '' : 's') . ')';
                if ($desc !== '') {
                    $line .= ": {$desc}";
                }
                $out .= $line . "\n";
            }
        }

        $tags = $this->repo->allTags();
        if ($tags !== []) {
            $out .= "\n## Tags\n\n";
            foreach ($tags as $tag) {
                $out .= "- {$tag}: {$siteUrl}/tags/{$tag}\n";
            }
        }

        return $out;
    }

    /**
     * Escape characters in user-provided strings that would break a markdown
     * list line: `]` `)` close link/text spans; newlines split the bullet.
     */
    private static function escapeListField(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = str_replace("\n", ' ', $s);
        return str_replace([']', ')'], ['\]', '\)'], $s);
    }

    public function readOrBuildIndex(): string
    {
        $path = $this->indexPath();
        if (is_file($path)) {
            $cached = @file_get_contents($path);
            if ($cached !== false) {
                return $cached;
            }
        }
        $built = $this->buildIndex();
        try { FileWriter::writeAtomic($path, $built); } catch (\Throwable) {}
        return $built;
    }
}
