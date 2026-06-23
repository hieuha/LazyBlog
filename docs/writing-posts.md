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
| `series` | no | Slug grouping this post into a multi-part series. Add the same value to every post in the series. Shows a banner at the top of the post ("Part N of M") + prev/next nav at the bottom. The series index lives at `/series/{slug}`. |
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
