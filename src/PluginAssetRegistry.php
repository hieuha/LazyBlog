<?php

declare(strict_types=1);

namespace App;

/**
 * Tracks which CSS/JS files each plugin owns plus which URL prefixes
 * trigger loading them.
 *
 * The layout calls forPath($currentPath) to decide what to inject. Prefix
 * matching uses an exact equality OR `prefix/` boundary so `/hello` does
 * NOT accidentally match `/helloworld`.
 */
final class PluginAssetRegistry
{
    /**
     * @var array<string,array{
     *     css:list<string>,
     *     js:list<string>,
     *     prefixes:list<string>,
     * }>
     */
    private array $byPlugin = [];

    public function css(string $slug, string $file): void
    {
        $this->ensure($slug);
        $this->byPlugin[$slug]['css'][] = ltrim($file, '/');
    }

    public function js(string $slug, string $file): void
    {
        $this->ensure($slug);
        $this->byPlugin[$slug]['js'][] = ltrim($file, '/');
    }

    /** Record a URL path that should load this plugin's assets. */
    public function prefix(string $slug, string $prefix): void
    {
        $this->ensure($slug);
        $this->byPlugin[$slug]['prefixes'][] = $prefix;
    }

    /**
     * Resolve which assets apply to the current request path.
     *
     * @return array{css:list<string>,js:list<string>}
     */
    public function forPath(string $path): array
    {
        $css = [];
        $js = [];
        foreach ($this->byPlugin as $slug => $entry) {
            foreach ($entry['prefixes'] as $prefix) {
                $boundary = rtrim($prefix, '/') . '/';
                if ($path === $prefix || str_starts_with($path, $boundary)) {
                    foreach ($entry['css'] as $f) {
                        $css[] = "/plugin-assets/{$slug}/{$f}";
                    }
                    foreach ($entry['js'] as $f) {
                        $js[] = "/plugin-assets/{$slug}/{$f}";
                    }
                    break;
                }
            }
        }

        return ['css' => $css, 'js' => $js];
    }

    private function ensure(string $slug): void
    {
        $this->byPlugin[$slug] ??= ['css' => [], 'js' => [], 'prefixes' => []];
    }
}
