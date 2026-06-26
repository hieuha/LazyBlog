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
| `password_hash` | no | bcrypt hash of a password. When present, public visits to `/posts/{slug}` render an unlock form instead of the body until the visitor enters the password. Set/remove via the admin editor — never edit this line by hand. See "Password-protected posts" below. |

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
- Manifest badge (YES if `manifest.json` exists)
- Cover thumbnail (40×22 dot preview when `cover.webp` exists)
- Last activity date
- Actions: EDIT · DEL MANIFEST

`/admin/series/{slug}` lets you set:

- **Title** — overrides slug-derived title on `/series` index card + detail banner.
- **Description** — 1-2 sentences, shown on card + detail banner. Two-line clamp on cards.
- **Cover image** — JPG/PNG/WebP up to 5 MB. Server center-crops every
  upload to a canonical 600×600 square (no matter the source aspect ratio:
  portrait, panorama, screenshot — all land at the same dimensions),
  histogram-normalises, then runs Imagick's ordered Bayer dither
  (`orderedDitherImage('o4x4,2')`) — the clean halftone-grid pattern
  matching the album-cover reference aesthetic. Output is a transparent-
  where-light WebP rendered via CSS `mask-image: url(cover.webp)` +
  `currentColor`. The active theme colour (phosphor green, amber, C64,
  LCD, …) flows through the dots automatically. Same trick the QR
  cover uses today. Falls through to `o4x4` / `4x4` / `o2x2` / `checks`
  for ImageMagick builds whose `thresholds.xml` ships a different map
  alias set.

**Preview-before-save**: clicking `[ PREVIEW DITHER ]` runs the upload through
the dither pipeline and renders the result inline as a "PENDING PREVIEW"
chip. The form's `[ SAVE ]` button then promotes the preview to `cover.webp`
if you tick the "promote pending preview" checkbox (default on).

**Attach posts**: under "+ Attach a post" in the right sidebar of
`/admin/series/{slug}`, type or pick from the datalist any post slug,
optionally set its `Part #`, and click `[ ATTACH ]`. Backend rewrites the
post's `series:` frontmatter to the current slug atomically. A post already
in another series gets moved (its old series silently loses that post on
the next discovery pass; manifest at the old slug is preserved for
re-attachment later).

**Rename slug**: the `Slug` field at the top of `/admin/series/{slug}` is
editable — typing a new kebab-case value and clicking `[ RENAME SLUG ]`
will bulk-rewrite the `series:` frontmatter on every matching post and
rename `content/series/{old}/` → `content/series/{new}/` so the manifest
and cover follow. Refuses to merge into an existing series (the new slug
must have no manifest and no posts using it).

**Deletion**: `[ DEL MANIFEST ]` removes `manifest.json` + `cover.webp` +
`cover-src.webp`. Posts that reference the series via frontmatter are
**not** touched — the series simply falls back to its slug-derived title
and the QR fallback cover.

**Discovery rule**: a manifest without any matching posts is an orphan and
silently ignored on `/series` index. Rename via the admin (above) is the
safe path; manually changing `series:` on individual posts works too but
leaves orphaned metadata at the old slug.

**SEO**: `/series/{slug}` carries the cover as `og:image` and the manifest
description as `og:description`, so social shares (Telegram, X, Slack,
Facebook, Discord) render the dithered cover instead of the site-wide
fallback. Falls through to `SITE_OG_IMAGE` when no cover exists.

**Storage layout**:

```
content/series/<slug>/
├── manifest.json        # title, description, updated_at
├── cover-src.webp       # upload re-encoded to WebP @ q=80 for compact backup
└── cover.webp           # 600×600 1-bit transparent ordered Bayer dither
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

## Password-protected posts

Lock a single post behind a password without changing the rest of the
admin flow.

**Admin UI** (editor at `/admin/edit/{slug}`):

- `[ Set Password ]` button next to the password input → type the
  password (min 4 chars) and click. The post is locked in one click,
  no full Save Post round trip. Stays in the editor afterwards.
- `[ Update Password ]` (same button, label flips when the post is
  already locked) → rotate to a new password.
- `[ Remove Password ]` (only visible when the post is locked) →
  confirm dialog, then the hash is dropped from the frontmatter and
  the post is public again.

**On disk**, the frontmatter gains a single line:

```yaml
password_hash: '$2y$12$abc...'
```

This is bcrypt — never the plaintext. Editing the post body and saving
with the password field blank keeps the existing hash. To clear it
without using the button, delete the `password_hash:` line manually.

**What visitors see** at `/posts/{slug}` when the post is locked:

- An "ACCESS RESTRICTED" HUD form replaces the body. Title and
  `summary:` are visible (so a reader who knows the post exists can
  decide whether to ask for the password). Body, code blocks, embedded
  media — none of that renders.
- Correct password sets a session flag, redirects back to the post,
  and the body renders for the rest of the session. Closing the
  browser ends the unlock.
- Wrong password shakes the panel red and shows "X attempts left" —
  10 failures within a 15-minute sliding window throttle that IP and
  disable the input until the window clears. F5 honours the throttle.
- `/posts/{slug}.md` returns 404 to anonymous visitors. Visitors who
  have unlocked the post in this session, and admins, do get the raw
  markdown — with the `password_hash:` line stripped before serving.

**Listings & feeds** for locked posts:

- Home, `/tags/{tag}`, `/archive`, admin list: title and 🔒 badge.
  `summary:` is shown when present; no body excerpt.
- `/search?q=...`: title and tag matches still surface; body terms
  return zero matches and the snippet renders as `🔒 protected post`.
- `/llms.txt` and `/feed.xml`: the post is dropped entirely — title,
  URL, and body all stay out of the corpus.

**Rate limit + IP source**:

The unlock throttle counts per IP. Behind Cloudflare every visitor
arrives via the same edge IP and would share a single counter, so the
`.env` knob `TRUST_CF_CONNECTING_IP=true` switches the IP source from
`REMOTE_ADDR` to the `CF-Connecting-IP` header (validated as a real
IP). Only turn this on when origin traffic actually flows through
Cloudflare — see `security.md` for the threat model.

## Drafts and scheduling

- `draft: true` → hidden from home, tag pages, RSS, llms.txt. Visible in
  admin list with a `[DRAFT]` badge. URL still works if known.
- `date` in the future → same effect as draft until the date arrives.
  Listed in admin as `[SCHEDULED]`.

## Editing without the admin UI

If you edit `.md` files directly (text editor, git pull, scp, etc.):

1. The next request to any page detects the stale `.index.json` (mtime
   check) and rebuilds it automatically.
2. The rebuild also invalidates `.llms.txt` and `.feed.xml` — they
   regenerate lazily on the next request.

So nothing extra to run. Just save the file. Make sure php-fpm can read
it though — if you wrote it as root, `chown lazyblog:lazyblog` it back.
