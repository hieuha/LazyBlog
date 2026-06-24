# Graffiti

Cross-blog sticker exchange between LazyBlog friends.

> **Status:** scaffold only. Phase 1 of the implementation plan
> (`plans/260624-1838-lazyblog-graffiti-gamification/`). Routes register
> and render placeholder tabs; the actual send / receive / energy / friend
> handshake / overlay render land in Phases 2–8.

## What it does

When fully built, friends who run their own LazyBlog instance can leave
text messages, stickers, and spray-paint marks on each other's posts.
Writing a published post mints **energy**; sending graffiti spends it.
Targets independently rate-limit per friend (default 5 / 24h).

## Enable

```bash
# in .env
PLUGINS=graffiti              # or comma-list with others: hello-world,graffiti
```

After restart, an admin-only `[ GRAFFITI ]` link appears in the public
header navbar (visible only when you have an admin session). Anonymous
visitors never see this entry.

## Trust model

- Every friendship has its own pair of secret tokens (32 random bytes
  each). Tokens are exchanged out-of-band (copy-paste through your own
  chat — Signal, Telegram, email, …). HTTPS is required on both ends.
- Revoking one friend never affects others — independent per-friend
  tokens, not a shared global secret.
- The receiving blog is the single source of truth: it validates the
  incoming token, looks up the friend, enforces its own rate limit, and
  may hide any individual item from `/admin/graffiti`.

## Storage layout

Plugin-private storage lives under `content/plugins/graffiti/`:

```
content/plugins/graffiti/
├── friends.json        # per-friend token pair + state
├── graffiti.json       # received items (append-only; hide flag for mod)
├── energy.json         # balance + ledger
├── outbox.json         # send queue with retry state
├── stickers.json       # operator overrides on shipped catalogue
└── nonces.json         # 24h replay-protection cache
```

The shipped default catalogue is at
`plugins/graffiti/content/stickers.json`; first boot copies it into
storage. After that, the storage copy is canonical — operator edits
survive plugin upgrades.

## Disable

Remove `graffiti` from `PLUGINS=`. Routes vanish, navbar entry vanishes.
Your `content/plugins/graffiti/` data is preserved so re-enabling
restores everything.

## Tests

```bash
php tests/test-graffiti-boot.php   # scaffolding + boot
# more tests land per phase: friends, energy, inbox, rate-limit, outbox,
# render, moderation
```

## Plan

See `plans/260624-1838-lazyblog-graffiti-gamification/plan.md` for the
full phase breakdown and acceptance criteria.
