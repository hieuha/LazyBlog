<?php

declare(strict_types=1);

namespace App;

use Imagick;
use ImagickPixel;
use RuntimeException;

/**
 * Convert an uploaded image into the blog's Atkinson-dot cover style.
 *
 * Pipeline:
 *   1. Decode + resize to fit 800x450 (16:9 cover ratio).
 *   2. Grayscale.
 *   3. Atkinson error-diffusion dither (Bill Atkinson, Mac 1984) — preserves
 *      midtones via dot density rather than thresholding, giving the buzzy
 *      halftone-photo look that matches the CRT phosphor aesthetic.
 *   4. Emit transparent-where-light WebP so CSS `mask-image` + `currentColor`
 *      lets the active theme tint the dark dots (same trick as QrCache).
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
     * landscape, panorama, screenshot, whatever). 600px is small enough
     * to keep dither and WebP outputs lightweight, large enough to look
     * crisp on retina at the detail-page banner size.
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
     *   - cover-src.<ext>  (original, kept for re-render later)
     *   - cover.webp       (dithered, transparent background)
     * under content/series/<slug>/.
     *
     * Returns the cover_ext token for the manifest. Always 'webp' for v1.
     *
     * @param 'jpg'|'jpeg'|'png'|'webp' $sourceExt source extension, kept for
     *        API back-compat — the stash now always normalises to WebP for
     *        smaller backup footprint.
     */
    public function process(string $slug, string $uploadedTmpPath, string $sourceExt): string
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

        return 'webp';
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

            $width = $im->getImageWidth();
            $height = $im->getImageHeight();

            // Extract per-pixel grayscale as floats so the error-diffusion
            // math doesn't quantize before we want it to.
            $pixels = $im->exportImagePixels(0, 0, $width, $height, 'I', Imagick::PIXEL_FLOAT);
            $buf = self::atkinsonDither($pixels, $width, $height);

            $out = self::buildTransparentPng($buf, $width, $height);

            $target = $dir . '/' . $outputName;
            $tmp = $target . '.tmp';
            $out->writeImage($tmp);
            $out->clear();
            if (!@rename($tmp, $target)) {
                @unlink($tmp);
                throw new RuntimeException("Failed to write cover: {$target}");
            }
        } finally {
            $im->clear();
        }
    }

    /**
     * Classic Atkinson 1-bit error-diffusion. Spreads (oldGray - newGray) / 8
     * across six neighbours (only 6/8 of the error is diffused — the missing
     * 2/8 is the contrast-boost that gives Atkinson its punchy look).
     *
     * Input/Output values in [0.0, 1.0].
     *
     * @param array<int, float|int> $pixels
     * @return list<int> 0 = light (transparent), 1 = dark (ink)
     */
    private static function atkinsonDither(array $pixels, int $w, int $h): array
    {
        // Re-key into a flat numerically indexed array we can mutate by offset.
        $buf = array_values(array_map(static fn ($v): float => (float) $v, $pixels));
        $out = array_fill(0, $w * $h, 0);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $idx = $y * $w + $x;
                $old = $buf[$idx];
                if ($old < 0.0) {
                    $old = 0.0;
                } elseif ($old > 1.0) {
                    $old = 1.0;
                }
                $new = $old >= 0.5 ? 1.0 : 0.0;
                $out[$idx] = $new < 0.5 ? 1 : 0;   // dark cells get ink
                $err = ($old - $new) / 8.0;
                if ($err === 0.0) {
                    continue;
                }
                // Six Atkinson neighbours: (x+1,y) (x+2,y)
                //                          (x-1,y+1) (x,y+1) (x+1,y+1)
                //                          (x,y+2)
                if ($x + 1 < $w) {
                    $buf[$idx + 1] += $err;
                }
                if ($x + 2 < $w) {
                    $buf[$idx + 2] += $err;
                }
                if ($y + 1 < $h) {
                    $row = $idx + $w;
                    if ($x - 1 >= 0) {
                        $buf[$row - 1] += $err;
                    }
                    $buf[$row] += $err;
                    if ($x + 1 < $w) {
                        $buf[$row + 1] += $err;
                    }
                }
                if ($y + 2 < $h) {
                    $buf[$idx + ($w * 2)] += $err;
                }
            }
        }

        return $out;
    }

    /**
     * Pack the 1-bit map into a transparent-where-light WebP image.
     *
     * @param list<int> $bits 0 = light (transparent), 1 = dark (ink)
     */
    private static function buildTransparentPng(array $bits, int $w, int $h): Imagick
    {
        // Imagick::importImagePixels with 'RGBA' + PIXEL_CHAR expects a flat
        // array of int values (4 per pixel). Dark pixels are opaque black,
        // light pixels fully transparent.
        $n = $w * $h;
        $stream = [];
        for ($i = 0; $i < $n; $i++) {
            if ($bits[$i] === 1) {
                $stream[] = 0;     // R
                $stream[] = 0;     // G
                $stream[] = 0;     // B
                $stream[] = 255;   // A — opaque
            } else {
                $stream[] = 0;
                $stream[] = 0;
                $stream[] = 0;
                $stream[] = 0;     // A — transparent
            }
        }

        $out = new Imagick();
        $out->newImage($w, $h, new ImagickPixel('transparent'));
        $out->setImageFormat('webp');
        $out->setImageColorspace(Imagick::COLORSPACE_SRGB);
        $out->setImageMatte(true);
        $out->importImagePixels(0, 0, $w, $h, 'RGBA', Imagick::PIXEL_CHAR, $stream);

        // Prefer lossless webp so the 1-bit pattern survives without ringing
        // around the dot edges. Fallback path silently keeps default mode.
        try {
            $out->setOption('webp:lossless', 'true');
            $out->setImageCompressionQuality(100);
        } catch (\Throwable) {
            // Older imagick builds may reject the option — non-fatal.
        }

        return $out;
    }
}
