<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * HTTP helpers: view rendering with layout wrapping, redirects, escaping.
 */
final class Http
{
    private static ?PluginRegistry $plugins = null;

    /**
     * Stash the boot-time plugin registry so the layout (and any view) can
     * reach it via Http::plugins() without threading it through every
     * render() call. Set once in public/index.php; never reassigned.
     */
    public static function setPluginRegistry(?PluginRegistry $registry): void
    {
        self::$plugins = $registry;
    }

    public static function plugins(): ?PluginRegistry
    {
        return self::$plugins;
    }

    /**
     * Cache-busted URL for a plugin asset.
     *
     * Mirrors asset() but resolves the mtime against `plugins/{slug}/assets/{file}`
     * instead of the public webroot — plugin assets are PHP-served via the
     * /plugin-assets route, not symlinked into /public.
     */
    public static function pluginAsset(string $slug, string $file): string
    {
        $file = ltrim($file, '/');
        $registry = self::$plugins;
        $version = '0';
        if ($registry !== null && $registry->isEnabled($slug)) {
            $fsPath = $registry->pluginRoot($slug) . '/assets/' . $file;
            if (is_file($fsPath)) {
                $version = (string) filemtime($fsPath);
            }
        }
        return "/plugin-assets/{$slug}/{$file}?v={$version}";
    }

    /**
     * Render a view inside the base layout.
     *
     * The view file echoes its content (or builds it via string return) —
     * we capture stdout into $body, then include the layout, which uses
     * $title and $body.
     *
     * @param array<string,mixed> $data
     */
    public static function render(string $view, array $data = []): void
    {
        $viewPath = __DIR__ . '/../views/' . $view . '.php';
        if (!is_file($viewPath)) {
            throw new RuntimeException("View not found: {$view}");
        }

        $title = (string) ($data['title'] ?? Config::get('SITE_TITLE'));

        extract($data, EXTR_SKIP);

        ob_start();
        require $viewPath;
        /** @var string $body */
        $body = ob_get_clean();

        require __DIR__ . '/../views/layout.php';
    }

    public static function redirect(string $location, int $code = 302): never
    {
        // Strip CRLF + control chars to defeat HTTP response splitting.
        // PHP 8 mostly already rejects these in header() but defense-in-depth.
        $location = (string) preg_replace('/[\r\n\t\0]/', '', $location);
        if ($location === '') {
            $location = '/';
        }
        http_response_code($code);
        header('Location: ' . $location);
        exit;
    }

    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Return an asset URL with a cache-busting ?v=<mtime> query string.
     *
     * Takes a path relative to /public (e.g. "assets/base.css") and resolves
     * mtime against the on-disk file so deploys invalidate browser caches
     * automatically. Falls back to a static "0" when the file is missing so
     * a typo doesn't crash the page render.
     */
    public static function asset(string $path): string
    {
        $relative = '/' . ltrim($path, '/');
        $fsPath = __DIR__ . '/../public' . $relative;
        $version = is_file($fsPath) ? (string) filemtime($fsPath) : '0';
        return $relative . '?v=' . $version;
    }
}
