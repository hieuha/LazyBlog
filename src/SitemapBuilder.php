<?php

declare(strict_types=1);

namespace App;

use DOMDocument;
use DOMElement;

/**
 * Build sitemap.xml for search-engine discovery.
 *
 * URL set: home, /archive, /about (when the page exists), /series plus
 * each series detail page, and every published post. Password-protected
 * posts are excluded — same precedent as feed.xml and llms.txt: their
 * public URL only serves the unlock HUD, which is not worth indexing.
 * /search and tag pages are deliberately left out (thin listings that
 * would dilute the sitemap without adding crawlable content).
 *
 * Uses DOMDocument so every URL is XML-escaped, mirroring FeedBuilder.
 * Cached at content/.sitemap.xml; PostRepository invalidates it alongside
 * the feed + llms caches whenever the post index changes.
 */
final class SitemapBuilder
{
    public function __construct(
        private readonly PostRepository $repo,
        private readonly string $contentDir,
    ) {
    }

    public function cachePath(): string
    {
        return $this->contentDir . '/.sitemap.xml';
    }

    /**
     * Return the sitemap XML, building + caching it if missing.
     */
    public function readOrBuild(): string
    {
        $path = $this->cachePath();
        if (is_file($path)) {
            $cached = @file_get_contents($path);
            if ($cached !== false) {
                return $cached;
            }
        }
        $xml = $this->build();
        try { FileWriter::writeAtomic($path, $xml); } catch (\Throwable) {}
        return $xml;
    }

    public function build(): string
    {
        $siteUrl = rtrim((string) Config::get('SITE_URL'), '/');

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $urlset = $dom->createElement('urlset');
        $urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $dom->appendChild($urlset);

        $posts = array_values(array_filter(
            $this->repo->published(),
            static fn (array $e): bool => empty($e['protected']),
        ));

        // Home's lastmod tracks the newest post edit — the front page
        // changes whenever any listed post does.
        $latestMtime = 0;
        foreach ($posts as $entry) {
            $latestMtime = max($latestMtime, (int) ($entry['mtime'] ?? 0));
        }

        $this->appendUrl($dom, $urlset, $siteUrl . '/', $latestMtime);
        $this->appendUrl($dom, $urlset, $siteUrl . '/archive', $latestMtime);

        if ((new AboutRepository($this->contentDir))->exists()) {
            $this->appendUrl($dom, $urlset, $siteUrl . '/about', null);
        }

        $series = $this->repo->allSeries();
        if ($series !== []) {
            $this->appendUrl($dom, $urlset, $siteUrl . '/series', $latestMtime);
            foreach ($series as $s) {
                $this->appendUrl($dom, $urlset, $siteUrl . '/series/' . $s['slug'], null);
            }
        }

        foreach ($posts as $entry) {
            $this->appendUrl(
                $dom,
                $urlset,
                $siteUrl . '/posts/' . $entry['slug'],
                (int) ($entry['mtime'] ?? 0),
            );
        }

        $xml = $dom->saveXML();
        return $xml === false
            ? '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>'
            : $xml;
    }

    private function appendUrl(DOMDocument $dom, DOMElement $urlset, string $loc, ?int $mtime): void
    {
        $url = $dom->createElement('url');
        $urlset->appendChild($url);

        $locEl = $dom->createElement('loc');
        $locEl->appendChild($dom->createTextNode($loc));
        $url->appendChild($locEl);

        if ($mtime !== null && $mtime > 0) {
            $lastmod = $dom->createElement('lastmod');
            $lastmod->appendChild($dom->createTextNode(date('Y-m-d', $mtime)));
            $url->appendChild($lastmod);
        }
    }
}
