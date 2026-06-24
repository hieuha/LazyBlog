# Graffiti

Cross-blog sticker / spray-paint / free-text exchange between LazyBlog
friends. Sign published posts as currency: writing a post mints
**energy**; sending or receiving graffiti spends it.

Two delivery paths:

1. **Server-to-server outbox** (`/admin/graffiti/send`) — operator picks
   a friend + post + sticker, plugin queues + retries until the friend's
   `/graffiti/receive` accepts.
2. **Magic-link cross-blog spray** — friend A clicks "Visit & Spray" on
   their own admin → lands on B's post with a signed cookie → uses B's
   in-page spray button to drop graffiti without touching B's admin.

Both flows reconcile through the same energy ledger.

---

## Quick start

```bash
# .env on both blogs
PLUGINS=graffiti                    # or comma-list: hello-world,graffiti
SITE_URL=https://your-blog.example  # required — used in invite blocks +
                                    # cookie HMAC + revoke origin check
GRAFFITI_DEV=1                      # OPTIONAL, dev only: relaxes HTTPS
                                    # gate + rewrites localhost ↔
                                    # host.docker.internal for outbound
```

Restart PHP-FPM. The `[ GRAFFITI ]` link appears in the public header
navbar — admin-only (anonymous visitors never see it). The count badge
shows unread received items.

To friend two blogs, see **Friend handshake** below.

---

## What's where

### Routes

Public (token / cookie auth):

| Method | Path                          | Purpose                                                                 |
| :----- | :---------------------------- | :---------------------------------------------------------------------- |
| POST   | `/graffiti/receive`           | Inbox webhook. Friend posts a signed graffiti envelope.                 |
| POST   | `/graffiti/cross-spray`       | Cookie-authed cross-blog spray (magic-link visitor).                    |
| POST   | `/graffiti/notify-debit`      | Receiver-authoritative "debit my energy" callback after a cross-spray.  |
| POST   | `/graffiti/balance`           | Pre-flight balance probe — receiver asks sender before storing a spray. |
| POST   | `/graffiti/handshake-complete`| Auto-completion of an outstanding invite (one-paste flow).              |
| POST   | `/graffiti/revoke-notify`     | "I'm unfriending you" — symmetric cleanup on the other side.            |
| GET    | `/graffiti/visit`             | Magic-link entry. Validates token in URL, sets `gf_visit` cookie.       |
| GET    | `/graffiti/leave`             | Clears `gf_visit` cookie + redirects home.                              |
| GET    | `/graffiti/health`            | `{ok, plugin, version, api_version}` — handshake reachability probe.    |
| GET    | `/graffiti/stickers.json`     | Public catalogue (enabled stickers + current prices).                   |

Admin (cookie auth + CSRF):

| Method | Path                                          | Purpose                                            |
| :----- | :-------------------------------------------- | :------------------------------------------------- |
| GET    | `/admin/graffiti`                             | Received tab (moderation + outbox drain on visit). |
| GET    | `/admin/graffiti/friends`                     | Friends tab (invite + accept + revoke).            |
| GET    | `/admin/graffiti/stickers`                    | Stickers tab (per-sticker price + enable toggle).  |
| GET    | `/admin/graffiti/energy`                      | Energy tab (balance + ledger tail).                |
| GET    | `/admin/graffiti/send`                        | Send tab (form-driven outbox).                     |
| POST   | `/admin/graffiti/send/submit`                 | Submit a new graffiti to a friend or to self.      |
| POST   | `/admin/graffiti/hide/{id}` / `unhide/{id}`   | Mod actions on received items.                     |
| POST   | `/admin/graffiti/delete/{id}`                 | Hard-delete a received item.                       |
| POST   | `/admin/graffiti/friends/invite`              | Create an invite block to send out-of-band.        |
| POST   | `/admin/graffiti/friends/accept`              | Paste a received block to complete the handshake.  |
| POST   | `/admin/graffiti/friends/revoke/{id}`         | Remove a friend (notifies the other side).         |
| POST   | `/admin/graffiti/stickers/update`             | Change price for a sticker (operator override).    |
| POST   | `/admin/graffiti/stickers/toggle/{id}`        | Enable / disable a sticker.                        |

