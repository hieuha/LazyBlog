<?php

declare(strict_types=1);

/**
 * Assertion fixtures for the share-quote data attributes on views/post.php.
 * Run: php tests/test-share-quote-view-attributes.php
 *
 * The security assertion of the share-quote feature lives here: a quote card
 * is a downloadable PNG, so emitting the mount flag on a password-protected
 * post would carry gated prose past the password wall permanently. The view
 * must omit `data-quote-share` for those posts — including after a session
 * unlock, which this view has no way to distinguish and therefore must not
 * try to.
 *
 * Covers:
 *   - normal post   → data-quote-share="1" present
 *   - protected post → the string `data-quote-share` absent entirely
 *   - author/title exposed as attributes, HTML-escaped
 *   - a title carrying " and < cannot break out of the attribute
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Post;

$failures = 0;

function section(string $name): void
{
    echo "==> {$name}\n";
}

function ok(string $msg): void
{
    echo "  ok: {$msg}\n";
}

function fail(string $msg): void
{
    global $failures;
    $failures++;
    fwrite(STDERR, "  FAIL: {$msg}\n");
}

function check(bool $cond, string $msg, string $detail = ''): void
{
    $cond ? ok($msg) : fail($msg . ($detail !== '' ? " — {$detail}" : ''));
}

/**
 * Render views/post.php in isolation and return the HTML.
 *
 * The view only needs $post, $toc, $seriesNav, $body_html and $title. It
 * calls Http::plugins() (null when no registry is installed, guarded by `?->`)
 * and Auth::check() (starts a session — harmless in CLI, and the output is
 * buffered so no headers have been sent yet).
 */
function renderPostView(Post $post): string
{
    $toc = [];
    $seriesNav = null;
    $body_html = '<p>body</p>';
    $title = $post->title;

    ob_start();
    include __DIR__ . '/../views/post.php';
    return (string) ob_get_clean();
}

function makePost(string $title, ?string $author, ?string $passwordHash): Post
{
    return new Post(
        slug: 'sample-post',
        title: $title,
        date: '2026-08-28',
        tags: ['test'],
        draft: false,
        bodyMarkdown: 'body',
        icon: null,
        summary: null,
        author: $author,
        image: null,
        series: null,
        part: null,
        passwordHash: $passwordHash,
    );
}

section('Normal post exposes the share-quote mount flag');

$html = renderPostView(makePost('A normal post', 'Harry Ha', null));

check(
    str_contains($html, 'data-quote-share="1"'),
    'normal post emits data-quote-share="1"'
);
check(
    str_contains($html, 'data-quote-author="Harry Ha"'),
    'author exposed as data-quote-author'
);
check(
    str_contains($html, 'data-quote-title="A normal post"'),
    'title exposed as data-quote-title'
);

section('Protected post never exposes the mount flag');

$protected = makePost('A gated post', 'Harry Ha', password_hash('secret', PASSWORD_BCRYPT));
$html = renderPostView($protected);

check(
    $protected->isProtected(),
    'fixture is actually protected'
);
check(
    !str_contains($html, 'data-quote-share'),
    'protected post omits data-quote-share entirely',
    'a session unlock must not re-enable it either — the attribute is gone at the source'
);
check(
    str_contains($html, 'data-quote-title="A gated post"'),
    'title attribute still rendered (only the mount flag is gated)'
);

section('Hostile title cannot break out of the attribute');

$html = renderPostView(makePost('He said "hi" & <script>alert(1)</script>', null, null));

check(
    str_contains($html, 'data-quote-title="He said &quot;hi&quot; &amp; &lt;script&gt;alert(1)&lt;/script&gt;"'),
    'quotes, ampersand and angle brackets escaped inside the attribute'
);
check(
    !str_contains($html, '<script>alert(1)</script>'),
    'no raw script tag reaches the output'
);
check(
    str_contains($html, 'data-quote-author=""'),
    'null author renders as an empty attribute, not a PHP notice'
);

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}
echo "All share-quote view assertions passed\n";
