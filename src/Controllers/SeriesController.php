<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\Http;
use App\PostRepository;
use App\QrCache;
use App\SeriesManifest;

/**
 * GET /series/{slug} — index page for a series. Lists all posts with
 * `series: {slug}` in their frontmatter, ordered by `part:` then date.
 * 404 if no posts use the slug.
 */
final class SeriesController
{
    public function __construct(
        private readonly PostRepository $repo,
        private readonly SeriesManifest $manifest,
    ) {
    }

    /**
     * GET /series — index of every distinct series across published posts.
     */
    public function index(): void
    {
        $series = $this->repo->allSeries();
        Http::render('series-index', [
            'title' => 'Series // All Transmission Sequences',
            'series' => $series,
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function show(array $params): void
    {
        $slug = strtolower(trim($params['slug'] ?? ''));
        if ($slug === '') {
            http_response_code(404);
            Http::render('not-found', ['title' => '404 // NO SIGNAL']);
            return;
        }

        $posts = $this->repo->bySeries($slug);
        if ($posts === []) {
            http_response_code(404);
            Http::render('not-found', ['title' => '404 // NO SIGNAL']);
            return;
        }

        // Manifest is optional enhancement — if absent, fall back to the
        // slug-derived title and an empty description.
        $manifest = $this->manifest->load($slug);
        $manifestTitle = (is_array($manifest) && is_string($manifest['title'] ?? null) && $manifest['title'] !== '')
            ? $manifest['title']
            : null;
        $description = (is_array($manifest) && is_string($manifest['description'] ?? null) && $manifest['description'] !== '')
            ? $manifest['description']
            : null;
        $displayTitle = $manifestTitle ?? self::titleFromSlug($slug);
        $hasCover = $this->manifest->hasCover($slug);
        $coverUrl = $hasCover ? Http::seriesAsset($slug, 'cover.webp') : null;

        // QR fallback so the detail page never sits with an empty square
        // when the operator hasn't uploaded a cover yet — mirrors the
        // /series index card behaviour.
        $qrSvg = null;
        $qrMark = null;
        if (!$hasCover) {
            $siteUrl = rtrim((string) Config::get('SITE_URL'), '/');
            $seriesPath = '/series/' . $slug;
            $absUrl = $siteUrl !== '' ? $siteUrl . $seriesPath : $seriesPath;
            $qrSvg = (new QrCache())->svg($absUrl);
            $qrMark = QrCache::mark($slug);
        }

        Http::render('series', [
            'title' => 'Series // ' . $displayTitle,
            'seriesSlug' => $slug,
            'seriesTitle' => $displayTitle,
            'description' => $description,
            'coverUrl' => $coverUrl,
            'qrSvg' => $qrSvg,
            'qrMark' => $qrMark,
            'posts' => $posts,
        ]);
    }

    private static function titleFromSlug(string $slug): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }
}
