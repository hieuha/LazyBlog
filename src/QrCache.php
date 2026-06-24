<?php

declare(strict_types=1);

namespace App;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Generates inline SVG QR codes, cached to disk under
 * content/cache/qr/{md5(url)}.svg. Output paths are merged into a
 * single `<path fill="currentColor">` so the SVG inherits the active
 * theme colour wherever it's embedded — `color: var(--primary)` on
 * the host element is enough to tint it.
 */
final class QrCache
{
    private string $cacheDir;

    public function __construct(?string $contentDir = null)
    {
        $contentDir = $contentDir ?? (__DIR__ . '/../content');
        $this->cacheDir = $contentDir . '/cache/qr';
    }

    /**
     * Return SVG markup for the QR encoding $url. Cached by md5(url);
     * regenerated only when the cache file is missing or empty.
     */
    /**
     * Pool of central overlay marks — greek letters, leet/hacker
     * abbreviations, and ASCII glyphs. The series-index picks one
     * deterministically by slug so each series carries the same
     * mark across visits but a different one from its neighbours.
     */
    private const MARKS = [
        'Λ', 'Σ', 'Π', 'Δ', 'Ω', 'Φ', 'Ψ', 'Ξ',
        '1337', 'H4X', '0xFF', '404', 'RCE', 'XOR',
        '>_', '$#', '⌬', 'λ', '▲', '◆',
    ];

    public static function mark(string $key): string
    {
        $idx = crc32($key) % count(self::MARKS);
        return self::MARKS[$idx];
    }

    public function svg(string $url): string
    {
        // Cache key carries the ECC level — bumping ECC for centre
        // overlays produces a different pattern, so old cached files
        // become stale and we want them regenerated.
        $key = md5($url . '#ecc=H');
        $path = $this->cacheDir . '/' . $key . '.svg';

        if (is_file($path)) {
            $cached = @file_get_contents($path);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $svg = $this->render($url);

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
        @file_put_contents($path, $svg);

        return $svg;
    }

    private function render(string $url): string
    {
        $options = new QROptions();
        $options->outputInterface = QRMarkupSVG::class;
        // ECC H (30% recovery) lets a centre overlay mask ~20% of the
        // QR without breaking scannability. The trade-off is a denser
        // module grid + slightly larger SVG payload.
        $options->eccLevel = EccLevel::H;
        $options->svgUseFillAttributes = true;
        $options->outputBase64 = false;
        // Single merged dark path → easy to swap fill for currentColor;
        // light modules dropped entirely so the cover background shows
        // through the empty cells.
        $options->connectPaths = true;
        $options->drawLightModules = false;
        $options->addQuietzone = false;

        $svg = (new QRCode($options))->render($url);

        // Drop the XML prolog — inline SVG in HTML doesn't need it and
        // most browsers ignore (or warn about) it mid-document.
        $svg = preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg) ?? $svg;

        // Tint via currentColor — host element's CSS `color` flows in.
        return str_replace('fill="#000"', 'fill="currentColor"', $svg);
    }
}
