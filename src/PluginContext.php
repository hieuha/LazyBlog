<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Stable API surface a plugin receives in its register() method.
 *
 * Every router add goes through the registry's canRegister() guard so
 * reserved-path collisions and duplicate registrations surface as logged
 * warnings rather than silently shadowing core routes.
 *
 * Admin routes are auto-wrapped with Auth::requireAuth() and must live
 * under `/admin/{plugin-slug}` — enforced here so plugins can't claim
 * core admin paths.
 */
final class PluginContext
{
    public function __construct(
        public readonly PluginManifest $manifest,
        private readonly Router $router,
        private readonly PluginNavRegistry $nav,
        private readonly PluginAssetRegistry $assets,
        private readonly string $contentRoot,
        private readonly PluginRegistry $registry,
    ) {
    }

    public function get(string $pattern, callable $handler): void
    {
        if ($this->rejectUnwrappedAdmin($pattern)) {
            return;
        }
        if (!$this->registry->canRegister($this->manifest->slug, $pattern)) {
            return;
        }
        $this->assets->prefix($this->manifest->slug, $this->stripParams($pattern));
        $this->router->get($pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        if ($this->rejectUnwrappedAdmin($pattern)) {
            return;
        }
        if (!$this->registry->canRegister($this->manifest->slug, $pattern)) {
            return;
        }
        $this->assets->prefix($this->manifest->slug, $this->stripParams($pattern));
        $this->router->post($pattern, $handler);
    }

    /**
     * Register an admin GET page. Wrapped with Auth::requireAuth() automatically.
     * Pattern MUST start with `/admin/{plugin-slug}` — anything else is rejected
     * with a logged warning so core admin paths stay protected.
     */
    public function adminGet(string $pattern, callable $handler): void
    {
        if (!$this->isAdminPathAllowed($pattern)) {
            return;
        }
        if (!$this->registry->canRegister($this->manifest->slug, $pattern)) {
            return;
        }
        $this->registry->recordAdminRoute($this->manifest->slug);
        $this->assets->prefix($this->manifest->slug, $this->stripParams($pattern));
        $this->router->get($pattern, function (array $params) use ($handler): void {
            Auth::requireAuth();
            $handler($params);
        });
    }

    public function adminPost(string $pattern, callable $handler): void
    {
        if (!$this->isAdminPathAllowed($pattern)) {
            return;
        }
        if (!$this->registry->canRegister($this->manifest->slug, $pattern)) {
            return;
        }
        $this->registry->recordAdminRoute($this->manifest->slug);
        $this->assets->prefix($this->manifest->slug, $this->stripParams($pattern));
        $this->router->post($pattern, function (array $params) use ($handler): void {
            Auth::requireAuth();
            $handler($params);
        });
    }

    /**
     * Register a nav link contributed by this plugin.
     *
     * `$auth` gates visibility: 'public' (default) renders for everyone,
     * 'admin' only renders when the visitor has an admin session. Useful
     * for plugins that expose admin-only quick-access shortcuts (e.g.,
     * moderation counters) without core layout changes.
     */
    public function nav(string $label, string $href, string $placement = 'header', string $auth = 'public'): void
    {
        $this->nav->add($this->manifest->slug, $label, $href, $placement, $auth);
    }

    /**
     * Subscribe to the `post.view` event. Fired once per `GET /posts/{slug}`
     * render BEFORE the response body is flushed — listeners may call
     * `setcookie()` or `header()` safely. Listener exceptions are caught
     * and logged so one bad plugin never 500s a post page.
     */
    public function onPostView(callable $listener): void
    {
        $this->registry->addPostViewListener($listener);
    }

    /**
     * Subscribe to the `post.save` event. Fired after `PostRepository::save()`
     * succeeds in the admin handler — listeners receive a `PostSaveEvent`
     * with `slug`, `isNew`, `published`, `savedAt`. Listener exceptions are
     * caught and logged so a bad plugin never aborts the save flow.
     */
    public function onPostSave(callable $listener): void
    {
        $this->registry->addPostSaveListener($listener);
    }

    /**
     * Contribute HTML to the `post.meta` slot rendered near the post
     * date/tags. Renderer receives a context array (currently `['slug' => $slug]`)
     * and must return a string of HTML or null to skip. Output ordering is
     * registration order; no priority API.
     */
    public function onPostMeta(callable $renderer): void
    {
        $this->registry->addPostMetaRenderer($renderer);
    }

    /**
     * Contribute HTML to the `post.article_end` slot — rendered as the last
     * children of `<article class="post-article">`. Suitable for absolutely-
     * positioned overlays (graffiti layer, reaction badges, etc.) that need
     * to anchor against the article box. Same try/catch isolation as the
     * other render slots.
     */
    public function onPostArticleEnd(callable $renderer): void
    {
        $this->registry->addPostArticleEndRenderer($renderer);
    }

    public function css(string $file): void
    {
        $this->assets->css($this->manifest->slug, $file);
    }

    public function js(string $file): void
    {
        $this->assets->js($this->manifest->slug, $file);
    }

    /**
     * Render one of the plugin's view files through the main layout.
     *
     * Mirrors `App\Http::render()` — the view file echoes (or includes
     * partials that echo); we capture stdout and hand it off to layout.php
     * as `$body`. The view receives `$data` extracted into scope.
     *
     * @param array<string,mixed> $data
     */
    public function view(string $view, array $data = []): void
    {
        // Validate up front so a plugin that accidentally passes user input
        // (`$ctx->view($_GET['v'])`) can't traverse out of the views dir or
        // include arbitrary PHP. Allowed chars: alnum, dash, underscore.
        // No slashes, no dots. Plugin authors organise views as flat files.
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $view)) {
            throw new RuntimeException(
                "plugin view name must match [a-zA-Z0-9_-]+: {$this->manifest->slug}/{$view}",
            );
        }
        $viewPath = $this->pluginRoot() . '/views/' . $view . '.php';
        if (!is_file($viewPath)) {
            throw new RuntimeException(
                "plugin view not found: {$this->manifest->slug}/{$view}",
            );
        }