### Storage

```
content/plugins/graffiti/
├── friends.json     # one row per friendship (state, tokens, blog_url, handle)
├── graffiti.json    # received items (append-only; `hidden` flag for mod)
├── energy.json      # balance + ledger tail + minted_slugs (mint idempotency)
├── outbox.json      # send queue with attempt counters + retry timestamps
├── stickers.json    # operator overrides on the shipped catalogue
└── nonces.json      # 24h replay-protection cache for inbox auth
```

The shipped default sticker catalogue is
`plugins/graffiti/content/stickers.json`. First boot copies it into
storage; from then on, the storage copy is canonical — operator price /
enabled edits survive plugin upgrades.

### Assets

`plugins/graffiti/assets/`:

- `graffiti.css` — overlay + spray button + modal + admin tab styling
- `graffiti.js` — sticker picker, placing mode, submit handler,
  per-item dismiss for every visitor
- 16 SVG stickers (`ufo-1.svg`, `fire-1.svg`, …) served as
  `/plugin-assets/graffiti/<filename>` with mtime cache-bust

---

## Friend handshake

Tokens are exchanged out-of-band (Signal / Telegram / email / paper).
Each row holds two secrets from B's POV:

- `incoming_token` — secret B issued; friend presents on inbound calls.
- `outgoing_token` — secret friend issued; B presents on outbound calls.

Both 32 random bytes → 43-char base64url. Per-friend independent; revoke
one without rotating the rest.

### Two-paste manual flow (always works)

1. **A → admin → Friends tab → Invite.** Type B's handle + blog URL.
   Plugin mints A's `incoming_token`, creates a `pending` row, and
   stashes an invite block in the flash:
   ```
   [ GRAFFITI INVITE / v1 ]
   eyJibG9nX3VybCI6Imh0dHBzOi8vYS5leGFtcGxlIiwiaGFuZGxlIjoiYSIs...
   [ / END ]
   ```
   A copies the block, sends to B over any chat.
2. **B → admin → Friends tab → Accept.** Paste A's block.
   - Plugin probes `https://a.example/graffiti/health` first (rejects
     if the target isn't reachable / doesn't run graffiti).
   - Creates B's row with A's token as B's `outgoing_token`, mints B's
     `incoming_token`, state → `active`.
   - Stashes B's reply block in the flash.
3. **A → admin → Friends tab → Accept.** Paste B's reply block. Fills
   A's `outgoing_token` + flips A's row to `active`.

### One-paste auto-complete (when both sides reachable)

After step 2 above, B's blog calls `POST a.example/graffiti/handshake-complete`
with `{token: incoming_token_A_issued, reciprocal_token: B's_incoming_token,
handle, endpoint}`. A's `pending` row flips to `active` in the same
round-trip — no second paste needed from A. If A is offline during step 2,
fall back to the two-paste flow (B's reply block is still stashed).

### Revoke

Admin → Friends → `[REVOKE]`. Plugin captures the row before delete,
fires `POST {friend.blog_url}/graffiti/revoke-notify` with
`{token: outgoing_token, from_blog: SITE_URL}`, then hard-deletes
locally. Fire-and-forget — if the friend's blog is offline, local
delete still happens; friend can be cleaned up later.

The receiver checks two things:
1. Token matches a row in receiver's `friends.json` (auth proof — the
   secret was minted by the receiver, only the holder can present it).
2. Body's `from_blog` equals that row's stored `blog_url` (origin
   sanity check — defense-in-depth against a token holder who doesn't
   know which blog the secret was minted for).

Origin mismatch → `403 origin_mismatch`. No match → `200 accepted
note=no_match` (idempotent — already gone).

