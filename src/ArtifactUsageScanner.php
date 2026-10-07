<?php

declare(strict_types=1);

namespace App;

/**
 * Finds which posts (and the /about page) embed each artifact, by scanning
 * the markdown sources for `::: artifact id="…"` openers.
 *
 * Deliberately stateless — no reverse index to keep in sync with post
 * saves, renames and deletes. The admin artifact list is the only caller
 * and reading a personal blog's markdown files once per page view is
 * cheap. Drafts count as usage: deleting an artifact a draft still points
 * at would silently break that draft.
 */
final class ArtifactUsageScanner
{
    private const OPENER = '/^:::[ \t]*artifact\b[^\n]*\bid\s*=\s*"(' . ArtifactStore::ID_PATTERN . ')"/m';

    public function __construct(
        private readonly PostRepository $repo,
        private readonly string $aboutPath,
    ) {
    }

    /**
     * @return array<string, list<array{title:string,url:string,editUrl:string,draft:bool}>>
     *         artifact id => places it is embedded
     */
    public function usageById(): array
    {
        $usage = [];
        foreach ($this->repo->all() as $entry) {
            $md = @file_get_contents((string) $entry['file']);
            if (!is_string($md)) {
                continue;
            }
            foreach (self::idsIn($md) as $id) {
                $usage[$id][] = [
                    'title' => (string) $entry['title'],
                    'url' => '/posts/' . $entry['slug'],
                    'editUrl' => '/admin/edit/' . $entry['slug'],
                    'draft' => (bool) $entry['draft'],
                ];
            }
        }

        $about = @file_get_contents($this->aboutPath);
        if (is_string($about)) {
            foreach (self::idsIn($about) as $id) {
                $usage[$id][] = ['title' => 'About page', 'url' => '/about', 'editUrl' => '/admin/about', 'draft' => false];
            }
        }
        return $usage;
    }

    /** @return list<string> distinct artifact IDs embedded in one markdown source */
    public static function idsIn(string $markdown): array
    {
        preg_match_all(self::OPENER, $markdown, $m);
        return array_values(array_unique($m[1]));
    }
}
