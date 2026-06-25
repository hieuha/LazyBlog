<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Http;
use App\MarkdownRenderer;
use App\Post;
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

        // Password-protected posts render an unlock form instead of the body
        // until the session carries a flag for this slug. We deliberately do
        // NOT render markdown, do NOT dispatch PostViewEvent (so view counters
        // don't tick on a locked view), and the social meta path below skips
        // body-derived fallbacks via the $locked flag passed into the view.
        //
        // Throttle check on GET as well, otherwise F5 reload after a 429
        // would re-paint the unlock form as if nothing had happened — the
        // throttle counter would still be ticking on the server, but the
        // UI would lie about it. The check uses the SAME sliding 15-min
        // window per IP (Auth::RATE_LIMIT_WINDOW_SEC) the POST path uses.
        if ($post->isProtected() && !Auth::isPostUnlocked($post->slug)) {
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            if (Auth::postUnlockTooMany($ip)) {
                $this->renderUnlockForm(
                    $post,
                    'Too many attempts. Try again later.',
                    429,
                    true,
                );
                return;
            }
            $this->renderUnlockForm($post, null, 200, false);
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

        // Protected posts: 404 to anonymous visitors. Visitors who have
        // already unlocked the post in this session, and admins, do get
        // the .md — but we strip the `password_hash:` frontmatter line
        // before serving so the bcrypt hash never leaves the server in
        // plaintext markdown. Anonymous 404 keeps the leak surface
        // closed for scrapers / crawlers / `llms-full.txt` consumers.
        $isUnlockedReader = Auth::isPostUnlocked($post->slug) || Auth::check();
        if ($post->isProtected() && !$isUnlockedReader) {
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

        if ($post->isProtected()) {
            $raw = self::stripPasswordHashLine($raw);
        }

        header('Content-Type: text/markdown; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo $raw;
    }

    /**
     * Remove the `password_hash:` YAML line from a frontmatter block.
     * Operates only on the first `---`-delimited block to avoid touching
     * any body content that happens to contain that string. Idempotent
     * and a no-op when the line isn't present.
     */
    private static function stripPasswordHashLine(string $raw): string
    {
        // Match the leading frontmatter block: starts at file start with
        // `---\n`, ends at the next `---` on its own line.
        if (!preg_match('/^---\n(.*?)\n---\n/s', $raw, $m, PREG_OFFSET_CAPTURE)) {
            return $raw;
        }
        $block = $m[1][0];
        $blockStart = $m[1][1];
        $blockEnd = $blockStart + strlen($block);
        $cleanedBlock = (string) preg_replace(
            '/^password_hash:.*\R?/m',
            '',
            $block,
        );
        return substr($raw, 0, $blockStart) . $cleanedBlock . substr($raw, $blockEnd);
    }

    /**
     * POST /posts/{slug}/unlock — verify password, set session flag, redirect.
     *
     * Failure burns 500ms (matches Auth::attempt) and records against a
     * per-IP attempt file separate from /admin/login so a flood of guesses
     * here cannot lock the operator out of admin. 10 failures / 15 min
     * window returns 429 fast for that IP.
     *
     * @param array<string,string> $params
     */
    public function unlockSubmit(array $params): void
    {
        Csrf::requireValid();
        $slug = $params['slug'] ?? '';
        $post = $this->repo->bySlug($slug);

        if ($post === null || $post->draft || $post->displayDate() > date('Y-m-d')) {
            http_response_code(404);
            Http::render('not-found', ['title' => '404 // NO SIGNAL']);
            return;
        }

        // Already unlocked / never locked — just bounce to the post.
        if (!$post->isProtected()) {
            Http::redirect($post->url());
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (Auth::postUnlockTooMany($ip)) {
            usleep(500_000);
            // Throttled: render the form with input disabled. A second
            // submit during the cool-down window would just stall on the
            // same throttle branch, so disabling the field is the honest
            // signal that the user must wait.
            $this->renderUnlockForm($post, 'Too many attempts. Try again later.', 429, true);
            return;
        }

        $password = (string) ($_POST['password'] ?? '');
        if (!password_verify($password, (string) $post->passwordHash)) {
            usleep(500_000);
            Auth::postUnlockRecordFailure($ip);
            // After recording this failure, the IP may have just crossed
            // the threshold — re-check so the next paint reflects the new
            // throttle state immediately instead of letting the user
            // submit one more time only to bounce off the rate limit.
            $throttled = Auth::postUnlockTooMany($ip);
            if ($throttled) {
                $msg = 'Too many attempts. Try again later.';
            } else {
                $remaining = Auth::postUnlockAttemptsRemaining($ip);
                // Show remaining-attempt count so the user knows they're
                // burning through a finite budget. Singular/plural kept
                // simple (1 attempt left vs N attempts left).
                $msg = $remaining === 1
                    ? 'Wrong password. 1 attempt left.'
                    : "Wrong password. {$remaining} attempts left.";
            }
            $this->renderUnlockForm($post, $msg, $throttled ? 429 : 401, $throttled);
            return;
        }

        Auth::postUnlockClearFailures($ip);
        Auth::markPostUnlocked($post->slug);
        Http::redirect($post->url());
    }

    private function renderUnlockForm(Post $post, ?string $error, int $code, bool $throttled): void
    {
        http_response_code($code);
        Http::render('post-password', [
            'title' => $post->title,
            'post' => $post,
            'error' => $error,
            'throttled' => $throttled,
            'csrf' => Csrf::token(),
        ]);
    }
}
