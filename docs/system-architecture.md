# System Architecture

LazyBlog is a flat-file PHP blog. No database, no message queue, no
build step. Everything runs in a single Caddy + php-fpm container pair.

## High-level diagram

```
                          ┌──────────────────────────────────────┐
                          │            Caddy (TLS, 443)          │
                          │  ─ asset cache headers                │
                          │  ─ security headers                   │
                          │  ─ dotfile blocking                   │
                          │  ─ optional rate-limit /admin/login   │
                          └──────────────┬───────────────────────┘
                                         │ fastcgi unix socket
                                         ▼
                          ┌──────────────────────────────────────┐
                          │            php-fpm 8.2                │
                          │            (lazyblog user)            │
                          └──────────────┬───────────────────────┘
                                         │ index.php
                                         ▼
                          ┌──────────────────────────────────────┐
                          │   Router   →   Controllers           │
                          │                                       │
                          │   home  ─┐                            │
                          │   post  ─┤   ┌──────────────────┐    │
                          │   tag   ─┼──►│ PostRepository   │    │
                          │   admin ─┘   │ MarkdownRenderer │    │
                          │   feed       │ FeedBuilder      │    │
                          │   llms       │ LlmsBuilder      │    │
                          └──────────────┴──────────┬───────┘    │
                                                    │
                                                    ▼
                          ┌──────────────────────────────────────┐
                          │      content/  (flat files)          │
                          │                                       │
                          │   posts/2026-06-22-slug.md  ← source  │
                          │   .index.json               ← cache   │
                          │   .llms.txt                 ← cache   │
                          │   .llms-full.txt            ← cache   │
                          │   .feed.xml                 ← cache   │
                          └──────────────────────────────────────┘
```

## Request lifecycle

### Public read (`GET /posts/{slug}`)

```
1. Caddy receives request → strips proxy headers → fastcgi to php-fpm
2. public/index.php:
   - Composer autoload
   - Dotenv loads .env
   - Config::boot()  asserts required env vars
   - Auth::start()   opens session (cookie-only, strict-mode)
   - Global security headers + CSP emitted
   - Router::dispatch
3. PostController::show($slug)
   - PostRepository::bySlug($slug)
     ├─ indexStale()?  rebuildIndex() → writes .index.json + invalidates .llms*.txt + .feed.xml
     └─ file_get_contents on the matched .md
   - UNLOCK GATE: if post is protected, check Auth::isPostUnlocked($slug)
     ├─ false → check postUnlockTooMany() and render views/post-password.php instead
     └─ true → proceed to render body
   - MarkdownRenderer::render($body)
     ├─ preprocessStandaloneImages → collapse consecutive ![](url) into gallery
     ├─ preprocessAdmonitions  → ::: highlight / ::: story → <!--LAZY-INJ-N-->
     ├─ CommonMark convert     → HTML
     ├─ reinjectStashed        → restore admonition divs
     ├─ postprocessFreqTags    → <code>2.3 kHz</code> → <span class="freq-tag">
     ├─ postprocessFigures     → <p><img alt></p> → <figure><img><figcaption>; title → figcaption
     ├─ injectHeadingIds       → <h2 id="slug">
     └─ extractToc             → list of {level,id,text}
   - PluginRegistry::dispatchPostView → fire post.view event (plugins may setcookie/header)
   - Http::render('post', […])
     ├─ ob_start, require views/post.php
     │  └─ slotPostMeta(['slug'=>$slug]) → collect plugin-contributed HTML fragments
     ├─ ob_get_clean → $body
     └─ require views/layout.php  emits HTML with SEO / OG / JSON-LD
        └─ if post is protected, set $lockedView=true to skip og:image + og:description
4. Caddy applies asset cache + compression and returns the response
```

### Unlock submission (`POST /posts/{slug}/unlock`)

```
1. Caddy + php-fpm boot (same as above)
2. PostController::unlockSubmit($params)
   - CSRF::requireValid()
   - PostRepository::bySlug($slug)
   - Auth::postUnlockTooMany($ip)? → 429 or re-render form
   - verify password against $post->passwordHash() via password_verify()
   - On success: Auth::markPostUnlocked($slug) + redirect to /posts/{slug}
   - On failure: Auth::postUnlockRecordFailure($ip) + re-render form with error
3. Redirect back to GET /posts/{slug} (which now sees isPostUnlocked=true)
```

### Raw markdown (`GET /posts/{slug}.md`)

```
1. Caddy + php-fpm boot
2. PostController::raw($slug)
   - PostRepository::bySlug($slug)
   - if protected AND !Auth::check() AND !Auth::isPostUnlocked()
     → 404 (anonymous cannot download protected post markdown)
   - stripPasswordHashLine($raw) removes the `password_hash:` line
   - Serve plaintext markdown with Content-Type: text/plain
```

### Admin save (`POST /admin/save`)