        $title = (string) ($data['title'] ?? Config::get('SITE_TITLE'));
        extract($data, EXTR_SKIP);

        ob_start();
        require $viewPath;
        /** @var string $body */
        $body = ob_get_clean();

        require __DIR__ . '/../views/layout.php';
    }

    /** Absolute path to this plugin's writable storage; created on first call. */
    public function storagePath(): string
    {
        $dir = $this->contentRoot . '/' . $this->manifest->slug;
        if (!is_dir($dir)) {
            @mkdir($dir, 0o755, recursive: true);
        }
        return $dir;
    }

    /** Absolute path to `plugins/{slug}/`. */
    public function pluginRoot(): string
    {
        return $this->registry->pluginRoot($this->manifest->slug);
    }

    /**
     * Return the App\Csrf FQCN so plugins can call static helpers like
     * `($ctx->csrf())::requireValid()`. Cheaper than wrapping in an adapter.
     */
    public function csrf(): string
    {
        return Csrf::class;
    }

    public function auth(): string
    {
        return Auth::class;
    }

    /** Strip `{param}` placeholders so we get a prefix usable for asset matching. */
    private function stripParams(string $pattern): string
    {
        $stripped = preg_replace('/\/\{[^}]+\}.*$/', '', $pattern) ?? $pattern;
        return $stripped === '' ? '/' : $stripped;
    }

    private function isAdminPathAllowed(string $pattern): bool
    {
        $expected = '/admin/' . $this->manifest->slug;
        if ($pattern === $expected || str_starts_with($pattern, $expected . '/')) {
            return true;
        }
        error_log(
            "[plugin:{$this->manifest->slug}] admin route must start with {$expected}: {$pattern}"
        );
        return false;
    }

    /**
     * Block public get()/post() registrations from claiming any `/admin/*`
     * path. Otherwise a plugin author could accidentally expose an admin
     * page WITHOUT the auto-applied Auth::requireAuth() wrapper that
     * adminGet/adminPost provides. Logged so authors notice the mistake.
     */
    private function rejectUnwrappedAdmin(string $pattern): bool
    {
        if ($pattern === '/admin' || str_starts_with($pattern, '/admin/')) {
            error_log(
                "[plugin:{$this->manifest->slug}] use adminGet/adminPost for admin routes, "
                . "not get/post: {$pattern}"
            );
            return true;
        }
        return false;
    }
}
