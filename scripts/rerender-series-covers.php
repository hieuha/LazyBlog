<?php

declare(strict_types=1);

/**
 * One-off rebuild: re-run the SeriesCoverProcessor dither pipeline on every
 * series that has a stashed source image (`cover-src.webp`).
 *
 * Run from the project root:
 *   php scripts/rerender-series-covers.php
 *
 * Use after upgrading the dither algorithm (e.g. when switching off
 * orderedDitherImage on Ubuntu 22.04 where the named threshold maps
 * silently no-op'd) to refresh on-disk `cover.webp` files without
 * forcing the operator to re-upload every series through `/admin/series`.
 *
 * Idempotent:
 *   - skips slugs that have no `cover-src.webp` (cover was never uploaded
 *     or source wasn't stashed by a pre-stash-era upload)
 *   - per-slug failures don't abort the run; summary at the end + non-zero
 *     exit code so cron / CI can surface partial failures
 *   - safe to re-run; output is overwritten via the same tmp+rename path
 *     SeriesCoverProcessor uses for normal uploads
 *
 * Hard dep on ext-imagick (same as the upload pipeline). Exits 1 with a
 * pointer to the install command if the extension is missing.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\SeriesCoverProcessor;
use App\SeriesManifest;

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

if (!SeriesCoverProcessor::isAvailable()) {
    fwrite(STDERR, "ext-imagick is required. On Ubuntu/Debian: sudo apt install php8.2-imagick\n");
    exit(1);
}

$contentDir = __DIR__ . '/../content';
$seriesRoot = realpath($contentDir . '/series');
if ($seriesRoot === false) {
    echo "No content/series directory found at {$contentDir}/series — nothing to rerender.\n";
    exit(0);
}

$manifest = new SeriesManifest($contentDir);
$processor = new SeriesCoverProcessor($manifest);

$seriesDirs = glob($seriesRoot . '/*', GLOB_ONLYDIR) ?: [];
if ($seriesDirs === []) {
    echo "No series directories under {$seriesRoot}.\n";
    exit(0);
}

$rerendered = 0;
$skipped = 0;
$failed = 0;

foreach ($seriesDirs as $dir) {
    $slug = basename($dir);
    if (!is_file($dir . '/cover-src.webp')) {
        echo "  skip {$slug} (no cover-src.webp — re-upload via /admin/series to seed)\n";
        $skipped++;
        continue;
    }
    try {
        $processor->rerender($slug);
        echo "    ok {$slug}\n";
        $rerendered++;
    } catch (\Throwable $e) {
        fwrite(STDERR, "  fail {$slug}: " . $e->getMessage() . "\n");
        $failed++;
    }
}

echo "\nDone — {$rerendered} re-rendered, {$skipped} skipped, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