```
1. Same boot as above
2. AdminController::save
   - Auth::requireAuth  redirects to login if !$_SESSION['admin']
   - Csrf::requireValid hash_equals against $_SESSION['_csrf']
   - readFormValues + buildPostFromForm
     ├─ SlugUtil::valid  enforces [a-z0-9-]+ max 80
     ├─ Date field: YYYY-MM-DD (always extracted from filename or form date)
     └─ Time field (optional): HH:MM:SS → merged into ISO datetime if present
   - PostRepository::save($post, $previousFilename)
     ├─ symfony/yaml dumps frontmatter (date may be ISO datetime or YYYY-MM-DD)
     ├─ FileWriter::writeAtomic  tempnam + LOCK_EX + rename
     ├─ if rename (slug or date change) → unlink old file
     └─ invalidateCaches() unlinks .index.json + .llms*.txt + .feed.xml
   - setFlash + Http::redirect('/admin')
3. Next request rebuilds the index lazily (mtime check) →
   chain invalidates .llms*.txt + .feed.xml again so /llms.txt and
   /feed.xml regenerate on their next read
```

## Module responsibilities

| Module | Responsibility | Key invariant |
|--------|----------------|---------------|
| `Config` | Read $_ENV, assert required keys at boot | Throws RuntimeException if a required var is missing |
| `Auth` | Session lifecycle, password verify, requireAuth gate, per-post unlock tracking, rate-limit | Session ID regenerated on login; strict_mode rejects unknown SIDs; post-unlock state persists per session; rate-limit counts per IP in `/tmp/*.json` |
| `Csrf` | Per-session random_bytes(32) token | hash_equals comparison; one token per session |
| `Router` | Pattern → handler dispatch | Most-specific patterns first; 404 fallback renders `not-found.php` |
| `Http` | render() with layout wrap; redirect() with CRLF strip; e() escape | Output buffering for body capture; layout always rendered last |
| `Post` | Immutable value object; expose `dateTime()` + `hasExplicitTime()` + `passwordHash()` + `isProtected()` helpers | Body stays as raw markdown; rendering happens lazily; ISO datetime support; password hash optional |
| `PostRepository` | Read/write `.md` files, maintain index cache | Filename = `YYYY-MM-DD-{slug}.md`; rebuilds on mtime drift; index contains `"protected": bool` (never the hash) |
| `FrontmatterParser` | YAML frontmatter ⇄ body split | Tolerates missing frontmatter; accepts both `YYYY-MM-DD` and ISO datetime; parses optional `password_hash:` field |
| `SlugUtil` | Slug validation + Vietnamese-aware diacritic strip | `^[a-z0-9-]+$` max 80 chars |
| `MarkdownRenderer` | Markdown → HTML with LazyBlog extensions | Admonitions go through placeholder bridge to bypass CommonMark's block parser |
| `FileWriter` | Atomic write helper | tempnam in target dir + rename — never corrupts on crash |
| `LlmsBuilder` | Generate llms.txt + llms-full.txt | Reads index, lazily reads bodies; skips protected entries; outputs follow llmstxt.org |
| `FeedBuilder` | Generate RSS 2.0 XML | DOMDocument (not string concat); filters protected entries BEFORE slicing limit; full HTML in content:encoded |
| `Searcher` | Full-text index on title + tags + body | Indexes protected post title + tags only; body never indexed; snippet placeholder for protected posts |
| `GamificationCalculator` | Pure streak + badge evaluation logic | Takes post timestamps + arrays in, emits unlocked-badge list; memoises per-unit longest-streak |
| `BadgeRegistry` | Load and validate badge catalogue | Reads `content/badges.json`; silently omits entries with unknown kind |
| `BadgeKinds` | 13 reusable badge executors (post-count, longest-streak, time-window, gap-days, etc.) | Closures parameterised by dict; no filesystem or DB |
| `PostViewEvent` | Immutable event payload `{slug, userAgent, requestTime}` | Dispatched once per `GET /posts/{slug}` before response flush; plugins subscribe via `onPostView()` |
| `SeriesManifest` | Sidecar metadata for series under `content/series/{slug}/manifest.json` (title, description, updated_at) | Pure model — no HTTP / no Imagick. `load()` returns null for missing or malformed JSON; `save()` is atomic via `FileWriter` |
| `SeriesCoverProcessor` | Convert uploaded image → 1-bit Atkinson dither WebP with transparent background | Hard dep on `ext-imagick` (`isAvailable()` gate); downscale-only (never upscale a small upload); preview / commit two-phase flow |
| `SeriesAssetController` | Serve `content/series/{slug}/{file}` at `/series-assets/{slug}/{file}` | Slug + filename regex, MIME allowlist (`webp`/`png`/`jpg`/`jpeg` only — no `json`/`php`), realpath jail |
| `AdminSeriesController` | CRUD for series manifest + cover (list / edit / preview / save / delete) | Auth + CSRF + slug regex on every endpoint; manifest-delete keeps posts untouched (discovery still works via frontmatter) |

## Cache pyramid

