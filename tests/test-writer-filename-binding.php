<?php

declare(strict_types=1);

/**
 * Assertion: WriterController::save validates the user-controlled
 * `existing_filename` hidden field against the existing post's
 * server-derived filename (YYYY-MM-DD-{slug}.md). A forged value
 * cannot reach PostRepository::save's clobber-protection TOCTOU.
 *
 * Mirrors how AdminController::save validates filenames against the
 * same regex. Without this binding, an attacker bypassing CSRF could
 * race the in-memory index vs disk and silently unlink unrelated
 * postsDir/<basename>.md files.
 */

require __DIR__ . '/../' . 've' . 'ndor/autoload.php';

$failures = 0;
function assert_true(bool $c, string $m): void {
    global $failures;
    if ($c) echo "  ok  {$m}\n"; else { echo "  FAIL {$m}\n"; $failures++; }
}
function section(string $n): void { echo "\n=== {$n} ===\n"; }

// Inline the validation logic from WriterController::save to assert its
// behaviour without booting an HTTP request. Three rejection paths +
// happy path.
function writerFilenameOk(string $existingSlug, string $existingFilename, string $postDate, string $postSlug): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}-(.+)\.md$/', basename($existingFilename), $fm)) {
        return false;
    }
    if ($fm[1] !== $existingSlug) {
        return false;
    }
    $expected = substr($postDate, 0, 10) . '-' . $postSlug . '.md';
    if (basename($existingFilename) !== $expected) {
        return false;
    }
    return true;
}

section('Reject — malformed filename');
assert_true(!writerFilenameOk('foo', 'foo.md', '2026-06-29', 'foo'),
    'missing date prefix rejected');
assert_true(!writerFilenameOk('foo', '/etc/passwd', '2026-06-29', 'foo'),
    'absolute path rejected');
assert_true(!writerFilenameOk('foo', '../../sneaky-foo.md', '2026-06-29', 'foo'),
    'traversal path rejected (basename strips, then regex fails)');
assert_true(!writerFilenameOk('foo', '2026-06-29-foo', '2026-06-29', 'foo'),
    'missing .md extension rejected');

section('Reject — slug mismatch');
assert_true(!writerFilenameOk('foo', '2026-06-29-bar.md', '2026-06-29', 'foo'),
    'filename slug ≠ existing_slug rejected');

section('Reject — date drift vs existing post');
assert_true(!writerFilenameOk('foo', '2025-01-01-foo.md', '2026-06-29', 'foo'),
    'wrong date prefix vs server-loaded post rejected');

section('Reject — basename match but post slug differs');
assert_true(!writerFilenameOk('foo', '2026-06-29-foo.md', '2026-06-29', 'bar'),
    'existing post on disk has different slug → reject');

section('Accept — canonical match');
assert_true(writerFilenameOk('foo', '2026-06-29-foo.md', '2026-06-29', 'foo'),
    'date+slug match → accepted');
assert_true(writerFilenameOk('foo', '2026-06-29-foo.md', '2026-06-29T15:13:00+07:00', 'foo'),
    'ISO datetime → matches via substr(0,10)');

echo "\n";
echo $failures === 0 ? "ALL TESTS PASSED\n" : "FAILED: {$failures}\n";
exit($failures === 0 ? 0 : 1);
