<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Config;
use App\Csrf;
use App\Http;
use App\Post;
use App\PostRepository;
use App\PostSaveEvent;
use App\SlugUtil;

/**
 * Writer Mode — fullscreen distraction-free editor.
 *
 * `/writer` renders a standalone view (no site chrome) that posts back to
 * `/writer/save` with title + summary + body + mode (`draft` | `publish`).
 * Auth is mandatory; anonymous visitors are routed through `/admin/login`
 * with `next=/writer` so a successful login lands them back in the editor.
 */
final class WriterController
{
    public function __construct(private readonly PostRepository $repo)
    {
    }

    public function show(): void
    {
        Auth::requireAuth();

        // Optional ?slug=foo opens Writer Mode on an existing post — same
        // distraction-free editor surface, but the body + title come from
        // disk and saves go back to the same file instead of creating a
        // new one. The admin list links here via the [ WRITE ] action.
        $slug = isset($_GET['slug']) ? (string) $_GET['slug'] : '';
        $existingPost = null;
        $existingFilename = '';
        if ($slug !== '') {
            $existingPost = $this->repo->bySlug($slug);
            if ($existingPost !== null) {
                $existingFilename = $existingPost->displayDate() . '-' . $existingPost->slug . '.md';
            }
        }

        $viewPath = __DIR__ . '/../../views/writer.php';
        $title = $existingPost !== null ? 'Writer: ' . $existingPost->title : 'Writer';
        $csrf = Csrf::token();
        require $viewPath;
    }

    public function save(): void
    {
        Auth::requireAuth();
        Csrf::requireValid();

        $mode = (string) ($_POST['mode'] ?? 'draft');
        $isPublish = $mode === 'publish';

        $title = trim((string) ($_POST['title'] ?? ''));
        $summary = trim((string) ($_POST['summary'] ?? ''));
        $body = (string) ($_POST['body'] ?? '');
        $existingSlug = trim((string) ($_POST['existing_slug'] ?? ''));
        $existingFilename = trim((string) ($_POST['existing_filename'] ?? ''));

        if ($title === '') {
            self::jsonError(400, 'Title is required.');
            return;
        }

        // Edit mode: preserve the original frontmatter (slug, date, tags,
        // image, series, part, password) and only update body/title/
        // summary/draft state. The writer never sees those fields so the
        // operator manages them in the regular /admin/edit/{slug} form.
        if ($existingSlug !== '' && $existingFilename !== '') {
            $existing = $this->repo->bySlug($existingSlug);
            if ($existing === null) {
                self::jsonError(404, 'Original post not found.');
                return;
            }
            $post = new Post(
                slug: $existing->slug,
                title: $title,
                date: $existing->date,
                tags: $existing->tags,
                draft: !$isPublish,
                bodyMarkdown: $body,
                icon: $existing->icon,
                summary: $summary !== '' ? $summary : $existing->summary,
                author: $existing->author,
                image: $existing->image,
                series: $existing->series,
                part: $existing->part,
                passwordHash: $existing->passwordHash,
            );
            try {
                $this->repo->save($post, $existingFilename);
            } catch (\Throwable $e) {
                self::jsonError(500, $e->getMessage());
                return;
            }
            $slug = $existing->slug;
            $isNew = false;
        } else {
            $slug = SlugUtil::fromTitle($title);
            if ($slug === '' || !SlugUtil::valid($slug)) {
                self::jsonError(400, 'Could not derive a valid slug from title.');
                return;
            }

            // Avoid slug collisions by appending an incrementing suffix. The
            // operator can still rename in /admin/edit/{slug} if they want
            // something prettier.
            $slug = $this->uniqueSlug($slug);

            $tz = new \DateTimeZone((string) Config::get('TIMEZONE', 'UTC'));
            $nowLocal = new \DateTimeImmutable('now', $tz);
            $date = $nowLocal->format('Y-m-d\TH:i:sP');

            $post = new Post(
                slug: $slug,
                title: $title,
                date: $date,
                tags: [],
                draft: !$isPublish,
                bodyMarkdown: $body,
                icon: null,
                summary: $summary !== '' ? $summary : null,
                author: ($a = (string) Config::get('DEFAULT_AUTHOR', '')) !== '' ? $a : null,
                image: null,
                series: null,
                part: null,
                passwordHash: null,
            );

            try {
                $this->repo->save($post, null);
            } catch (\Throwable $e) {
                self::jsonError(500, $e->getMessage());
                return;
            }
            $isNew = true;
        }

        Http::plugins()?->dispatchPostSave(new PostSaveEvent(
            slug: $post->slug,
            isNew: $isNew,
            published: $isPublish,
            savedAt: time(),
        ));

        // Published → public post page so the writer sees their work live.
        // Draft → admin editor so they can keep working on it (drafts 404
        // on /posts/{slug} for non-admins, and we don't want to dump the
        // writer back into the admin list mid-thought).
        $redirect = $isPublish
            ? '/posts/' . rawurlencode($slug)
            : '/admin/edit/' . rawurlencode($slug);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'slug' => $slug,
            'redirect' => $redirect,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base;
        $n = 2;
        while ($this->repo->bySlug($slug) !== null) {
            $suffix = '-' . $n;
            $slug = mb_substr($base, 0, 80 - mb_strlen($suffix)) . $suffix;
            $n++;
            if ($n > 999) {
                break;
            }
        }
        return $slug;
    }

    private static function jsonError(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    }
}
