<?php

declare(strict_types=1);

namespace App;

use Imagick;
use ImagickDraw;
use ImagickPixel;
use RuntimeException;

/**
 * Encode an uploaded image into the 1-bit ordered-Bayer aesthetic used
 * for inline post body images — the same dither pattern as the series
 * cover pipeline, but without the 600×600 square crop and without the
 * transparent-paint step.
 *
 * Output is opaque B&W WebP: every pixel is either pure black or pure
 * white. Markdown ![](url.webp) renders the dot pattern natively,
 * independent of theme — matching the "image #1" reference aesthetic
 * (black-on-white dither photograph). The toolbar button in
 * admin-editor.js routes here via /admin/upload?dither=1.
 *
 * Pipeline:
 *   1. Strip metadata + flatten alpha against white (TRUECOLORALPHA
 *      source bug from the series-cover fix applies here too — a
 *      lingering alpha channel would drain to zero under COMPOSITE_MINUS).
 *   2. transformImageColorspace(GRAY) — actual sRGB→Gray luma walk,
 *      not just a metadata flag.
 *   3. Cap width at MAX_WIDTH while preserving the aspect ratio. Post
 *      body images can be any shape (portraits, panoramas, gallery
 *      tiles) so we never crop — only downscale when the source is
 *      wider than the layout needs.
 *   4. normalizeImage — stretch histogram so low-key shots don't
 *      collapse into solid ink slabs at threshold time.
 *   5. 4×4 Bayer dither via the same primitive
 *      COMPOSITE_MINUSSRC + thresholdImage trick the series cover
 *      pipeline uses. Reproduced here (instead of factoring out)
 *      because the series pipeline is load-bearing on the fixed
 *      transparent-mask aesthetic and we don't want a shared
 *      refactor to risk regressing it.
 *
 * Hard dep on ext-imagick. Callers should fall back to the plain
 * ImageProcessor pipeline if Imagick is unavailable (uploads still work,
 * just without dither).
 */
final class PostImageDitherer
{
    /**
     * Match ImageProcessor::MAX_WIDTH so dithered + plain uploads share a
     * single retina-headroom ceiling and no editor flow is wider than the
     * other. Aspect ratio is preserved — never crops.
     */
    public const MAX_WIDTH = 1600;

    /**
     * 4×4 ordered-Bayer threshold matrix — identical values to
     * SeriesCoverProcessor::BAYER_4X4. Kept in-class (not factored to a
     * shared trait) so this file remains self-contained and a future
     * algorithm tweak in either pipeline doesn't accidentally couple
     * the two surfaces. See SeriesCoverProcessor for the derivation
     * note (i*16+8 scaling, half-step centring).
     */
    private const BAYER_4X4 = [
        [  8, 136,  40, 168],
        [200,  72, 232, 104],
        [ 56, 184,  24, 152],
        [248, 120, 216,  88],
    ];

    public static function isAvailable(): bool
    {
        return extension_loaded('imagick') && class_exists(Imagick::class);
    }

    /**
     * Read $sourcePath, run the dither pipeline, write a 1-bit B&W WebP
     * to $destPath via tmp+rename. Returns ['width' => int, 'height' => int]
     * of the final output (after the MAX_WIDTH cap) so the caller can
     * include the dimensions in its JSON response — same shape as
     * ImageProcessor::processToWebp.
     *
     * @return array{width:int,height:int}
     * @throws RuntimeException on any unrecoverable processing failure
     */
    public static function processToWebp(string $sourcePath, string $destPath): array
    {
        if (!self::isAvailable()) {
            throw new RuntimeException('ext-imagick is required for dithered uploads');
        }

        $im = new Imagick();
        try {
            $im->readImage($sourcePath);

            // Strip EXIF/ICC/etc. first so colorspace conversion isn't
            // wrestling with embedded profiles. Same posture as
            // ImageProcessor — no leaked GPS coords, no camera serials.
            $im->stripImage();

            // Flatten any alpha against white before the colorspace walk
            // (see SeriesCoverProcessor for the bug this fixes — PNG
            // screenshots' TRUECOLORALPHA channel gets drained to zero
            // under the later COMPOSITE_MINUSSRC otherwise).
            $im->setImageBackgroundColor(new ImagickPixel('white'));
            $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);

            $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);

            // Preserve aspect — only downscale if wider than MAX_WIDTH.
            // scaleImage with (W, 0) auto-computes height from aspect.
            if ($im->getImageWidth() > self::MAX_WIDTH) {
                $im->scaleImage(self::MAX_WIDTH, 0);
            }

            try {
                $im->normalizeImage();
            } catch (\Throwable) {
                // non-fatal — dither still works on the un-stretched image
            }

            $w = $im->getImageWidth();
            $h = $im->getImageHeight();

            // Bayer threshold tile sized to the larger dimension, then
            // cropped to (w, h). Doubling construction is square-only so
            // we build a square tile and trim the rectangle out of it.
            $threshold = self::buildBayerThreshold(max($w, $h));
            $threshold->cropImage($w, $h, 0, 0);

            $im->compositeImage($threshold, self::compositeMinusOp(), 0, 0);
            $threshold->clear();
            // Belt-and-braces clamp for HDRI builds — see series cover.
            $im->clampImage();
            $im->thresholdImage(1);

            // Output is 1-bit — lossless WebP is dirt cheap and avoids any
            // chroma noise lossy encoding would introduce around dot edges.
            $im->setImageFormat('webp');
            $im->setImageColorspace(Imagick::COLORSPACE_SRGB);
            try {
                $im->setOption('webp:lossless', 'true');
                $im->setImageCompressionQuality(100);
            } catch (\Throwable) {
                // non-fatal — encoder picks its default mode
            }

            $tmp = $destPath . '.tmp';
            $im->writeImage($tmp);
            if (!@rename($tmp, $destPath)) {
                @unlink($tmp);
                throw new RuntimeException("Failed to write dithered image: {$destPath}");
            }

            return ['width' => $w, 'height' => $h];
        } finally {
            $im->clear();
        }
    }

    /**
     * Same algorithm as SeriesCoverProcessor::buildBayerThreshold —
     * power-of-two doubling for log(n) construction. Returns a square
     * grayscale Imagick image of side $size carrying BAYER_4X4 per
     * 4-pixel cycle position.
     */
    private static function buildBayerThreshold(int $size): Imagick
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
     * Resolve "dst = dst - src" composite operator across PHP-Imagick
     * generations. See SeriesCoverProcessor for the IM6 vs IM7 alias drop
     * story.
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
