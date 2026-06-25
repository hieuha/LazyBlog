<?php

declare(strict_types=1);

/**
 * Smoke test for PostRepository::allSeries() manifest augmentation.
 *
 * Run: php tests/test-series-discovery.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\PostRepository;
use App\SeriesManifest;

$tmpRoot = sys_get_temp_dir() . '/lazyblog-test-discovery-' . bin2hex(random_bytes(4));
mkdir($tmpRoot . '/posts', 0775, true);
mkdir($tmpRoot . '/series', 0775, true);
register_shutdown_function(static function () use ($tmpRoot): void {
    if (is_dir($tmpRoot)) {
        @exec('rm -rf ' . escapeshellarg($tmpRoot));
    }
});

// Past date so the published() filter doesn't reject.
$date = date('Y-m-d', strtotime('-1 day'));

file_put_contents("{$tmpRoot}/posts/{$date}-a.md", <<<MD
---
title: A
date: {$date}
series: lit-up
part: 1
---
body
MD);
file_put_contents("{$tmpRoot}/posts/{$date}-b.md", <<<MD
---
title: B
date: {$date}
series: lit-up
part: 2
---
body
MD);
file_put_contents("{$tmpRoot}/posts/{$date}-c.md", <<<MD
---
title: C
date: {$date}
series: bare
---
body
MD);

$m = new SeriesManifest($tmpRoot);
$m->save('lit-up', ['title' => 'Lit-Up Series', 'description' => 'A demo']);
file_put_contents($tmpRoot . '/series/lit-up/cover.webp', 'fake');
// Orphan manifest — no post uses this slug.
$m->save('phantom', ['title' => 'Phantom']);

$repo = new PostRepository($tmpRoot, $m);
$series = $repo->allSeries();
$bySlug = array_column($series, null, 'slug');

assert(count($series) === 2, 'two real series discovered, orphan filtered');
assert(!isset($bySlug['phantom']), 'orphan manifest does not appear');
assert(isset($bySlug['lit-up']), 'manifest-backed series present');
assert(isset($bySlug['bare']), 'plain-frontmatter series present');
echo "discovery + orphan filter: OK\n";

assert($bySlug['lit-up']['title'] === 'Lit-Up Series', 'manifest title overrides derived');
assert($bySlug['lit-up']['manifestTitle'] === 'Lit-Up Series');
assert($bySlug['lit-up']['description'] === 'A demo');
assert($bySlug['lit-up']['hasCover'] === true);
assert($bySlug['lit-up']['count'] === 2);
echo "manifest augmentation: OK\n";

assert($bySlug['bare']['title'] === 'Bare', 'derived title fallback');
assert($bySlug['bare']['manifestTitle'] === null);
assert($bySlug['bare']['description'] === null);
assert($bySlug['bare']['hasCover'] === false);
echo "no-manifest fallback: OK\n";

echo "\nAll discovery assertions passed.\n";
