<?php

declare(strict_types=1);

namespace Plugins\Graffiti;

/**
 * Fetch a friend's public sticker catalogue (`/graffiti/stickers.json`) so
 * the composer page can show the operator current prices on the target's
 * blog. Cached for 60s per friend to keep composer responsive without
 * hammering the network on every keystroke.
 *
 * Cache lives at `content/plugins/graffiti/catalogue-cache/{friend_id}.json`.
 * Operator can force-refresh by deleting the file.
 */
final class CatalogueFetcher
{
    private const TTL_SECONDS = 60;
    private const CACHE_DIR_NAME = 'catalogue-cache';

    private string $cacheDir;

    public function __construct(string $storagePath)
    {
        $this->cacheDir = $storagePath . '/' . self::CACHE_DIR_NAME;
    }

    /**
     * @return list<array{id:string,name:string,price:int}>
     */
    public function fetch(string $friendId, string $friendBlogUrl): array
    {
        $cachePath = $this->cacheDir . '/' . preg_replace('/[^a-z0-9_-]/i', '', $friendId) . '.json';

        if (is_file($cachePath) && (time() - filemtime($cachePath)) < self::TTL_SECONDS) {
            $cached = json_decode((string) file_get_contents($cachePath), true);
            if (is_array($cached)) {
                return self::normalize($cached);
            }
        }

        $url = rtrim($friendBlogUrl, '/') . '/graffiti/stickers.json';
        $res = HttpSender::get($url);
        if ($res['transport_failed'] || $res['status'] !== 200) {
            // Fall back to a stale cache if we have one — better than nothing
            // when the friend's blog is momentarily down.
            if (is_file($cachePath)) {
                $cached = json_decode((string) file_get_contents($cachePath), true);
                if (is_array($cached)) {
                    return self::normalize($cached);
                }
            }
            return [];
        }

        $data = json_decode($res['body'], true);
        if (!is_array($data)) {
            return [];
        }

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0o755, recursive: true);
        }
        @file_put_contents($cachePath, (string) json_encode($data, JSON_UNESCAPED_SLASHES));

        return self::normalize($data);
    }

    /** @return list<array{id:string,name:string,price:int}> */
    private static function normalize(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $id = (string) ($r['id'] ?? '');
            if ($id === '') continue;
            $out[] = [
                'id'    => $id,
                'name'  => (string) ($r['name'] ?? $id),
                'price' => (int) ($r['price'] ?? $r['default_price'] ?? 0),
            ];
        }
        return $out;
    }
}
