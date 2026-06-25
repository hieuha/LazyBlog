# Configuration

## `.env` variables

| Key | Required | Purpose |
|-----|----------|---------|
| `SITE_TITLE` | yes | Brand shown in header, `<title>`, and og:title |
| `SITE_URL` | yes | Canonical URL base — used by og:url, llms.txt links, RSS guid |
| `SITE_DESCRIPTION` | no | Default meta description; overridden by post `summary` on post pages |
| `TIMEZONE` | yes | PHP `date_default_timezone_set` value (e.g. `Asia/Saigon`) |
| `CALLSIGN` | no | Small line above the site title (e.g. `XV5HP // STATION // CITY`). Empty hides it |
| `DEFAULT_AUTHOR` | no | Author shown when a post has no `author:` frontmatter |
| `FOOTER_SIGNOFF` | no | Copyright line at the bottom. Supports `{year}` token. Empty hides the line |
| `POSTS_PER_PAGE` | no | Page size on home + tag listings. Default `10` |
| `STREAK_UNIT` | no | Streak card cadence on `/about` — `day` / `week` / `month` / `year`. Default `week`. Badges each declare their own `unit` param so this only affects the standalone "Current Streak" card, not achievement badges. |
| `ADMIN_PASSWORD_HASH` | yes for admin | bcrypt hash. Empty = login disabled — site is read-only |
| `SESSION_NAME` | no | Cookie name. Default `lazyblog_sess` |
| `SESSION_SECURE` | yes for HTTPS | `true` in production (HTTPS-only cookie); `false` for local HTTP dev |
| `SITE_OG_IMAGE` | no | Default social-card image (path or absolute URL). Used when a post doesn't define `image:` in frontmatter. Recommended 1200×630 px |
| `SITE_TWITTER_HANDLE` | no | Site's Twitter handle with `@`. Emitted as `twitter:site` so the rich card credits your account |
| `SITE_GITHUB_URL` | no | Project source link in footer "§ SOURCE" block. Defaults to the upstream LazyBlog repo. Empty hides the line |
| `SITE_DEFAULT_THEME` | no | Initial theme — `amber` (default), `green`, `crypt` (blood-red), `brutalist` (flat monochrome, no CRT effects), `c64` (Commodore 64 lavender on saturated blue, all CRT layers killed), or `lcd` (Game Boy DMG sage-on-pea LCD panel with pixel-grid scanlines, no glow). Rendered server-side on `<html data-theme>` so no-JS visitors see it. Visitor's header picker still overrides via `localStorage` |
| `SITE_NOISE` | no | Film-grain / dust overlay on every page. `true` (default) / `false`. Off leaves scanlines + vignette intact |
| `PLUGINS` | no | Comma-separated plugin slugs to enable. Each must be a folder under `plugins/{slug}/` with a valid `manifest.json`. Empty = no plugins loaded, zero boot cost. See [`plugin-development.md`](plugin-development.md) for the author guide |

Generate the password hash with the interactive helper:

```bash
php scripts/hash-password.php
# Paste the printed line into .env as ADMIN_PASSWORD_HASH="..."
```

Without `ADMIN_PASSWORD_HASH`, login is disabled and the site is read-only.
You can still edit posts by writing markdown files into `content/posts/`.

## URL routes

### Public

