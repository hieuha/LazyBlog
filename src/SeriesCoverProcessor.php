<?php

declare(strict_types=1);

namespace App;

use Imagick;
use ImagickDraw;
use ImagickPixel;
use RuntimeException;

/**
 * Convert an uploaded image into the blog's halftone-dot cover style.
 *
 * Pipeline (all stages run inside libMagickCore — zero PHP-heap pixel
 * buffers, so memory is bounded regardless of source dimensions):
 *   1. Decode + center crop-resize to COVER_SIZE × COVER_SIZE square.
 *   2. Convert to grayscale + histogram normalize so dark-midtone photos
 *      don't dither into solid ink slabs.
 *   3. 1-bit ordered Bayer dither built from the BAYER_4X4 constant via
 *      a primitive COMPOSITE_MINUS + thresholdImage pass — see
 *      buildBayerThreshold(). We deliberately skip
 *      orderedDitherImage / orderedPosterizeImage because both depend
 *      on ImageMagick's thresholds.xml mapping the cell name, which
 *      Ubuntu 22.04's apt IM 6.9.11 ships incomplete (the call silently
 *      no-ops and the output is a flat silhouette). The COMPOSITE_MINUS
 *      path uses only primitives with stable cross-version semantics,
 *      so output is bit-identical across IM6 / IM7 / Alpine / brew /
 *      Raspbian / any future build. Still runs in native C — no
 *      PHP-side pixel arrays.
 *   4. Paint white pixels transparent so CSS `mask-image` + `currentColor`
 *      lets the active theme tint the dark dots (same trick as QrCache).
 *   5. Encode lossless WebP.
 *
 * Hard dep on ext-imagick. Callers should check isAvailable() and degrade
 * gracefully — the manifest title/description still works without imagick.
 */
final class SeriesCoverProcessor
{
    /**
     * Canonical cover size — center-cropped square. All uploads are forced
     * to this exact dimension so the index card and detail banner stay
     * visually consistent regardless of source aspect ratio (portrait,
     * landscape, panorama, screenshot, whatever). Ordered dither runs in
     * native C so resolution is unconstrained by PHP memory; 600px gives
     * ~2 dither pixels per CSS pixel at the typical 300px card width
     * (fine halftone grid) without bloating WebP storage.
     */
    public const COVER_SIZE = 600;

    /**
     * Standard 4×4 ordered-Bayer threshold matrix, scaled from raw indices
     * (0..15) to 8-bit thresholds via `i * 16 + 8` → {8, 24, 40, …, 248}.
     * The half-step (+8) centres each threshold band on its grayscale
     * bucket so the dot pattern matches the canonical Bayer reference
     * (same matrix as ImageMagick's built-in `o4x4` map, but applied via
     * primitive composites instead of the thresholds.xml lookup that
     * older ImageMagick builds — notably Ubuntu 22.04's apt IM 6.9.11 —
     * silently no-op on).
     */
    private const BAYER_4X4 = [
        [  8, 136,  40, 168],
        [200,  72, 232, 104],
        [ 56, 184,  24, 152],
        [248, 120, 216,  88],
    ];

    public function __construct(private readonly SeriesManifest $manifest)
    {
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('imagick') && class_exists(Imagick::class);
    }

    /**
     * Read $uploadedTmpPath, dither, and write
     *   - cover-src.webp   (original re-encoded for re-render later)
     *   - cover.webp       (dithered, transparent background)
     * under content/series/<slug>/.
     */
    public function process(string $slug, string $uploadedTmpPath): void
    {
        if (!self::isAvailable()) {
            throw new RuntimeException('ext-imagick is required for cover processing');
        }
        if (!is_file($uploadedTmpPath)) {
            throw new RuntimeException("Source file missing: {$uploadedTmpPath}");
        }

        $dir = $this->manifest->dir($slug);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create series dir: {$dir}");
        }

