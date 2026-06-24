<?php

declare(strict_types=1);

namespace Plugins\Graffiti;

use App\Config;
use App\Http;

/**
 * Renders the absolute-positioned graffiti overlay layer for a post page.
 *
 * Layout strategy: a single `<div class="graffiti-layer">` wraps every
 * sticker/text/spray item for the slug. `.post-article` is set to
 * `position: relative` by `graffiti.css` so the layer's `inset: 0`
 * anchors to the article box. Pointer-events on the layer are disabled
 * so links and CTAs in the post body stay clickable; individual items
 * re-enable pointer-events so their hover tooltip works.
 *
 * Stickers may visually overlap — z-index is the `received_at` timestamp
 * so newer items sit on top of older ones (the requirement from §10 of
 * the brainstorm).
 */
final class OverlayRenderer
{
    /** Font token → CSS font-family stack. All four chosen for Vietnamese
     *  diacritics support. Tokens validated upstream so we never insert
     *  attacker-controlled CSS into the style attribute. */
    private const TEXT_FONT_MAP = [
        'marker' => "'Caveat', cursive",
        'spray'  => "'Bangers', cursive",
        'tag'    => "'Russo One', sans-serif",
        'block'  => "'Bungee Spice', cursive",
    ];

    /** Color token → hex. Used for text color, border, and text-shadow. */
    private const TEXT_COLOR_MAP = [
        'green'  => '#39ff14',
        'white'  => '#f5f5f5',
        'pink'   => '#ff3399',
        'yellow' => '#ffd700',
        'orange' => '#ff7700',
        'red'    => '#ff3344',
        'blue'   => '#00b3ff',
        'purple' => '#a855f7',
    ];

    private GraffitiStore $store;
    private FriendStore $friends;
    private StickerCatalogue $catalogue;

    public function __construct(GraffitiStore $store, FriendStore $friends, StickerCatalogue $catalogue)
    {
        $this->store = $store;
        $this->friends = $friends;
        $this->catalogue = $catalogue;
    }

    public function render(string $slug): string
    {
        $items = $this->store->forSlug($slug);
        if ($items === []) {
            return '';
        }

        // Filter out hidden + sort oldest-first so the natural HTML order
        // matches DOM-order z-index (newer items declared later → on top).
        $visible = array_values(array_filter(
            $items,
            static fn (array $r): bool => !(bool) ($r['hidden'] ?? false),
        ));
        usort($visible, static fn (array $a, array $b): int =>
            ((int) ($a['received_at'] ?? 0)) <=> ((int) ($b['received_at'] ?? 0))
        );

        if ($visible === []) {
            return '';
        }

        // Resolve friend metadata once per render to avoid repeated lookups.
        $friendCache = [];

        $rows = [];
        foreach ($visible as $item) {
            $friendId = (string) ($item['from_friend_id'] ?? '');
            if (!isset($friendCache[$friendId])) {
                $friendCache[$friendId] = $friendId === 'self'
                    ? self::selfAttribution()
                    : $this->friends->find($friendId);
            }
            $friend = $friendCache[$friendId];
            $blogUrl = (string) ($friend['blog_url'] ?? '');
            $handle  = (string) ($friend['handle'] ?? 'anon');

            $rows[] = $this->renderOne($item, $blogUrl, $handle);
        }

        $html  = '<div class="graffiti-layer" aria-hidden="true">';
        $html .= implode('', $rows);
        $html .= '</div>';
        return $html;
    }

    /** @param array<string,mixed> $item */
    private function renderOne(array $item, string $blogUrl, string $handle): string
    {
        $type = (string) ($item['type'] ?? '');
        $payload = (array) ($item['payload'] ?? []);
        $position = (array) ($payload['position'] ?? []);
        $x = self::clamp01((float) ($position['x'] ?? 0.5));
        $y = self::clamp01((float) ($position['y'] ?? 0.5));
        $rot = self::clampRotation((float) ($position['rotation'] ?? 0));

        $style = sprintf('left:%.2f%%;top:%.2f%%;transform:translate(-50%%,-50%%) rotate(%ddeg);', $x * 100, $y * 100, $rot);
        $tooltip = "from {$handle} (" . self::stripScheme($blogUrl) . ')';

        // Dismiss button — broom on hover devices, × on touch (CSS gates
        // visibility). Click removes the DOM node only; on reload the
        // server re-renders all items. Permanent removal stays admin-only.
        $dismiss = '<button type="button" class="graffiti-item-dismiss" aria-label="Dismiss for this session" data-graffiti-dismiss>'
            . '<span class="graffiti-icon-broom" aria-hidden="true">🧹</span>'
            . '<span class="graffiti-icon-x" aria-hidden="true">×</span>'
            . '</button>';

        if ($type === 'sticker' || $type === 'spray') {
            $stickerId = (string) ($payload[$type === 'sticker' ? 'sticker_id' : 'spray_id'] ?? '');
            $row = $this->catalogue->find($stickerId);
            $svg = (string) ($row['svg_filename'] ?? '');
            if ($svg === '') {
                return '';
            }
            return sprintf(
                '<span class="graffiti-overlay-item graffiti-overlay-item--sticker" style="%s">'
                . '<img class="graffiti-sticker" src="/plugin-assets/graffiti/%s" alt="" title="%s">'
                . '%s</span>',
                Http::e($style),
                Http::e($svg),
                Http::e($tooltip),
                $dismiss,
            );
        }

        if ($type === 'text') {
            $text = (string) ($payload['text'] ?? '');
            if ($text === '') {
                return '';
            }
            // Font + color are optional + allowlisted upstream by
            // PayloadValidator. Fall back to defaults for old rows or
            // missing keys. Concat into the style attr so we keep one
            // inline-style site instead of N CSS classes.
            $font = self::TEXT_FONT_MAP[(string) ($payload['font'] ?? '')] ?? self::TEXT_FONT_MAP['marker'];
            $color = self::TEXT_COLOR_MAP[(string) ($payload['color'] ?? '')] ?? self::TEXT_COLOR_MAP['green'];
            $textStyle = $style
                . sprintf('font-family:%s;color:%s;border-color:%s;text-shadow:0 0 8px %s80;', $font, $color, $color, $color);
            return sprintf(
                '<div class="graffiti-overlay-item graffiti-text" style="%s">%s'
                . '<small>— <a href="%s" rel="noopener" target="_blank">%s</a></small>%s</div>',
                Http::e($textStyle),
                Http::e($text),
                Http::e($blogUrl),
                Http::e($handle),
                $dismiss,
            );
        }

        return '';
    }

    /**
     * Synthesize attribution row for self-decorations. Pulled from Config
     * at render time so renaming the operator handle updates all past
     * self-stickers without a data migration.
     *
     * @return array{handle:string,blog_url:string}
     */
    private static function selfAttribution(): array
    {
        return [
            'handle'   => (string) (Config::get('DEFAULT_AUTHOR') ?? Config::get('SITE_TITLE') ?? 'me'),
            'blog_url' => rtrim((string) Config::get('SITE_URL'), '/'),
        ];
    }

    private static function clamp01(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }

    private static function clampRotation(float $v): int
    {
        return (int) round(max(-180.0, min(180.0, $v)));
    }

    private static function stripScheme(string $url): string
    {
        return (string) preg_replace('#^https?://#i', '', $url);
    }
}
