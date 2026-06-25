<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Http;
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
            'imagickAvailable' => SeriesCoverProcessor::isAvailable(),
            'formError' => null,
            'flash' => $this->consumeFlash(),
        ]);
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
        $coverExt = null;
        $upload = $_FILES['cover'] ?? null;
        if (is_array($upload) && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $coverExt = $this->ingestUpload($slug, $upload, $uploadError, /* promote */ true);
        }

        if ($uploadError === null && $promotePreview) {
            $this->processor->commitPreview($slug);
            $coverExt ??= 'webp';
        }

        if ($uploadError !== null) {
            $this->renderEditWithError($slug, $title, $description, $uploadError);
            return;
        }

        $payload = [
            'title' => $title !== '' ? $title : null,
            'description' => $description !== '' ? $description : null,
        ];
        if ($this->manifest->hasCover($slug)) {
            $payload['cover_ext'] = $coverExt ?? 'webp';
        }
        $this->manifest->save($slug, $payload);

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
    private function ingestUpload(string $slug, array $upload, ?string &$error, bool $promote): ?string
    {
        $err = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            $error = 'No file selected.';
            return null;
        }
        if ($err !== UPLOAD_ERR_OK) {
            $error = self::uploadErrMessage($err);
            return null;
        }
        $size = (int) ($upload['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            $error = 'File too large (max ' . (self::MAX_BYTES / 1024 / 1024) . ' MB).';
            return null;
        }
        $tmp = (string) ($upload['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp) && !is_file($tmp)) {
            $error = 'Upload temp file missing.';
            return null;
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        if (!isset(self::ACCEPTED_MIME[$mime])) {
            $error = "Unsupported image type: {$mime}. Allowed: jpeg, png, webp.";
            return null;
        }
        if (!SeriesCoverProcessor::isAvailable()) {
            $error = 'ext-imagick is not installed on this server — cover upload is disabled.';
            return null;
        }
        $ext = self::ACCEPTED_MIME[$mime];
        try {
            if ($promote) {
                return $this->processor->process($slug, $tmp, $ext);
            }
            $this->processor->preview($slug, $tmp);
            return 'webp';
        } catch (\Throwable $e) {
            $error = 'Cover processing failed: ' . $e->getMessage();
            return null;
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
