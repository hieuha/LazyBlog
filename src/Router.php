<?php

declare(strict_types=1);

namespace App;

/**
 * Tiny pattern-based router.
 *
 * Patterns use `{name}` placeholders. Routes are checked in registration
 * order — register most-specific patterns first so they win over catch-alls.
 */
final class Router
{
    /** @var list<array{0:string,1:string,2:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->routes[] = ['GET', $pattern, $handler];
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->routes[] = ['POST', $pattern, $handler];
    }

    /**
     * Match the request against registered routes and invoke the handler.
     *
     * Handler signature: `function (array<string,string> $params): void`.
     * Falls back to 404 if no route matches.
     */
    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        foreach ($this->routes as [$routeMethod, $pattern, $handler]) {
            if ($routeMethod !== $method) {
                continue;
            }

            $regex = '#^'
                . preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern)
                . '$#';

            if (preg_match($regex, $path, $matches)) {
                /** @var array<string,string> $params */
                $params = array_filter(
                    $matches,
                    static fn (int|string $k): bool => is_string($k),
                    ARRAY_FILTER_USE_KEY,
                );
                $handler($params);
                return;
            }
        }

        http_response_code(404);
        Http::render('not-found', [
            'title' => '404 // NO SIGNAL',
        ]);
    }
}
