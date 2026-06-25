<?php

declare(strict_types=1);

namespace App;

use Imagick;
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
 *   3. 1-bit ordered Bayer dither via orderedPosterizeImage('o4x4,2').
 *      Gives the regular halftone grid pattern the target aesthetic
 *      asks for (compare error-diffusion which produces a noisy organic
 *      pattern). Runs entirely in native C — no PHP-side pixel arrays.
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
            $im->setImageColorspace(Imagick::COLORSPACE_GRAY);
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

            // Ordered Bayer dither: 4x4 cell, 2 output levels. Native
            // libMagick implementation walks pixels in C — no PHP-side
            // buffers, so memory is bounded regardless of cover size.
            // The 4x4 cell gives the clean regular halftone grid pattern
            // matching the album-cover reference aesthetic. Output is
            // 1-bit (pure black or pure white per pixel) ready for the
            // mask-image transparency trick. Threshold-map syntax varies
            // by ImageMagick build (`o4x4,2` works in newer Alpine builds
            // where thresholds.xml ships the level suffix; older or
            // brew-packaged builds reject it and need plain `o4x4`).
            $orderedMap = null;
            foreach (['o4x4,2', 'o4x4', '4x4', 'o2x2', 'checks'] as $candidate) {
                try {
                    $im->orderedDitherImage($candidate);
                    $orderedMap = $candidate;
                    break;
                } catch (\Throwable) {
                    // try next candidate
                }
            }
            if ($orderedMap === null) {
                // Last-resort fallback: plain 50% threshold. Visually a
                // hard binarisation (no halftone grid), but it keeps the
                // pipeline producing a usable cover on imagick builds with
                // no threshold maps configured.
                $im->thresholdImage(0.5 * (($im->getQuantumRange()['quantumRangeLong'] ?? 65535)));
            }

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
}
