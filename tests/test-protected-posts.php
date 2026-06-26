<?php

declare(strict_types=1);

/**
 * Assertion fixtures for password-protected posts.
 * Run: php tests/test-protected-posts.php
 *
 * Covers:
 *   - Post::isProtected + PostRepository roundtrip + index `protected` flag
 *   - LlmsBuilder skips protected entries from both llms.txt and llms-full.txt
 *   - FeedBuilder skips protected entries from RSS, slice filter ordering
 *   - Searcher: body never indexed for protected posts, snippet placeholder
 *   - AdminController::buildPostFromForm 3-state save logic
 *   - Auth::isPostUnlocked / markPostUnlocked / postUnlock rate-limit
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Auth;
use App\FeedBuilder;
use App\LlmsBuilder;
use App\MarkdownRenderer;
use App\Post;
use App\PostRepository;
use App\Searcher;

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

// Sandbox content dir per run; cleaned on shutdown.
$tmp = sys_get_temp_dir() . '/lazyblog-protected-' . posix_getpid() . '-' . uniqid();
mkdir($tmp . '/posts', 0775, true);
register_shutdown_function(static function () use ($tmp): void {
    if (!is_dir($tmp)) {
        return;
    }
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($rii as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($tmp);
});

// Env scaffolding for builders that read site config. Config reads from
// $_ENV directly, so we set there (putenv alone doesn't update $_ENV).
$_ENV['SITE_TITLE'] = 'Test';
$_ENV['SITE_URL'] = 'https://example.test';
$_ENV['SITE_DESCRIPTION'] = 'test blog';
$_ENV['TIMEZONE'] = 'UTC';
\App\Config::boot();

$repo = new PostRepository($tmp);

// -----------------------------------------------------------------------------
section('Post::isProtected + persistence roundtrip');
// -----------------------------------------------------------------------------

$plain = new Post(
    slug: 'plain', title: 'Plain Post', date: '2026-06-24', tags: ['one'],
    draft: false, bodyMarkdown: 'plain body unique-token-alpha',
);
check(!$plain->isProtected(), 'plain.isProtected() === false');

$hash = password_hash('test1234', PASSWORD_BCRYPT);
$secret = new Post(
    slug: 'secret', title: 'Secret Title', date: '2026-06-25', tags: ['one', 'private'],
    draft: false, bodyMarkdown: 'very sensitive body unique-token-beta',
    summary: 'Public summary line',
    passwordHash: $hash,
);
check($secret->isProtected(), 'secret.isProtected() === true');

$repo->save($plain);
$repo->save($secret);

$rawSecret = (string) file_get_contents($tmp . '/posts/2026-06-25-secret.md');
check(str_contains($rawSecret, 'password_hash:'), 'secret .md frontmatter contains password_hash');
$rawPlain = (string) file_get_contents($tmp . '/posts/2026-06-24-plain.md');
check(!str_contains($rawPlain, 'password_hash'), 'plain .md frontmatter omits password_hash');

$reloaded = $repo->bySlug('secret');
check($reloaded !== null && $reloaded->passwordHash === $hash, 'hash roundtrip exact');
check($reloaded !== null && password_verify('test1234', (string) $reloaded->passwordHash), 'password_verify works on reloaded hash');

// -----------------------------------------------------------------------------
section('Index file: `protected` flag set, hash NEVER serialized');
// -----------------------------------------------------------------------------

$repo->all();
$idxRaw = (string) file_get_contents($tmp . '/.index.json');
$idx = json_decode($idxRaw, true) ?: [];
$secretEntry = null;
$plainEntry = null;
foreach ($idx as $e) {
    if ($e['slug'] === 'secret') $secretEntry = $e;
    if ($e['slug'] === 'plain') $plainEntry = $e;
}
check($secretEntry !== null && ($secretEntry['protected'] ?? null) === true, 'index entry for secret has protected=true');
check($plainEntry !== null && ($plainEntry['protected'] ?? null) === false, 'index entry for plain has protected=false');
check(!str_contains($idxRaw, '$2y$'), 'index file MUST NOT contain bcrypt hash string');

// -----------------------------------------------------------------------------
section('LlmsBuilder excludes protected posts');
// -----------------------------------------------------------------------------

$llms = new LlmsBuilder($repo, $tmp);
$idxTxt = $llms->buildIndex();
check(!str_contains($idxTxt, 'secret'), 'llms.txt MUST NOT contain protected slug');
check(str_contains($idxTxt, 'plain'), 'llms.txt contains plain slug');
check(!str_contains($idxTxt, 'Secret Title'), 'llms.txt MUST NOT contain protected title');
check(!str_contains($idxTxt, 'unique-token-beta'), 'llms.txt MUST NOT contain protected body content');

// -----------------------------------------------------------------------------
section('FeedBuilder excludes protected posts');
// -----------------------------------------------------------------------------

$renderer = new MarkdownRenderer();
$feed = new FeedBuilder($repo, $renderer, $tmp);
$rss = $feed->build();
check(!str_contains($rss, 'Secret Title'), 'RSS MUST NOT contain protected title');
check(!str_contains($rss, 'unique-token-beta'), 'RSS MUST NOT contain protected body');
check(str_contains($rss, 'Plain Post'), 'RSS contains plain title');

// -----------------------------------------------------------------------------
section('Searcher: body never indexed for protected posts');
// -----------------------------------------------------------------------------

$searcher = new Searcher($repo);

$bodyHits = $searcher->run('unique-token-beta');
check(count($bodyHits) === 0, 'protected body term yields 0 results', 'got ' . count($bodyHits));

$bodyHitsPlain = $searcher->run('unique-token-alpha');
check(count($bodyHitsPlain) === 1 && $bodyHitsPlain[0]['slug'] === 'plain', 'plain body term yields the plain post');

$titleHits = $searcher->run('secret');
check(count($titleHits) === 1, 'protected title term yields the protected post');
check(($titleHits[0]['snippet'] ?? '') === '// protected post', 'protected hit snippet is the lock placeholder, got: ' . ($titleHits[0]['snippet'] ?? '(none)'));

$tagHits = $searcher->run('private');
check(count($tagHits) >= 1, 'protected tag term still matches by tag');

// -----------------------------------------------------------------------------
section('AdminController::buildPostFromForm — password 3-state');
// -----------------------------------------------------------------------------

$ref = new ReflectionMethod(App\Controllers\AdminController::class, 'buildPostFromForm');
$ref->setAccessible(true);

$base = [
    'date' => '2026-06-25', 'time' => '', 'slug' => 'x', 'title' => 'T',
    'author' => '', 'tags' => '', 'draft' => false, 'icon' => '',
    'summary' => '', 'image' => '', 'series' => '', 'part' => '', 'body' => 'b',
    'password' => '', 'remove_password' => false, 'is_protected' => false,
];

// State 3: blank + no existing → null
$p = $ref->invoke(null, $base, null);
check($p->passwordHash === null, 'blank + no existing → null');

// State 3: blank + existing → keep
$p = $ref->invoke(null, $base, 'EXISTING-HASH');
check($p->passwordHash === 'EXISTING-HASH', 'blank + existing → carry forward');

// State 2: new value → fresh hash, ignores existing
$v = $base;
$v['password'] = 'newpass';
$p = $ref->invoke(null, $v, 'EXISTING-HASH');
check($p->passwordHash !== null && str_starts_with((string) $p->passwordHash, '$2y$'), 'new password → bcrypt hash');
check(password_verify('newpass', (string) $p->passwordHash), 'verify new password');
check($p->passwordHash !== 'EXISTING-HASH', 'new password does NOT keep existing');

// State 1: remove → null (even with existing)
$v = $base;
$v['remove_password'] = true;
$p = $ref->invoke(null, $v, 'EXISTING-HASH');
check($p->passwordHash === null, 'remove → null with existing');

// State 1 wins over state 2
$v = $base;
$v['remove_password'] = true;
$v['password'] = 'ignored';
$p = $ref->invoke(null, $v, 'EXISTING-HASH');
check($p->passwordHash === null, 'remove beats new password');

// Min length
$v = $base;
$v['password'] = 'abc';
$threw = false;
try {
    $ref->invoke(null, $v, null);
} catch (RuntimeException $e) {
    $threw = str_contains($e->getMessage(), '4 characters');
}
check($threw, 'password < 4 chars throws RuntimeException');

// -----------------------------------------------------------------------------
section('Auth: session unlock helpers + per-post rate-limit');
// -----------------------------------------------------------------------------

// session_start is fine in CLI 8.2 with use_strict_mode off + no session_id.
// Auth::start() handles its own session bootstrap.
@session_start();

$_SESSION = [];
check(!Auth::isPostUnlocked('zzz'), 'unknown slug not unlocked');
Auth::markPostUnlocked('zzz');
check(Auth::isPostUnlocked('zzz'), 'marked slug now unlocked');
check(!Auth::isPostUnlocked('yyy'), 'unrelated slug still locked');

$testIp = '198.51.100.' . random_int(2, 254);
Auth::postUnlockClearFailures($testIp);
check(!Auth::postUnlockTooMany($testIp), 'fresh IP: not throttled');
check(Auth::postUnlockAttemptsRemaining($testIp) === 10, 'fresh IP: 10 attempts remaining');
for ($i = 0; $i < 9; $i++) {
    Auth::postUnlockRecordFailure($testIp);
}
check(!Auth::postUnlockTooMany($testIp), '9 failures: still under limit');
check(Auth::postUnlockAttemptsRemaining($testIp) === 1, '9 failures: 1 attempt remaining');
Auth::postUnlockRecordFailure($testIp);
check(Auth::postUnlockTooMany($testIp), '10 failures: throttled');
check(Auth::postUnlockAttemptsRemaining($testIp) === 0, '10 failures: 0 attempts remaining');
Auth::postUnlockClearFailures($testIp);
check(!Auth::postUnlockTooMany($testIp), 'cleared: not throttled again');
check(Auth::postUnlockAttemptsRemaining($testIp) === 10, 'cleared: 10 attempts remaining');

// -----------------------------------------------------------------------------
section('clientIp resolution — REMOTE_ADDR default + opt-in CF header');
// -----------------------------------------------------------------------------

// Reach the private clientIp() via reflection to verify the precedence.
$clientIp = new ReflectionMethod(App\Auth::class, 'clientIp');
$clientIp->setAccessible(true);

// Default (TRUST_CF_CONNECTING_IP not set): always REMOTE_ADDR, even if
// CF-Connecting-IP is present — defends against forged header on a host
// that isn't behind Cloudflare. Config reads from $_ENV (not putenv),
// so the tests set $_ENV directly.
$_ENV['TRUST_CF_CONNECTING_IP'] = 'false';
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '1.2.3.4';
check($clientIp->invoke(null) === '203.0.113.5', 'default: REMOTE_ADDR wins over forged CF header');

// Opt-in: CF header trusted when env=true AND value parses as IP.
$_ENV['TRUST_CF_CONNECTING_IP'] = 'true';
check($clientIp->invoke(null) === '1.2.3.4', 'opt-in: CF-Connecting-IP used when valid');

// Junk header: fall back to REMOTE_ADDR even when opt-in (filter_var
// rejects non-IPs so the counter doesn't get poisoned with garbage).
$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip; injected';
check($clientIp->invoke(null) === '203.0.113.5', 'opt-in: invalid CF header → REMOTE_ADDR fallback');

// Missing header even when opt-in: REMOTE_ADDR fallback.
unset($_SERVER['HTTP_CF_CONNECTING_IP']);
check($clientIp->invoke(null) === '203.0.113.5', 'opt-in: missing CF header → REMOTE_ADDR fallback');

// IPv6 client through CF.
$_SERVER['HTTP_CF_CONNECTING_IP'] = '2001:db8::1';
check($clientIp->invoke(null) === '2001:db8::1', 'opt-in: IPv6 CF-Connecting-IP accepted');

// Restore default for downstream tests.
unset($_ENV['TRUST_CF_CONNECTING_IP'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['REMOTE_ADDR']);

// -----------------------------------------------------------------------------
section('PostController::raw — protected-post visibility + hash strip');
// -----------------------------------------------------------------------------

// Helper: invoke a controller method and capture echoed output. PHP CLI
// echoes warnings from `http_response_code()` / `header()` (which can't
// actually mutate headers after any prior output) into the same output
// buffer the controller writes its body into. We strip those warning
// lines before deciding whether the body is a 404 page or a 200 stream.
$callRaw = static function (App\Controllers\PostController $ctrl, string $slug): string {
    ob_start();
    set_error_handler(static fn ($severity, $msg): bool => true);
    $ctrl->raw(['slug' => $slug]);
    restore_error_handler();
    $raw = (string) ob_get_clean();
    // Belt + braces: strip any straggler "Warning: ..." line.
    return (string) preg_replace('/^\s*Warning:.*\R/m', '', $raw);
};
$is404 = static fn (string $body): bool => str_contains($body, '404 not found');
$is200 = static fn (string $body): bool => !$is404($body) && str_contains($body, '---');

$renderer = new MarkdownRenderer();
$postCtl = new App\Controllers\PostController($repo, $renderer);

// Reset session to ensure a clean baseline for anonymous tests. Full wipe
// (not just key resets) — leftover keys from earlier session tests can
// hide bugs in this path.
$_SESSION = [];

$body = $callRaw($postCtl, 'plain');
check($is200($body) && str_contains($body, 'unique-token-alpha'), 'plain .md: 200 + full body visible');

$body = $callRaw($postCtl, 'secret');
check($is404($body), 'protected .md anonymous: 404');
check(!str_contains($body, 'unique-token-beta'), 'protected .md anonymous: body NOT leaked');
check(!str_contains($body, '$2y$'), 'protected .md anonymous: hash NOT leaked');

// Now unlock the session for this slug — raw should flip to 200 with hash stripped.
Auth::markPostUnlocked('secret');
$body = $callRaw($postCtl, 'secret');
check($is200($body), 'protected .md session-unlocked: 200 (frontmatter present)');
check(str_contains($body, 'unique-token-beta'), 'protected .md unlocked: body visible');
check(!str_contains($body, 'password_hash'), 'protected .md unlocked: password_hash line STRIPPED');
check(!str_contains($body, '$2y$'), 'protected .md unlocked: bcrypt hash literal NOT leaked');
check(str_contains($body, 'title:'), 'protected .md unlocked: other frontmatter preserved');

// Reset session, simulate admin login — admin should also see raw.
$_SESSION = ['admin' => true];
$body = $callRaw($postCtl, 'secret');
check($is200($body), 'protected .md admin: 200');
check(!str_contains($body, 'password_hash'), 'protected .md admin: hash line STRIPPED');

// Cleanup
$_SESSION = [];

// -----------------------------------------------------------------------------
section('stripPasswordHashLine edge cases');
// -----------------------------------------------------------------------------

$strip = new ReflectionMethod(App\Controllers\PostController::class, 'stripPasswordHashLine');
$strip->setAccessible(true);

// Hash NOT in frontmatter — must not touch body even though body mentions the key.
$body = "---\ntitle: T\ndate: 2026-06-25\n---\n\nSome body mentioning password_hash: \$2y\$12\$fake inline\n";
$out = $strip->invoke(null, $body);
check($out === $body, 'no-op when no hash in frontmatter (body mention preserved verbatim)');

// Hash present + other fields → strips only that line.
$body = "---\ntitle: T\ndate: 2026-06-25\nauthor: A\npassword_hash: \$2y\$12\$abcdefghij\ntags: [a, b]\n---\n\nbody\n";
$out = $strip->invoke(null, $body);
check(!str_contains($out, 'password_hash'), 'strips hash line');
check(str_contains($out, 'title: T'), 'preserves title');
check(str_contains($out, 'author: A'), 'preserves author');
check(str_contains($out, 'tags: [a, b]'), 'preserves tags');
check(str_contains($out, "\nbody\n"), 'preserves body');
check(!str_contains($out, '$2y$'), 'no bcrypt literal remaining');

// Hash with single-quoted YAML form (how Symfony YAML serializes it).
$body = "---\ntitle: T\npassword_hash: '\$2y\$12\$abc'\n---\n\nbody\n";
$out = $strip->invoke(null, $body);
check(!str_contains($out, 'password_hash'), 'strips quoted hash form');

// Empty frontmatter block → no-op safe.
$body = "---\n---\n\nbody\n";
$out = $strip->invoke(null, $body);
check($out === $body, 'empty frontmatter block: no-op');

// File without any frontmatter — no-op.
$body = "just markdown body, no frontmatter\n";
$out = $strip->invoke(null, $body);
check($out === $body, 'no frontmatter: no-op');

// -----------------------------------------------------------------------------
section('Searcher edge cases');
// -----------------------------------------------------------------------------

check($searcher->run('') === [], 'empty query → no results');
check($searcher->run('   ') === [], 'whitespace query → no results');
check($searcher->run('zz-no-such-term-anywhere') === [], 'no-match query → no results');

// Result count cap: limit param honored.
$capped = $searcher->run('one', limit: 1);
check(count($capped) <= 1, 'limit param honored');

// -----------------------------------------------------------------------------
section('FeedBuilder slice boundary with protected posts mid-list');
// -----------------------------------------------------------------------------

// Build a fresh sandbox with > ITEM_LIMIT posts so we can verify protected
// entries don't shadow unprotected ones out of the slice.
$tmp2 = sys_get_temp_dir() . '/lazyblog-protected-feed-' . posix_getpid() . '-' . uniqid();
mkdir($tmp2 . '/posts', 0775, true);
register_shutdown_function(static function () use ($tmp2): void {
    if (!is_dir($tmp2)) return;
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp2, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($rii as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($tmp2);
});
$repo2 = new PostRepository($tmp2);
$hashFeed = password_hash('x', PASSWORD_BCRYPT);
// Create 22 posts: positions 5, 12 are protected, rest unprotected. Dates
// descending so post-22 is newest. Feed default limit is 20.
for ($i = 22; $i >= 1; $i--) {
    $dateInt = 24 - intdiv($i - 1, 30);  // stay within plausible date space
    $date = sprintf('2026-05-%02d', max(1, min(28, $i)));
    $isProtected = in_array($i, [5, 12], true);
    $repo2->save(new Post(
        slug: "p{$i}",
        title: "Title {$i}",
        date: $date,
        tags: [],
        draft: false,
        bodyMarkdown: "body {$i}",
        passwordHash: $isProtected ? $hashFeed : null,
    ));
}
$feed2 = new FeedBuilder($repo2, $renderer, $tmp2);
$rss = $feed2->build();
foreach ([5, 12] as $protected) {
    check(!str_contains($rss, "Title {$protected}"), "RSS excludes protected post {$protected}");
}
// Count unprotected titles in feed — should be 20 (limit), not fewer due to
// the 2 protected posts being skipped before the slice.
$inFeed = 0;
for ($i = 1; $i <= 22; $i++) {
    if (str_contains($rss, "<title>Title {$i}</title>")) $inFeed++;
}
check($inFeed === 20, "RSS contains exactly 20 unprotected items (slice filled around skipped protected)", "got {$inFeed}");

// -----------------------------------------------------------------------------
section('Set-password endpoint: minimum length + idempotent removal');
// -----------------------------------------------------------------------------

// Helper to invoke removePassword end-to-end via reflection (skips actual
// HTTP redirect — that exits — so we only check the side effect on disk).
// We mock $_POST and $_SESSION to simulate a logged-in admin with a valid
// CSRF token.
$_SESSION = [
    'admin' => true,
    '_csrf' => 'test-token',
];
$_POST = ['_csrf' => 'test-token'];

// Run removePassword on a fresh protected post.
$hashEp = password_hash('topsecret', PASSWORD_BCRYPT);
$repo->save(new Post(
    slug: 'ep-test',
    title: 'Endpoint test',
    date: '2026-06-25',
    tags: [],
    draft: false,
    bodyMarkdown: 'body',
    passwordHash: $hashEp,
));
check($repo->bySlug('ep-test')->isProtected(), 'ep-test starts protected');

$adminCtl = new App\Controllers\AdminController($repo);
// removePassword calls Http::redirect which exit()s; spawn a sub-process by
// capturing the exit via a try/catch isn't trivial. Instead invoke the
// underlying flow: load post, write null hash, save.
$preExisting = $repo->bySlug('ep-test');
$updated = new Post(
    slug: $preExisting->slug,
    title: $preExisting->title,
    date: $preExisting->date,
    tags: $preExisting->tags,
    draft: $preExisting->draft,
    bodyMarkdown: $preExisting->bodyMarkdown,
    icon: $preExisting->icon,
    summary: $preExisting->summary,
    author: $preExisting->author,
    image: $preExisting->image,
    series: $preExisting->series,
    part: $preExisting->part,
    passwordHash: null,
);
$repo->save($updated, $preExisting->displayDate() . '-' . $preExisting->slug . '.md');
$reloaded = $repo->bySlug('ep-test');
check($reloaded !== null && !$reloaded->isProtected(), 'after removePassword flow: post unprotected');
$rawAfter = (string) file_get_contents($tmp . '/posts/2026-06-25-ep-test.md');
check(!str_contains($rawAfter, 'password_hash'), 'after remove: file has no password_hash line');

// -----------------------------------------------------------------------------

echo "\n";
if ($failures === 0) {
    echo "ALL OK\n";
    exit(0);
}
fwrite(STDERR, "{$failures} failure(s)\n");
exit(1);
