# Writing Posts

Two ways to author posts: drop markdown files directly into `content/posts/`,
or use the browser admin UI at `/admin`. Both write to the same place, so
mix and match freely.

## File layout

```
content/
└── posts/
    ├── 2026-06-15-system-online-first-broadcast.md
    ├── 2026-06-18-rtl-sdr-cho-nguoi-moi.md
    └── 2026-06-22-discovery-dish-nghe-ve-tinh-tu-roof-top.md
```

Filename pattern is `YYYY-MM-DD-slug.md`. The date and slug are parsed out
of the filename — the slug in the URL (`/posts/discovery-dish-...`) matches
the slug in the filename.

## Frontmatter

YAML frontmatter at the top of every post, fenced by `---`:

```yaml
---
title: "SSTV — Hình Ảnh Qua Sóng Radio"
date: "2026-06-22"
author: "XV5HP"
tags: [radio, sstv, ham]
draft: false
summary: "Decode SSTV images off the air with $20 of hardware."
icon: "📻"
image: "/uploads/2026/06/antenna.webp"
---
```

| Key | Required | Notes |
|-----|----------|-------|
| `title` | yes | Used in `<title>`, post header, og:title |
| `date` | yes | `YYYY-MM-DD` (date-only) or ISO datetime `YYYY-MM-DDTHH:MM:SS+TZ` (e.g. `2026-06-22T14:30:00+07:00`). Posts dated in the future render as scheduled (visible in admin only). Time-based badges (NIGHT-OWL, etc.) only trigger with explicit ISO datetime. |
| `tags` | no | List or comma-joined string. Lowercased for URL matching |
| `author` | no | Falls back to `DEFAULT_AUTHOR` env var |
| `draft` | no | `true` → hidden from home, tag, RSS, and llms.txt. Still served at `/posts/{slug}` if URL known |
| `summary` | no | Shown in listings, RSS description, llms.txt entry, og:description |
| `icon` | no | Emoji shown next to the title in listings |
| `image` | no | Per-post social-card image. Used as `og:image` + `twitter:image` when shared on Telegram/Facebook/Slack/Twitter. Path (`/uploads/…webp`) gets prefixed with `SITE_URL`; absolute URLs pass through. Falls back to auto-detected first body image, then `SITE_OG_IMAGE` env when omitted. |
| `series` | no | Slug grouping this post into a multi-part series. Add the same value to every post in the series. Shows a banner at the top of the post ("Part N of M") + prev/next nav at the bottom. The series index lives at `/series/{slug}`. The editor's series field auto-suggests existing slugs via a native `<datalist>` — pick one or type a new kebab-case slug to start a series. |
| `part` | no | Explicit numeric ordering within a series (e.g. `1`, `2`). When omitted, posts in the series are ordered by `date` ascending. |

## Body — markdown syntax

See `markdown-syntax.md` for the full reference. Quick reminders:

- Standalone `![alt](url)` line → full-width figure with caption + theme tint
- Standalone YouTube URL line → 16:9 embed
- `::: highlight ... :::` → callout box
- `::: story icon="X" title="Y" ... :::` → narrative card
- Inline `` `145.8 MHz` `` → freq-tag chip (auto)
- Tables (GFM pipe syntax)

## Admin UI workflow

Login at `/admin/login` with the password set via `scripts/hash-password.php`.

`/admin` lists every post with server-side pagination (`POSTS_PER_PAGE` env).
Click a row to edit; the form opens at `/admin/edit/{slug}` or `/admin/new`.

The editor (EasyMDE pinned to 2.18.0) includes:

- Standard toolbar: bold, italic, heading, quote, list, code, link, image, table
- Custom buttons: `!` inserts `::: highlight`, `💬` inserts `::: story icon="..." title="..."`
- `Cmd-P` toggle preview, `F9` side-by-side, `F11` fullscreen
- Tag chip input — type a tag + Enter/comma to add, click `×` or Backspace to remove

**Date and Time fields**: Date picker (always required, e.g. 2026-06-22) + optional Time picker
(HH:MM:SS). When Time is filled, the post's frontmatter `date` becomes ISO datetime 
(e.g. `2026-06-22T14:30:00+TZ`). When empty, only the date is stored (e.g. `2026-06-22`).
On `/admin/new`, the Time field pre-fills with the current wall-clock time.

**Social image**: Explicit `image:` frontmatter field or UPLOAD button for quick image selection.
Auto-populated from the first body image when editing existing posts (fallback if no `image:` set).

**Server-side preview**: the preview pane POSTs to `/admin/preview` and renders
through the same MarkdownRenderer used for public pages — so `::: highlight`,
`::: story`, freq-tag chips, image figures, YouTube embeds all render correctly
(EasyMDE's default marked.js can't parse them). Debounced 300ms.

