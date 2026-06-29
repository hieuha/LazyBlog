<?php

declare(strict_types=1);

namespace App;

use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;

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
 *   5. Inject stable `id` attributes on h1/h2/h3 so the TOC can deep-link.
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
        $env = $this->converter->getEnvironment();
        $env->addExtension(new TableExtension());
        $env->addExtension(new TaskListExtension());
        $env->addExtension(new StrikethroughExtension());
        $env->addExtension(new FootnoteExtension());
    }

    /**
     * @return array{html:string, toc:list<array{level:int,id:string,text:string}>}
     */
    public function render(string $markdown): array
    {
        $this->injected = [];

        $pre = $this->preprocessStandaloneVideos($markdown);
        $pre = $this->preprocessStandaloneImages($pre);
        $pre = $this->preprocessYouTube($pre);
        $pre = $this->preprocessAdmonitions($pre);
        $html = (string) $this->converter->convert($pre);
        $html = $this->reinjectStashed($html);
        $html = $this->postprocessBlockquoteLineBreaks($html);
        $html = $this->postprocessHighlights($html);
        $html = $this->postprocessStrikethrough($html);
        $html = $this->postprocessFreqTags($html);
        $html = $this->postprocessFigures($html);
        $html = $this->postprocessLinkTargets($html);
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
    /**
     * Rewrite a line containing ONLY a direct-link video URL
     * (`.webm` / `.mp4` / `.mov` / `.ogv`, optionally with `?query`
     * or `#fragment`) into a standalone markdown image — `![](url)`.
     * That way the downstream `preprocessStandaloneImages` +
     * `postprocessFigures` pipeline handles it like any other figure
     * and the renderer's `<img>`→`<video>` swap kicks in.
     *
     * Skip lines inside fenced code blocks. URLs in the middle of a
     * sentence are NOT rewritten — they stay as ordinary text/links so
     * we don't accidentally hijack inline references.
     */
    private function preprocessStandaloneVideos(string $md): string
    {
        $lines = preg_split('/\R/u', $md) ?: [];
        $inFence = false;
        // Match an entire line that is just a video URL (optionally
        // wrapped in `< >` autolink delimiters or angle brackets).
        // Delimiter is `~` because the pattern contains a literal `#`
        // inside the optional query/fragment char-class — `#` as the
        // regex delimiter would close prematurely there.
        $re = '~^\s*<?\s*(https?://[^\s<>]+?\.(?:webm|mp4|mov|ogv)(?:[?#][^\s<>]*)?)\s*>?\s*$~i';
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(?:```|~~~)/u', $line)) {
                $inFence = !$inFence;
                continue;
            }
            if ($inFence) {
                continue;
            }
            if (preg_match($re, $line, $m)) {
                $lines[$i] = '![](' . $m[1] . ')';
            }
        }
        return implode("\n", $lines);
    }

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

    /**
     * Force every <a> in the rendered body to open in a new tab. Skip:
     *   - intra-page anchor links (href="#…" — TOC scroll, footnote refs/backrefs)
     *   - links that already declare target= (admonition / stashed HTML may set it)
     * rel="noopener noreferrer" prevents tab-nabbing + referrer leak.
     */
    private function postprocessLinkTargets(string $html): string
    {
        $result = preg_replace_callback(
            '/<a\b([^>]*)>/i',
            static function (array $m): string {
                $attrs = $m[1];
                if (preg_match('/\btarget\s*=/i', $attrs)) {
                    return $m[0];
                }
                if (!preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $attrs, $h)) {
                    return $m[0];
                }
                $href = $h[2];
                if ($href === '' || $href[0] === '#') {
                    return $m[0];
                }
                return '<a' . $attrs . ' target="_blank" rel="noopener noreferrer">';
            },
            $html,
        );
        return $result ?? $html;
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
     * Add stable `id` slugs to h1/h2/h3 elements so the TOC can deep-link.
     * Duplicates get -2, -3 suffixes via the seen-counter.
     */
    private function injectHeadingIds(string $html): string
    {
        $seen = [];
        return (string) preg_replace_callback(
            '/<(h[1-3])>(.*?)<\/\1>/u',
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
        if (preg_match_all('/<(h[1-3]) id="([^"]+)">(.*?)<\/\1>/u', $html, $matches, PREG_SET_ORDER)) {
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
                $hasVideo = false;

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
                    // the media.
                    //
                    // Optional URL fragment opts into ambient/background
                    // playback (autoplay + loop + muted + no controls):
                    //   url.webm                                  → controls, click to play
                    //   url.webm#bg  / url.webm#background        → ambient hero-style
                    //   url.webm#autoplay,loop,muted              → explicit flag list
                    // Browsers require `muted` for unmuted autoplay; the
                    // `bg` alias bundles all four flags so the common case
                    // is one word.
                    $isVideo = (bool) preg_match('/\.(?:webm|mp4|mov|ogv)(?:[?#]|$)/i', $rawUrl);
                    if ($isVideo) {
                        $hasVideo = true;
                    }
                    $aria = ($alt !== '' && $isVideo) ? ' aria-label="' . $alt . '"' : '';
                    if ($isVideo) {
                        $videoAttrs = self::videoAttrsFromUrl($rawUrl);
                        // Strip the fragment from the rendered src — the
                        // flags are renderer-only, not part of the URL the
                        // browser should request.
                        $cleanUrl = htmlspecialchars(preg_replace('/#.*$/', '', $rawUrl) ?? $rawUrl, ENT_QUOTES);
                        $media = '<video src="' . $cleanUrl . '"' . $videoAttrs . $titleRender . $aria . '></video>';
                    } else {
                        $media = '<img src="' . $url . '" alt="' . $alt . '"' . $titleRender . ' loading="lazy" />';
                    }

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
                if ($hasVideo) {
                    // PHP-side marker so CSS doesn't depend on `:has(video)`
                    // (relatively recent selector). Lets us pull the figure
                    // back from full-bleed without browser support roulette.
                    $class .= ' post-figure-video';
                }
                return '<figure class="' . $class . '">'
                    . implode("\n", $cells)
                    . '</figure>';
            },
            $html,
        );
    }

    /**
     * Build the attribute list for a `<video>` element based on the
     * URL fragment. No fragment → safe defaults (controls, playsinline,
     * preload=metadata) so the operator manually presses play and the
     * browser only fetches the seek header.
     *
     * Fragment supports two short aliases (`bg`, `background`) that
     * bundle the ambient hero pattern (autoplay + loop + muted +
     * playsinline + no controls) plus a comma-separated flag list for
     * fine-grained opt-ins (`autoplay`, `loop`, `muted`, `nocontrols`,
     * `controls`). Flags compose: e.g. `#loop,muted` keeps controls
     * but loops silently.
     */
    private static function videoAttrsFromUrl(string $rawUrl): string
    {
        // Default: controls visible, no autoplay, no loop.
        $controls = true;
        $autoplay = false;
        $loop = false;
        $muted = false;

        $fragment = '';
        if (preg_match('/#(.*)$/', $rawUrl, $m)) {
            $fragment = strtolower($m[1]);
        }
        if ($fragment !== '') {
            $flags = array_map('trim', explode(',', $fragment));
            foreach ($flags as $flag) {
                switch ($flag) {
                    case 'bg':
                    case 'background':
                        $autoplay = true;
                        $loop = true;
                        $muted = true;
                        $controls = false;
                        break;
                    case 'autoplay':
                        $autoplay = true;
                        // Browsers require muted for unattended autoplay
                        // — assume that's what the operator wants here.
                        $muted = true;
                        break;
                    case 'loop':
                        $loop = true;
                        break;
                    case 'muted':
                        $muted = true;
                        break;
                    case 'nocontrols':
                        $controls = false;
                        break;
                    case 'controls':
                        $controls = true;
                        break;
                }
            }
        }

        $parts = [];
        if ($controls) {
            $parts[] = 'controls';
        }
        if ($autoplay) {
            $parts[] = 'autoplay';
        }
        if ($loop) {
            $parts[] = 'loop';
        }
        if ($muted) {
            $parts[] = 'muted';
        }
        $parts[] = 'playsinline';
        // Autoplaying clips need to start without a user gesture, so
        // skip the metadata-only preload (the browser would otherwise
        // pull metadata, see no play intent, and wait).
        $parts[] = $autoplay ? 'preload="auto"' : 'preload="metadata"';

        return ' ' . implode(' ', $parts);
    }

    /**
     * Within each <blockquote>, convert the soft-break `\n` chars that
     * CommonMark left inside `<p>` content into `<br>\n` so consecutive
     * `> ` lines render as visually separate lines instead of collapsing
     * into one run-on paragraph. Regular paragraphs outside <blockquote>
     * keep CommonMark's default soft-break behavior — only multi-line
     * quote authoring gets the hard-break treatment.
     *
     * Note: skips nested `<blockquote>` (non-greedy outer match would
     * mis-pair with the first inner close). Nested quotes are rare and
     * can be added later via DOM walking if a real case comes up.
     */
    private function postprocessBlockquoteLineBreaks(string $html): string
    {
        return (string) preg_replace_callback(
            '/(<blockquote\b[^>]*>)(.*?)(<\/blockquote>)/s',
            static function (array $m): string {
                // `\n(?=[^<])` only matches newlines followed by a text
                // character — soft breaks inside <p>. Structural newlines
                // between tags (`</p>\n<p>`) stay untouched.
                $inner = (string) preg_replace('/\n(?=[^<])/', "<br />\n", $m[2]);
                return $m[1] . $inner . $m[3];
            },
            $html,
        );
    }

    /**
     * Lenient `~~text~~` → `<del>text</del>` catch-up pass. GFM's strict
     * flanking rule rejects `~~text ~~` (trailing space before close) and
     * `~~text~~word` (close immediately followed by alphanumeric), which
     * surprises writers who expect Obsidian/Notion-style loose strike. The
     * StrikethroughExtension still handles strict cases at AST level
     * (covers nesting with bold/italic) — this pass only mops up what
     * survived as literal `~~…~~` text. Skips `<pre>` and `<code>` so
     * source samples stay literal.
     */
    private function postprocessStrikethrough(string $html): string
    {
        return $this->replaceOutsideCode(
            $html,
            '/~~([^~\n]+?)~~/u',
            '<del>$1</del>',
        );
    }

    /**
     * Convert `==text==` to `<mark>text</mark>` (extended-markdown highlight).
     * Skips anything inside `<pre>` and `<code>` so code samples that contain
     * `==` (Python equality, etc.) stay literal. Inner content can't include
     * `=` or newlines, and can't start/end with whitespace — that filters out
     * comparison operators like `5 == 4` while still matching real highlights.
     */
    private function postprocessHighlights(string $html): string
    {
        return $this->replaceOutsideCode(
            $html,
            '/==(?!\s)([^\n=]+?)(?<!\s)==/u',
            '<mark>$1</mark>',
        );
    }

    /**
     * Apply a regex replacement to HTML while leaving `<pre>` and `<code>`
     * regions untouched. Code blocks are stashed first, the replacement
     * runs on the remaining HTML, then the stashed blocks are restored.
     * Used by both the highlight (`==`) and lenient strikethrough (`~~`)
     * postprocessors so neither corrupts source samples that contain the
     * marker chars literally.
     */
    private function replaceOutsideCode(string $html, string $pattern, string $replacement): string
    {
        $stashed = [];
        $stash = static function (array $m) use (&$stashed): string {
            $idx = count($stashed);
            $stashed[$idx] = $m[0];
            return "\x01RX{$idx}\x01";
        };
        $html = (string) preg_replace_callback('/<pre[\s\S]*?<\/pre>/u', $stash, $html);
        $html = (string) preg_replace_callback('/<code[\s\S]*?<\/code>/u', $stash, $html);
        $html = (string) preg_replace($pattern, $replacement, $html);
        $html = (string) preg_replace_callback(
            '/\x01RX(\d+)\x01/',
            static fn (array $m): string => $stashed[(int) $m[1]] ?? '',
            $html,
        );
        return $html;
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
