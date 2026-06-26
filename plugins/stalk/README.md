# Stalk

Pull-only feed reader for **other LazyBlog blogs**. The operator pastes a
friend's blog URL, the plugin polls their `/feed.xml`, and visitors see the
latest posts across the whole friend list at `/stalk`.

No handshake, no tokens, no comments. Strict: feeds that don't carry
`<generator>LazyBlog</generator>` are rejected — Stalk is intentionally
scoped to the LazyBlog network only.

```
visitor /stalk  ─►  gate fresh?  ─►  read cache              (fast path)
                       │
                       └─ stale  ─►  fetch batch in parallel  (~5s worst)
                                     │
                                     └─►  read cache
```

---

## Quick start

```ini
# .env
PLUGINS=stalk                       # or comma-list, e.g. graffiti,stalk
SITE_URL=https://your-blog.example  # used in the friend-fetch UA string
TIMEZONE=Asia/Saigon                # display TZ for "last refresh" lines
```

Restart PHP-FPM. A `[ STALK ]` link appears in the public header.

Open `/admin/stalk` (after the usual admin login) and paste a friend's
blog URL into the **Add Friend** form. The plugin probes their
`/feed.xml`, checks the `<generator>` element contains the literal
substring `LazyBlog`, then stores them in
`content/plugins/stalk/friends.json` and runs a first-time refresh.

Anonymous visitors hitting `/stalk` see the aggregated feed (newest
first, year-grouped). The visit also opportunistically triggers a batch
refresh if the gate has expired.

---

## What's where

### Routes

| Method | Path                            | Auth   | Purpose |
| :----- | :------------------------------ | :----- | :------ |
| GET    | `/stalk`                        | public | Aggregated friend feed. Calls `RefreshService::refreshStale()` first. |
| GET    | `/admin/stalk`                  | admin  | Management UI (URL list, status, add form, config). **No refresh on GET.** |
| POST   | `/admin/stalk/add`              | admin  | Probe + validate + create friend row + first-time refresh of that friend. |
| POST   | `/admin/stalk/remove/{id}`      | admin  | Drop friend + purge their cached posts. |
| POST   | `/admin/stalk/refresh-now`      | admin  | Force `refreshAll()` (bypasses the interval gate). |
| POST   | `/admin/stalk/config`           | admin  | Update `refresh_interval` / `max_friends` / `max_items_per_friend`. |

Admin routes auto-wrapped with `App\Auth::requireAuth()` via
`PluginContext::adminGet/adminPost`. POST handlers each call
`App\Csrf::requireValid()` first line.

### Storage

```
content/plugins/stalk/
├── friends.json   # one row per followed blog
├── posts.json     # cached items across all friends
└── config.json    # plugin-wide settings + global refresh gate
```

#### `friends.json` row shape

```jsonc
{
    "id":              "ff_<8 hex>",
    "blog_url":        "https://friend.example",
    "handle":          "Friend's Blog",
    "max_items":       3,        // null = use Config::maxItemsPerFriend()
    "added_at":        1730000000,
    "last_fetched_at": 1730086400,  // unix ts of latest SUCCESSFUL parse
    "last_status":     "ok",        // null | 'ok' | 'error'
    "last_http_code":  200,         // 0 = transport-level fail
    "last_error":      null         // short message when last_status='error'
}
```

#### `posts.json` row shape

```jsonc
{
    "id":            "p_<8 hex>",
    "friend_id":     "ff_<8 hex>",
    "title":         "Post Title",
    "link":          "https://friend.example/posts/slug",
    "pub_date":      1730000000,
    "guid":          "https://friend.example/posts/slug",
    "first_seen_at": 1730000000   // ts when this guid first hit the cache
}
```

`first_seen_at` is PRESERVED across refreshes when a guid is already
known — that's how the "NEW" badge on `/stalk` distinguishes items added
by the most recent batch from items that have been around.

#### `config.json` shape

```jsonc
{
    "refresh_interval":     "10h",   // "3h" | "10h" | "1d"
    "max_friends":          13,      // 1..100
    "max_items_per_friend": 3,       // 1..10, DEFAULT for new friends
    "last_refresh_at":      1730000000,
    "previous_refresh_at":  1729964000  // last batch's timestamp, BEFORE the most recent — used to tag NEW items
}
```

Caps (defaults shown):

| Field                    | Default | Range    |
| :----------------------- | :------ | :------- |
| `refresh_interval`       | `10h`   | `3h`, `10h`, `1d` |
| `max_friends`            | `13`    | 1..100   |
| `max_items_per_friend`   | `3`     | 1..10    |