**Image upload**: drag-drop, paste from clipboard, or the `📤 upload-image`
toolbar button. Backend strips ALL metadata (EXIF, GPS, ICC, vendor blobs),
downscales to ≤1600px wide, converts to WebP @ q=82, and saves under
`content/uploads/YYYY/MM/{rand}.webp`. The original (potentially carrying GPS
coords or device info) is never persisted — only the cleaned WebP. Accepts
PNG, JPEG, WebP up to 10 MB. EasyMDE auto-inserts `![alt](url)` at the cursor
on success. Requires `php8.2-gd` extension (already installed by
`install-vps.sh` and the Docker images).

**Sticky toolbar**: the formatting toolbar stays pinned to the top of the
viewport while you scroll a long post, so the buttons stay reachable.

## Series management (`/admin/series`)

Series are still discovered from post frontmatter — putting `series: my-slug`
on a post is what creates the series. The admin page is an enhancement layer
for editorial metadata (custom title, description, cover image).

`/admin/series` lists every series discovered across published posts with:

- Slug (link → public series page)
- Title (manifest if set, else slug → Title Case)
- Post count
- Manifest badge (YES if `manifest.yaml` exists)
- Cover thumbnail (40×22 dot preview when `cover.webp` exists)
- Last activity date
- Actions: EDIT · DEL MANIFEST

`/admin/series/{slug}` lets you set:

- **Title** — overrides slug-derived title on `/series` index card + detail banner.
- **Description** — 1-2 sentences, shown on card + detail banner. Two-line clamp on cards.
- **Cover image** — JPG/PNG/WebP up to 5 MB. Server center-crops every
  upload to a canonical 900×900 square (no matter the source aspect ratio:
  portrait, panorama, screenshot — all land at the same dimensions),
  applies tonal prep (histogram normalise + ~12% brightness lift +
  gentle contrast S-curve so dark photos don't dither into ink slabs),
  runs Atkinson 1-bit error-diffusion dither (the buzzy Mac 1984 halftone-
  photo aesthetic) with a slight light-bias threshold, outputs a
  transparent-where-light WebP, and renders it via CSS
  `mask-image: url(cover.webp)` + `currentColor`. The active theme
  colour (phosphor green, amber, C64, LCD, …) flows through the dots
  automatically. Same trick the QR cover uses today.

**Preview-before-save**: clicking `[ PREVIEW DITHER ]` runs the upload through
the dither pipeline and renders the result inline as a "PENDING PREVIEW"
chip. The form's `[ SAVE ]` button then promotes the preview to `cover.webp`
if you tick the "promote pending preview" checkbox (default on).

**Deletion**: `[ DEL MANIFEST ]` removes `manifest.yaml` + `cover.webp` +
`cover-src.*`. Posts that reference the series via frontmatter are **not**
touched — the series simply falls back to its slug-derived title and the
QR fallback cover.

**Discovery rule**: a manifest without any matching posts is an orphan and
silently ignored on `/series` index. If you rename a `series:` slug on
posts, the old manifest becomes orphan; create a new manifest at the new
slug or delete the orphan via the admin.

**Storage layout**:

```
content/series/<slug>/
├── manifest.yaml        # title, description, cover_ext, updated_at
├── cover-src.webp       # upload re-encoded to WebP @ q=80 for compact backup
└── cover.webp           # 900×900 1-bit transparent Atkinson dither
```

All uploads are normalised to WebP regardless of source format (JPG / PNG /
WebP all land as `cover-src.webp`) — typical 5 MB JPG sources collapse to
~200–500 KB on disk. The original is kept only to re-run the dither
pipeline after an algorithm tweak; the public surface always serves
`cover.webp`. All of `content/` is backed up by the existing
`backup-content.sh` rsync.

**Requirements**: cover upload requires `ext-imagick`. Without it the title
and description fields still save fine — only the cover upload is disabled,
and a warning shows on `/admin/series`. See `docs/configuration.md`.

**Autosave** to `localStorage` keyed by slug, 1500ms delay — restored if you
reopen the tab after an accidental close. Cleared from localStorage after successful save or delete.

**Unsaved-changes guard** — navigating away with unsaved edits triggers a
browser confirm. Cleared on actual SAVE.

## Drafts and scheduling

- `draft: true` → hidden from home, tag pages, RSS, llms.txt. Visible in
  admin list with a `[DRAFT]` badge. URL still works if known.
- `date` in the future → same effect as draft until the date arrives.
  Listed in admin as `[SCHEDULED]`.

## Editing without the admin UI

If you edit `.md` files directly (text editor, git pull, scp, etc.):

1. The next request to any page detects the stale `.index.json` (mtime
   check) and rebuilds it automatically.
2. The rebuild also invalidates `.llms.txt`, `.llms-full.txt`, and
   `.feed.xml` — they regenerate lazily on the next request.

So nothing extra to run. Just save the file. Make sure php-fpm can read
it though — if you wrote it as root, `chown lazyblog:lazyblog` it back.
