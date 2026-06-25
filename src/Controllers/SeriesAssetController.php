<?php

declare(strict_types=1);

namespace App\Controllers;

use App\SeriesManifest;

/**
 * Serves files from `content/series/{slug}/{file}` at the
 * `/series-assets/{slug}/{file}` URL.
 *
 * Same defence-in-depth posture as PluginAssetController — slug + filename
 * regex, extension allowlist (no `.yaml`, no `.php`), realpath jail.
 */
final class SeriesAssetController
{
    private const MIME = [
        'webp' => 'image/webp',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
    ];

    public function __construct(private readonly SeriesManifest $manifest)
    {
    }

    /** @param array<string,string> $params */
    public function serve(array $params): void
    {
        $slug = $params['slug'] ?? '';
        $file = $params['file'] ?? '';

        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            http_response_code(404);
            return;
        }
        if (
            $file === ''
            || str_contains($file, '..')
            || str_contains($file, "\0")
            || str_starts_with($file, '/')
            || !preg_match('/^[a-z0-9._-]+$/', $file)
        ) {
            http_response_code(404);
            return;
        }

        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        if (!isset(self::MIME[$ext])) {
            http_response_code(404);
            return;
        }

        $dir = $this->manifest->dir($slug);
        $base = realpath($dir);
        $full = realpath($dir . '/' . $file);
        if ($base === false || $full === false || !str_starts_with($full, $base . DIRECTORY_SEPARATOR)) {
            http_response_code(404);
            return;
        }

        header('Content-Type: ' . self::MIME[$ext]);
        header('Content-Length: ' . (string) filesize($full));
        header('Cache-Control: public, max-age=86400');
        readfile($full);
    }
}
