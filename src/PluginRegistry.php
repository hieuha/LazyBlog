<?php

declare(strict_types=1);

namespace App;

use Throwable;

/**
 * Loads enabled plugins from `plugins/` based on the PLUGINS env var.
 *
 * Boot is best-effort: any single plugin failure (missing folder, bad
 * JSON, unsupported api_version, register() throw, ...) is caught and
 * logged so the site stays up. The cost of a broken plugin is one less
 * plugin enabled, never a 500.
 *
 * Plugins boot BEFORE core routes register on the router. Core routes
 * stay always-on because canRegister() rejects any plugin pattern that
 * collides with a reserved core prefix.
 */
final class PluginRegistry
{
    /** Major versions of the plugin API this build supports. */
    private const SUPPORTED_API_VERSIONS = [1];

    /**
     * Path prefixes a plugin must NOT claim. Ordered longest-first so
     * `/llms-full.txt` matches before `/llms.txt`, etc. The `/admin`
     * prefix gets a special exemption in matchesReserved() for the
     * plugin's own `/admin/{slug}/...` namespace.
     */
    private const RESERVED_PREFIXES = [
        '/llms-full.txt',
        '/llms.txt',
        '/feed.xml',
        '/plugin-assets',
        '/admin',
        '/posts',
        '/tags',
        '/series',
        '/archive',
        '/search',
        '/about',
        '/healthz',
        '/',
    ];

    private PluginNavRegistry $nav;
    private PluginAssetRegistry $assets;

    /** @var array<string,string> slug => absolute plugin root */
    private array $roots = [];

    /** @var array<string,PluginManifest> */
    private array $manifests = [];

    /** @var array<string,bool> pattern => registered */
    private array $registeredPaths = [];

    /** @var array<string,bool> slug => has at least one admin route */
    private array $adminRoutes = [];

    public function __construct(
        private readonly string $pluginsDir,
        private readonly string $enabledCsv,
        private readonly string $contentRoot,
    ) {
        $this->nav = new PluginNavRegistry();
        $this->assets = new PluginAssetRegistry();
    }

    public function nav(): PluginNavRegistry
    {
        return $this->nav;
    }

    public function assets(): PluginAssetRegistry
    {
        return $this->assets;
    }

    /** @return list<string> slugs that booted successfully */
    public function enabledSlugs(): array
    {
        return array_keys($this->manifests);
    }

    public function manifest(string $slug): ?PluginManifest
    {
        return $this->manifests[$slug] ?? null;
    }

    public function pluginRoot(string $slug): string
    {
        if (!isset($this->roots[$slug])) {
            throw new \RuntimeException("unknown plugin: {$slug}");
        }
        return $this->roots[$slug];
    }

    public function isEnabled(string $slug): bool
    {
        return isset($this->manifests[$slug]);
    }

    public function hasAdminRoute(string $slug): bool
    {
        return !empty($this->adminRoutes[$slug]);
    }

    /** Called from PluginContext when an admin route is successfully registered. */
    public function recordAdminRoute(string $slug): void
    {
        $this->adminRoutes[$slug] = true;
    }

    public function boot(Router $router): void
    {
        foreach ($this->parseEnabled() as $slug) {
            try {
                $this->bootOne($slug, $router);
            } catch (Throwable $e) {
                error_log("[plugin:{$slug}] boot failed: {$e->getMessage()}");
            }
        }
    }

    /**
     * Guard every router add. Returns false (and logs) when the pattern
     * collides with a reserved core prefix or another already-registered
     * plugin pattern.
     */
    public function canRegister(string $slug, string $pattern): bool
    {
        foreach (self::RESERVED_PREFIXES as $reserved) {
            if ($this->matchesReserved($pattern, $reserved, $slug)) {
                error_log("[plugin:{$slug}] route {$pattern} collides with reserved {$reserved}");
                return false;
            }
        }
        if (isset($this->registeredPaths[$pattern])) {
            error_log("[plugin:{$slug}] route {$pattern} already registered");
            return false;
        }
        $this->registeredPaths[$pattern] = true;
        return true;
    }

    /**
     * True when $pattern conflicts with a reserved prefix. `/admin` gets a
     * carve-out: a plugin can register under its OWN `/admin/{slug}` namespace
     * but not under `/admin` itself or any other `/admin/...` path.
     */
    private function matchesReserved(string $pattern, string $reserved, string $slug): bool
    {
        if ($reserved === '/admin') {
            $allowed = '/admin/' . $slug;
            return !($pattern === $allowed || str_starts_with($pattern, $allowed . '/'));
        }
        if ($reserved === '/') {
            return $pattern === '/';
        }
        return $pattern === $reserved || str_starts_with($pattern, $reserved . '/');
    }

    /** @return list<string> */
    private function parseEnabled(): array
    {
        $raw = trim($this->enabledCsv);
        if ($raw === '') {
            return [];
        }
        $slugs = [];
        foreach (explode(',', $raw) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            if (!preg_match('/^[a-z][a-z0-9-]*$/', $segment)) {
                error_log("[plugin] invalid slug in PLUGINS env: {$segment}");
                continue;
            }
            $slugs[] = $segment;
        }
        return $slugs;
    }

    private function bootOne(string $slug, Router $router): void
    {
        $root = $this->pluginsDir . '/' . $slug;
        if (!is_dir($root)) {
            error_log("[plugin:{$slug}] directory missing: {$root}");
            return;
        }
        $manifestPath = $root . '/manifest.json';
        if (!is_file($manifestPath)) {
            error_log("[plugin:{$slug}] manifest.json missing");
            return;
        }
        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            error_log("[plugin:{$slug}] manifest.json unreadable");
            return;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            error_log("[plugin:{$slug}] manifest.json invalid JSON");
            return;
        }
        $manifest = PluginManifest::fromArray($data);
        if ($manifest->slug !== $slug) {
            error_log("[plugin:{$slug}] manifest slug mismatch: {$manifest->slug}");
            return;
        }
        if (!in_array($manifest->apiVersion, self::SUPPORTED_API_VERSIONS, true)) {
            error_log("[plugin:{$slug}] unsupported api_version: {$manifest->apiVersion}");
            return;
        }
        $bootFile = $root . '/plugin.php';
        if (!is_file($bootFile)) {
            error_log("[plugin:{$slug}] plugin.php missing");
            return;
        }
        $this->registerAutoload($manifest, $root);

        $instance = require $bootFile;
        if (!$instance instanceof Plugin) {
            error_log("[plugin:{$slug}] plugin.php must return an App\\Plugin instance");
            return;
        }
        // Record the plugin as booted before register() runs so $ctx->view()
        // and friends can look up the plugin root via pluginRoot().
        $this->roots[$slug] = $root;
        $this->manifests[$slug] = $manifest;

        $ctx = new PluginContext(
            manifest: $manifest,
            router: $router,
            nav: $this->nav,
            assets: $this->assets,
            contentRoot: $this->contentRoot,
            registry: $this,
        );
        $instance->register($ctx);
    }

    /**
     * Dynamic PSR-4 autoload for `plugins/{slug}/src/` mapped to the
     * manifest's namespace. Avoids a composer dump-autoload step every
     * time the operator drops in a new plugin.
     */
    private function registerAutoload(PluginManifest $manifest, string $root): void
    {
        $prefix = $manifest->namespace;
        $srcDir = $root . '/src/';
        spl_autoload_register(static function (string $class) use ($prefix, $srcDir): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            $file = $srcDir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }
}
