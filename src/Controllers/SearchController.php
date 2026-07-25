<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http;
use App\Searcher;

final class SearchController
{
    public function __construct(private readonly Searcher $searcher)
    {
    }

    public function show(): void
    {
        // Cap query at 256 chars — past that it's never a real search query,
        // and unbounded length lets an attacker burn CPU in fold()/strtr().
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 256);

        // JSON mode feeds the Ctrl/Cmd+K command palette. Same Searcher, so
        // protected-post rules (no body index, `// protected post` snippet)
        // carry over for free. Smaller limit — the palette shows a shortlist,
        // not a results page.
        if (($_GET['format'] ?? '') === 'json') {
            $hits = $q !== '' ? $this->searcher->run($q, 8) : [];
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode([
                'q' => $q,
                'hits' => array_map(static fn (array $h): array => [
                    'url' => '/posts/' . $h['slug'],
                    'title' => $h['title'],
                    'date' => mb_substr($h['date'], 0, 10),
                    'tags' => $h['tags'],
                    'snippet' => $h['snippet'],
                ], $hits),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $hits = $q !== '' ? $this->searcher->run($q) : [];

        Http::render('search', [
            'title' => $q !== '' ? 'Search // ' . $q : 'Search // Transmission Index',
            'q' => $q,
            'hits' => $hits,
            'fold' => fn (string $s): string => $this->searcher->fold($s),
        ]);
    }
}