`max_items_per_friend` in config is the **default for newly added
friends**. Each friend row stores its own `max_items` (set by operator
at add time, can be left empty to fall back to the current config
default). Existing friends keep their own cap when the config default
changes.

### Assets

`assets/stalk.css` — route-scoped (loads only on `/stalk` and
`/admin/stalk*`). Reuses LazyBlog CRT tokens (`--bg`, `--primary`,
`--accent`, etc.) from `public/assets/base.css`, so theme switching works
unchanged.

`pages.css` is NOT loaded on `/stalk` (core only ships it on
`/archive`, `/search`, `/series`) — the `.archive-*` rules the public
view depends on are lifted into `stalk.css`.

No JS. The plugin's UI is plain HTML forms — CSP-clean by construction.

---

## Cache & refresh model

### The cache

Two on-disk caches living under `content/plugins/stalk/`:

- **`friends.json`** — the operator's followed-blogs list. Updated on
  admin add/remove and on every refresh (status fields per friend).
- **`posts.json`** — items pulled from every friend's `/feed.xml`.
  Updated only by refresh code paths. **Read-only at `/stalk` render time.**

`config.json` holds two timestamps that drive everything:

| Field | Meaning |
| :---- | :------ |
| `last_refresh_at` | When the most recent batch ran. Drives `isStale()`. |
| `previous_refresh_at` | When the batch BEFORE the most recent ran. Drives the NEW badge. |

### The global interval gate

ONE timestamp in `config.json` decides whether a batch fetches or skips
— there is no per-friend gating.

```
isStale()  ⇔  (time() - last_refresh_at)  >=  intervalSeconds()
```

Mental model: "every `refresh_interval`, ONE batch refreshes ALL friends
in parallel; in between, every reader uses cache."

### Who triggers a refresh

| Caller                          | Method called      | Respects gate? |
| :------------------------------ | :----------------- | :------------: |
| `GET /stalk` (any visitor, incl. anon) | `refreshStale()` | ✅ |
| `GET /admin/stalk`              | (none — pure UI)   | n/a |
| Admin `[ REFRESH NOW ]` button  | `refreshAll()`     | ❌ force |
| CLI `scripts/stalk-refresh.php` | `refreshStale()`   | ✅ (synthetic visitor) |
| Admin add new friend            | `refreshOne(...)`  | n/a (single friend, no gate) |

### Scenarios — what visitors experience

Assume `refresh_interval = 1d` and a batch ran at `T+0h`.

| Scenario | Behavior |
| :------- | :------- |
| Visit at `T+2h` | Gate fresh → no fetch → render cached `posts.json` in ~0ms |
| Visit at `T+23h59m` | Still fresh → still 0ms render |
| Visit at `T+24h` (first one) | Gate stale → bump `markRefreshed(now)` → **fetch batch in parallel via cURL multi (≈ 5s)** → render fresh data. The "unlucky" visitor pays the ~5s. |
| Visit at `T+24h+1s` | Gate just bumped → fresh → 0ms render. Subsequent visitors freeride on the unlucky one. |
| Two visitors land in same millisecond at `T+24h` | Narrow race: both might see stale and trigger duplicate batches. Acceptable per KISS — `FileWriter::writeAtomic` keeps state consistent; friend blogs just see 2 requests instead of 1 (no real harm). flock() rejected as not worth the complexity. |
| Operator clicks `[ REFRESH NOW ]` at `T+5h` | Forces `refreshAll()` — bypasses gate, bumps `last_refresh_at = T+5h`, fetches all friends |
| Operator runs cron every 30 min | Cron = synthetic visitor → respects gate → only one cron tick per `interval` actually fetches; the rest skip. Useful for low-traffic blogs where visitors aren't reliable refreshers. |

### Batch path (`runBatch`)

1. **Bump `Config::markRefreshed(time())` BEFORE the network call.**
   Side-effects:
   - Old `last_refresh_at` slides into `previous_refresh_at` (the NEW
     boundary — see below).
   - `isStale()` flips false → second concurrent visitor skips → race
     window stays narrow.
2. `FeedFetcher::fetchMany()` — parallel cURL multi.
   Total wall time ≈ `max(per-friend 5s)`, not `sum`. Worst case for
   13 friends ≈ 5s.
