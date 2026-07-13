<?php

declare(strict_types=1);

/**
 * Verifies extended-markdown additions wired into MarkdownRenderer:
 *   - GFM task lists  (`- [ ]` / `- [x]`)
 *   - GFM strikethrough (`~~text~~`)
 *   - Footnotes (`[^id]` + `[^id]: body`)
 *   - Highlight (`==text==`) — custom postprocess, must skip code spans
 *
 * Plain-PHP assertions in the project's existing test style (no PHPUnit).
 */

require __DIR__ . '/../vendor/autoload.php';

use App\MarkdownRenderer;

$renderer = new MarkdownRenderer();
$fail = 0;
$pass = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $fail, $pass;
    if ($cond) {
        $pass++;
        echo "  ok  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

// --- Task lists ----------------------------------------------------------
$out = $renderer->render("- [x] done\n- [ ] todo\n")['html'];
check(
    'task list: checked item rendered',
    str_contains($out, '<input checked="" disabled="" type="checkbox">'),
);
check(
    'task list: unchecked item rendered',
    str_contains($out, '<input disabled="" type="checkbox">'),
);

// --- Strikethrough -------------------------------------------------------
$out = $renderer->render("This is ~~old~~ text.")['html'];
check(
    'strikethrough: strict GFM ~~text~~ → <del>text</del>',
    str_contains($out, '<del>old</del>'),
);

$out = $renderer->render("thế ~~nào bây giờ ~~nhỉ")['html'];
check(
    'strikethrough: lenient (trailing space before close) still strikes',
    str_contains($out, '<del>nào bây giờ </del>'),
);

$out = $renderer->render("~~old~~new")['html'];
check(
    'strikethrough: lenient (no space after close) still strikes',
    str_contains($out, '<del>old</del>new'),
);

$out = $renderer->render("inline `~~code~~` stays")['html'];
check(
    'strikethrough: skipped inside inline <code>',
    !str_contains($out, '<del>') && str_contains($out, '~~code~~'),
);

// --- Footnotes -----------------------------------------------------------
$out = $renderer->render("Claim.[^1]\n\n[^1]: Source.")['html'];
check(
    'footnotes: inline ref produces <sup class="footnote-ref">',
    str_contains($out, 'class="footnote-ref"') && str_contains($out, '<sup'),
);
check(
    'footnotes: bottom section emitted',
    str_contains($out, 'class="footnotes"'),
);
check(
    'footnotes: backref link present',
    str_contains($out, 'class="footnote-backref"'),
);

// Sidenotes: each footnote body is duplicated into an inline span after the
// reference (wide-screen margin note), while the bottom list is preserved for
// the narrow-screen fallback.
$out = $renderer->render("Claim.[^1] More.[^two]\n\n[^1]: Source **A**.\n\n[^two]: Source B.")['html'];
check(
    'sidenotes: inline span injected after reference',
    str_contains($out, '</sup><span class="sidenote" role="note">'),
);
check(
    'sidenotes: number marker matches sequential index',
    str_contains($out, '<span class="sidenote-num">2</span> Source B.'),
);
check(
    'sidenotes: body keeps inline markup, drops backref link',
    // Backref survives only in the bottom list (once per note), never copied
    // into a sidenote — so the count equals the footnote count, not double.
    str_contains($out, 'Source <strong>A</strong>.</span>')
        && substr_count($out, 'class="footnote-backref"') === 2,
);
check(
    'sidenotes: bottom .footnotes list preserved for mobile fallback',
    str_contains($out, 'class="footnotes"') && str_contains($out, 'class="footnote-backref"'),
);
check(
    'sidenotes: no-op when post has no footnotes',
    !str_contains($renderer->render("Just text, no notes.")['html'], 'class="sidenote"'),
);

// Non-breaking spaces (U+00A0), which contenteditable editors insert for typed
// spaces, must be normalized to real spaces before CommonMark — otherwise a
// `[^id]:<nbsp>body` definition is not recognized and folds into the previous
// note, leaving the reference unrendered.
$nbsp = "\u{00A0}";
$out = $renderer->render("a[^1] b[^2] c.\n\n[^1]: One.\n[^2]:{$nbsp}Two.{$nbsp}end.")['html'];
check(
    'footnotes: nbsp after [^id]: still parses as its own definition',
    str_contains($out, 'id="fn:2"') && !str_contains($out, '[^2]'),
);

// --- Highlight (==text==) ------------------------------------------------
$out = $renderer->render("This is ==important== news.")['html'];
check(
    'highlight: ==text== → <mark>',
    str_contains($out, '<mark>important</mark>'),
);

$out = $renderer->render("Code: `==leave alone==` here.")['html'];
check(
    'highlight: skipped inside inline <code>',
    !str_contains($out, '<mark>') && str_contains($out, '==leave alone=='),
);

$out = $renderer->render("```\n==fenced stays==\n```\n")['html'];
check(
    'highlight: skipped inside fenced <pre><code>',
    !str_contains($out, '<mark>') && str_contains($out, '==fenced stays=='),
);

$out = $renderer->render("If `result == expected` then 5 == 4 is false.")['html'];
check(
    'highlight: comparison operators (spaces around) do not match',
    !str_contains($out, '<mark>'),
);

$out = $renderer->render("== leading-space ==")['html'];
check(
    'highlight: leading/trailing space inside == == disqualifies',
    !str_contains($out, '<mark>'),
);

// --- Coexistence inside admonition body ---------------------------------
$body = "::: highlight\nA ==key== fact with ~~old~~ value.\n:::\n";
$out = $renderer->render($body)['html'];
check(
    'admonition body: highlight + strikethrough both render',
    str_contains($out, '<mark>key</mark>') && str_contains($out, '<del>old</del>'),
);

// --- Done ----------------------------------------------------------------
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
