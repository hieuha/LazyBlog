<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Sidecar metadata for a series — title, description, cover image.
 *
 * Stored under content/series/<slug>/{manifest.json, cover-src.webp, cover.webp}.
 * The manifest is purely an enhancement layer; series discovery still happens
 * via post frontmatter (`series:` field). A manifest with no matching posts
 * is an orphan and silently ignored on listing surfaces.
 *
 * JSON (not YAML) keeps the on-disk format consistent with the rest of the
 * project's machine-written sidecars (.index.json, badges.json, plugin
 * state). Cover presence is derived from `is_file(cover.webp)` — no
 * separate field needed.
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
        return $this->dir($slug) . '/manifest.json';
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
     * @return array{title:?string, description:?string, updated_at:?string}|null
     */
    public function load(string $slug): ?array
    {
        $path = $this->manifestPath($slug);
        if (!is_file($path)) {
            return null;
        }

        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return null;
        }

        try {
            $raw = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($raw)) {
            return null;
        }

        return [
            'title' => isset($raw['title']) && is_string($raw['title']) ? trim($raw['title']) : null,
            'description' => isset($raw['description']) && is_string($raw['description']) ? trim($raw['description']) : null,
            'updated_at' => isset($raw['updated_at']) && is_string($raw['updated_at']) ? $raw['updated_at'] : null,
        ];
    }

    /**
     * Atomic manifest write. Caller already validated slug + data shape.
     *
     * @param array{title?:?string, description?:?string} $data
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
        $payload['updated_at'] = (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        FileWriter::writeAtomic($this->manifestPath($slug), $json);
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
