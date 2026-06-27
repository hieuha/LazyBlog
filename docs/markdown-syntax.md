# LazyBlog — Markdown Syntax Reference

What you can write inside a `content/posts/YYYY-MM-DD-slug.md` body.
Anything not listed here is **not** supported.

The rendering pipeline lives in `src/MarkdownRenderer.php`. Parser is
[league/commonmark](https://commonmark.thephpleague.com) v2 + GFM
TableExtension, plus a handful of LazyBlog-specific pre/post processors.

---

## Standard CommonMark

Everything in the CommonMark 0.31 spec works:

- Headings (`#` … `######`) — H1, H2, and H3 get auto-injected IDs and
  appear in the post's TOC (h1 entries marked with `§`, h2 with `›`,
  h3 with `—` and a left-indent so the visual hierarchy is obvious)
- Bold (`**x**`), italic (`*x*`)
- Inline code (`` `x` ``) and fenced code blocks (``` ```lang … ``` ```)
- Lists: unordered (`-`/`*`) and ordered (`1.`)
- Blockquotes (`> …`)
- Links: `[text](url)`, `[text](url "title")`
- Reference-style links: `[text][ref]` + `[ref]: url`
- Horizontal rule: `---` or `***`
- Raw HTML is **allowed** (`html_input => allow`) — useful for one-off
  embeds, but you give up XSS protection for what you write

Also enabled (extended-markdown additions — see sections below):

- Task lists (`- [ ]` / `- [x]`)
- Strikethrough (`~~x~~`)
- Footnotes (`[^id]` + `[^id]: definition`)
- Highlight (`==x==`)

Not supported (by design — kept the surface small):

- Emoji shortcodes (`:smile:`)
- Plain URL autolinking (write `<https://x.com>` or `[label](url)`)

---

## Task lists

Checkboxes inside list items — exactly the GitHub syntax. Rendered as
`<input type="checkbox" disabled>` with a CRT-tile box (`✓` for checked).
The bullet is dropped automatically so the checkbox sits flush with the
text.

```markdown
- [x] Antenna soldered
- [x] Tracker calibrated
- [ ] Cold-soak test
- [ ] Launch window confirmed
```

Mix and nest with normal list items freely.

---

## Strikethrough

`~~text~~` becomes `<del>text</del>`. Renders dimmed with a 1px
line-through — useful for retracted statements you want to keep visible
as historical context.

```markdown
The downlink was ~~437.500 MHz~~ 437.475 MHz after the last keplerian update.
```

**Lenient matching.** GFM's strict flanking-delimiter rule rejects
`~~text ~~next` (trailing space before close) and `~~text~~next` (close
followed by alphanumeric). LazyBlog adds a forgiving fallback so both
shapes still strike — content can be anything except `~` or newlines. If
you need a literal `~~` in prose, wrap it in inline `` `~~code~~` ``.

---

## Highlight

Surround any inline span with `==` to mark it as highlighted prose.
Renders as `<mark>` styled like the active TOC link (phosphor block on
the background color). Skipped inside `<code>` and fenced code blocks,
so equality comparisons (`5 == 4`) in code samples stay literal.

```markdown
The takeaway: ==always log the raw IQ stream before any DSP==.
```

Rules: the inner content can't span multiple lines, can't contain `=`,
and can't start or end with whitespace — that filters out comparison
operators in prose (e.g. `result == expected`).

---

## Footnotes

Inline reference plus a definition block. The reference renders as a
bracketed superscript link; the definition gets collected into a
`<div class="footnotes">` section at the bottom of the post with a back
arrow (`↩`) returning to the reference.

```markdown
The dish saw first light at 0413 UTC.[^pass]

[^pass]: Pass #4 of METEOR M N2-3 — elevation peaked at 47°.
```

The footnote ID can be any token (`[^1]`, `[^pass]`, `[^author-note]`) —
multiple references to the same ID reuse the same definition.

---

## Code block syntax highlighting