3. For each result, per-friend try/catch:
   - **Success**: parse → slice to that friend's `max_items` cap →
     `PostCache::replaceForFriend()` → mark row `last_status='ok'` with
     `last_http_code=200` and `last_fetched_at=now`.
   - **Failure** (HTTP non-200, timeout, parse error, SSRF reject, etc.):
     mark row `last_status='error'` with `last_http_code` and
     `last_error`. **Cache for that friend is NOT touched** — visitors
     keep seeing the previous good items until the next success.

### What "NEW" means on `/stalk`

An item is tagged `NEW` when its `first_seen_at >= previous_refresh_at`
— i.e. the item showed up in the **most recent batch**, not before. It
is per-batch, not per-visitor (we do not track per-cookie last-visit).

Walkthrough:

1. `T+0` first ever refresh: `previous_refresh_at=0`, every item is NEW.
2. `T+10h` second refresh (interval expired): `previous_refresh_at` becomes
   the `T+0` value. Items added at `T+10h` are NEW; items already known
   from `T+0` carry over with their original `first_seen_at < T+0`
   so they are NOT NEW.
3. `T+20h` third refresh: `previous_refresh_at` becomes `T+10h`. Only
   items added at `T+20h` are NEW.

`first_seen_at` is preserved across `replaceForFriend()` for items whose
guid was already cached. Without that preservation, every refresh would
re-tag every item as NEW and the badge would be meaningless.

### What happens when a friend errors

| Error                           | `last_http_code` | `last_status` | Cache for that friend |
| :------------------------------ | :--------------: | :-----------: | :-------------------- |
| HTTP 404 / 410                  | `404` / `410`    | `error`       | **preserved** |
| HTTP 500 / 502 / 503 / 504      | the actual code  | `error`       | **preserved** |
| Connection refused / DNS fail   | `0`              | `error`       | **preserved** |
| Timeout (> 5s)                  | `0`              | `error`       | **preserved** |
| Body > 512KB                    | `200`            | `error`       | **preserved** |
| Redirect lands on loopback/private IP | the final code | `error` | **preserved** |
| Feed parses but no `LazyBlog` generator | `200`    | `error`       | **preserved** |
| Malformed XML                   | `200`            | `error`       | **preserved** |

Other friends in the same batch are unaffected (per-friend try/catch).
Admin row shows `[HTTP NNN]` chip with the error message in a `title=`
tooltip. The batch is still recorded as having run (`last_refresh_at`
gets bumped) so a dead friend doesn't trigger a re-fetch on every
visit — operator can remove them at leisure or wait for the blog to
recover.

### What happens when a friend is removed

`POST /admin/stalk/remove/{id}` runs:

1. `PostCache::removeByFriend($id)` — wipes that friend's rows from
   `posts.json`.
2. `FriendStore::delete($id)` — removes the friend row from
   `friends.json`.

Both writes are atomic via `FileWriter::writeAtomic`. After remove:

- `/stalk` no longer shows any of that friend's posts.
- `posts.json` shrinks — no orphan rows pointing at a deleted friend.
- Friend count in admin and `max_friends` cap free up by one slot.

This is **destructive** — re-adding the same blog later fetches their
current feed from scratch (you lose any posts they may have already
rotated off their own feed). The admin remove button confirms with a
JS dialog before posting.

### What happens when a friend blog goes back online