        // Persist source first — if dither blows up we still keep the upload
        // and the user can retry without re-uploading.
        $this->stashSource($dir, $uploadedTmpPath);

        $this->writeCover($dir, $uploadedTmpPath, 'cover.webp');
    }

    /**
     * Re-run dither from the persisted source. Useful after an algorithm
     * tweak. No-op if no source on disk.
     */
    public function rerender(string $slug): void
    {
        $src = $this->manifest->coverSrcPath($slug);
        if ($src === null) {
            return;
        }
        $this->writeCover($this->manifest->dir($slug), $src, 'cover.webp');
    }

    /**
     * Produce a preview without committing — writes .preview.webp into the
     * series dir. Caller renames to cover.webp on confirm.
     */
    public function preview(string $slug, string $uploadedTmpPath): string
    {
        if (!self::isAvailable()) {
            throw new RuntimeException('ext-imagick is required for cover processing');
        }
        $dir = $this->manifest->dir($slug);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create series dir: {$dir}");
        }
        // Drop any prior preview so the new render is unambiguous.
        @unlink($dir . '/.preview.webp');
        $this->writeCover($dir, $uploadedTmpPath, '.preview.webp');
        return $dir . '/.preview.webp';
    }

    /**
     * Promote .preview.webp to cover.webp atomically. Returns true on success.
     */
    public function commitPreview(string $slug): bool
    {
        $dir = $this->manifest->dir($slug);
        $preview = $dir . '/.preview.webp';
        if (!is_file($preview)) {
            return false;
        }
        return @rename($preview, $dir . '/cover.webp');
    }

    /**
     * Stash the upload as `cover-src.webp` — re-encoded to WebP so backups
     * stay small (a 5 MB JPG typically drops to 200-500 KB lossy WebP) and
     * there is only ever one cover-src filename to clean up. The source
     * survives only as a re-dither input, so a quality of 80 keeps enough
     * detail for any reasonable Atkinson re-render without bloating disk.
     */
    private function stashSource(string $dir, string $uploadedTmpPath): void
    {
        // Wipe any prior cover-src in legacy formats to avoid ambiguity from
        // earlier runs that may have kept jpg/png/webp.
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $e) {
            @unlink($dir . '/cover-src.' . $e);
        }

        $target = $dir . '/cover-src.webp';
        $tmp = $target . '.tmp';
        $im = new Imagick();
        try {
            $im->readImage($uploadedTmpPath);
            $im->setImageFormat('webp');
            try {
                $im->setOption('webp:method', '6');
            } catch (\Throwable) {
                // Older imagick builds may reject this option — ignore.
            }
            $im->setImageCompressionQuality(80);
            $im->writeImage($tmp);
            if (!@rename($tmp, $target)) {
                @unlink($tmp);
                // Fallback: keep the original bytes so the operator's source
                // is not lost even if rename fails. Re-render will still work
                // because process() also writes cover.webp from the upload.
                @copy($uploadedTmpPath, $target);
            }
        } catch (\Throwable) {
            // Imagick can fail on exotic source formats; fall back to a raw
            // copy so re-dither still has source bytes.
            @unlink($tmp);
            @copy($uploadedTmpPath, $target);
        } finally {
            $im->clear();
        }
    }

    private function writeCover(string $dir, string $sourcePath, string $outputName): void
    {
        $im = new Imagick();
        try {
            $im->readImage($sourcePath);

            // Flatten any source alpha onto a white background BEFORE the
            // colorspace conversion. PNG screenshots (and many phone-camera
            // exports) carry a TRUECOLORALPHA channel even when every pixel
            // is opaque. If left intact, the later COMPOSITE_MINUSSRC
            // against the alpha-less Bayer threshold tile drains the
            // destination's alpha to zero — the whole cover comes back
            // 100 % transparent and the page shows a blank slot. Removing
            // alpha here also keeps the histogram normalization honest:
            // ALPHACHANNEL_REMOVE composites the visible RGB against the
            // background colour we just set, so what feeds into grayscale
            // is exactly what the human eye would see on the page.
            $im->setImageBackgroundColor(new ImagickPixel('white'));
            $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);

            // transformImageColorspace (NOT setImageColorspace) — the
            // former actually walks pixels through the sRGB→Gray luma
            // formula (Rec601-ish weighted R/G/B), the latter only sets
            // a metadata flag and leaves channels untouched. The flag-only
            // path lets purple/blue real photos like the satellite hero
            // shot reach the dither with R/G/B still un-collapsed; once
            // there, normalizeImage runs per-channel and zeroes G+B while
            // stretching R, which combined with the alpha bug above made
            // every output pixel read as "white" downstream.
            $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);

            // Force every upload to the canonical COVER_SIZE square via
            // center crop-then-fit. cropThumbnailImage does both passes in
            // one call: it scales the longer side to COVER_SIZE then center-
            // crops the shorter side. Source can be any aspect ratio —
            // portrait, landscape, square, panorama — output is always
            // COVER_SIZE × COVER_SIZE so the card/banner layout stays
            // stable across the whole site.
            $im->cropThumbnailImage(self::COVER_SIZE, self::COVER_SIZE);

            // Stretch the histogram so the darkest pixel hits 0 and the
            // brightest hits 255 before quantization — keeps low-key shots
            // from dithering into solid ink. Non-fatal if rejected.
            try {
                $im->normalizeImage();
            } catch (\Throwable) {
                // ignore — dither still runs on the un-stretched grayscale
            }

            // 4×4 ordered-Bayer dither, built from BAYER_4X4 via primitive
            // COMPOSITE_MINUS + thresholdImage. We deliberately avoid
            // orderedDitherImage / orderedPosterizeImage because both
            // resolve the cell name through ImageMagick's thresholds.xml,
            // which Ubuntu 22.04's apt IM 6.9.11 ships without the named
            // ordered maps — the call then silently no-ops and the
            // pipeline produces a flat silhouette (the bug this replaces).
            //
            // Algorithm: build a same-sized grayscale tile carrying the
            // Bayer threshold per pixel, subtract it from the normalised
            // source. COMPOSITE_MINUS clamps the difference at 0 in
            // non-HDRI builds (default on every distro we target), so
            // pixels where source ≤ threshold collapse to black and
            // pixels where source > threshold survive with a small
            // positive remainder. thresholdImage(1) then promotes every
            // non-zero remainder to pure white. Result is a 1-bit dot
            // pattern identical across IM6 / IM7 / brew / Alpine because
            // every operator used (newImage, drawImage point, compositeImage
            // COPY/MINUS, thresholdImage) is a primitive native-C op with
            // stable cross-version semantics.
            $threshold = $this->buildBayerThreshold(self::COVER_SIZE);
            $im->compositeImage($threshold, self::compositeMinusOp(), 0, 0);
            $threshold->clear();
            // Belt-and-braces clamp for Q*-HDRI builds (macOS brew defaults
            // to Q16-HDRI). In HDRI mode COMPOSITE_MINUS preserves negative
            // remainders rather than clamping at 0, which leaves the
            // following thresholdImage gate to compare against floats below
            // zero — undefined-territory behaviour. clampImage forces the
            // pixel range back to [0, QuantumRange] so the threshold
            // decision is unambiguous on both HDRI and non-HDRI builds.
            $im->clampImage();
            $im->thresholdImage(1);

            // White pixels → fully transparent. CSS mask-image lets the
            // active theme tint the dark dots via currentColor. Tiny fuzz
            // tolerates any near-white pixel that didn't snap to 255.
            $im->setImageMatte(true);
            try {
                $quantum = $im->getQuantumRange();
                $fuzz = (int) (($quantum['quantumRangeLong'] ?? 65535) * 0.05);
            } catch (\Throwable) {
                $fuzz = 0;
            }
            $im->transparentPaintImage(new ImagickPixel('white'), 0.0, $fuzz, false);

            $im->setImageFormat('webp');
            $im->setImageColorspace(Imagick::COLORSPACE_SRGB);
            try {
                $im->setOption('webp:lossless', 'true');
                $im->setImageCompressionQuality(100);
            } catch (\Throwable) {
                // non-fatal — webp encoder picks its default mode.
            }

            $target = $dir . '/' . $outputName;
            $tmp = $target . '.tmp';
            $im->writeImage($tmp);
            if (!@rename($tmp, $target)) {
                @unlink($tmp);
                throw new RuntimeException("Failed to write cover: {$target}");
            }
        } finally {
            $im->clear();
        }
    }

    /**
     * Build a grayscale Imagick image of size $size × $size where pixel
     * (x, y) carries the BAYER_4X4 threshold for its 4-pixel cycle
     * position — i.e. BAYER_4X4[y % 4][x % 4]. The result is the
     * "threshold tile" the dither pipeline subtracts from the normalised
     * source.
     *
     * Construction uses power-of-two doubling rather than the obvious
     * nested-loop tiling: at COVER_SIZE = 600 the loop variant would
     * issue (600/4)² = 22 500 composite calls, while doubling stops
     * after ⌈log₂(600/4)⌉ = 8 steps (4 composites each = 32 calls)
     * before a single cropImage trims the result down to exactly $size.
     * Each composite is a primitive native-C copy, so the whole tile
     * is built in well under 10 ms even on a Raspberry Pi.
     */
    private function buildBayerThreshold(int $size): Imagick
    {
        $tile = new Imagick();
        $tile->newImage(4, 4, new ImagickPixel('black'));
        $tile->setImageFormat('png');
        $tile->setImageColorspace(Imagick::COLORSPACE_GRAY);
        $tile->setImageDepth(8);

        $draw = new ImagickDraw();
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $v = self::BAYER_4X4[$y][$x];
                $draw->setFillColor(sprintf('rgb(%d,%d,%d)', $v, $v, $v));
                $draw->point($x, $y);
            }
        }
        $tile->drawImage($draw);

        while ($tile->getImageWidth() < $size) {
            $w = $tile->getImageWidth();
            $next = new Imagick();
            $next->newImage($w * 2, $w * 2, new ImagickPixel('black'));
            $next->setImageColorspace(Imagick::COLORSPACE_GRAY);
            $next->compositeImage($tile, Imagick::COMPOSITE_COPY, 0,  0);
            $next->compositeImage($tile, Imagick::COMPOSITE_COPY, $w, 0);
            $next->compositeImage($tile, Imagick::COMPOSITE_COPY, 0,  $w);
            $next->compositeImage($tile, Imagick::COMPOSITE_COPY, $w, $w);
            $tile->clear();
            $tile = $next;
        }
        $tile->cropImage($size, $size, 0, 0);

        return $tile;
    }

    /**
     * Resolve the "dst = dst - src" composite operator across PHP-Imagick
     * generations. PHP-Imagick 3.7+ renamed the legacy
     * `Imagick::COMPOSITE_MINUS` to `Imagick::COMPOSITE_MINUSSRC` (matching
     * IM7's MagickCore enum) and dropped the old alias outright on
     * Q16-HDRI brew builds. Ubuntu 22.04 via Ondrej PPA ships either
     * generation depending on PHP version, so we pick at runtime.
     *
     * The integer value isn't stable across generations (36 vs 47), so the
     * named constant is the only portable identifier. Cached after first
     * lookup to keep the hot path branch-free.
     */
    private static function compositeMinusOp(): int
    {
        /** @var int|null $cached */
        static $cached = null;
        if ($cached === null) {
            $cached = defined('Imagick::COMPOSITE_MINUSSRC')
                ? Imagick::COMPOSITE_MINUSSRC
                : Imagick::COMPOSITE_MINUS;
        }
        return $cached;
    }
}
