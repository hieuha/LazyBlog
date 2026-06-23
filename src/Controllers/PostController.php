<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http;
use App\MarkdownRenderer;
use App\PostRepository;
use App\PostViewEvent;

final class PostController
{
    public function __construct(
        private readonly PostRepository $repo,
        private readonly MarkdownRenderer $renderer,
    ) {
    }

    /**
     * @param array<string,string> $params
     */
    public function show(array $params): void
    {
        $slug = $params['slug'] ?? '';
        $post = $this->repo->bySlug($slug);

        if ($post === null || $post->draft || $post->displayDate() > date('Y-m-d')) {
            http_response_code(404);
            Http::render('not-found', ['title' => '404 // NO SIGNAL']);
            return;
        }

        $rendered = $this->renderer->render($post->bodyMarkdown);

        // Series context — if this post belongs to a series, fetch the
        // ordered list and find this post's position so the view can show
        // a "Part N of M" banner + prev/next nav at the bottom.
        $seriesNav = null;
        if ($post->series !== null) {
            $seriesPosts = $this->repo->bySeries($post->series);
            $total = count($seriesPosts);
            $idx = null;
            foreach ($seriesPosts as $i => $e) {
                if ($e['slug'] === $post->slug) {
                    $idx = $i;
                    break;
                }
            }
            if ($idx !== null && $total > 0) {
                $seriesNav = [
                    'slug' => $post->series,
                    'title' => ucwords(str_replace(['-', '_'], ' ', $post->series)),
                    'position' => $idx + 1,
                    'total' => $total,
                    'prev' => $idx > 0 ? $seriesPosts[$idx - 1] : null,
                    'next' => $idx < $total - 1 ? $seriesPosts[$idx + 1] : null,
                ];
            }
        }

        // Dispatch BEFORE render so listeners can setcookie()/header() safely
        // (e.g. view-counter plugin minting the lz_uid cookie).
        Http::plugins()?->dispatchPostView(new PostViewEvent(
            slug: $post->slug,
            userAgent: (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            requestTime: time(),
        ));

        Http::render('post', [
            'title' => $post->title,
            'post' => $post,
            'body_html' => $rendered['html'],
            'toc' => $rendered['toc'],
            'seriesNav' => $seriesNav,
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function raw(array $params): void
    {
        $slug = $params['slug'] ?? '';
        $post = $this->repo->bySlug($slug);

        if ($post === null || $post->draft || $post->displayDate() > date('Y-m-d')) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "404 not found\n";
            return;
        }

        $raw = $this->repo->rawMarkdownBySlug($slug);
        if ($raw === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "404 not found\n";
            return;
        }

        header('Content-Type: text/markdown; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo $raw;
    }
}
