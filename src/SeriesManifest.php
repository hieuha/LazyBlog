<?php

declare(strict_types=1);

namespace App;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Sidecar metadata for a series — title, description, cover image.
 *
 * Stored under content/series/<slug>/{manifest.yaml, cover-src.*, cover.webp}.
 * The manifest is purely an enhancement layer; series discovery still happens
 * via post frontmatter (`series:` field). A manifest with no matching posts
 * is an orphan and silently ignored on listing surfaces.
 *
 * Pure model — no HTTP, no image processing. Controllers handle slug
 * validation before reaching this layer.
 */
final class SeriesManifest
{
    private string $seriesRoot;

    public function __construct(string $contentDir)
    {
        $this->seriesRoot = rtrim($contentDir, '/') . '/series';
    }

    public function dir(string $slug): string
    {
        return $this->seriesRoot . '/' . $slug;
    }

    public function manifestPath(string $slug): string
    {
        return $this->dir($slug) . '/manifest.yaml';
    }

    public function coverPath(string $slug): ?string
    {
        $path = $this->dir($slug) . '/cover.webp';
        return is_file($path) ? $path : null;
    }

    /**
     * Locate the original uploaded source kept for re-rendering after a
     * dither algorithm tweak. Returns null if no source was persisted.
     */
    public function coverSrcPath(string $slug): ?string
    {
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            $p = $this->dir($slug) . '/cover-src.' . $ext;
            if (is_file($p)) {
                return $p;
            }
        }
        return null;
    }

    public function exists(string $slug): bool
    {
        return is_file($this->manifestPath($slug));
    }

    public function hasCover(string $slug): bool
    {
        return $this->coverPath($slug) !== null;
    }

    /**
     * @return array{title:?string, description:?string, cover_ext:?string, updated_at:?string}|null
     */
    public function load(string $slug): ?array
    {
        $path = $this->manifestPath($slug);
        if (!is_file($path)) {
            return null;
        }

        try {
            $raw = Yaml::parseFile($path);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($raw)) {
            return null;
        }

        return [
            'title' => isset($raw['title']) && is_string($raw['title']) ? trim($raw['title']) : null,
            'description' => isset($raw['description']) && is_string($raw['description']) ? trim($raw['description']) : null,
            'cover_ext' => isset($raw['cover_ext']) && is_string($raw['cover_ext']) ? $raw['cover_ext'] : null,
            'updated_at' => isset($raw['updated_at']) && is_string($raw['updated_at']) ? $raw['updated_at'] : null,
        ];
    }

    /**
     * Atomic manifest write. Caller already validated slug + data shape.
     *
     * @param array{title?:?string, description?:?string, cover_ext?:?string} $data
     */
    public function save(string $slug, array $data): void
    {
        $dir = $this->dir($slug);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create series dir: {$dir}");
        }

        $payload = [];
        if (isset($data['title']) && $data['title'] !== null && $data['title'] !== '') {
            $payload['title'] = (string) $data['title'];
        }
        if (isset($data['description']) && $data['description'] !== null && $data['description'] !== '') {
            $payload['description'] = (string) $data['description'];
        }
        if (isset($data['cover_ext']) && $data['cover_ext'] !== null && $data['cover_ext'] !== '') {
            $payload['cover_ext'] = (string) $data['cover_ext'];
        }
        $payload['updated_at'] = (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);

        $yaml = Yaml::dump($payload, 2, 2);
        FileWriter::writeAtomic($this->manifestPath($slug), $yaml);
    }

    /**
     * Remove manifest + cover artefacts. Idempotent — missing files are
     * silently skipped. Posts that reference this series via frontmatter
     * are left untouched; the series simply falls back to derived metadata.
     */
    public function delete(string $slug): void
    {
        $dir = $this->dir($slug);
        if (!is_dir($dir)) {
            return;
        }

        $candidates = [
            $this->manifestPath($slug),
            $dir . '/cover.webp',
            $dir . '/cover-src.jpg',
            $dir . '/cover-src.jpeg',
            $dir . '/cover-src.png',
            $dir . '/cover-src.webp',
            $dir . '/.preview.webp',
        ];
        foreach ($candidates as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }

        // Best-effort dir cleanup only if empty (don't blow away unknown files).
        $remaining = @scandir($dir);
        if (is_array($remaining) && count(array_diff($remaining, ['.', '..'])) === 0) {
            @rmdir($dir);
        }
    }

    public function seriesRoot(): string
    {
        return $this->seriesRoot;
    }
}
