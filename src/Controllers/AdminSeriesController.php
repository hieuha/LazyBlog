<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Http;
use App\Post;
use App\PostRepository;
use App\SeriesCoverProcessor;
use App\SeriesManifest;

/**
 * Admin CRUD for series manifests.
 *
 *   GET  /admin/series                — list discovered series + manifest state
 *   GET  /admin/series/{slug}         — edit form for one series
 *   POST /admin/series/{slug}         — save title/description and (optionally)
 *                                       promote the previewed cover to live
 *   POST /admin/series/{slug}/preview — render Atkinson dither preview only
 *                                       (writes .preview.webp, no manifest mut)
 *   POST /admin/series/{slug}/delete  — remove manifest + cover artefacts.
 *                                       Posts that reference the slug stay put.
 *
 * Auth + CSRF on every endpoint. Slug regex `[a-z0-9][a-z0-9-]*` enforced
 * before any filesystem touch. The series must already be discoverable from
 * post frontmatter — manifest is an enhancement layer, never the source of
 * truth for "does this series exist".
 */
final class AdminSeriesController
{
    private const TITLE_MAX = 200;
    private const DESC_MAX = 500;
    private const ACCEPTED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly PostRepository $repo,
        private readonly SeriesManifest $manifest,
        private readonly SeriesCoverProcessor $processor,
    ) {
    }

    public function index(): void
    {
        Auth::requireAuth();

        $series = $this->repo->allSeries();
        foreach ($series as &$row) {
            $row['hasManifest'] = $this->manifest->exists($row['slug']);
        }
        unset($row);

        Http::render('admin/series-list', [
            'title' => 'Series // ADMIN',
            'series' => $series,
            'flash' => $this->consumeFlash(),
            'imagickAvailable' => SeriesCoverProcessor::isAvailable(),
        ]);
    }

    /** @param array<string,string> $params */
    public function editForm(array $params): void
    {
        Auth::requireAuth();

        $slug = self::cleanSlug($params['slug'] ?? '');
        if ($slug === null) {
            $this->notFound();
            return;
        }
        if (!$this->seriesIsDiscovered($slug)) {
            $this->notFound();
            return;
        }

        $loaded = $this->manifest->load($slug);
        $values = [
            'title' => $loaded['title'] ?? '',
            'description' => $loaded['description'] ?? '',
        ];

        Http::render('admin/series-edit', [
            'title' => 'Edit Series // ' . $slug,
            'slug' => $slug,
            'values' => $values,
            'hasCover' => $this->manifest->hasCover($slug),
            'hasPreview' => is_file($this->manifest->dir($slug) . '/.preview.webp'),
            'postsInSeries' => $this->repo->bySeries($slug),
            'candidatePosts' => $this->candidatePostsForAttach($slug),
            'imagickAvailable' => SeriesCoverProcessor::isAvailable(),
            'formError' => null,
            'flash' => $this->consumeFlash(),
        ]);
    }

    /**
     * Posts that aren't already in this series, sorted newest-first. Used
     * to populate the attach-post datalist on the edit form. We list ALL
     * posts (including those in OTHER series) so the operator can move
     * a post between series without first having to detach it — saving
     * a click. The slugs being attached overwrite the post's existing
     * `series:` frontmatter, which is the intended semantics.
     *
     * @return list<array{slug:string,title:string,date:string,series:?string}>
     */
    private function candidatePostsForAttach(string $slug): array
    {
        $candidates = [];
        foreach ($this->repo->published() as $entry) {
            if (($entry['series'] ?? null) === $slug) {
                continue;
            }
            $candidates[] = [
                'slug' => (string) $entry['slug'],
                'title' => (string) $entry['title'],
                'date' => substr((string) ($entry['date'] ?? ''), 0, 10),
                'series' => isset($entry['series']) && is_string($entry['series']) ? $entry['series'] : null,
            ];
        }
        return $candidates;
    }

    /**
     * POST /admin/series/{slug}/attach — set the target post's frontmatter
     * `series:` field to {slug} and save. If the post already belonged to
     * another series, the previous series silently loses that post on
     * discovery (manifest preserved for re-attachment later).
     *
     * @param array<string,string> $params
     */
    public function attach(array $params): void
    {
        Auth::requireAuth();
        Csrf::requireValid();

        $slug = self::cleanSlug($params['slug'] ?? '');
        if ($slug === null) {
            $this->notFound();
            return;
        }

        $postSlug = trim((string) ($_POST['post_slug'] ?? ''));
        $part = trim((string) ($_POST['part'] ?? ''));
        if ($postSlug === '') {
            $this->flash('No post selected to attach.');
            Http::redirect('/admin/series/' . $slug);
            return;
        }

        $post = $this->repo->bySlug($postSlug);
        if ($post === null) {
            $this->flash('Post not found: ' . $postSlug);
            Http::redirect('/admin/series/' . $slug);
            return;
        }

        $updated = new Post(
            slug: $post->slug,
            title: $post->title,
            date: $post->date,
            tags: $post->tags,
            draft: $post->draft,
            bodyMarkdown: $post->bodyMarkdown,
            icon: $post->icon,
            summary: $post->summary,
            author: $post->author,
            image: $post->image,
            series: $slug,
            part: $part !== '' && ctype_digit($part) ? (int) $part : $post->part,
        );

        $originalFilename = substr($post->date, 0, 10) . '-' . $post->slug . '.md';

        try {
            $this->repo->save($updated, $originalFilename);
        } catch (\Throwable $e) {
            $this->flash('Attach failed: ' . $e->getMessage());
            Http::redirect('/admin/series/' . $slug);
            return;
        }

        $this->flash('Attached: ' . $postSlug . ' → ' . $slug);
        Http::redirect('/admin/series/' . $slug);
    }

    /** @param array<string,string> $params */
    public function save(array $params): void
    {
        Auth::requireAuth();
        Csrf::requireValid();

        $slug = self::cleanSlug($params['slug'] ?? '');
        if ($slug === null || !$this->seriesIsDiscovered($slug)) {
            $this->notFound();
            return;
        }

        $title = self::trimField($_POST['title'] ?? '', self::TITLE_MAX);
        $description = self::trimField($_POST['description'] ?? '', self::DESC_MAX);
        $promotePreview = isset($_POST['promote_preview']) && $_POST['promote_preview'] === '1';

        // Optional fresh upload — same flow as preview but commit straight away.
        $uploadError = null;
        $upload = $_FILES['cover'] ?? null;
        if (is_array($upload) && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $this->ingestUpload($slug, $upload, $uploadError, /* promote */ true);
        }

        if ($uploadError === null && $promotePreview) {
            $this->processor->commitPreview($slug);
        }

        if ($uploadError !== null) {
            $this->renderEditWithError($slug, $title, $description, $uploadError);
            return;
        }

        $this->manifest->save($slug, [
            'title' => $title !== '' ? $title : null,
            'description' => $description !== '' ? $description : null,
        ]);

        $this->flash('Saved series: ' . $slug);
        Http::redirect('/admin/series');
    }

    /** @param array<string,string> $params */
    public function preview(array $params): void
    {
        Auth::requireAuth();
        Csrf::requireValid();

        $slug = self::cleanSlug($params['slug'] ?? '');
        if ($slug === null || !$this->seriesIsDiscovered($slug)) {
            $this->notFound();
            return;
        }

        if (!SeriesCoverProcessor::isAvailable()) {
            $this->renderEditWithError(
                $slug,
                (string) ($_POST['title'] ?? ''),
                (string) ($_POST['description'] ?? ''),
                'ext-imagick is not installed on this server — cover upload is disabled.',
            );
            return;
        }

        $upload = $_FILES['cover'] ?? null;
        $error = null;
        $this->ingestUpload($slug, is_array($upload) ? $upload : [], $error, /* promote */ false);

        if ($error !== null) {
            $this->renderEditWithError(
                $slug,
                (string) ($_POST['title'] ?? ''),
                (string) ($_POST['description'] ?? ''),
                $error,
            );
            return;
        }

        // Re-render edit form with the freshly produced preview visible.
        $loaded = $this->manifest->load($slug);
        Http::render('admin/series-edit', [
            'title' => 'Edit Series // ' . $slug,
            'slug' => $slug,
            'values' => [
                'title' => (string) ($_POST['title'] ?? ($loaded['title'] ?? '')),
                'description' => (string) ($_POST['description'] ?? ($loaded['description'] ?? '')),
            ],
            'hasCover' => $this->manifest->hasCover($slug),
            'hasPreview' => true,
            'postsInSeries' => $this->repo->bySeries($slug),
            'imagickAvailable' => true,
            'formError' => null,
            'flash' => 'Preview rendered — click [ SAVE ] to promote it.',
        ]);
    }

    /**
     * POST /admin/series/{slug}/rename — change the series slug everywhere.
     * Rewrites the `series:` frontmatter on every post that currently uses
     * the old slug, then renames `content/series/{old}/` to
     * `content/series/{new}/` so the manifest + cover follow. The active
     * URL changes; we redirect to the new admin URL.
     *
     * @param array<string,string> $params
     */
    public function rename(array $params): void
    {
        Auth::requireAuth();
        Csrf::requireValid();

        $oldSlug = self::cleanSlug($params['slug'] ?? '');
        $newSlug = self::cleanSlug($_POST['new_slug'] ?? '');
        if ($oldSlug === null) {
            $this->notFound();
            return;
        }
        if ($newSlug === null) {
            $this->flash('Invalid new slug. Use kebab-case: lowercase + digits + hyphens.');
            Http::redirect('/admin/series/' . $oldSlug);
            return;
        }
        if ($oldSlug === $newSlug) {
            Http::redirect('/admin/series/' . $oldSlug);
            return;
        }

        // Refuse if the target already has a manifest or any posts —
        // otherwise the rename would silently merge into an existing series.
        if ($this->manifest->exists($newSlug) || $this->manifest->hasCover($newSlug)) {
            $this->flash("Cannot rename: a manifest already exists at '{$newSlug}'.");
            Http::redirect('/admin/series/' . $oldSlug);
            return;
        }
        if ($this->repo->bySeries($newSlug) !== []) {
            $this->flash("Cannot rename: posts already use 'series: {$newSlug}'.");
            Http::redirect('/admin/series/' . $oldSlug);
            return;
        }

        // Re-stamp every post's frontmatter. Each save is atomic via the
        // PostRepository's tmp+rename pattern, so a mid-way crash leaves
        // partial-but-valid state we can resume from.
        $rewritten = 0;
        foreach ($this->repo->bySeries($oldSlug) as $entry) {
            $post = $this->repo->bySlug((string) $entry['slug']);
            if ($post === null) {
                continue;
            }
            $updated = new Post(
                slug: $post->slug,
                title: $post->title,
                date: $post->date,
                tags: $post->tags,
                draft: $post->draft,
                bodyMarkdown: $post->bodyMarkdown,
                icon: $post->icon,
                summary: $post->summary,
                author: $post->author,
                image: $post->image,
                series: $newSlug,
                part: $post->part,
            );
            $original = substr($post->date, 0, 10) . '-' . $post->slug . '.md';
            $this->repo->save($updated, $original);
            $rewritten++;
        }

        // Move the manifest directory onto the new slug. POSIX rename is
        // atomic when src+dst are on the same filesystem (always true here).
        $oldDir = $this->manifest->dir($oldSlug);
        $newDir = $this->manifest->dir($newSlug);
        if (is_dir($oldDir)) {
            @rename($oldDir, $newDir);
        }

        $this->flash("Renamed series: {$oldSlug} → {$newSlug} ({$rewritten} post" . ($rewritten === 1 ? '' : 's') . " updated)");
        Http::redirect('/admin/series/' . $newSlug);
    }

    /** @param array<string,string> $params */
    public function delete(array $params): void
    {
        Auth::requireAuth();
        Csrf::requireValid();

        $slug = self::cleanSlug($params['slug'] ?? '');
        if ($slug === null) {
            $this->notFound();
            return;
        }

        $this->manifest->delete($slug);
        $this->flash('Deleted manifest + cover for: ' . $slug . ' (posts untouched)');
        Http::redirect('/admin/series');
    }

    /**
     * @param array<string,mixed> $upload  the $_FILES['cover'] payload
     */
    private function ingestUpload(string $slug, array $upload, ?string &$error, bool $promote): void
    {
        $err = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            $error = 'No file selected.';
            return;
        }
        if ($err !== UPLOAD_ERR_OK) {
            $error = self::uploadErrMessage($err);
            return;
        }
        $size = (int) ($upload['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            $error = 'File too large (max ' . (self::MAX_BYTES / 1024 / 1024) . ' MB).';
            return;
        }
        $tmp = (string) ($upload['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp) && !is_file($tmp)) {
            $error = 'Upload temp file missing.';
            return;
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        if (!isset(self::ACCEPTED_MIME[$mime])) {
            $error = "Unsupported image type: {$mime}. Allowed: jpeg, png, webp.";
            return;
        }
        if (!SeriesCoverProcessor::isAvailable()) {
            $error = 'ext-imagick is not installed on this server — cover upload is disabled.';
            return;
        }
        try {
            if ($promote) {
                $this->processor->process($slug, $tmp);
            } else {
                $this->processor->preview($slug, $tmp);
            }
        } catch (\Throwable $e) {
            $error = 'Cover processing failed: ' . $e->getMessage();
        }
    }

    private function seriesIsDiscovered(string $slug): bool
    {
        foreach ($this->repo->allSeries() as $s) {
            if ($s['slug'] === $slug) {
                return true;
            }
        }
        return false;
    }

    private function renderEditWithError(string $slug, string $title, string $description, string $msg): void
    {
        http_response_code(400);
        Http::render('admin/series-edit', [
            'title' => 'Edit Series // ' . $slug,
            'slug' => $slug,
            'values' => ['title' => $title, 'description' => $description],
            'hasCover' => $this->manifest->hasCover($slug),
            'hasPreview' => is_file($this->manifest->dir($slug) . '/.preview.webp'),
            'postsInSeries' => $this->repo->bySeries($slug),
            'imagickAvailable' => SeriesCoverProcessor::isAvailable(),
            'formError' => $msg,
            'flash' => null,
        ]);
    }

    private static function cleanSlug(string $raw): ?string
    {
        $slug = strtolower(trim($raw));
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            return null;
        }
        return $slug;
    }

    private static function trimField(mixed $value, int $max): string
    {
        $s = trim((string) $value);
        if (function_exists('mb_substr')) {
            $s = mb_substr($s, 0, $max);
        } elseif (strlen($s) > $max) {
            $s = substr($s, 0, $max);
        }
        return $s;
    }

    private static function uploadErrMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large.',
            UPLOAD_ERR_PARTIAL    => 'Upload was interrupted.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temp directory missing.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write upload to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by a PHP extension.',
            default               => 'Unknown upload error.',
        };
    }

    private function notFound(): void
    {
        http_response_code(404);
        Http::render('not-found', ['title' => '404 // NO SIGNAL']);
    }

    private function flash(string $msg): void
    {
        Auth::start();
        $_SESSION['_flash'] = $msg;
    }

    private function consumeFlash(): ?string
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
