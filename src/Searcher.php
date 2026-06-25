<?php

declare(strict_types=1);

namespace App;

/**
 * Full-text search over the published posts.
 *
 * Scoring:
 *   title occurrence  × 10
 *   tag exact match   × 5
 *   body occurrence   × 1
 *
 * Multi-word queries use AND semantics — every term must appear in at
 * least one of {title, tags, body} for the post to count as a hit.
 * Diacritic-insensitive via a hand-rolled Vietnamese fold so "tin hieu"
 * matches "tín hiệu" without requiring the `intl` extension.
 */
final class Searcher
{
    /** @var array<string,string> source char → folded char */
    private const FOLD = [
        'à'=>'a','á'=>'a','ả'=>'a','ã'=>'a','ạ'=>'a',
        'ă'=>'a','ằ'=>'a','ắ'=>'a','ẳ'=>'a','ẵ'=>'a','ặ'=>'a',
        'â'=>'a','ầ'=>'a','ấ'=>'a','ẩ'=>'a','ẫ'=>'a','ậ'=>'a',
        'è'=>'e','é'=>'e','ẻ'=>'e','ẽ'=>'e','ẹ'=>'e',
        'ê'=>'e','ề'=>'e','ế'=>'e','ể'=>'e','ễ'=>'e','ệ'=>'e',
        'ì'=>'i','í'=>'i','ỉ'=>'i','ĩ'=>'i','ị'=>'i',
        'ò'=>'o','ó'=>'o','ỏ'=>'o','õ'=>'o','ọ'=>'o',
        'ô'=>'o','ồ'=>'o','ố'=>'o','ổ'=>'o','ỗ'=>'o','ộ'=>'o',
        'ơ'=>'o','ờ'=>'o','ớ'=>'o','ở'=>'o','ỡ'=>'o','ợ'=>'o',
        'ù'=>'u','ú'=>'u','ủ'=>'u','ũ'=>'u','ụ'=>'u',
        'ư'=>'u','ừ'=>'u','ứ'=>'u','ử'=>'u','ữ'=>'u','ự'=>'u',
        'ỳ'=>'y','ý'=>'y','ỷ'=>'y','ỹ'=>'y','ỵ'=>'y',
        'đ'=>'d',
    ];

    public function __construct(private readonly PostRepository $repo)
    {
    }

    /**
     * @return list<array{
     *   slug:string, title:string, date:string, tags:list<string>,
     *   icon:?string, score:int, snippet:string
     * }>
     */
    public function run(string $query, int $limit = 50): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $terms = $this->tokenize($query);
        if ($terms === []) {
            return [];
        }

        $hits = [];
        foreach ($this->repo->published() as $entry) {
            $isProtected = !empty($entry['protected']);
            $title = $entry['title'];
            $tags = $entry['tags'];
            $titleN = $this->fold($title);
            $tagsN = array_map([$this, 'fold'], $tags);

            if ($isProtected) {
                // Protected posts: title + tags are searchable so the operator
                // can still find them in admin scenarios and readers know they
                // exist, but the body never enters the search index and the
                // snippet does NOT leak the body opening either.
                $score = 0;
                $allFound = true;
                foreach ($terms as $term) {
                    $titleHits = substr_count($titleN, $term);
                    $tagHits = count(array_filter($tagsN, static fn (string $t): bool => $t === $term));
                    if ($titleHits === 0 && $tagHits === 0) {
                        $allFound = false;
                        break;
                    }
                    $score += $titleHits * 10 + $tagHits * 5;
                }
                if (!$allFound) {
                    continue;
                }
                $hits[] = [
                    'slug' => $entry['slug'],
                    'title' => $title,
                    'date' => $entry['date'],
                    'tags' => $tags,
                    'icon' => $entry['icon'] ?? null,
                    'score' => $score,
                    'snippet' => '🔒 protected post',
                ];
                continue;
            }

            $raw = (string) @file_get_contents($entry['file']);
            // Reuse FrontmatterParser instead of a duplicate regex — the
            // lazy `(.*?)` form would devour body content when the body
            // contains a `---` horizontal rule (CommonMark HR).
            [, $body] = FrontmatterParser::parse($raw);

            $bodyN = $this->fold($body);

            $score = 0;
            $allFound = true;
            foreach ($terms as $term) {
                $titleHits = substr_count($titleN, $term);
                $tagHits = count(array_filter($tagsN, static fn (string $t): bool => $t === $term));
                $bodyHits = substr_count($bodyN, $term);

                if ($titleHits === 0 && $tagHits === 0 && $bodyHits === 0) {
                    $allFound = false;
                    break;
                }
                $score += $titleHits * 10 + $tagHits * 5 + $bodyHits;
            }

            if (!$allFound) {
                continue;
            }

            $hits[] = [
                'slug' => $entry['slug'],
                'title' => $title,
                'date' => $entry['date'],
                'tags' => $tags,
                'icon' => $entry['icon'] ?? null,
                'score' => $score,
                'snippet' => $this->snippet($body, $bodyN, $terms),
            ];
        }

        usort($hits, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_slice($hits, 0, $limit);
    }

    /**
     * Lowercase + strip Vietnamese diacritics for accent-insensitive match.
     */
    public function fold(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        return strtr($s, self::FOLD);
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $query): array
    {
        $folded = $this->fold($query);
        $parts = preg_split('/\s+/u', trim($folded)) ?: [];
        // Drop tokens shorter than 2 chars — single letters are too noisy.
        return array_values(array_filter($parts, static fn (string $t): bool => mb_strlen($t) >= 2));
    }

    /**
     * Build a ~120-char snippet centered on the first match. `$bodyOriginal`
     * and `$bodyFolded` must align character-for-character (the fold is 1:1).
     *
     * @param list<string> $terms
     */
    private function snippet(string $bodyOriginal, string $bodyFolded, array $terms): string
    {
        // Find first match position across all terms.
        $pos = false;
        $matchedTerm = '';
        foreach ($terms as $term) {
            $p = strpos($bodyFolded, $term);
            if ($p !== false && ($pos === false || $p < $pos)) {
                $pos = $p;
                $matchedTerm = $term;
            }
        }

        if ($pos === false) {
            // Match was only in title/tags — fall back to body opening.
            $snippet = mb_substr(trim($bodyOriginal), 0, 160);
            return $snippet . '…';
        }

        // Convert byte offset to char offset for mb_substr.
        $charPos = mb_strlen(substr($bodyOriginal, 0, $pos));
        $start = max(0, $charPos - 50);
        $snippet = mb_substr($bodyOriginal, $start, 160);
        if ($start > 0) {
            $snippet = '…' . $snippet;
        }
        $snippet = trim((string) preg_replace('/\s+/u', ' ', $snippet)) . '…';

        return $snippet;
    }
}
