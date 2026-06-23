---
title: Post view counter — brainstorm, plan, ship
date: 2026-06-24
type: design
status: shipped
---

# Post view counter — brainstorm + plan

## What

Designed a per-post unique-user view counter for LazyBlog, shipped as opt-in plugin `view-counter`. Public badge `N lượt xem` on every post page. No code written — brainstorm + plan only.

- Brainstorm: `plans/reports/brainstorm-summary-260624-0007-post-view-counter-plugin-report.md`
- Plan: `plans/260624-0007-post-view-counter-plugin/` (plan.md + phase-01..06)
- Tasks: 6 hydrated (one per phase)

## Decisions locked

| Question | Answer |
|---|---|
| Metric | Unique-user views only. Impressions dropped. |
| Identity | Anonymous `lz_uid` cookie (16-byte hex, 1y, HttpOnly, SameSite=Lax, Secure iff `SESSION_SECURE`) |
| Dedup key | `sha256(uid \| slug)` in `seen.json` — raw IDs never persisted |
| Bot filter | UA-regex denylist (bot/crawl/spider/slurp/gptbot/claudebot/curl/wget/rss/…). Empty UA = bot. |
| Storage | Sidecar JSON at `content/plugins/view-counter/{stats,seen}.json` + `.stats.lock` |
| Concurrency | `flock(LOCK_EX)` on `.stats.lock` around read-increment-write — `FileWriter::writeAtomic` alone is insufficient |
| Packaging | Plugin + new core hook surfaces (`onPostView`, `slot('post.meta')`) |
| Display | Always show the count, no threshold, no link |
| Number format | `number_format($n, 0, '.', ',')` → `1,234` |
| Label locale | Hard-coded `"lượt xem"` in `views/badge.php` — single-point change |

## Non-obvious rejections (kept here so future-me doesn't relitigate)

**Writing the counter into the post `.md` frontmatter — rejected.** Tempting (single source of truth, `cat`-able, `rsync`-friendly), but it cascade-invalidates the cache pyramid in `docs/system-architecture.md`: every view bumps `.md` mtime → `PostRepository::indexStale()` triggers → `.index.json` + `.llms.txt` + `.llms-full.txt` + `.feed.xml` all rebuild on the next request. A handful of visitors becomes a regeneration storm. Also races with admin save (background view tick rewrites the file under EasyMDE) and pollutes git history with derived state. The mtime-based invalidation is the cleverest piece of the design; counters must not touch it.

**Plugin packaging with JS beacon — rejected.** Plugin would register `POST /view-counter/beacon`, post page fires `fetch()` on load. But: no-JS / RSS / curl readers never count, CSP needs `connect-src` edit, and the post template lives in core anyway — so it'd modify core regardless. Server-side dispatch is honest and universal.

**Core feature with env flag — runner-up.** Simpler (~150 LOC) and KISS-aligned, but author explicitly chose to invest in a thin hook seam (`onPostView` + `slot('post.meta')`) so future analytics-style plugins can subscribe without further core edits. Accepted YAGNI cost (~50 LOC of dispatcher infrastructure) for plugin-system extensibility.

## Architectural seams added by the plan

Two new generic surfaces on `PluginContext` / `PluginRegistry`:

- `$ctx->onPostView(callable $listener)` — observer for `PostViewEvent { slug, userAgent, requestTime }`. Listener throws → caught + `error_log`'d. No priority, no removal. Narrow on purpose.
- `$ctx->onPostMeta(callable $renderer)` — returns HTML string contributed to `post.meta` slot. `views/post.php` iterates and echoes.

Both intentionally narrow. Resist building a generic event bus until a second consumer demands it.

## Correctness flags for implementation