Nothing automatic flips the row to `ok` — the next batch refresh
(visitor, force, or cron) attempts the fetch again. On success,
`last_status` flips to `ok`, `last_http_code` becomes `200`, the
friend's cache rows are replaced with the freshly-parsed items, and
posts the friend published while they were down start appearing on
`/stalk` (up to that friend's `max_items` cap).

---

## Operator cron (optional)

Plugin v1 contract forbids the plugin itself from scheduling background
work. A standalone CLI script ships as the documented escape hatch:

```cron
# every 30 minutes — gate ensures actual fetches respect admin interval
*/30 * * * * /usr/bin/php /var/www/lazyblog/plugins/stalk/scripts/stalk-refresh.php >> /var/log/stalk.log 2>&1
```

Cron acts as a synthetic visitor — respects the same interval gate
`/stalk` does. Operator can run cron more frequently than the configured
interval (e.g. every 5 minutes); the gate just skips overshooting
ticks. Useful for low-traffic blogs where visitors aren't reliable
enough to keep the cache fresh on their own.

The CLI accepts no arguments and prints one summary line:

```
[stalk] refreshed=2 errored=0 skipped=0 gated=0
```

---

## Strict LazyBlog validation

The add handler probes `{blog_url}/feed.xml`, parses the XML, and
rejects unless the feed's `<generator>` element contains the literal
substring `LazyBlog`. Both the current plain `LazyBlog` and historic
`LazyBlog (PHP + Markdown)` generators pass. WordPress / Substack /
Ghost / Jekyll / generic SSG feeds are all rejected with
`"not a LazyBlog blog"`.

---

## Security

- **No tokens / no inbound auth** — Stalk is read-only on the friend's
  side: it just calls `GET /feed.xml`. No webhook, no callback.
- **Auth gate** — all 5 admin routes auto-wrapped with
  `App\Auth::requireAuth()` via `PluginContext::adminGet/adminPost`.
- **CSRF** — every POST handler calls `App\Csrf::requireValid()` first
  line; every form embeds `<input type="hidden" name="_csrf">`.
- **Scheme guard** — fetcher refuses anything outside `http://` and
  `https://`. cURL `CURLOPT_PROTOCOLS` + `CURLOPT_REDIR_PROTOCOLS`
  pinned to the same set so redirect to `file://` / `gopher://` is
  blocked at the libcurl layer too.
- **SSRF blocklist (`HostGuard`)** — rejects loopback / private /
  link-local destinations both pre-fetch (admin add) AND post-fetch
  (cURL's effective URL after redirects). Closes the
  `innocent.example` 302→`169.254.169.254` (AWS metadata) pivot.
  Covers: `127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`,
  `192.168.0.0/16`, `169.254.0.0/16`, `0.0.0.0/8`, plus IPv6 `::`,
  `::1`, `fc00::/7` (ULA), `fe80::/10` (link-local), and well-known
  loopback hostnames.
- **HTTP fetcher hardening** — 5s connect/total timeout, 512KB body
  cap via `CURLOPT_PROGRESSFUNCTION` abort + post-exec strlen check,
  max 3 redirects, `Accept-Encoding: identity` to block decompression
  bombs, distinct UA `LazyBlog-Stalk/0.1.0 (+SITE_URL)`.
- **XML parser hardening** — `simplexml_load_string()` with
  `LIBXML_NONET | LIBXML_NOCDATA`. `LIBXML_NONET` prevents
  external-entity fetches (XXE).
- **Friend remove wipes cached posts** — `handleRemove` calls
  `PostCache::removeByFriend()` before deleting the friend row, so
  unfollowed friends disappear cleanly from `/stalk` and `posts.json`
  stays bounded.

---

## Disable

Remove `stalk` from `PLUGINS=`. Routes and the nav link vanish.
`content/plugins/stalk/` is preserved — re-enabling restores the friend
list and cached posts.

To wipe state completely:

```bash
rm -rf content/plugins/stalk
```

---

## Tests

```bash
php tests/test-stalk-friend-store.php       # CRUD on friends.json
php tests/test-stalk-post-cache.php         # replace-per-friend + first_seen_at preservation
php tests/test-stalk-config.php             # interval / caps / gate timestamps
php tests/test-stalk-feed-parser.php        # strict LazyBlog generator + XXE-safe
php tests/test-stalk-host-guard.php         # SSRF blocklist (IPv4/IPv6/hostnames)
php tests/test-stalk-refresh-service.php    # global gate + batch + isolation + per-friend cap
php tests/test-stalk-boot.php               # plugin boot + route registration + nav contribution
```

Test doubles in `test-stalk-refresh-service.php` subclass `FeedFetcher`
so the suite never touches the network.

---

## File layout

```
plugins/stalk/
├── README.md
├── manifest.json
├── plugin.php
├── src/
│   ├── StalkPlugin.php       # plugin entry — registers routes + handlers
│   ├── FriendStore.php       # CRUD over friends.json
│   ├── PostCache.php         # replace-per-friend over posts.json
│   ├── Config.php            # interval / caps / global gate timestamps
│   ├── FeedFetcher.php       # cURL single + multi, SSRF + size + timeout guards
│   ├── FeedParser.php        # SimpleXML, strict LazyBlog generator check
│   ├── RefreshService.php    # orchestrator: gate + parallel fetch + cache merge
│   └── HostGuard.php         # SSRF blocklist shared by Plugin (pre-fetch) + Fetcher (post-redirect)
├── views/
│   ├── public-index.php      # /stalk — archive-style year-grouped list
│   └── admin-index.php       # /admin/stalk — add + friends list + config
├── assets/
│   └── stalk.css             # route-scoped styles
└── scripts/
    └── stalk-refresh.php     # CLI for operator cron (gated, same as visitor)
```