---

## Energy economy

| Event              | Δ balance | Idempotent on        |
| :----------------- | :-------: | :------------------- |
| Publish new post   |    +10    | post slug            |
| Send (admin path)  |   −price  | outbox row           |
| Cross-spray (xs)   |   −price  | graffiti id          |
| Receive            |     0     | n/a                  |

- **Mint** (`EnergyLedger::MINT_PER_POST = 10`) fires from
  `onPostSave` only when `event.isNew && event.published`. Idempotent
  via `minted_slugs` — re-saving a post never inflates the balance.
- **Price** is the **receiver's** authority. Sender's UI shows the
  fetched price from `/graffiti/stickers.json`; receiver's `Inbox`
  also re-checks against own catalogue at intake.
- **Cross-spray debit** is owed via `POST {sender}/graffiti/notify-debit`.
  Receiver-authoritative: even if buggy sender over-charges, only the
  ledger debit lands — graffiti itself already stored on receiver.
- **Negative balance** still possible from concurrent self-spends on
  sender between pre-flight and debit, but the cross-spray pre-flight
  closes the common case where a visitor keeps spraying after going
  broke. Server-to-server outbox path is hard-gated by `canSpend`.
  Revoke ends future debits immediately.

---

## Rate limiting

`RateLimiter` (`DEFAULT_LIMIT = 5` / day) keys on
`friend.id × calendar day (UTC)`. Per-friend override via the
`rate_limit_per_day` field on the friend row (no admin UI yet — edit
`friends.json` directly).

Limit is **receiver-side**: B decides how often A is allowed to spray B,
regardless of A's local energy balance. A failed-with-`429` send goes
back into the outbox with backoff.

---

## Magic-link cross-blog spray

Without server-to-server outbox, A can spray B "live" by visiting:

1. **A → admin → Send tab → pick friend B → "Visit & Spray".** A's
   blog redirects A's browser to `https://b.example/graffiti/visit
   ?token=<A's_outgoing_token>&to=/posts/<slug>`.
2. **B's server** validates the token, sets a signed `gf_visit` cookie
   (HMAC over `SITE_URL` + `ADMIN_PASSWORD_HASH` + plugin tag — secret
   per blog; rotating admin password invalidates all visit cookies),
   then redirects to the sanitized `to` (relative paths only — no
   open redirect).
3. **A in browser**, now carrying the `gf_visit` cookie, sees a
   `[ friend WAS HERE ]` badge in B's navbar and the spray-can button
   on the post.
4. **A clicks spray** → modal opens. Identity badge in the modal's
   bottom-right shows `VISITOR · @a-handle` (yellow) so A doesn't
   confuse role. (Admin viewing own posts sees `OWNER · @self` in
   green — same surface, different identity.)
5. **A places the sticker** → JS POSTs to `/graffiti/cross-spray` with
   the cookie. **Pre-flight**: B's server calls
   `POST a.example/graffiti/balance` (token-auth) to read A's current
   balance. If A's blog is unreachable → `502 balance_unreachable`,
   spray refused. If `balance < price` → `402 insufficient_energy`,
   spray refused. Otherwise B stores graffiti + fires
   `POST a.example/graffiti/notify-debit` with `{token, amount, reason}`
   so A's ledger debits the cost. B's local rate limit applies.
6. **A clicks the navbar badge** (`[ a-handle WAS HERE ]`) → cookie
   cleared → redirect home → identity drops back to anonymous.

Cookie has 24h TTL; signature uses constant-time `hash_equals`.

---

## Inbox auth (server-to-server send)

`POST /graffiti/receive` body shape:

```json
{
    "token": "<receiver's incoming_token for sender>",
    "from_blog_url": "https://a.example",
    "post_slug": "my-post",
    "type": "sticker | text | spray",
    "payload": {
        "sticker_id": "fire-1",            // OR
        "text": "hi", "font": "marker", "color": "green",
        "position": { "x": 0.42, "y": 0.31, "rotation": -7 }
    },
    "nonce": "12-byte random",
    "issued_at": 1730000000
}
```

