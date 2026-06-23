<?php

declare(strict_types=1);

namespace App;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads/writes posts as markdown files under content/posts/.
 *
 * Maintains a JSON index (content/.index.json) of frontmatter-only entries
 * so listing pages can avoid touching every body. Index is rebuilt lazily
 * when missing or when any `.md` mtime is newer than the index mtime.
 */
final class PostRepository
{
    private string $contentDir;
    private string $postsDir;
    private string $indexPath;

    public function __construct(?string $contentDir = null)
    {
        $this->contentDir = $contentDir ?? (__DIR__ . '/../content');
        $this->postsDir = $this->contentDir . '/posts';
        $this->indexPath = $this->contentDir . '/.index.json';
    }

    public function postsDir(): string
    {
        return $this->postsDir;
    }

    /**
     * @return list<array{slug:string,title:string,date:string,tags:list<string>,draft:bool,icon:?string,summary:?string,file:string,mtime:int}>
     */
    public function all(): array
    {
        if ($this->indexStale()) {
            $this->rebuildIndex();
        }

        $raw = @file_get_contents($this->indexPath);
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        // 'file' is stored as a basename (portable across environments —
        // Docker bind mounts and host paths differ). Resolve to absolute
        // path against this instance's postsDir.
        foreach ($decoded as &$entry) {
            if (isset($entry['file']) && is_string($entry['file']) && !str_contains($entry['file'], '/')) {
                $entry['file'] = $this->postsDir . '/' . $entry['file'];
            }
        }
        unset($entry);

        return $decoded;
    }