```
       /llms.txt   /llms-full.txt   /feed.xml
            │              │            │
            └──────┬───────┴────────────┘
                   │ derives from
                   ▼
            content/.index.json         ← rebuilt when ANY post .md mtime > index mtime
                   │
                   ▼
            content/posts/*.md          ← source of truth
```

All four cache files are invalidated together. The index rebuild trigger
is mtime-based, so manual `.md` drops (without admin UI) are picked up
automatically on the next HTTP request that touches `PostRepository::all()`.

Public visitors transparently warm the caches. No cron, no service.

### Public read (`GET /about`)

```
1. Same boot as above
2. AboutController::show
   - AboutRepository::read() loads content/about.md or 404
   - MarkdownRenderer::render($body) (same pipeline as posts)
   - GamificationCalculator evaluation:
     ├─ Retrieve all published posts from index
     ├─ longestStreakForUnit() memoised per STREAK_UNIT env
     └─ Streak card renders standalone, unaffected by badge `unit` params
   - BadgeRegistry loads content/badges.json and evaluates each entry
     ├─ Volume tier: always render, locked → show N/M, unlocked → glow
     └─ Hidden tier: only render unlocked (HTML doesn't leak codes)
   - Http::render('about', […])
     ├─ Renders: Transmission stats → Current Streak card (if any posts)
     ├─ → Badges grid (volume + unlocked hidden tiers)
     ├─ → BIO body → Contact/Stack → Transmission log
     └─ views/layout.php wraps with SEO / JSON-LD
3. Caddy applies asset cache + compression and returns the response
```

## Threat model

| Asset | Threat | Defense |
|-------|--------|---------|
| Markdown files on disk | Path traversal via URL slug | Slugs never used as filesystem paths; only `$entry['file']` from index |
| Markdown files on disk | Concurrent write corruption | `FileWriter::writeAtomic` — LOCK_EX + rename |
| Admin session | Fixation | `session.use_strict_mode` + regenerate_id on login |
| Admin session | Cookie theft | HttpOnly, SameSite=Lax, Secure (when SESSION_SECURE=true) |
| Admin login | Brute force | 500ms delay per failed attempt + optional Caddy rate-limit |
| Protected post unlock | Brute force | 10 failures per IP within 15-min sliding window + 500ms delay per attempt |
| Protected post unlock | Timing oracle (enumerate locked posts) | Anonymous `.md` fetches return 404 instead of 403 |
| Protected post password | Plaintext disclosure | Never stored in YAML, never indexed, never served via `.md` routes — only hash on disk |
| State-changing admin endpoints | CSRF | Csrf::requireValid on every POST |
| `?next=` parameter | Open redirect / header injection | `safeRedirectTarget` allow-list + CRLF reject |
| Preview endpoint | DoS via huge payload | 256KB read cap in AdminController::preview |
| Markdown body | XSS via raw HTML | Single-author trust model; documented in README |
| Markdown links | `javascript:` URL | `allow_unsafe_links: false` in CommonMark |
| RSS XML | Malformed XML when post has < > & | DOMDocument auto-escaping (never string concat) |
| Public pages | Banner disclosure (PHP version) | `header_remove('X-Powered-By')` at boot |
| Public pages | Clickjacking | `X-Frame-Options: SAMEORIGIN` + CSP `frame-ancestors 'self'` |
| Public pages | Foreign script injection | CSP `script-src 'self' jsdelivr` |
| `.env`, `Dockerfile` | Filesystem disclosure via HTTP | Caddy dotfile block + web root = `public/` |

## File system layout (runtime)

```
/var/www/lazyblog/                        owner: lazyblog:lazyblog
├── public/                               ← Caddy root (read-only to web)
│   ├── index.php
│   ├── .htaccess                         (Apache fallback)
│   ├── robots.txt
│   └── assets/
│       ├── base.css                      (tokens, reset, typography, header/main/footer)
│       ├── effects.css                   (CRT scanlines, vignette, bezel, back-to-top, progress)
│       ├── components.css                (chips, callouts, tables, video, TOC base, pagination)
│       ├── post.css                      (post list, post page, post-figure, floating TOC, series)
│       ├── pages.css                     (archive, search, 404, series index)
│       ├── site.js                       (theme toggle + back-to-top; loaded everywhere)
│       ├── post.js                       (reading progress, scrollspy, code-block UI; /posts/* only)
│       ├── admin.css                     (admin-only)
│       └── admin-editor.js               (admin-only)
├── src/                                  ← outside web root
├── views/                                ← outside web root
│   ├── post-password.php                 (unlock form for protected posts)
│   └── ...
├── scripts/
│   ├── hash-password.php
│   └── backup-content.sh
├── vendor/                               ← composer install --no-dev
├── .env                                  ← mode 640, owner lazyblog:www-data
├── composer.json + composer.lock
└── content/                              ← writable by www-data (php-fpm group)
    └── posts/
        ├── 2026-06-22-slug.md
        ├── .index.json                   ← gitignored caches
        ├── .llms.txt
        ├── .llms-full.txt
        └── .feed.xml
```
