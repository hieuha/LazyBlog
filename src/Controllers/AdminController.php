<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Config;
use App\Csrf;
use App\Http;
use App\MarkdownRenderer;
use App\Post;
use App\PostRepository;
use App\SlugUtil;

/**
 * Admin CRUD: login, list, new, edit, save, delete, logout.
 *
 * Every state-changing handler is POST + CSRF-checked.
 * Every authed handler calls Auth::requireAuth() first.
 */
final class AdminController
{
    public function __construct(private readonly PostRepository $repo)
    {
    }

    // ----- Auth -----

    public function loginForm(): void
    {
        if (Auth::check()) {
            Http::redirect('/admin');
        }
        // Validate `next` at form-render time too (not just submit) so a
        // crafted ?next=https://evil.example/... cannot ride along in the
        // visible URL bar and round-trip through the form.
        $next = isset($_GET['next']) ? (string) $_GET['next'] : '/admin';
        if (!self::safeRedirectTarget($next)) {
            $next = '/admin';
        }
        Http::render('admin/login', [
            'title' => 'Admin Login',
            'next' => $next,
            'error' => null,
        ]);
    }

    public function loginSubmit(): void
    {
        Csrf::requireValid();
        $password = (string) ($_POST['password'] ?? '');
        $next = (string) ($_POST['next'] ?? '/admin');

        if (!self::safeRedirectTarget($next)) {
            $next = '/admin';
        }

        if (Auth::attempt($password)) {
            Http::redirect($next);
        }

        http_response_code(401);
        Http::render('admin/login', [
            'title' => 'Admin Login',
            'next' => $next,
            'error' => 'Wrong password.',
        ]);
    }

    public function logout(): void
    {
        Csrf::requireValid();
        Auth::logout();
        Http::redirect('/admin/login');
    }

    // ----- List -----

    public function index(): void
    {
        Auth::requireAuth();

        // Server-side pagination — reuses the POSTS_PER_PAGE env that
        // home + tag listings already honour so a single dial controls
        // the whole site's list density.
        $perPage = max(1, (int) Config::get('POSTS_PER_PAGE', '10'));
        $all = $this->repo->all();
        $total = count($all);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
        $offset = ($page - 1) * $perPage;
        $posts = array_slice($all, $offset, $perPage);

        Http::render('admin/list', [
            'title' => 'Admin',
            'posts' => $posts,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'pageBaseUrl' => '/admin',
            'flash' => self::consumeFlash(),
        ]);
    }

    // ----- New / Edit form -----

