<?php

declare(strict_types=1);

namespace Plugins\Stalk;

use RuntimeException;
use SimpleXMLElement;

/**
 * Parses RSS 2.0 produced by another LazyBlog instance.
 *
 * Strict generator check: rejects feeds whose `<generator>` does not
 * contain the literal substring `LazyBlog`. This is how we keep the
 * Stalk plugin scoped to LazyBlog blogs (no general-purpose RSS reader).
 *
 * Defense-in-depth XML hardening:
 *   - LIBXML_NONET — blocks SimpleXML from following external entities
 *     across the network (XXE prevention).
 *   - LIBXML_NOCDATA — flattens CDATA into ordinary string content.
 *   - libxml internal-errors switch ensures malformed XML throws cleanly
 *     instead of warning-leak on stderr.
 *
 * Output:
 *   parse(): [
 *     'generator'     => string,
 *     'channel_title' => string,   // used to derive default friend handle
 *     'items'         => list<{
 *       'title'       => string,
 *       'link'        => string,    // http/https only — others skipped
 *       'pub_date_ts' => int,       // 0 when unparseable
 *       'guid'        => string,    // falls back to link when absent
 *     }>,
 *   ]
 *
 * `items` are sorted by `pub_date_ts` DESC and capped at HARD_CEILING.
 * The user-configured `max_items_per_friend` slice is RefreshService's
 * responsibility on top of this.
 */
final class FeedParser
{
    public const HARD_CEILING = 10;

    /** @return array{generator:string,channel_title:string,items:list<array<string,mixed>>} */
    public function parse(string $xml): array
    {
        if (trim($xml) === '') {
            throw new RuntimeException('empty XML body');
        }

        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if ($doc === false) {
                throw new RuntimeException('malformed XML');
            }

            // Some feeds nest items under <channel>, some surface them at the root.
            $channel = $doc->channel ?? $doc;

            $generator = trim((string) ($channel->generator ?? ''));
            if (!str_contains($generator, 'LazyBlog')) {
                throw new RuntimeException('not a LazyBlog blog');
            }

            $channelTitle = trim((string) ($channel->title ?? ''));

            $items = [];
            foreach ($channel->item ?? [] as $node) {
                $title = trim((string) $node->title);
                $link  = trim((string) $node->link);
                if ($title === '' || $link === '') {
                    continue;
                }
                $scheme = parse_url($link, PHP_URL_SCHEME);
                if (!in_array($scheme, ['http', 'https'], true)) {
                    continue;
                }
                $guidRaw = trim((string) $node->guid);
                $pubRaw  = trim((string) $node->pubDate);
                $pubTs   = $pubRaw !== '' ? (int) (strtotime($pubRaw) ?: 0) : 0;

                $items[] = [
                    'title'       => $title,
                    'link'        => $link,
                    'pub_date_ts' => $pubTs,
                    'guid'        => $guidRaw !== '' ? $guidRaw : $link,
                ];
            }

            // Newest first; defense against feeds that don't sort their items.
            usort(
                $items,
                static fn (array $a, array $b): int => ($b['pub_date_ts'] <=> $a['pub_date_ts']),
            );
            $items = array_slice($items, 0, self::HARD_CEILING);

            return [
                'generator'     => $generator,
                'channel_title' => $channelTitle,
                'items'         => $items,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }
}
