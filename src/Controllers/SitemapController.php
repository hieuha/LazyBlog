<?php

declare(strict_types=1);

namespace App\Controllers;

use App\SitemapBuilder;

/**
 * GET /sitemap.xml — search-engine sitemap.
 *
 * Same serving shape as FeedController: cached XML with an ETag so
 * crawlers polling the sitemap get cheap 304s between content changes.
 */
final class SitemapController
{
    public function __construct(private readonly SitemapBuilder $builder)
    {
    }

    public function show(): void
    {
        $xml = $this->builder->readOrBuild();
        $etag = '"' . sha1($xml) . '"';

        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if (is_string($ifNoneMatch) && trim($ifNoneMatch) === $etag) {
            http_response_code(304);
            header('ETag: ' . $etag);
            return;
        }

        header('Content-Type: application/xml; charset=utf-8');
        header('ETag: ' . $etag);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=300');
        echo $xml;
    }
}