Fenced code blocks with a language tag get token-level highlighting via
[Prism.js](https://prismjs.com/). Prism is loaded only on `/posts/*`
pages, and grammars (php, js, python, etc.) lazy-load from the CDN on
demand — posts with no code pay nothing extra.

```markdown
    ```php
    function greet(string $name): void {
        echo "hello {$name}";
    }
    ```
```

Supported languages: anything Prism ships
([component list](https://prismjs.com/#supported-languages)) — common
picks: `php`, `js`, `ts`, `python`, `bash`, `json`, `yaml`, `sql`,
`html`, `css`, `markdown`, `nginx`, `rust`, `go`, `c`, `cpp`.

Token colors map to the active phosphor palette — switch themes (amber,
green, crypt, brutalist, p7, p11) and syntax recolors with the rest of
the page. Code blocks without a language tag stay literal (no
highlighting), with the dashed HUD frame and `COPY` button still active.

---

## Tables (GFM)

Pipe-style. The first row is the header; the second is required and
defines per-column alignment.

```markdown
| Frequency | Mode     | Notes               |
|-----------|---------:|:--------------------|
| 14.230 MHz | SSTV    | RX-only window      |
| 144.500 MHz| FM       | Calling, simplex    |
```

Renders full-width with uppercase amber header, dashed row borders, and
alternating row tint. Wide tables scroll horizontally on mobile.

---

## Frequency / unit tags

Any inline `<code>` whose content matches a number + recognized unit gets
auto-promoted into a `<span class="freq-tag">` chip. The chip uses the
accent color so RF / file-size / timing values stand out from the body.

```markdown
The downlink is on `137.625 MHz` and each pass writes about `8 MB` of IQ.
```

Recognized units: `Hz, kHz, MHz, GHz, MB, GB, TB, ms, s, min, km, m, cm, mm`

Inline code that isn't a number + unit (e.g. `` `rsync` ``, `` `.env` ``)
renders as a normal code span.

---

## Standalone images → in-column figure

A line containing **only** `![alt](url)` gets wrapped in
`<figure class="post-figure">`. The figure stays inside the article
reading column so images don't bleed past the post width; the caption
sits underneath, narrower and centered. A theme-color tint
(`mix-blend-mode: multiply`) gives the photo a CRT-phosphor feel.

```markdown
![Hai chiếc dish array nhìn từ rooftop, hoàng hôn cam](https://example.com/photo.jpg)
```

Surrounding blank lines are inserted for you, so this also works inside
flowing prose:

```markdown
This is the paragraph just before the photo.
![Caption goes here](https://example.com/photo.jpg)
And this paragraph comes right after.
```

To embed an image inline (not full-width, no caption), drop it inside a
sentence — `see the diagram ![arrow](…) over here` — and it renders as a
plain inline `<img>`.

### Captions — separate from alt text

By default, the `alt` text becomes the visible caption. To use a
different caption while keeping `alt` for screen readers, use the
markdown **title** attribute (CommonMark standard):

```markdown
![alt text describes the image](https://example.com/photo.jpg "This is the visible caption")
```

Rendered output:

```html
<figure class="post-figure">
    <div class="post-figure-cell">
        <div class="post-figure-image">
            <img src="..." alt="alt text describes the image" title="This is the visible caption" />
        </div>
        <figcaption>This is the visible caption</figcaption>
    </div>
</figure>
```

For multi-image blocks, each (image + caption) pair is wrapped in its own
`.post-figure-cell` so the caption always sits **below** its image —
never beside it as a second column slot.

| Markdown | Visible caption | `<img alt>` |
|----------|-----------------|-------------|
| `![alt](url)` | `alt` | `alt` |
| `![alt](url "cap")` | `cap` | `alt` |
| `![](url "cap")` | `cap` | empty |
| `![](url)` | (none) | empty |

The admin editor has a dedicated **image-with-caption** toolbar button
(picture icon) that prompts for URL, alt, and caption separately.

### Multiple images — side-by-side grid

Consecutive image-only lines (no blank line between them) are merged into
a single figure rendered as a CSS Grid. **Mỗi count-N giữ đúng N cột**
bất kể viewport size (count-2 = 2 cột, count-3 = 3 cột, ..., count-6 = 6
cột). A blank line between images keeps them as separate figures.

```markdown
<!-- 2 images on adjacent lines → one 2-column block -->
![Left](https://example.com/a.jpg)
![Right](https://example.com/b.jpg)

<!-- 3 images on adjacent lines → one 3-column block -->
![One](https://example.com/1.jpg)
![Two](https://example.com/2.jpg)
![Three](https://example.com/3.jpg)

<!-- 2 images separated by a blank line → two separate full-width figures -->
![Solo A](https://example.com/a.jpg)

![Solo B](https://example.com/b.jpg)
```

You can also write several `![]()` on the **same line** — they render as
a single multi-column figure the same way.

On screens narrower than 600px (điện thoại, iPad portrait), multi-image
blocks vẫn giữ đúng N cột tương ứng (count-2 vẫn 2 cột, count-4 vẫn 4
cột, v.v.) — mỗi ảnh co lại còn 1/N figure width nhưng KHÔNG bị xếp dọc
hoặc rút cột. Mục đích: người dùng mobile xem được song song, không phải
scroll dọc qua từng ảnh.

**Where the URL comes from**: paste any HTTPS URL, or use the admin UI's
upload feature (drag-drop / clipboard paste / `📤 upload-image` toolbar
button) — it'll insert `![alt](/uploads/YYYY/MM/{rand}.webp)` for you,
already metadata-stripped and resized.

### Direct-link videos (`.webm` / `.mp4` / `.mov` / `.ogv`)

Same `![alt](url)` syntax — if the URL ends with one of those four
extensions (with or without a query/fragment), the renderer swaps the
`<img>` for a `<video>` inside the same figure wrapper.

**Default playback (click-to-play):**
- `<video controls playsinline preload="metadata">` — `controls`
  keeps play/seek visible, `playsinline` stops mobile fullscreen on
  tap, `preload="metadata"` only fetches the seek header until the
  visitor presses play.

**Opt into ambient/hero playback** by adding a URL fragment:

```markdown
![Hero orb](https://example.com/orb.webm#bg)
```

| Fragment | Effect |
|----------|--------|
| _(none)_ | controls, click-to-play (safe default) |
| `#bg` or `#background` | `autoplay loop muted playsinline` — Hermes-style ambient hero |
| `#autoplay,loop,muted` | explicit flag list (same effect, more readable) |
| `#loop,muted` | controls visible but loops silently |
| `#nocontrols` | hide the control bar |
| `#controls` | force controls (override `bg`) |

Browsers require `muted` for unattended autoplay — `#autoplay` alone
will silently add `muted`. The fragment is stripped from the rendered
`src` attribute so the browser fetches the clean URL.

A **bare URL on its own line** (no `![]()` wrapper) also works — the
renderer detects the extension, rewrites it as `![](url)`, then runs
through the same pipeline. Pair with `#bg` for paste-and-go ambient
backgrounds:

```markdown
https://example.com/orb.webm#bg
```

```markdown
![Portal orb](https://example.com/clip.webm "Caption shown under it")
```

Videos sit inside `.post-figure-cell` exactly like images, so they
compose into multi-column galleries with the same grouping rules:

```markdown
![Photo A](a.webp)
![Clip B](b.mp4 "Demo clip")
![Photo C](c.webp)
```

→ one `count-3` figure with three cells, the middle one a video.

The `alt` text becomes the `aria-label` on `<video>` (screen-reader
fallback), and `title="caption"` still drives the visible figcaption.

YouTube URLs are still handled separately via the auto-embed below —
that's an `<iframe>`, not a `<video>`.

---

## YouTube auto-embed

A line containing **only** a YouTube URL becomes a 16:9 iframe embed
(privacy-friendly `youtube-nocookie.com` domain, `loading="lazy"`).
Supported URL shapes:

```markdown
https://www.youtube.com/watch?v=dQw4w9WgXcQ

https://youtu.be/dQw4w9WgXcQ

https://www.youtube.com/embed/dQw4w9WgXcQ
```

The same blank-line auto-insertion as images applies — write the URL on
its own line and it gets promoted to a paragraph automatically.

URLs inside prose (`check this https://youtu.be/abc video`) and inside
fenced code blocks render as literal text, not embeds.

---

## Admonitions

Two block-level callouts using a `:::` fence.

### Highlight box

For a "key fact / callout" panel. Renders as `<div class="highlight-box">`
with a phosphor-tinted background.

```markdown
::: highlight
SSTV transmissions are slow but resilient — a 36-second image can
survive QSB that would kill a fast digital mode.
:::
```

The body inside `:::` is recursively rendered as markdown — lists, bold,
links, frequency tags all work.

### Story card

For a longer narrative block, with optional icon + title.

```markdown
::: story icon="🌕" title="First contact"
On a January night in 2024, the dish picked up a faint birdie at
137.100 MHz. It turned out to be METEOR M N2-3 — a satellite I hadn't
even confirmed was alive.
:::
```

The `icon=""` and `title=""` attrs are optional. Body is markdown.

---

## Heading IDs + TOC

Every `<h2>` and `<h3>` in the post body gets:

- An auto-generated ID derived from the heading text (kebab-case,
  ASCII-folded for Vietnamese diacritics) — so you can link to
  `/posts/slug#tieu-de-cua-toi`
- An entry in the post's auto-generated table of contents (the
  `§ TOC — NAVIGATION` box at the top of every post)

You don't need to do anything to opt in. To opt **out** of the TOC for a
specific heading, use H4+ (`####` etc.) — those aren't tracked.

---

## File and frontmatter conventions

For completeness — these aren't markdown syntax but they determine how
the file is picked up at all:

- Filename: `content/posts/YYYY-MM-DD-slug.md` (the date and slug get
  parsed out of the filename)
- Frontmatter is YAML, fenced by `---`:

```yaml
---
title: "SSTV — Hình Ảnh Qua Sóng Radio"
date: "2026-06-22"
author: "XV5HP"
tags: [radio, sstv, ham]
draft: false
summary: "Decode SSTV images off the air with $20 of hardware."
icon: "📻"
---
```

Required: `title`, `date`. Everything else is optional. `draft: true`
hides the post from listings and feeds (but the file at
`/posts/{slug}.md` is still served if someone knows the URL).