Pipeline in `Inbox.php`:

1. HTTPS gate (skip in `GRAFFITI_DEV=1`).
2. JSON shape + required fields.
3. `findByIncomingToken` → 403 on miss.
4. `nonces.json` dedup (24h window) → 409 on replay.
5. `PostRepository::bySlug` → 404 on unknown post.
6. `RateLimiter` → 429 on over-quota.
7. `PayloadValidator` (sticker_id whitelisted in own catalogue, text
   length ≤ 140, font/color in allowed sets, position clamped 0..1).
8. `GraffitiStore::append`.

Every reject is JSON-shaped so the sender's outbox can decode the
reason and decide retry / drop.

---

## Outbox (server-to-server)

`Outbox::drain` runs on every admin page visit (`afterDrain` wrapper)
plus on submit. Drainer:

1. Picks rows whose `next_attempt_at <= now`.
2. POSTs to `friend.graffiti_endpoint` (= `friend.blog_url + /graffiti/receive`).
3. On `200` → mark `delivered`, debit energy.
4. On `409` (replay) → mark `delivered` (treat as ours).
5. On `429` / 5xx / transport fail → exponential backoff, schedule
   retry, increment `attempts`. After cap, mark `failed`.
6. On `4xx` other → mark `failed` (don't retry — payload is bad).

---

## Identity badge (modal)

Spray modal header carries a small label at bottom-right so the
operator never confuses identity:

| Mode      | Label                | Color   | Auth source         |
| :-------- | :------------------- | :------ | :------------------ |
| Admin     | `OWNER · @author`    | green   | session + CSRF      |
| Magic-link| `VISITOR · @handle`  | yellow  | signed `gf_visit`   |

Anonymous visitors never see the modal (button isn't emitted).

---

## Trust model

- 256-bit per-friend tokens; constant-time compare; HTTPS required in
  prod. Revoking one friend never affects others.
- Receiver is single source of truth: validates token, dedups nonce,
  re-prices against own catalogue, applies own rate limit, owns the
  hide / delete UI.
- Origin claim (`from_blog` in revoke body) is checked but treated as
  defense-in-depth — token is the primary auth boundary, not the URL.
- Cookie HMAC secret derives from `SITE_URL + ADMIN_PASSWORD_HASH`:
  two blogs sharing infra cannot forge each other's session cookies,
  and rotating the admin password kills every active friend session.

---

## Dev / federation testing

```bash
docker compose --profile blog-b up -d
```

Brings up a second blog (B) sharing the same code but using
`.env.blog-b` + `content-b/` for independent identity. Default port
`8081` on host. `GRAFFITI_DEV=1` should be set on both `.env` files so
HTTPS gate relaxes and outbound `localhost` URLs get rewritten to
`host.docker.internal:<port>` for in-container loopback.

---

## Disable

Remove `graffiti` from `PLUGINS=`. Routes vanish, navbar entry vanishes.
`content/plugins/graffiti/` is preserved — re-enabling restores
everything (friends, ledger, history).

---

## Tests

```bash
php tests/test-graffiti-boot.php          # boot + manifest + routing
php tests/test-graffiti-friends.php       # friend handshake + revoke
php tests/test-graffiti-inbox.php         # receive + nonces + rate limit
php tests/test-graffiti-energy.php        # mint + debit + idempotency
php tests/test-graffiti-outbox.php        # queue + retry + backoff
php tests/test-graffiti-cross-spray.php   # magic link + cookie + xs path
```

(Not every file exists yet; run `ls tests/test-graffiti-*.php` for the
actual set on your branch.)

---

## Plan archive

The phased buildout lives in
`plans/260624-1838-lazyblog-graffiti-gamification/`. Useful when
auditing why a particular concern got designed the way it did.
