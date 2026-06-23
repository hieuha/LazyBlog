# Security

LazyBlog is single-author. The threat model is "drive-by web attacker" +
"someone scans the open internet". Not multi-tenant, not high-value-target.
Defenses below are scoped to that.

## Application-layer defenses

- `.env` is **never committed**. Web root is `public/`; `.env` lives one dir up
- `content/` is fully gitignored — posts stay on the host
- Caddy + `Dockerfile.prod` both block `/.env*`, `/Dockerfile`, `/docker-compose.yml`, dotfiles → 404
- CommonMark `allow_unsafe_links: false` blocks `javascript:` URLs
- All variables from frontmatter or URL params reach views via `Http::e()`
  (`htmlspecialchars(ENT_QUOTES, 'UTF-8')`)
- **Path traversal blocked**: URL slugs are never used as filesystem paths.
  PostRepository looks up entries via the index cache (closed set from `glob()`)
- **Atomic writes**: FileWriter uses `tempnam` + `LOCK_EX` + `rename`. Crash
  mid-write leaves the previous file intact
- **RSS XML safety**: FeedBuilder uses DOMDocument; never string-concatenates

## Trust assumption: raw HTML in markdown

`html_input: 'allow'` is set so the admonition placeholder bridge works.
This means **raw HTML inside `.md` files is rendered as-is**.

Acceptable because posts are author-only. If multi-author writing is added
later, switch to `'escape'` and re-implement admonition reinjection on the
escaped form.

## Image upload

`/admin/upload` is a state-changing endpoint guarded by:

- **Auth + CSRF**: `Auth::requireAuth` + `Csrf::requireValid` (token via
  `X-CSRF-Token` header — body is multipart, not form-encoded)
- **MIME whitelist** via `finfo` magic-byte check (PNG / JPEG / WebP only).
  Client-sent `Content-Type` is ignored
- **Raw byte cap**: 10 MB on the upload itself (`$_FILES['size']`)
- **Pixel-count cap**: 40 megapixels max before GD decode — bounds RAM
  usage and prevents decompression bombs
- **Metadata strip**: source is decoded into a GD truecolor buffer, then
  re-encoded as WebP. EXIF, GPS, ICC profile, and any vendor chunks are
  dropped because GD only writes the pixel data
- **Filename is randomized** (`bin2hex(random_bytes(8))`) so uploads
  aren't guessable from a slug
- **Original never persisted**: PHP's temp file is auto-cleaned at end
  of request; only the cleaned WebP lives on disk
- **Caddy serves `/uploads/*` directly** from `content/uploads/` —
  PHP isn't in the hot path, so an attacker can't trick the server into
  executing uploaded content as code (the dir contains only `.webp`)
- **Edge-layer extension blocks** (in `Caddyfile.example` and the
  installer's generated Caddyfile):
  - Any request whose path matches `*.php` / `*.phtml` / `*.phar` (or
    `*.phpN` variants) AND starts with `/uploads/*`, `/posts/*`,
    `/plugins/*`, `/plugin-assets/*`, or `/content/*` returns 403 at the
    edge. Defends against future code paths or operator misconfig that
    might accidentally expose a content-derived URL prefix
  - Any request to `/uploads/*` whose extension is NOT one of `.webp`,
    `.png`, `.jpg`, `.jpeg`, `.gif`, `.svg`, `.avif` returns 403. Even
    if the upload pipeline ever drops a non-image (it shouldn't),
    Caddy refuses to serve it

## Auth + session

- **CSRF**: every state-changing POST (login, logout, save, delete) is gated
  by `Csrf::requireValid()` — `random_bytes(32)`, `hash_equals` verify
- **Open redirect**: `safeRedirectTarget()` validates `?next=` (must start
  with `/`, not `//`, no CRLF/tab/NUL). `Http::redirect()` strips control
  chars too
- **Session hardening**: `session.use_strict_mode = 1`,
  `session.use_only_cookies = 1`, `HttpOnly`, `SameSite=Lax`, `Secure` when
  `SESSION_SECURE=true`. Session ID regenerated on login. 500ms delay on
  failed password attempts
- **Preview DoS**: `/admin/preview` reads at most 256KB from the request body

## Response headers (set on every request)

```
X-Content-Type-Options: nosniff
X-Frame-Options:        SAMEORIGIN
Referrer-Policy:        strict-origin-when-cross-origin
Permissions-Policy:     geolocation=(), camera=(), microphone=(), payment=()
Content-Security-Policy: default-src 'self';
    script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net;
    style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net;
    font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net data:;
    img-src 'self' data: https:;
    connect-src 'self';
    frame-src https://www.youtube-nocookie.com https://www.youtube.com;
    frame-ancestors 'self';
    base-uri 'self';
    form-action 'self';
```

`X-Powered-By` is removed so the PHP version doesn't leak.

## Production hardening checklist

Items done in code (verify on your specific deploy):

- [x] Non-root user in `Dockerfile.prod` (`lazyblog` UID 1000)
- [x] Non-root user in `scripts/install-vps.sh` (`lazyblog` system user, FPM pool isolated)
- [x] `Content-Security-Policy` set at PHP layer (and at Caddy layer too via Caddyfile.example)
- [x] PHP `display_errors=Off`, `log_errors=On`, `expose_php=Off`,
      `opcache.validate_timestamps=0` baked into `Dockerfile.prod` + FPM pool
- [x] Session strict-mode + cookie-only + HttpOnly + SameSite=Lax

Items still on the human-operator:

- [ ] `Strict-Transport-Security` uncommented in Caddyfile (after TLS proves stable for a week)
- [ ] `SESSION_SECURE=true` in `.env`
- [ ] `.env` mode `640`, owner `lazyblog:www-data`
- [ ] `content/posts/` writable by php-fpm; rest read-only
- [ ] Daily backup cron + at least weekly restore test
- [ ] Fail2ban or Caddy rate-limit plugin on `/admin/login` if exposed publicly
- [ ] DNS CAA record locking certs to LetsEncrypt
