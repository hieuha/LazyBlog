<?php

declare(strict_types=1);

namespace App;

use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Extension\Table\TableExtension;

/**
 * Markdown → HTML with the CRT design pattern library baked in.
 *
 * Pipeline:
 *   1. Pre-process custom `::: story` and `::: highlight` fenced blocks. The
 *      rendered HTML for each block is STASHED in $injected, and the source is
 *      replaced with a placeholder HTML comment (`<!--LAZY-INJ-N-->`). This
 *      keeps CommonMark's block parser from being poisoned by raw `<div>` tags
 *      whose surrounding blank-line spacing it may interpret unpredictably.
 *   2. Run CommonMark over the markdown + placeholders.
 *   3. Re-inject stashed HTML in place of each placeholder.
 *   4. Post-process `<code>` tags matching unit patterns (e.g. `2.3 kHz`) into
 *      `<span class="freq-tag">` chips.
 *   5. Inject stable `id` attributes on h2/h3 so the TOC can deep-link.
 *   6. Extract the TOC.
 */
final class MarkdownRenderer
{
    private CommonMarkConverter $converter;

    /** @var array<int,string> */
    private array $injected = [];

    public function __construct()
    {
        // SECURITY: html_input='allow' renders raw HTML inside .md files
        // verbatim. This is required so the admonition placeholder bridge
        // (stash → CommonMark → reinjectStashed) survives.
        //
        // Trust assumption: posts are author-only. If multi-author writing
        // is ever added, switch to 'escape' and re-implement admonitions
        // on the escaped form so writer B cannot XSS readers via writer A's
        // post. See docs/security.md.
        $this->converter = new CommonMarkConverter([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);
        $this->converter->getEnvironment()->addExtension(new TableExtension());
    }

    /**
     * @return array{html:string, toc:list<array{level:int,id:string,text:string}>}
     */
    public function render(string $markdown): array
    {
        $this->injected = [];

        $pre = $this->preprocessStandaloneImages($markdown);
        $pre = $this->preprocessYouTube($pre);
        $pre = $this->preprocessAdmonitions($pre);
        $html = (string) $this->converter->convert($pre);
        $html = $this->reinjectStashed($html);
        $html = $this->postprocessFreqTags($html);
        $html = $this->postprocessFigures($html);
        $html = $this->injectHeadingIds($html);
        $toc = $this->extractToc($html);

        return ['html' => $html, 'toc' => $toc];
    }

    /**
     * Replace lines containing ONLY a YouTube URL with a stashed iframe
     * embed (privacy-friendly youtube-nocookie domain, lazy-loaded). Same
     * fenced-code-block guard as the image preprocessor.
     * Supported URL shapes:
     *   https://www.youtube.com/watch?v=ID(&...)
     *   https://youtu.be/ID
     *   https://www.youtube.com/embed/ID
     */
    private function preprocessYouTube(string $md): string
    {
        $lines = preg_split('/\R/u', $md) ?: [];
        $inFence = false;
        $out = [];
        $pattern = '#^\s*(?:https?://)?(?:www\.)?(?:youtube\.com/watch\?(?:[^&\s]*&)*v=([a-zA-Z0-9_-]{11})|youtu\.be/([a-zA-Z0-9_-]{11})|youtube\.com/embed/([a-zA-Z0-9_-]{11}))[^\s]*\s*$#';

        foreach ($lines as $line) {
            if (preg_match('/^\s*(?:```|~~~)/u', $line)) {
                $inFence = !$inFence;
                $out[] = $line;
                continue;
            }
            if (!$inFence && preg_match($pattern, $line, $m)) {
                $id = $m[1] !== '' ? $m[1] : ($m[2] !== '' ? $m[2] : $m[3]);
                if ($id !== '') {
                    $html = '<figure class="video-embed">'
                        . '<iframe src="https://www.youtube-nocookie.com/embed/' . $id . '" '
                        . 'loading="lazy" '
                        . 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" '
                        . 'allowfullscreen></iframe>'
                        . '</figure>';
                    $out[] = $this->stash($html);
                    continue;
                }
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    /**
     * Promote any line that contains ONLY `![alt](url)` into its own paragraph
     * by inserting surrounding blank lines. Without this, CommonMark glues the
     * image into the adjacent paragraph (e.g. `text\n![alt](url)`) and the
     * figure-wrapping postprocess sees `<p>text<img></p>` — which it skips,
     * leaving the image inline at natural size with no tint overlay.
     *
     * Grouping: consecutive image-only lines (separated by single newlines,
     * NOT blank lines) are merged into a single paragraph so the figure
     * postprocess wraps them all in one <figure class="post-figure-gallery
     * count-N"> for a side-by-side grid. A blank line between images keeps
     * them as separate figures.
     *
     * Skips lines inside fenced code blocks (```/~~~).
     */
    private function preprocessStandaloneImages(string $md): string
    {
        $lines = preg_split('/\R/u', $md) ?: [];
        $inFence = false;
        $imgRe = '/^\s*!\[[^\]]*\]\([^)]+\)\s*$/u';

        // Pass 1: classify each line, group consecutive image lines together.
        // $groups is a list of either ['text', $line] or ['images', $line1, $line2, ...].
        $groups = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^\s*(?:```|~~~)/u', $line)) {
                $inFence = !$inFence;
                if ($current !== null) { $groups[] = $current; $current = null; }
                $groups[] = ['text', $line];
                continue;
            }
            if ($inFence || !preg_match($imgRe, $line)) {
                if ($current !== null) { $groups[] = $current; $current = null; }
                $groups[] = ['text', $line];
                continue;
            }
            if ($current === null || $current[0] !== 'images') {
                if ($current !== null) $groups[] = $current;
                $current = ['images', $line];
            } else {
                $current[] = $line;
            }
        }
        if ($current !== null) $groups[] = $current;

        // Pass 2: flatten. Image groups collapse to a single line (joined with
        // a single space so CommonMark treats them as multiple inline images
        // within one paragraph), wrapped by blank lines just like a single
        // image line was before.
        $out = [];
        foreach ($groups as $g) {
            if ($g[0] === 'text') {
                $out[] = $g[1];
                continue;
            }
            $images = array_slice($g, 1);
            if (!empty($out) && trim((string) end($out)) !== '') {
                $out[] = '';
            }
            $out[] = implode(' ', $images);
            $out[] = '';
        }

        return implode("\n", $out);
    }

    /**
     * Replace `::: story icon="X" title="T" / body / :::` and
     * `::: highlight / body / :::` fenced blocks with placeholders. The body
     * is recursively rendered via CommonMark so writers can use full markdown
     * (lists, bold, links, freq-tag-eligible code) inside admonitions.
     */
    private function preprocessAdmonitions(string $md): string
    {
        // Story block: requires opening line `::: story attrs...`
        $md = (string) preg_replace_callback(
            '/^:::[ \t]*story(?P<attrs>[^\n]*)\n(?P<body>.*?)\n^:::[ \t]*$/sm',
            function (array $m): string {
                $attrs = $m['attrs'];
                $icon = '';
                $title = '';
                if (preg_match('/icon\s*=\s*"([^"]*)"/', $attrs, $am)) {
                    $icon = $am[1];
                }
                if (preg_match('/title\s*=\s*"([^"]*)"/', $attrs, $am)) {
                    $title = $am[1];
                }

                $iconAttr = $icon !== '' ? ' data-icon="' . htmlspecialchars($icon, ENT_QUOTES) . '"' : '';
                $titleHtml = $title !== ''
                    ? '<div class="story-title">' . htmlspecialchars($title, ENT_QUOTES) . '</div>'
                    : '';
                $inner = (string) $this->converter->convert($m['body']);

                return $this->stash("<div class=\"story-card\"{$iconAttr}>\n{$titleHtml}\n{$inner}\n</div>");
            },
            $md,
        );

        // Highlight block
        $md = (string) preg_replace_callback(
            '/^:::[ \t]*highlight[ \t]*\n(?P<body>.*?)\n^:::[ \t]*$/sm',
            function (array $m): string {
                $inner = (string) $this->converter->convert($m['body']);
                return $this->stash("<div class=\"highlight-box\">\n{$inner}\n</div>");
            },
            $md,
        );

        return $md;
    }

    /**
     * Store rendered HTML and return a placeholder fenced by blank lines so
     * CommonMark always parses it as its own HTML block (Type 2 — HTML comment).
     */
    private function stash(string $html): string
    {
        $idx = count($this->injected);
        $this->injected[$idx] = $html;
        return "\n\n<!--LAZY-INJ-{$idx}-->\n\n";
    }

    private function reinjectStashed(string $html): string
    {
        return (string) preg_replace_callback(
            '/<!--LAZY-INJ-(\d+)-->/',
            fn (array $m): string => $this->injected[(int) $m[1]] ?? '',
            $html,
        );
    }

    /**
     * Add stable `id` slugs to h2 and h3 elements so the TOC can deep-link.
     * Duplicates get -2, -3 suffixes via the seen-counter.
     */
    private function injectHeadingIds(string $html): string
    {
        $seen = [];
        return (string) preg_replace_callback(
            '/<(h[2-3])>(.*?)<\/\1>/u',
            static function (array $m) use (&$seen): string {
                $base = SlugUtil::fromTitle(strip_tags($m[2]));
                if ($base === '') {
                    return $m[0];
                }
                $id = $base;
                if (isset($seen[$base])) {
                    $seen[$base]++;
                    $id = $base . '-' . $seen[$base];
                } else {
                    $seen[$base] = 1;
                }
                return "<{$m[1]} id=\"{$id}\">{$m[2]}</{$m[1]}>";
            },
            $html,
        );
    }

    /**
     * @return list<array{level:int,id:string,text:string}>
     */
    private function extractToc(string $html): array
    {
        $toc = [];
        if (preg_match_all('/<(h[2-3]) id="([^"]+)">(.*?)<\/\1>/u', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $toc[] = [
                    'level' => (int) substr($m[1], 1),
                    'id' => $m[2],
                    'text' => trim(strip_tags($m[3])),
                ];
            }
        }
        return $toc;
    }

    /**
     * Wrap every `<h2>` plus its following siblings (up to the next `<h2>`)
     * in a `<section class="post-section">` so each top-level section gets the
     * CRT left-rail border that the SSTV reference HTML uses.
     */
    private function wrapH2Sections(string $html): string
    {
        $parts = preg_split(
            '/(<h2[^>]*>.*?<\/h2>)/u',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );
        if ($parts === false || count($parts) <= 1) {
            return $html;
        }

        $out = $parts[0]; // intro paragraphs (if any) before the first h2
        $n = count($parts);
        for ($i = 1; $i < $n; $i += 2) {
            $h2 = $parts[$i] ?? '';
            $body = $parts[$i + 1] ?? '';
            $out .= '<section class="post-section">' . $h2 . $body . '</section>';
        }
        return $out;
    }

    /**
     * Wrap `<p>` containing one or more `<img>` tags in
     * `<figure class="post-figure">` (single image) or
     * `<figure class="post-figure post-figure-gallery count-N">` (N>=2 images
     * grouped from adjacent markdown lines by preprocessStandaloneImages).
     * Each image gets its own `<div class="post-figure-image">` + figcaption
     * pulled from the alt text. The figure element itself becomes a CSS Grid
     * container for the multi-image case.
     */
    private function postprocessFigures(string $html): string
    {
        return (string) preg_replace_callback(
            '/<p>((?:\s*<img\s+[^>]*\/?>\s*)+)<\/p>/u',
            static function (array $m): string {
                preg_match_all(
                    '/<img\s+[^>]*src="([^"]+)"[^>]*alt="([^"]*)"(?:\s+title="([^"]*)")?[^>]*\/?>/u',
                    $m[1],
                    $imgs,
                    PREG_SET_ORDER,
                );
                $count = count($imgs);

                $cells = [];
                foreach ($imgs as $img) {
                    $rawUrl = $img[1];
                    $url = htmlspecialchars($rawUrl, ENT_QUOTES);
                    $alt = htmlspecialchars($img[2], ENT_QUOTES);
                    // Title attribute (from `![alt](url "caption")`) wins as the
                    // visible caption. Falls back to alt text if no title is set,
                    // so existing posts without captions keep working unchanged.
                    $titleAttr = isset($img[3]) ? htmlspecialchars($img[3], ENT_QUOTES) : '';
                    $captionText = $titleAttr !== '' ? $img[3] : $img[2];
                    $titleRender = $titleAttr !== '' ? ' title="' . $titleAttr . '"' : '';
                    $cap = $captionText !== ''
                        ? '<figcaption>' . htmlspecialchars($captionText, ENT_QUOTES) . '</figcaption>'
                        : '';
                    // Direct-link video files (.webm/.mp4/.mov/.ogv) swap out
                    // the <img> for a <video> player. Same wrapper structure,
                    // same gallery grid placement — captions still fall under
                    // the media. Controls visible by default, no autoplay,
                    // preload=metadata so the browser only pulls the seek
                    // header until the user actually plays.
                    $isVideo = (bool) preg_match('/\.(?:webm|mp4|mov|ogv)(?:[?#]|$)/i', $rawUrl);
                    $aria = ($alt !== '' && $isVideo) ? ' aria-label="' . $alt . '"' : '';
                    $media = $isVideo
                        ? '<video src="' . $url . '" controls playsinline preload="metadata"' . $titleRender . $aria . '></video>'
                        : '<img src="' . $url . '" alt="' . $alt . '"' . $titleRender . ' loading="lazy" />';

                    // Wrap each (media + its caption) in a single cell div so
                    // the grid lays them out as a column inside the cell —
                    // captions UNDER their media, never pushed to the side
                    // as a second column slot.
                    $cells[] = '<div class="post-figure-cell">'
                        . '<div class="post-figure-image">' . $media . '</div>' . $cap
                        . '</div>';
                }

                $class = $count > 1
                    ? 'post-figure post-figure-gallery count-' . $count
                    : 'post-figure';
                return '<figure class="' . $class . '">'
                    . implode("\n", $cells)
                    . '</figure>';
            },
            $html,
        );
    }

    /**
     * Upgrade `<code>NN(.NN)?\s*UNIT</code>` to `.freq-tag` spans.
     * Units recognized: Hz, kHz, MHz, GHz, MB, GB, TB, ms, s, min, km, m, cm, mm.
     */
    private function postprocessFreqTags(string $html): string
    {
        $units = '(?:Hz|kHz|MHz|GHz|MB|GB|TB|ms|s|min|km|m|cm|mm)';
        return (string) preg_replace(
            '/<code>(\d+(?:[.,]\d+)?(?:\s*[-–]\s*\d+(?:[.,]\d+)?)?\s*' . $units . ')<\/code>/u',
            '<span class="freq-tag">$1</span>',
            $html,
        );
    }
}