    /**
     * @return list<array{slug:string,title:string,date:string,tags:list<string>,draft:bool,icon:?string,summary:?string,file:string,mtime:int}>
     */
    public function published(): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        // Compare on the date part only — ISO datetime entries would
        // otherwise lexicographically exceed plain `YYYY-MM-DD` today
        // and get filtered as future posts. Also reject empty date
        // strings outright so a malformed frontmatter entry can't slip
        // past the filter via the `'' <= today` truthiness trick.
        return array_values(array_filter(
            $this->all(),
            static function (array $e) use ($today): bool {
                if ($e['draft'] ?? false) {
                    return false;
                }
                $dateKey = substr((string) ($e['date'] ?? ''), 0, 10);
                return $dateKey !== '' && $dateKey <= $today;
            },
        ));
    }

    /**
     * Enumerate every distinct series across published posts, with
     * part count + earliest/latest date for display on the /series
     * index page. Sorted by latest activity desc (recently-updated
     * series first).
     *
     * @return list<array{slug:string, title:string, count:int, firstDate:string, lastDate:string}>
     */
    public function allSeries(): array
    {
        $byKey = [];
        foreach ($this->published() as $entry) {
            $slug = $entry['series'] ?? null;
            if (!is_string($slug) || $slug === '') continue;
            if (!isset($byKey[$slug])) {
                $byKey[$slug] = [
                    'slug' => $slug,
                    'title' => ucwords(str_replace(['-', '_'], ' ', $slug)),
                    'count' => 0,
                    'firstDate' => $entry['date'],
                    'lastDate' => $entry['date'],
                ];
            }
            $byKey[$slug]['count']++;
            if ($entry['date'] < $byKey[$slug]['firstDate']) $byKey[$slug]['firstDate'] = $entry['date'];
            if ($entry['date'] > $byKey[$slug]['lastDate'])  $byKey[$slug]['lastDate']  = $entry['date'];
        }
        $list = array_values($byKey);
        usort($list, static fn (array $a, array $b): int => strcmp($b['lastDate'], $a['lastDate']));
        return $list;
    }

    /**
     * Return all published posts in the given series, ordered by
     * explicit `part:` ascending, then date ascending. The chronological
     * fallback means a writer who doesn't bother with `part:` still gets
     * the right reading order (oldest → newest).
     *
     * @return list<array<string,mixed>>
     */
    public function bySeries(string $series): array
    {
        $matching = array_values(array_filter(
            $this->published(),
            static fn (array $e): bool => isset($e['series']) && $e['series'] === $series,
        ));
        usort($matching, static function (array $a, array $b): int {
            $pa = $a['part'] ?? null;
            $pb = $b['part'] ?? null;
            if ($pa !== null && $pb !== null && $pa !== $pb) {
                return $pa <=> $pb;
            }
            // Posts with explicit part come before un-numbered ones.
            if ($pa !== null && $pb === null) return -1;
            if ($pa === null && $pb !== null) return 1;
            return strcmp($a['date'], $b['date']);   // chronological
        });
        return $matching;
    }

    /**
     * @return list<array{slug:string,title:string,date:string,tags:list<string>,draft:bool,icon:?string,summary:?string,file:string,mtime:int}>
     */
    public function byTag(string $tag): array
    {
        return array_values(array_filter(
            $this->published(),
            static fn (array $e): bool => in_array($tag, $e['tags'], true),
        ));
    }

    /**
     * Slice a published-post list with prev/next metadata, used by paginated
     * views (home, tag pages).
     *
     * @param list<array<string,mixed>> $entries
     * @return array{posts:list<array<string,mixed>>, page:int, totalPages:int, total:int, perPage:int}
     */
    public static function paginate(array $entries, int $page, int $perPage = 10): array
    {
        $perPage = max(1, $perPage);
        $total = count($entries);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

        return [
            'posts' => array_slice($entries, $offset, $perPage),
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'perPage' => $perPage,
        ];
    }

    public function bySlug(string $slug): ?Post
    {
        foreach ($this->all() as $entry) {
            if ($entry['slug'] === $slug) {
                return $this->loadFromFile($entry['file']);
            }
        }
        return null;
    }

    public function rawMarkdownBySlug(string $slug): ?string
    {
        foreach ($this->all() as $entry) {
            if ($entry['slug'] === $slug) {
                $raw = @file_get_contents($entry['file']);
                return $raw === false ? null : $raw;
            }
        }
        return null;
    }

    /**
     * @return list<string>
     */
    public function allTags(): array
    {
        $tags = [];
        foreach ($this->published() as $entry) {
            foreach ($entry['tags'] as $t) {
                $tags[$t] = true;
            }
        }
        $list = array_keys($tags);
        sort($list);
        return $list;
    }

    /**
     * Post-count per tag across published posts. Useful for surfaces that
     * need to rank tags by activity (e.g. the TAG-SPECIALIST badge).
     *
     * @return array<string,int>
     */
    public function tagCounts(): array
    {
        $counts = [];
        foreach ($this->published() as $entry) {
            foreach ($entry['tags'] as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }
        return $counts;
    }

    public function invalidateCaches(): void
    {
        @unlink($this->indexPath);
        @unlink($this->contentDir . '/.llms.txt');
        @unlink($this->contentDir . '/.llms-full.txt');
        @unlink($this->contentDir . '/.feed.xml');
    }

    /**
     * Persist a Post to disk as `YYYY-MM-DD-{slug}.md`. Validates the slug,
     * serializes frontmatter via Symfony YAML, writes atomically, then
     * invalidates derived caches.
     *
     * @param string|null $previousFilename When editing, the file the post was
     *   loaded from. If the new filename differs (slug or date changed) the
     *   previous file is removed after the new one is written.
     */
    public function save(Post $post, ?string $previousFilename = null): string
    {
        if (!SlugUtil::valid($post->slug)) {
            throw new RuntimeException("Invalid slug: {$post->slug}");
        }
        // Accept either `YYYY-MM-DD` (legacy) or ISO datetime
        // `YYYY-MM-DDTHH:MM:SS±TZ` (enables wall-clock-aware features).
        if (!preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}([+-]\d{2}:\d{2}|Z)?)?$/', $post->date)) {
            throw new RuntimeException("Invalid date format: {$post->date}");
        }

        if (!is_dir($this->postsDir)) {
            mkdir($this->postsDir, 0775, true);
        }

        // Filename always uses the date part only — uniqueness is by day,
        // and `:` would be illegal on many filesystems anyway.
        $filenameDate = substr($post->date, 0, 10);
        $filename = $filenameDate . '-' . $post->slug . '.md';
        $path = $this->postsDir . '/' . $filename;

        // Refuse to clobber an unrelated post with a different previous filename.
        if (is_file($path) && $previousFilename !== null && basename($previousFilename) !== $filename) {
            throw new RuntimeException("Slug taken: {$post->slug}");
        }

        $meta = [
            'title' => $post->title,
            'date' => $post->date,
        ];
        if ($post->author !== null && $post->author !== '') {
            $meta['author'] = $post->author;
        }
        if ($post->tags !== []) {
            $meta['tags'] = array_values($post->tags);
        }
        $meta['draft'] = $post->draft;
        if ($post->icon !== null && $post->icon !== '') {
            $meta['icon'] = $post->icon;
        }
        if ($post->summary !== null && $post->summary !== '') {
            $meta['summary'] = $post->summary;
        }
        if ($post->image !== null && $post->image !== '') {
            $meta['image'] = $post->image;
        }
        if ($post->series !== null && $post->series !== '') {
            $meta['series'] = $post->series;
        }
        if ($post->part !== null) {
            $meta['part'] = $post->part;
        }

        // Inline tags array (`[a, b]`) is friendlier than YAML's default
        // block style for short lists.
        $yaml = Yaml::dump($meta, 2, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

        $body = rtrim($post->bodyMarkdown, "\n") . "\n";
        $contents = "---\n{$yaml}---\n\n{$body}";

        FileWriter::writeAtomic($path, $contents);

        // Slug or date rename: delete the old file once the new one is on disk.
        if ($previousFilename !== null && $previousFilename !== '' && basename($previousFilename) !== $filename) {
            $oldPath = $this->postsDir . '/' . basename($previousFilename);
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        $this->invalidateCaches();
        return $path;
    }

    public function delete(string $slug): bool
    {
        foreach ($this->all() as $entry) {
            if ($entry['slug'] === $slug) {
                $ok = @unlink($entry['file']);
                $this->invalidateCaches();
                return $ok;
            }
        }
        return false;
    }

    private function indexStale(): bool
    {
        if (!is_file($this->indexPath)) {
            return true;
        }
        $indexMtime = (int) filemtime($this->indexPath);
        foreach ($this->scanPostFiles() as $file) {
            if (filemtime($file) > $indexMtime) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>
     */
    private function scanPostFiles(): array
    {
        if (!is_dir($this->postsDir)) {
            return [];
        }
        $files = glob($this->postsDir . '/*.md') ?: [];
        sort($files);
        return $files;
    }

    public function rebuildIndex(): void
    {
        $entries = [];
        foreach ($this->scanPostFiles() as $file) {
            $raw = file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            [$meta, $body] = FrontmatterParser::parse($raw);

            $basename = basename($file, '.md');
            // Filename pattern: YYYY-MM-DD-slug
            if (preg_match('/^(\d{4}-\d{2}-\d{2})-(.+)$/', $basename, $m)) {
                $dateFromName = $m[1];
                $slugFromName = $m[2];
            } else {
                $dateFromName = '';
                $slugFromName = $basename;
            }

            /** @var list<string> $tags */
            $tags = [];
            if (isset($meta['tags']) && is_array($meta['tags'])) {
                $tags = array_values(array_filter(array_map(
                    static fn (mixed $t): string => is_string($t) ? strtolower(trim($t)) : '',
                    $meta['tags'],
                )));
            }

            $entries[] = [
                'slug' => is_string($meta['slug'] ?? null) && $meta['slug'] !== ''
                    ? (string) $meta['slug']
                    : $slugFromName,
                'title' => (string) ($meta['title'] ?? $slugFromName),
                'date' => self::normalizeDate($meta['date'] ?? null, $dateFromName),
                'tags' => $tags,
                'draft' => (bool) ($meta['draft'] ?? false),
                'icon' => isset($meta['icon']) ? (string) $meta['icon'] : null,
                'summary' => isset($meta['summary']) ? (string) $meta['summary'] : null,
                'author' => self::resolveAuthor($meta['author'] ?? null),
                'image' => self::safeImage(isset($meta['image']) && is_string($meta['image']) ? (string) $meta['image'] : null),
                'series' => isset($meta['series']) && is_string($meta['series']) && $meta['series'] !== '' ? strtolower(trim($meta['series'])) : null,
                'part' => isset($meta['part']) && is_numeric($meta['part']) ? (int) $meta['part'] : null,
                // Store basename only — keeps the index portable between
                // Docker (/var/www/html/...) and host (./content/...) paths.
                // Resolved back to absolute in all().
                'file' => basename($file),
                'mtime' => (int) filemtime($file),
            ];

            // Mark $body as used — body is intentionally NOT cached in the
            // index file. Listing pages only need frontmatter.
            unset($body);
        }

        // Sort by date desc, title asc as tiebreaker.
        usort($entries, static function (array $a, array $b): int {
            $c = strcmp($b['date'], $a['date']);
            return $c !== 0 ? $c : strcmp($a['title'], $b['title']);
        });

        if (!is_dir($this->contentDir)) {
            mkdir($this->contentDir, 0775, true);
        }

        $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode index');
        }
        FileWriter::writeAtomic($this->indexPath, $json);

        // Index rebuild means posts may have changed — invalidate downstream
        // caches so /llms.txt and /feed.xml regenerate on next request instead
        // of serving stale data.
        @unlink($this->contentDir . '/.llms.txt');
        @unlink($this->contentDir . '/.llms-full.txt');
        @unlink($this->contentDir . '/.feed.xml');
    }

    private function loadFromFile(string $file): ?Post
    {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        [$meta, $body] = FrontmatterParser::parse($raw);

        $basename = basename($file, '.md');
        if (preg_match('/^(\d{4}-\d{2}-\d{2})-(.+)$/', $basename, $m)) {
            $dateFromName = $m[1];
            $slugFromName = $m[2];
        } else {
            $dateFromName = '';
            $slugFromName = $basename;
        }

        /** @var list<string> $tags */
        $tags = [];
        if (isset($meta['tags']) && is_array($meta['tags'])) {
            $tags = array_values(array_filter(array_map(
                static fn (mixed $t): string => is_string($t) ? strtolower(trim($t)) : '',
                $meta['tags'],
            )));
        }

        return new Post(
            slug: is_string($meta['slug'] ?? null) && $meta['slug'] !== '' ? (string) $meta['slug'] : $slugFromName,
            title: (string) ($meta['title'] ?? $slugFromName),
            date: self::normalizeDate($meta['date'] ?? null, $dateFromName),
            tags: $tags,
            draft: (bool) ($meta['draft'] ?? false),
            bodyMarkdown: $body,
            icon: isset($meta['icon']) ? (string) $meta['icon'] : null,
            summary: isset($meta['summary']) ? (string) $meta['summary'] : null,
            author: self::resolveAuthor($meta['author'] ?? null),
            image: isset($meta['image']) && is_string($meta['image']) ? (string) $meta['image'] : null,
            series: isset($meta['series']) && is_string($meta['series']) && $meta['series'] !== '' ? strtolower(trim($meta['series'])) : null,
            part: isset($meta['part']) && is_numeric($meta['part']) ? (int) $meta['part'] : null,
        );
    }

    /**
     * Per-post `author:` wins; otherwise fall back to env DEFAULT_AUTHOR;
     * otherwise null (view will hide the field).
     */
    private static function resolveAuthor(mixed $raw): ?string
    {
        if (is_string($raw) && $raw !== '') {
            return $raw;
        }
        $default = Config::get('DEFAULT_AUTHOR', '');
        return ($default !== null && $default !== '') ? $default : null;
    }

    /**
     * Scheme whitelist for `image:` frontmatter / og:image. Mirrors
     * `AboutRepository::safeAvatar()` — only site-relative paths
     * (starting with `/` but not `//`) or absolute http(s) URLs
     * survive. `javascript:`, `data:`, `vbscript:` and friends drop
     * to null so a compromised admin session can't smuggle an
     * active-content URL into the `<meta property="og:image">`
     * rendering path.
     */
    public static function safeImage(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        return preg_match('#^(/(?!/)|https?://)#i', $url) === 1 ? $url : null;
    }

    /**
     * Coerce frontmatter `date:` to a canonical string. Symfony YAML
     * parses bare `2026-06-22` as a Unix int and a quoted ISO datetime as
     * a `\DateTimeInterface`; either is reduced back to a string.
     *
     * Accepted output shapes:
     *   - `YYYY-MM-DD`             (date-only, legacy)
     *   - `YYYY-MM-DDTHH:MM:SS±TZ` (ISO datetime, preserves wall-clock time)
     *
     * A DateTime whose time is exactly midnight is treated as "no time
     * intent" and collapses to date-only so date-only posts coming back
     * from YAML's int-timestamp path don't accidentally become ISO
     * datetimes with a misleading T00:00 component.
     */
    private static function normalizeDate(mixed $raw, string $fallback): string
    {
        if (is_int($raw)) {
            return date('Y-m-d', $raw);
        }
        if ($raw instanceof \DateTimeInterface) {
            if ((int) $raw->format('His') === 0) {
                return $raw->format('Y-m-d');
            }
            return $raw->format('Y-m-d\TH:i:sP');
        }
        if (is_string($raw) && $raw !== '') {
            return $raw;
        }
        return $fallback;
    }
}
