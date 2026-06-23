<?php

declare(strict_types=1);

namespace App\Controllers;

use App\PluginRegistry;

/**
 * Serves files from `plugins/{slug}/assets/{file}` at the
 * `/plugin-assets/{slug}/{file}` URL.
 *
 * v1 limitation: flat assets only. The Router's `{file}` placeholder
 * matches `[^/]+`, so subdirectories aren't reachable — plugin authors
 * keep css/js/images at the top of `assets/`. Documented for authors.
 *
 * Guards (in order, all required):
 *   - slug must be in the enabled plugin set
 *   - file must not contain `..`, null bytes, or be absolute (slashes are
 *     already excluded by the route regex; we re-check for defence in depth)
 *   - extension must be in the allowlist (no `.php`, `.env`, etc.)
 *   - realpath() must stay inside the plugin's assets directory
 *
 * On any failure: HTTP 4xx, no body, nothing leaks about the filesystem.
 */
final class PluginAssetController
{
    private const MIME = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'mjs' => 'application/javascript; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'woff2' => 'font/woff2',
    ];

    public function __construct(private readonly PluginRegistry $registry)
    {
    }

    /** @param array<string,string> $params */
    public function serve(array $params): void
    {
        $slug = $params['slug'] ?? '';
        $file = $params['file'] ?? '';

        if (!$this->registry->isEnabled($slug)) {
            http_response_code(404);
            return;
        }
        if (
            $file === ''
            || str_contains($file, '..')
            || str_contains($file, "\0")
            || str_starts_with($file, '/')
        ) {
            http_response_code(400);
            return;
        }
        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        if (!isset(self::MIME[$ext])) {
            http_response_code(404);
            return;
        }
        $assetsDir = $this->registry->pluginRoot($slug) . '/assets';
        $base = realpath($assetsDir);
        $full = realpath($assetsDir . '/' . $file);
        if ($full === false || $base === false || !str_starts_with($full, $base . DIRECTORY_SEPARATOR)) {
            http_response_code(404);
            return;
        }
        header('Content-Type: ' . self::MIME[$ext]);
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($full);
    }
}
