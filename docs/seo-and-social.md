# SEO & Social Cards

What LazyBlog emits for search engines, AI agents, and link-preview platforms.

## Per-page meta tags

Set automatically in `views/layout.php`:

| Tag | Source |
|-----|--------|
| `<title>` | Post title + ` — ` + `SITE_TITLE` |
| `<meta name="description">` | Post `summary` > `SITE_DESCRIPTION` |
| `<meta name="author">` | Post `author` > `DEFAULT_AUTHOR` |
| `<meta name="robots">` | `noindex, nofollow` on `/admin/*`; `index, follow, max-image-preview:large` elsewhere |
| `<link rel="canonical">` | `SITE_URL` + path |
| `<meta name="theme-color">` | `#0a0e0a` (CRT phosphor bg) |

## Open Graph (Facebook, Telegram, Slack, Discord, LinkedIn)

| Tag | Source |
|-----|--------|
| `og:title` | Page title |
| `og:description` | Same as meta description |
| `og:url` | Canonical URL (absolute) |
| `og:type` | `article` for posts, `website` for everything else |
| `og:site_name` | `SITE_TITLE` |
| `og:locale` | `vi_VN` |
| `og:image` | Three-tier fallback — see below |
| `og:image:alt` | Page title (when image present) |

## Twitter Card

| Tag | Source |
|-----|--------|
| `twitter:card` | `summary_large_image` when `og:image` present; `summary` otherwise |
| `twitter:title` / `twitter:description` | Same as the OG equivalents |
| `twitter:image` | Same source as `og:image` |
| `twitter:site` | `SITE_TWITTER_HANDLE` env (e.g. `@yourhandle`) — omitted when blank |

## `og:image` fallback chain (post pages)

1. **Frontmatter `image:` field** — explicit override per post (via admin UI UPLOAD button or manual entry)
2. **First `![alt](url)` in the body** — auto-detected via `Post::firstBodyImage()`, requires zero config
3. **`SITE_OG_IMAGE` env var** — site-wide default for posts with no images
4. **Omit** — platforms render a compact title-only card

Admin editor auto-populates the `image:` field from the first body image when editing, so you usually get social previews without extra work.

## `/series/{slug}` SEO

Series detail pages carry their own meta:

- **`og:title`** — manifest title (or slug → Title Case fallback)
- **`og:description`** — manifest description (when set)
- **`og:image`** — the dithered `cover.webp` when a manifest+cover exists;
  falls through to `SITE_OG_IMAGE` otherwise. Served via the
  `/series-assets/{slug}/cover.webp` route with a 24h `max-age` cache
  header so platform crawlers can cache the art.

Manage these on `/admin/series/{slug}` — see `docs/writing-posts.md`
→ "Series management".

Relative paths (`/uploads/foo.webp`) are auto-prefixed with `SITE_URL` so
the URL is always absolute. Absolute `http(s)://` URLs pass through.

**Recommended image dimensions**: 1200 × 630 px (1.91:1) — the size
Facebook, Twitter, and LinkedIn all expect for the big-card layout.

## JSON-LD structured data

Emitted in a `<script type="application/ld+json">` block inside `<head>`,
escaped with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`
so a post title containing `</script>` can't break out.

| Page | Type | Fields |
|------|------|--------|
| `/posts/{slug}` | `BlogPosting` | headline, description, datePublished (preserves ISO datetime when set), dateModified, url, mainEntityOfPage, inLanguage, keywords, author, publisher |
| `/` (home) | `Blog` | name, description, url, inLanguage, author |

## AI-friendly endpoints

- `/llms.txt` — site index per [llmstxt.org](https://llmstxt.org).
  Three sections: `## Posts` (one bullet per published post with summary),
  `## Series` (one bullet per series with count + manifest description),
  `## Tags` (one bullet per tag). No body content — agents follow the
  per-post `/posts/{slug}.md` for raw markdown. Cached on disk,
  `Cache-Control: public, max-age=3600`.
- Every post HTML page includes
  `<link rel="alternate" type="text/markdown" href="…/posts/{slug}.md">`
  so agents auto-discover the raw markdown.
- `robots.txt` disallows `/admin/` only — `/llms.txt` is open to bots.

## RSS

`/feed.xml` — RSS 2.0 of the latest 20 published posts. Includes
`<content:encoded>` with the fully rendered HTML wrapped in CDATA. ETag
+ `304 Not Modified` on subsequent fetches. Auto-discoverable via
`<link rel="alternate" type="application/rss+xml">` in every page's
`<head>`.

The `<generator>` tag emits `LazyBlog` for compatibility with feed-reader
plugins and RSS parsers. Aggregators can use this to identify feeds
originating from a LazyBlog instance (e.g. via
`str_contains($generatorText, 'LazyBlog')`).

## Testing your previews

Each platform caches preview metadata for days to weeks. After updating
`og:image` or any tag, force a re-scrape:

- **Facebook**: https://developers.facebook.com/tools/debug/ — paste
  URL → "Scrape Again"
- **Twitter / X**: https://cards-dev.twitter.com/validator (still works
  for many account types; otherwise re-share)
- **Telegram**: send the URL to `@WebpageBot` → "Update preview again"
- **LinkedIn**: https://www.linkedin.com/post-inspector/
- **Discord / Slack**: paste a slightly different query string
  (`?v=2`) the first time so they cache miss

## Password-protected posts

Social platforms cache the og:image and og:description when a link is first
shared. For protected posts, `views/layout.php` sets `$lockedView=true`,
which skips `og:image` (via `firstBodyImage()`) and skips og:description
(via body excerpt fallback). This prevents the locked post body from leaking
into preview cards on Facebook, Telegram, LinkedIn, etc.

The post title and og:url still appear (so sharers can identify the post),
but the preview card remains minimal — no image, no body excerpt.

## Common gotchas

- **`og:url` must match the actual domain** — if you share
  `blog.example.com` but `SITE_URL=https://example.com`, FB/Telegram
  treat them as different sites and refuse the preview. Set
  `SITE_URL` in `.env` to the canonical hostname.
- **Telegram won't render embedded thumbnails larger than 5 MB**.
  WebP from the upload pipeline (~150-400 KB) is far below this; only
  worry if you reference external CDNs with huge files.
- **No image at all on a post → no rich card**. Either upload one image
  (auto-detected) or set `SITE_OG_IMAGE` as the site default.
- **Cache propagation can take minutes** even after a force re-scrape.
  Test in incognito so your own browser cache doesn't mislead you.
- **Protected post og:image silently omitted** — if you share a locked
  post before unlocking it, the preview card won't have an image. Once
  unlocked in your session and shared again, the cached card doesn't
  update for days. Plan accordingly if social sharing locked content.