    public function newForm(): void
    {
        Auth::requireAuth();

        // Pre-fill date + clock time at form open so the new post lands
        // with the operator's *actual* wall-clock by default — matters
        // for the time-of-day gamification kinds (NIGHT-OWL etc.). The
        // operator can still wipe the time field if they want a
        // legacy date-only entry.
        $tz = new \DateTimeZone((string) Config::get('TIMEZONE', 'UTC'));
        $nowLocal = new \DateTimeImmutable('now', $tz);
        $today = $nowLocal->format('Y-m-d');
        $nowTime = $nowLocal->format('H:i:s');
        Http::render('admin/edit', [
            'title' => 'New Post',
            'mode' => 'new',
            'post' => null,
            'originalFilename' => '',
            'formError' => null,
            'formValues' => [
                'date' => $today,
                'time' => $nowTime,
                'slug' => '',
                'title' => '',
                'author' => (string) Config::get('DEFAULT_AUTHOR', ''),
                'tags' => '',
                'draft' => false,
                'icon' => '',
                'summary' => '',
                'image' => '',
                'series' => '',
                'part' => '',
                'body' => '',
            ],
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function editForm(array $params): void
    {
        Auth::requireAuth();
        $slug = $params['slug'] ?? '';
        $post = $this->repo->bySlug($slug);
        if ($post === null) {
            http_response_code(404);
            Http::render('not-found', ['title' => '404 // NO SIGNAL']);
            return;
        }

        // Filename is always date-only — strip any ISO datetime tail so
        // edit-with-rename detection still matches against the actual file.
        $originalFilename = $post->displayDate() . '-' . $post->slug . '.md';

        // Split ISO datetime back into discrete date + time fields so the
        // form re-populates as a plain `YYYY-MM-DD` plus `HH:MM[:SS]`.
        $editDate = $post->displayDate();
        $editTime = '';
        if ($post->hasExplicitTime() && preg_match('/T(\d{2}:\d{2}(?::\d{2})?)/', $post->date, $m)) {
            $editTime = $m[1];
        }

        // Social image: if no explicit `image:` frontmatter is set, fall
        // back to the first body image so the field reflects what
        // og:image will actually render. Saving the form persists this
        // value explicitly — making the implicit fallback durable.
        $editImage = $post->image ?? '';
        if ($editImage === '') {
            $editImage = $post->firstBodyImage() ?? '';
        }

        Http::render('admin/edit', [
            'title' => 'Edit: ' . $post->title,
            'mode' => 'edit',
            'post' => $post,
            'originalFilename' => $originalFilename,
            'formError' => null,
            'formValues' => [
                'date' => $editDate,
                'time' => $editTime,
                'slug' => $post->slug,
                'title' => $post->title,
                'author' => $post->author ?? '',
                'tags' => implode(', ', $post->tags),
                'draft' => $post->draft,
                'icon' => $post->icon ?? '',
                'summary' => $post->summary ?? '',
                'image' => $editImage,
                'series' => $post->series ?? '',
                'part' => $post->part !== null ? (string) $post->part : '',
                'body' => $post->bodyMarkdown,
            ],
        ]);
    }

    // ----- Live preview (EasyMDE side-by-side / preview button) -----

    /**
     * POST /admin/preview — body is raw markdown, response is rendered HTML.
     * Lets EasyMDE show the SAME output as the public post page, including
     * LazyBlog admonitions (::: highlight, ::: story) and freq-tag chips —
     * EasyMDE's default marked.js can't render those.
     *
     * Auth + CSRF required. Token comes via X-CSRF-Token header since the
     * body is raw markdown, not form-encoded. SameSite=Lax already blocks
     * cross-origin POSTs, but the explicit token closes the gap.
     */
    public function preview(): void
    {
        Auth::requireAuth();
        Csrf::requireValid();

        // Cap the preview payload at 256 KB — guards against accidental or
        // malicious massive POSTs that would consume CPU in CommonMark.
        $raw = file_get_contents('php://input', false, null, 0, 262_144);
        if (!is_string($raw)) {
            $raw = '';
        }

        $rendered = (new MarkdownRenderer())->render($raw);

        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo $rendered['html'];
    }

    // ----- Save -----

    public function save(): void
    {
        Auth::requireAuth();
        Csrf::requireValid();

        $values = self::readFormValues();
        $originalFilename = (string) ($_POST['original_filename'] ?? '');
        $mode = (string) ($_POST['mode'] ?? 'new');

        // Default slug from title when empty.
        if ($values['slug'] === '' && $values['title'] !== '') {
            $values['slug'] = SlugUtil::fromTitle($values['title']);
        }

        try {
            $post = self::buildPostFromForm($values);
            $this->repo->save($post, $originalFilename !== '' ? $originalFilename : null);
        } catch (\Throwable $e) {
            http_response_code(400);
            Http::render('admin/edit', [
                'title' => $mode === 'edit' ? 'Edit Post' : 'New Post',
                'mode' => $mode,
                'post' => null,
                'originalFilename' => $originalFilename,
                'formError' => $e->getMessage(),
                'formValues' => $values,
            ]);
            return;
        }

        self::setFlash("Saved: {$post->slug}");
        Http::redirect('/admin');
    }

    // ----- Delete -----

    /**
     * @param array<string,string> $params
     */
    public function delete(array $params): void
    {
        Auth::requireAuth();
        Csrf::requireValid();

        $slug = $params['slug'] ?? '';
        $ok = $this->repo->delete($slug);
        self::setFlash($ok ? "Deleted: {$slug}" : "Delete failed: {$slug}");
        Http::redirect('/admin');
    }

    // ----- Helpers -----

    /**
     * @return array{date:string,time:string,slug:string,title:string,author:string,tags:string,draft:bool,icon:string,summary:string,image:string,series:string,part:string,body:string}
     */
    private static function readFormValues(): array
    {
        return [
            'date' => trim((string) ($_POST['date'] ?? '')),
            'time' => trim((string) ($_POST['time'] ?? '')),
            'slug' => trim((string) ($_POST['slug'] ?? '')),
            'title' => trim((string) ($_POST['title'] ?? '')),
            'author' => trim((string) ($_POST['author'] ?? '')),
            'tags' => trim((string) ($_POST['tags'] ?? '')),
            'draft' => !empty($_POST['draft']),
            'icon' => trim((string) ($_POST['icon'] ?? '')),
            'summary' => trim((string) ($_POST['summary'] ?? '')),
            // Prefer the visible input; fall back to the JS-driven mirror
            // (`image_mirror`) so uploads survive any browser quirk where
            // the visible input's JS-assigned value drops out of the form
            // serialization but the hidden mirror still carries the URL.
            'image' => trim((string) ($_POST['image'] ?? '')) !== ''
                ? trim((string) $_POST['image'])
                : trim((string) ($_POST['image_mirror'] ?? '')),
            'series' => trim((string) ($_POST['series'] ?? '')),
            'part' => trim((string) ($_POST['part'] ?? '')),
            'body' => (string) ($_POST['body'] ?? ''),
        ];
    }

    /**
     * @param array{date:string,time:string,slug:string,title:string,author:string,tags:string,draft:bool,icon:string,summary:string,image:string,series:string,part:string,body:string} $v
     */
    private static function buildPostFromForm(array $v): Post
    {
        if ($v['title'] === '') {
            throw new \RuntimeException('Title is required.');
        }
        if ($v['slug'] === '') {
            throw new \RuntimeException('Slug is required.');
        }
        if (!SlugUtil::valid($v['slug'])) {
            throw new \RuntimeException("Invalid slug: only [a-z0-9-], max 80 chars.");
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v['date'])) {
            throw new \RuntimeException('Date must be YYYY-MM-DD.');
        }

        // Optional clock time. When present, fold it into the date as an
        // ISO datetime so time-of-day-sensitive features (e.g. NIGHT-OWL
        // gamification) can read a real wall-clock from the frontmatter.
        $date = $v['date'];
        if ($v['time'] !== '') {
            if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $v['time'])) {
                throw new \RuntimeException('Time must be HH:MM or HH:MM:SS.');
            }
            $timeFull = strlen($v['time']) === 5 ? $v['time'] . ':00' : $v['time'];
            $tz = new \DateTimeZone((string) Config::get('TIMEZONE', 'UTC'));
            $offset = (new \DateTimeImmutable('now', $tz))->format('P');
            $date = $v['date'] . 'T' . $timeFull . $offset;
        }

        /** @var list<string> $tags */
        $tags = [];
        foreach (explode(',', $v['tags']) as $raw) {
            $t = strtolower(trim($raw));
            $t = (string) preg_replace('/[^a-z0-9-]/', '', $t);
            if ($t !== '' && !in_array($t, $tags, true)) {
                $tags[] = $t;
            }
        }

        // Normalize series slug (lowercase + kebab-friendly) so a typo'd
        // capitalization doesn't fork the series into two groups.
        $series = $v['series'] !== '' ? strtolower(trim($v['series'])) : null;
        $part = ($v['part'] !== '' && is_numeric($v['part'])) ? (int) $v['part'] : null;

        return new Post(
            slug: $v['slug'],
            title: $v['title'],
            date: $date,
            tags: $tags,
            draft: $v['draft'],
            bodyMarkdown: $v['body'],
            icon: $v['icon'] !== '' ? $v['icon'] : null,
            summary: $v['summary'] !== '' ? $v['summary'] : null,
            author: $v['author'] !== '' ? $v['author'] : null,
            // Scheme-gate so a hostile paste (`javascript:`, `data:`)
            // can't reach og:image even if it bypasses the JS hint.
            image: \App\PostRepository::safeImage($v['image']),
            series: $series,
            part: $part,
        );
    }

    /**
     * Reject open-redirect targets — only allow relative paths starting with "/".
     */
    private static function safeRedirectTarget(string $url): bool
    {
        if ($url === '' || $url[0] !== '/' || str_starts_with($url, '//')) {
            return false;
        }
        // Reject CRLF, tab, NUL — header injection vectors.
        if (preg_match('/[\r\n\t\0]/', $url)) {
            return false;
        }
        return true;
    }

    private static function setFlash(string $msg): void
    {
        Auth::start();
        $_SESSION['_flash'] = $msg;
    }

    private static function consumeFlash(): ?string
    {
        Auth::start();
        if (!empty($_SESSION['_flash'])) {
            $msg = (string) $_SESSION['_flash'];
            unset($_SESSION['_flash']);
            return $msg;
        }
        return null;
    }
}
