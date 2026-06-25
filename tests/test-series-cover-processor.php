<?php

declare(strict_types=1);

/**
 * Smoke test for App\SeriesCoverProcessor. Skips if ext-imagick missing.
 *
 * Run: php tests/test-series-cover-processor.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\SeriesCoverProcessor;
use App\SeriesManifest;

if (!SeriesCoverProcessor::isAvailable()) {
    echo "SKIP: ext-imagick not loaded.\n";
    exit(0);
}

$tmpRoot = sys_get_temp_dir() . '/lazyblog-test-processor-' . bin2hex(random_bytes(4));
mkdir($tmpRoot . '/series', 0775, true);
register_shutdown_function(static function () use ($tmpRoot): void {
    if (is_dir($tmpRoot)) {
        @exec('rm -rf ' . escapeshellarg($tmpRoot));
    }
});

$m = new SeriesManifest($tmpRoot);
$p = new SeriesCoverProcessor($m);

// ---------- build a gradient JPEG source ----------
$srcPath = $tmpRoot . '/source.jpg';
$src = new Imagick();
$src->newPseudoImage(400, 300, 'gradient:white-black');
$src->setImageFormat('jpeg');
$src->setImageCompressionQuality(85);
$src->writeImage($srcPath);
$src->clear();

// ---------- process() writes cover.webp + cover-src.webp ----------
$p->process('gamma', $srcPath);
$cover = $tmpRoot . '/series/gamma/cover.webp';
assert(is_file($cover), 'cover.webp produced');
assert(filesize($cover) > 0, 'cover non-empty');
// cover-src is normalised to WebP for smaller backup footprint regardless
// of upload format. Legacy jpg/png/webp filenames are wiped on save.
assert(is_file($tmpRoot . '/series/gamma/cover-src.webp'), 'cover-src normalised to webp');
assert(!is_file($tmpRoot . '/series/gamma/cover-src.jpg'), 'no legacy jpg leftover');
echo "process() outputs: OK\n";

// ---------- cover has alpha channel ----------
$out = new Imagick($cover);
$opaqueCount = 0;
$transCount = 0;
$w = $out->getImageWidth();
$h = $out->getImageHeight();
for ($i = 0; $i < 200; $i++) {
    $x = ($i * 17) % $w;
    $y = ($i * 23) % $h;
    $px = $out->getImagePixelColor($x, $y);
    $c = $px->getColor(2);
    if (($c['a'] ?? 1) < 0.5) {
        $transCount++;
    } else {
        $opaqueCount++;
    }
}
assert($transCount > 10 && $opaqueCount > 10, 'dither produced mix of opaque + transparent pixels');
echo "alpha channel mix: opaque={$opaqueCount} transparent={$transCount}: OK\n";
$out->clear();

// ---------- preview() writes .preview.webp without touching cover ----------
$m->delete('gamma');
$p->preview('gamma', $srcPath);
assert(is_file($tmpRoot . '/series/gamma/.preview.webp'), 'preview written');
assert($m->hasCover('gamma') === false, 'cover NOT yet promoted');
echo "preview without commit: OK\n";

// ---------- commitPreview() renames .preview.webp -> cover.webp ----------
assert($p->commitPreview('gamma') === true);
assert(!is_file($tmpRoot . '/series/gamma/.preview.webp'), 'preview consumed');
assert($m->hasCover('gamma') === true, 'cover promoted');
echo "commitPreview(): OK\n";

// ---------- bad source path throws ----------
$threw = false;
try {
    $p->process('delta', '/no/such/file.jpg');
} catch (\Throwable $e) {
    $threw = true;
}
assert($threw, 'process() throws on missing source');
echo "missing source throws: OK\n";

// ---------- canonical square output regardless of source aspect ratio ----------
$expectedSize = App\SeriesCoverProcessor::COVER_SIZE;
foreach ([
    ['100x60-landscape.png', 100, 60],
    ['60x100-portrait.png',  60, 100],
    ['400x400-square.png',   400, 400],
    ['1920x1080-cinematic.jpg', 1920, 1080],
] as [$name, $w, $h]) {
    $slug = strtolower(strtok($name, '.'));
    $path = $tmpRoot . '/' . $name;
    $img = new Imagick();
    $img->newPseudoImage($w, $h, 'gradient:white-black');
    $img->setImageFormat(pathinfo($name, PATHINFO_EXTENSION));
    $img->writeImage($path);
    $img->clear();
    $p->process($slug, $path);
    $out = new Imagick($tmpRoot . '/series/' . $slug . '/cover.webp');
    assert(
        $out->getImageWidth() === $expectedSize && $out->getImageHeight() === $expectedSize,
        "expected {$expectedSize}x{$expectedSize}, got "
            . $out->getImageWidth() . 'x' . $out->getImageHeight()
            . " for source {$w}x{$h}"
    );
    $out->clear();
}
echo "canonical {$expectedSize}x{$expectedSize} output: OK\n";

echo "\nAll SeriesCoverProcessor assertions passed.\n";