| Path | Purpose |
|------|---------|
| `/` | Home — paginated post list (`?page=N`) |
| `/posts/{slug}` | Rendered HTML post |
| `/posts/{slug}.md` | Raw markdown of the post (`text/markdown`) |
| `/tags/{tag}` | Posts filtered by tag (`?page=N`) |
| `/archive` | Posting heatmap + chronological list grouped by year |
| `/search?q=...` | Diacritic-insensitive search across title, tags, body |
| `/about` | Operator profile page — 404 when `content/about.md` is missing |
| `/feed.xml` | RSS 2.0 of the latest 20 posts (ETag + 304) |
| `/llms.txt` | Site index per [llmstxt.org](https://llmstxt.org) |
| `/llms-full.txt` | Every published post concatenated for LLM consumption |
| `/robots.txt` | `Disallow: /admin/` + `Disallow: /llms-full.txt` |
| `/healthz` | Liveness probe — `text/plain` `ok`, no-store. Short-circuited before autoload/session/repo so monitor traffic costs ~nothing |
| `/plugin-assets/{slug}/{file}` | Plugin asset (CSS/JS/image/font). Served only when the plugin is enabled. Cache-busted via `?v=<mtime>`. See [`plugin-development.md`](plugin-development.md) |
| `/series` | Index of all multi-part series — manifest-backed dot covers when present, QR fallback otherwise |
| `/series/{slug}` | Single series with banner cover + ordered post list |
| `/series-assets/{slug}/{file}` | Series cover image (`cover.webp` only). Slug + filename regex, MIME allowlist (`webp`/`png`/`jpg`/`jpeg`), realpath jail. Cache `max-age=86400` |

Each rendered post page also includes `<link rel="alternate" type="text/markdown">`
in `<head>` so AI agents auto-discover the raw source, and
`<link rel="alternate" type="application/rss+xml" href="/feed.xml">` so RSS
readers find the feed without a URL hint.

### Admin (auth required)

| Path | Purpose |
|------|---------|
| `GET /admin/login` · `POST /admin/login` | Single-password login (bcrypt) |
| `POST /admin/logout` | CSRF-protected session destroy |
| `GET /admin` | List every post — live · draft · scheduled |
| `GET /admin/new` | Blank edit form |
| `GET /admin/edit/{slug}` | Prefilled edit form |
| `POST /admin/save` | Validates + atomic write + cache invalidation |
| `POST /admin/delete/{slug}` | CSRF-protected unlink |
| `POST /admin/preview` | Server-side markdown render for EasyMDE preview pane |
| `POST /admin/upload` | Image upload — strips metadata, resizes to ≤1600px, returns `{url}` pointing at `/uploads/YYYY/MM/...webp` |
| `GET /admin/about` · `POST /admin/about/save` | Manage `content/about.md` — same EasyMDE editor + avatar upload reuses `/admin/upload` |
| `GET /admin/series` | Discovered series + manifest/cover state |
| `GET /admin/series/{slug}` · `POST /admin/series/{slug}` | Edit title, description, cover image |
| `POST /admin/series/{slug}/preview` | Atkinson-dither preview only — writes `.preview.webp` for confirm-before-commit |
| `POST /admin/series/{slug}/delete` | Remove manifest + cover artefacts; posts referencing the slug are untouched |

### PHP extensions

- `ext-gd` — required. Image upload pipeline for posts (downscale + WebP encode + EXIF/GPS strip). Installed by `install-vps.sh` and the Docker images.
- `ext-imagick` — optional. Required **only** for series cover upload (Atkinson dither). Without it, the `/admin/series` editor still saves title and description; the cover upload form is disabled with a friendly warning. Existing covers continue to render.
  - Debian/Ubuntu: `sudo apt install php8.2-imagick && sudo systemctl restart php8.2-fpm`
  - Docker (Alpine): `apk add imagemagick imagemagick-libs php82-pecl-imagick`

## Reading-experience flags

These are styling defaults baked into the CSS files under `public/assets/`
(`base.css`, `effects.css`, `components.css`, `post.css`, `pages.css`), not
env vars — editing them means tweaking the CSS:

- **Theme**: amber (default) ↔ green (toggle in header). Persists in `localStorage`
- **CRT scanlines + vignette + bezel**: layered fixed overlays at z-index 998–1000
- **Heading glow + chromatic-aberration RGB split**: respects `prefers-reduced-motion`
- **Auto TOC** on posts with ≥3 headings — inline on mobile, floats to left rail on desktop
- **Reading progress bar** on `/posts/*` — fixed top, fills with theme accent as you scroll
- **Image full-bleed** + theme tint via `mix-blend-mode: multiply`
- **Back-to-top** button after ~400px of scroll
- **Code blocks** as HUD-style targeting frame (cross-grid bg + dashed border + language label + copy button)
