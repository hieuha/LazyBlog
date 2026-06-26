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

## Password-protected posts

Posts can be individually locked behind a bcrypt-hashed password. Unlock
state is session-flagged, not cookie-based.

**Storage and secrecy**:
- Hash lives only in the post frontmatter YAML as `password_hash:` (bcrypt
  cost 12). Never indexed by search, never served via `.md` routes to
  unlocked/admin, never sent in feeds or llms.txt.
- `PostController::raw()` strips the `password_hash:` line before serving
  plaintext markdown to authenticated visitors
- `content/.index.json` records `"protected": bool` only — the hash stays in
  the file itself

**Visitor flow**:
- `PostController::show()` gates render: locked posts serve the
  `views/post-password.php` unlock form instead of body until
  `Auth::isPostUnlocked($slug)` returns true
- Correct password calls `Auth::markPostUnlocked($slug)` and redirects back;
  session ends when browser closes
- Wrong password fails silently after incrementing the per-IP counter

**Rate limiting (per-IP, separate file)**:
- 10 failures within a 15-minute sliding window throttle the IP and disable
  input until the window clears. Throttle honours browser F5.
- 500ms timing burn on every failure (same as admin login)
- Rate-limit state stored in `/tmp/lazyblog-post-unlock-attempts.json` —
  ephemeral, cleared on reboot (acceptable for a single-server blog)
- Anonymous `.md` fetches return 404 for protected posts to avoid a timing
  oracle

**Listings, search, and feeds**:
- Locked posts are excluded from `/llms.txt` and `/feed.xml` — title,
  URL, body all omitted
- Home, tags, archive: title + `🔒 LOCKED` / `🔒 UNLOCKED` badge (unlocked
  only if visitor's session is flagged)
- Search: title + tag matches surface; body terms return zero hits with a
  `🔒 protected post` placeholder

## Behind a CDN / reverse proxy

When deployed behind Cloudflare or another edge proxy, the reverse proxy
replaces `REMOTE_ADDR` with its own IP, so rate-limit counters are shared
across all visitors and become useless.

**`TRUST_CF_CONNECTING_IP` env knob** (default `false`):
- `false` (recommended): rate-limits apply per `REMOTE_ADDR` only. If you
  accept direct traffic, this is correct. If ALL traffic flows through
  Cloudflare, rate-limits won't work.
- `true`: switch to `CF-Connecting-IP` header (validated as a real IP via
  `filter_var(FILTER_VALIDATE_IP)`). Only enable when origin traffic
  actually flows through Cloudflare — see threat model below.

**Threat model when enabling `TRUST_CF_CONNECTING_IP=true`**:
- The header is spoofable by any client that connects directly to the
  origin IP (bypassing Cloudflare). If your origin accepts direct HTTP,
  an attacker can spoof any IP to reset rate-limit counters.
- **Fix**: lock the origin to Cloudflare ranges only. Use a Cloudflare
  Tunnel (origin-less), a reverse-proxy allowlist (IP ranges from
  https://www.cloudflare.com/ips/), or a cloud firewall (AWS SG, Azure NSG)
  to block non-Cloudflare direct connections.
- **Multi-server scope**: if you load-balance across multiple servers,
  `/tmp/*.json` is per-host, so an attacker can distribute requests across
  servers to bypass the limit. Migrate rate-limit storage to a shared Redis
  or database if needed.

## Plugin network surface

Enabled plugins (configured via `PLUGINS=` env) may make outbound HTTP
requests. For example, the `stalk` plugin fetches remote RSS feeds to
aggregate friend-blog posts. It includes SSRF guards that block requests to:

**IPv4 ranges:**
- Loopback: `0.0.0.0/8`, `127.0.0.0/8`
- Private: `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`
- Link-local + cloud metadata: `169.254.0.0/16`

**IPv6 ranges:**
- Loopback: `::/1`, `::1`
- Unique Local: `fc00::/7`
- Link-local: `fe80::/10`

The blocklist is applied twice — pre-fetch (on operator-provided URL) and
post-redirect (via `CURLINFO_EFFECTIVE_URL` to close the "innocent.example
302 → metadata" pivot). Review plugin source before enabling, especially if
it makes network calls.

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
