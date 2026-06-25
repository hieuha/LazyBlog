<?php

declare(strict_types=1);

/**
 * Smoke test for App\SeriesManifest. Plain-PHP assertions, temp content
 * dir per case, register_shutdown_function cleanup.
 *
 * Run: php tests/test-series-manifest.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\SeriesManifest;

$tmpRoot = sys_get_temp_dir() . '/lazyblog-test-manifest-' . bin2hex(random_bytes(4));
mkdir($tmpRoot . '/series', 0775, true);
register_shutdown_function(static function () use ($tmpRoot): void {
    if (is_dir($tmpRoot)) {
        @exec('rm -rf ' . escapeshellarg($tmpRoot));
    }
});

$m = new SeriesManifest($tmpRoot);

// ---------- load() returns null for nonexistent slug ----------
assert($m->load('ghost') === null, 'load() must be null for missing manifest');
assert($m->exists('ghost') === false, 'exists() false for missing');
assert($m->hasCover('ghost') === false, 'hasCover() false for missing');
echo "load missing: OK\n";

// ---------- save() then load() round-trips fields ----------
$m->save('alpha', [
    'title' => 'Alpha Series',
    'description' => 'First series for the test suite.',
]);
assert($m->exists('alpha'));
$loaded = $m->load('alpha');
assert(is_array($loaded));
assert($loaded['title'] === 'Alpha Series', 'title round trip');
assert($loaded['description'] === 'First series for the test suite.', 'desc round trip');
assert(is_string($loaded['updated_at']) && $loaded['updated_at'] !== '', 'updated_at stamped');

// Confirm the manifest is JSON on disk (not YAML).
$raw = file_get_contents($tmpRoot . '/series/alpha/manifest.json');
assert(is_string($raw) && json_decode($raw, true) !== null, 'manifest is valid JSON');
echo "save/load round trip: OK\n";

// ---------- save() leaves no temp file behind ----------
$leftovers = glob($tmpRoot . '/series/alpha/.lazyblog-*');
assert($leftovers === [] || $leftovers === false, 'no orphan tmp files');
echo "atomic write hygiene: OK\n";

// ---------- empty-string fields collapse to null on load ----------
$m->save('beta', ['title' => '', 'description' => '']);
$beta = $m->load('beta');
assert($beta !== null);
assert($beta['title'] === null, 'empty title persists as null');
assert($beta['description'] === null, 'empty description persists as null');
echo "empty field collapse: OK\n";

// ---------- hasCover() flips when cover.webp dropped ----------
assert($m->hasCover('alpha') === false);
file_put_contents($tmpRoot . '/series/alpha/cover.webp', 'fake');
clearstatcache();
assert($m->hasCover('alpha') === true);
assert($m->coverPath('alpha') !== null);
echo "hasCover detection: OK\n";

// ---------- coverSrcPath() detects cover-src.<ext> ----------
file_put_contents($tmpRoot . '/series/alpha/cover-src.jpg', 'fakejpg');
assert(str_ends_with((string) $m->coverSrcPath('alpha'), 'cover-src.jpg'));
echo "coverSrcPath detection: OK\n";

// ---------- delete() removes manifest + cover + cover-src ----------
$m->delete('alpha');
assert($m->exists('alpha') === false, 'manifest gone');
assert($m->hasCover('alpha') === false, 'cover gone');
assert($m->coverSrcPath('alpha') === null, 'cover-src gone');
assert($m->load('alpha') === null, 'load returns null after delete');
echo "delete cleanup: OK\n";

// ---------- delete() is idempotent ----------
$m->delete('alpha');
$m->delete('never-existed');
echo "delete idempotent: OK\n";

// ---------- load() of malformed json returns null ----------
mkdir($tmpRoot . '/series/garbled', 0775, true);
file_put_contents($tmpRoot . '/series/garbled/manifest.json', "{this is not valid json,,,\n");
assert($m->load('garbled') === null, 'malformed json returns null');
echo "malformed json safe: OK\n";

echo "\nAll SeriesManifest assertions passed.\n";
