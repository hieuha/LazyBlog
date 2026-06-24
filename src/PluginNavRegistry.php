<?php

declare(strict_types=1);

namespace App;

/**
 * Header + footer nav links contributed by enabled plugins.
 *
 * Layout reads from header()/footer() at render time. Placement is a free
 * string but normalised to "header" or "footer" — anything else falls back
 * to "header" so a typo never silently drops the link. `auth` is normalised
 * to "public" or "admin"; layout filters admin-only items when there is no
 * admin session.
 */
final class PluginNavRegistry
{
    /** @var list<array{slug:string,label:string,href:string,placement:string,auth:string}> */
    private array $items = [];

    public function add(
        string $slug,
        string $label,
        string $href,
        string $placement = 'header',
        string $auth = 'public',
    ): void {
        $placement = $placement === 'footer' ? 'footer' : 'header';
        $auth = $auth === 'admin' ? 'admin' : 'public';
        $this->items[] = [
            'slug' => $slug,
            'label' => $label,
            'href' => $href,
            'placement' => $placement,
            'auth' => $auth,
        ];
    }

    /** @return list<array{slug:string,label:string,href:string,placement:string,auth:string}> */
    public function header(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (array $i): bool => $i['placement'] === 'header',
        ));
    }

    /** @return list<array{slug:string,label:string,href:string,placement:string,auth:string}> */
    public function footer(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (array $i): bool => $i['placement'] === 'footer',
        ));
    }

    /** @return list<array{slug:string,label:string,href:string,placement:string,auth:string}> */
    public function all(): array
    {
        return $this->items;
    }
}
