<?php

declare(strict_types=1);

namespace Plugins\ViewCounter;

/**
 * Coarse-grained bot detection by User-Agent substring match.
 *
 * Generic suffixes (`bot`, `crawl`, `spider`) catch the long tail; the
 * named entries are heavy hitters worth documenting explicitly. Empty UA
 * is treated as a bot — real browsers always send one.
 *
 * Trade-off: a future crawler with no obvious token in its UA will leak
 * through until the list is extended. Acceptable for a personal blog;
 * better than a maintenance burden of a full UA database.
 */
final class BotFilter
{
    /** Case-insensitive substrings. Order is irrelevant; first match wins. */
    private const TOKENS = [
        'bot',              // googlebot, bingbot, applebot, facebookexternalhit, twitterbot, …
        'crawl',            // crawler, web-crawler
        'spider',           // baiduspider, yandexspider
        'slurp',            // yahoo
        'mediapartners',
        'facebookexternalhit',  // FB link unfurler — bare word, no "bot" suffix
        'gptbot',
        'claudebot',
        'perplexitybot',
        'ccbot',
        'ahrefsbot',
        'semrushbot',
        'mj12bot',
        'dotbot',
        'petalbot',
        'curl/',
        'wget',
        'python-requests',
        'go-http-client',
        'feedfetcher',
        'rss',
        'feed',
    ];

    public static function isBot(string $ua): bool
    {
        if ($ua === '') {
            return true;
        }
        $lower = strtolower($ua);
        foreach (self::TOKENS as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }
        return false;
    }
}
