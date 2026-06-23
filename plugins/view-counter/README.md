# View Counter

Counts unique-user views per post and renders a public `N lượt xem` badge on the post page. Opt-in plugin.

## Enable

```env
PLUGINS=view-counter
```

(Comma-separate with other plugins if multiple.)

## How it works

- Each visitor gets a random `lz_uid` cookie (32-hex, 1-year, HttpOnly, SameSite=Lax). The cookie is the identity; no IP or User-Agent is ever stored.
- `GET /posts/{slug}` increments the slug's counter at most once per `(lz_uid, slug)` pair (dedup via sha256 hash in `seen.json`).
- Common crawlers (`Googlebot`, `GPTBot`, `ClaudeBot`, `curl`, `wget`, RSS readers, …) are filtered out by User-Agent. See `src/BotFilter.php` to extend.
- Counter persisted at `content/plugins/view-counter/stats.json`. Sidecar `.stats.lock` file serialises read-modify-write under `flock(LOCK_EX)`.
- Post `.md` files are **never** modified — the cache pyramid (`.index.json`, `.llms.txt`, `.llms-full.txt`, `.feed.xml`) stays untouched.

## Storage

```
content/plugins/view-counter/
├── stats.json     ← {"<slug>": {"views": 42}}
├── seen.json      ← {"<sha256>": <unix-ts>} dedup index
└── .stats.lock    ← flock target (zero-byte)
```

Backup via the existing `rsync content/` flow. The stats file is plain JSON; you can edit, reset, or delete it manually.

## Disable

Remove `view-counter` from `PLUGINS=`. Routes unregister, no listener fires, the badge disappears. Stats files stay on disk (harmless; re-enable to restore).

## No public routes, no nav

This plugin contributes nothing to the header/footer nav and registers no public routes. It runs entirely through the core `post.view` event and `post.meta` slot.