1. **Cookie timing.** `setcookie()` requires headers not yet sent. Hook dispatch in `PostController::show()` must fire **before** `Http::render` flushes the body — not after, as initially considered.
2. **`flock` vs `writeAtomic`.** `FileWriter::writeAtomic` is rename-atomic but does not protect a read-modify-write counter. Phase 3 mandates `flock(LOCK_EX)` on `.stats.lock` around the read → mutate → write sequence. Phase 6 has a 50-process fork-and-join test specifically to catch regressions.
3. **`seen.json` growth.** Accepted unbounded (each (uid, slug) pair = one sha256 entry). Revisit threshold = 5 MB or 10k entries.

## Cut from scope

- Impressions of any kind (listing + viewport).
- Admin leaderboard / "top posts by views" page (deferred to its own plan if wanted).
- Backfill from Caddy access logs.
- Per-day or time-series analytics — only running totals.
- Geo / referrer / device tracking.
- SQLite path (kept as future migration target if `stats.json` lock contention ever shows up).

## Cook outcome

Implemented same session. All 6 phases shipped; tests green including the 50-process concurrent-write race that validates `flock(LOCK_EX)` correctness on `.stats.lock`. Live smoke test (php -S + curl): cookie minted on fresh visit, dedup'd on repeat, Googlebot UA filtered out (no Set-Cookie, no counter bump), stats.json mtime/structure as designed.

Two UX pivots during cook (user iteration on live render):

1. **Badge → inline transmission-line text.** Initial design had a styled `.post-views` span with border + padding + § icon + uppercase label inside a dedicated `.post-meta-slots` div between tag chips and post body. After seeing it rendered, user requested removing the badge box entirely and appending the count inline to the existing `.section-tag` transmission line: `§ TRANSMISSION — {date} — {author} — {N} View`. Net effect: 35 lines of CSS deleted, badge view collapsed to a single `echo`, slot consumer moved from below-tags to inside the transmission tag. Same plugin hooks, different rendering surface.
2. **Label `"lượt xem"` → `"View"`.** User asked for English label. One-line change in `views/badge.php`.

## Implementation notes worth remembering

- `Http::plugins()` already exists as a static accessor for the boot-time PluginRegistry — no need to thread the registry through controllers or views. Saved a lot of plumbing in Phase 1.
- `FileWriter::writeAtomic` does NOT serialize concurrent read-modify-write — needed an explicit `flock(LOCK_EX)` on a separate `.stats.lock` file. The 50-process fork test caught a second-order bug in the test itself first: `register_shutdown_function` registered in the parent is inherited by every forked child, so the first child's shutdown handler deleted the race dir under subsequent children. Fix was do cleanup inline in parent only, never as a shutdown handler when forking.
- Cookie timing: dispatch the `post.view` event BEFORE `Http::render` (initially documented as "after") — `setcookie()` needs headers not yet sent.
- The plugin scaffold + manifest pattern (mirroring `hello-world`) made onboarding trivial; the only friction was adding `view-counter` to the `.gitignore` plugin allowlist (`!/plugins/view-counter`).

## Files shipped

Core (5):
- `src/PostViewEvent.php` (new)
- `src/PluginContext.php` (+ `onPostView`, `onPostMeta`)
- `src/PluginRegistry.php` (+ listeners, `dispatchPostView`, `slotPostMeta`)
- `src/Controllers/PostController.php` (dispatch event before render)
- `views/post.php` (consume slot inside `.section-tag`)

Plugin (6):
- `plugins/view-counter/manifest.json`
- `plugins/view-counter/plugin.php`
- `plugins/view-counter/README.md`
- `plugins/view-counter/src/ViewCounterPlugin.php`
- `plugins/view-counter/src/CookieIdentity.php`
- `plugins/view-counter/src/StatsStore.php`
- `plugins/view-counter/src/BotFilter.php`
- `plugins/view-counter/views/badge.php`

Tests: `tests/test-view-counter.php` (BotFilter matrix + CookieIdentity + StatsStore single/dedup/corrupt + 50-process race).
Docs: `docs/plugin-development.md` + `docs/system-architecture.md` updated with hook surfaces.
.gitignore: `!/plugins/view-counter` exception added.
