# Passwordless Login (FIDO2 / WebAuthn)

LazyBlog admin can sign in with a hardware security key (Yubikey, SoloKey,
Nitrokey…) or a platform Passkey (iPhone Face ID, macOS Touch ID, Windows
Hello). No password to type, no password to leak. Opt-in via one env var.

## TL;DR

```bash
# 1. Make sure you can still log in with the password first.
# 2. Set WEBAUTHN=true in .env (just so /admin/security renders the right hints).
# 3. Visit /admin/security → register your Yubikey (and a backup key).
# 4. Log out, reload — /admin/login now shows [ TAP YOUR SECURITY KEY ].
```

## Why bother?

| Concern                             | Password           | FIDO2 / WebAuthn        |
|-------------------------------------|--------------------|-------------------------|
| Server stores reusable secret       | bcrypt hash        | only public key         |
| Phishable                           | yes                | no (origin-bound)       |
| Reusable across sites               | password fatigue   | no — each site gets its own keypair |
| Replay of captured login            | yes (until expiry) | rejected (counter monotonic) |
| Cost of compromise                  | password reuse risk| zero — public key is useless without the key |
| Loss recovery                       | password reset email | second key, env flip, or SSH wipe |

The win is mostly **phishing resistance** + **no shared secret**. For a
single-operator blog the daily UX upgrade (tap > type) is the obvious
benefit.

## Requirements

- LazyBlog ≥ 1.20 (the release that bundles `lbuchs/webauthn`)
- PHP 8.2+ (already required)
- HTTPS in production (browsers refuse WebAuthn on plain HTTP except
  `localhost`)
- A FIDO2 authenticator:
  - **Hardware key**: Yubikey 5 series, SoloKey, Nitrokey 3, Token2 — any
    FIDO2/CTAP2 device works
  - **Platform Passkey**: iOS 16+ (Face ID / Touch ID + iCloud), macOS Big
    Sur+ (Touch ID + iCloud), Android 9+ (Google Password Manager),
    Windows Hello
- A modern browser: Chrome 67+, Firefox 60+, Safari 14+, Edge 18+

## Installation

WebAuthn ships as part of LazyBlog ≥ 1.20. Upgrading from 1.19.x or
earlier needs one pass of dependency install + php-fpm reload before
the env flag does anything.

### Step 0. Pull the upgrade

Pick the path that matches your deploy.

**Bare-metal `git pull`:**

```bash
cd /var/www/lazyblog          # or wherever you cloned
git fetch origin && git checkout v1.20.0

# Pull the new lbuchs/webauthn dep into vendor/
composer install --no-dev --optimize-autoloader

# Reload php-fpm so the new class autoload + env are picked up.
# Match the unit name to your distro (`systemctl list-units 'php*-fpm.service'`).
sudo systemctl reload php8.2-fpm   # or php-fpm, php8.3-fpm, etc.
```

**Docker (`Dockerfile.prod`):**

```bash
# composer install runs at build time — just rebuild the image.
docker compose pull              # if you publish a registry image
# OR
docker compose build --no-cache  # if you build locally
docker compose up -d
```

**Smoke test:**

```bash
curl -s https://your-blog.example.com/healthz
# expect: ok 1.20.0
```

If it still prints `ok 1.19.0`, php-fpm hasn't reloaded — old OPcache
or process pool still serving. Force `sudo systemctl restart php8.2-fpm`
(restart, not reload) to evict cached bytecode.

### Step 1. Set the env flag

In your project `.env`:

```dotenv
WEBAUTHN="true"

# Optional but recommended in production:
# Pin the Relying Party ID to your canonical host so a future domain
# migration doesn't invalidate existing credentials. Default = HTTP_HOST.
# WEBAUTHN_RP_ID="blog.example.com"
```

Reload php-fpm (or whatever runs PHP) so the new env is picked up.

### Step 2. Log in with your password (one last time)

Visit `/admin/login`, sign in with the password as usual.

Because `WEBAUTHN=true` but no keys are registered yet, LazyBlog falls
back to **bootstrap mode**: the password form still works, with a hint
reminding you to register a key. This prevents lockout if you flip the
env flag before registering.

### Step 3. Register your first key

Visit `/admin/security`. Click **[ + ADD KEY ]**, give the key a nickname
(e.g. *"Yubikey 5C primary"*), tap **[ + REGISTER ]**, then:

- **Yubikey**: insert into USB port, tap the gold disc when it blinks
- **iPhone**: choose *"This device"* → Face ID / Touch ID
- **Mac**: Touch ID prompt appears

The page reloads with the key listed. Status pill shows *"Never used"*
until your first WebAuthn login.

### Step 4. Register a backup key

**Strongly recommended.** Lose one key → second key still works. Repeat
Step 3 with the backup. Common pairings:

- Primary Yubikey on your keychain + Backup Yubikey in your desk drawer
- Yubikey + iCloud Passkey (Touch ID from any Apple device)
- iCloud Passkey + Google Password Manager Passkey (cross-platform)

### Step 5. Log out and verify

Click **[ LOG OUT ]** in the admin header. Visit `/admin/login`. The
password form is replaced with **[ TAP YOUR SECURITY KEY ]**.

Tap your key — you're in.

## Daily use

### Logging in

`/admin/login` → tap **[ TAP YOUR SECURITY KEY ]** → tap your authenticator.
That's it. No nickname needed; the authenticator self-identifies via the
credentialId it stored at registration time.

If you have multiple keys, the browser may offer a chooser. Pick whichever
you have physically nearby.

### Adding more keys

`/admin/security` → **[ + ADD KEY ]** → register. Same flow as bootstrap.

Each key gets its own row in the table with:
- Operator-chosen nickname
- Transports advertised (`usb`, `nfc`, `internal`, `hybrid`)
- ADDED + LAST USED timestamps (timezone-aware)
- Status pill: *Active* (used in last 30 days), *Idle*, *Never used*

### Revoking a key

`/admin/security` → click **REVOKE** next to the key → confirm.

**Last-key guard**: when `WEBAUTHN=true`, you cannot revoke the very last
key — the button is disabled and the server rejects the request too.
This stops you from accidentally locking yourself out. To go back to
password mode, flip `WEBAUTHN=false` first, then revoke.

## Recovery (lost all keys)

You have **three paths** out, ordered by preference:

### Path A — Tap your backup key (best)

This is why you registered a second key in Step 4. Tap the backup, log in,
go to `/admin/security`, revoke the lost key, register a replacement.

### Path B — SSH and flip the env flag

```bash
ssh you@your-server
cd /path/to/lazyblog
sed -i 's/^WEBAUTHN=.*/WEBAUTHN="false"/' .env
# reload php-fpm
sudo systemctl reload php-fpm  # or your supervisor of choice
```

Now `/admin/login` shows the password form again. Existing keys stay
registered but inactive. Log in with the password, fix your key situation
at `/admin/security`, then flip `WEBAUTHN=true` again when ready.

### Path C — Wipe the credential file

```bash
ssh you@your-server
cd /path/to/lazyblog
rm content/admin/webauthn-credentials.json
```

Next request to `/admin/login` sees 0 keys + `WEBAUTHN=true` → bootstrap
mode → password form. Log in, re-register from scratch.

### `ADMIN_PASSWORD_HASH` is your final break-glass

**Keep it set.** Even if you switch to passwordless, an attacker who steals
the password hash file still cannot brute-force their way in via
`/admin/login` because the password endpoint is blocked when
`WEBAUTHN=true` AND keys are registered. The hash only matters during
recovery — and only after you've already pulled SSH access.

If you must rotate the password hash, run:

```bash
docker compose exec app php scripts/hash-password.php "newpassword"
# paste the output into ADMIN_PASSWORD_HASH in .env
```

## Security guarantees (and what they aren't)

### What WebAuthn enforces for you

- **Origin / RP ID binding** — every assertion is signed against the
  authenticator's stored Relying Party ID (your blog domain). A phishing
  page at `evil.example.com` cannot harvest a working assertion because
  the browser refuses to ask the authenticator to sign for a domain
  mismatch. Server-side the lib double-checks `clientDataJSON.origin` and
  the signed `authenticatorData.rpIdHash`.
- **No shared secret on the server** — `webauthn-credentials.json` only
  holds public keys + counters. Leaking the file does not enable login.
- **Replay defense** — each successful assertion increments a signature
  counter. Replay of a captured assertion fails the counter check.
- **Per-site keypair** — the same Yubikey on GitHub and on LazyBlog gets
  two unrelated keypairs. Neither site can identify the other's
  credentialId. Privacy preserving.

### What WebAuthn does NOT protect against

- **Compromised server** — an attacker with shell access can `rm` the
  credentials file and flip `WEBAUTHN=false`, then brute-force the
  password. Treat SSH access as the ultimate root of trust. Keep
  `ADMIN_PASSWORD_HASH` strong even after going passwordless.
- **Hijacked browser session** — if your laptop is unlocked and your
  cookie is stolen, the attacker is already inside. Use
  `SESSION_SECURE=true` in production, lock your screen, and rotate the
  session if you suspect theft (log out + log back in regenerates the ID).
- **Physical theft of an unlocked Yubikey** — a Yubikey 5 with no PIN
  auto-authorizes on touch. If your threat model includes someone
  grabbing your keychain, use **Yubikey 5 FIPS** or **Yubikey Bio** with a
  PIN, or rely on Touch ID / Face ID Passkeys which require biometric
  verification per assertion.
- **Resetting the authenticator** — wiping a Yubikey via Yubico Authenticator
  destroys all credentials it holds. Make sure you can recover via Path A/B/C
  before doing that.

## Common pitfalls

### Don't reuse `content/admin/webauthn-credentials.json` across deploys

Each credential is bound to the RP ID that was active **at registration
time**. Cloning the file from prod to staging where the RP ID differs
will silently produce a file full of credentials that nothing on
staging can verify. Each environment registers its own keys.

### Don't use a bare shared-hosting hostname as RP ID

If you deploy on `something.shared-hosting.com` and that's your RP ID,
other tenants on the same suffix can register credentials that the
browser will offer for your origin too. Always use a domain you control
(your own subdomain on a hosting provider, or your apex domain).

### Don't forget HTTPS in production

`navigator.credentials.create()` throws `NotAllowedError` on plain HTTP
(except `localhost`). Caddy in the shipped Caddyfile.example already
handles TLS — if you swap reverse proxies make sure HTTPS is intact.

### Don't change `WEBAUTHN_RP_ID` after registering keys

The authenticator signs `rpIdHash = SHA-256(rpId at registration)`. Server
checks against `SHA-256(rpId from current config)`. Mismatch → assertion
rejected. If you must migrate domains:

1. Decide your final canonical hostname (e.g. `blog.example.com`)
2. Pin via `WEBAUTHN_RP_ID="blog.example.com"` **before** registering keys
3. Migrate — credentials survive any path/scheme change as long as RP ID
   stays put

### Don't trust `transports` for security decisions

`transports: ['internal']` ≠ "Touch ID was actually used". The client
self-reports transports; an attacker could lie. Use `userVerification`
flags signed by the authenticator instead if your policy needs that.

LazyBlog only uses `transports` cosmetically (the TYPE column on
`/admin/security`).

### Don't expect to see the Yubikey serial number

FIDO2 is **anti-tracking by design**. Authenticators never expose unique
device identifiers to relying parties. The most you get is:
- AAGUID (16-byte UUID identifying the make/model — same for every
  Yubikey 5C NFC ever sold)
- The transports it advertised

Distinguish your keys with nicknames, not serials.

## Troubleshooting

### "Session expired — reload the page and try again."

Server-side challenge TTL is 60 seconds. If you tapped the button, walked
away, came back, and tapped your key — you exceeded the window. Reload
the page, then tap.

### "No security keys registered."

`/admin/login` is in WebAuthn mode but the credentials file has 0
entries. Either you're in fresh bootstrap state (use the password form),
or the file got deleted. Path C in the recovery section restores
behavior cleanly.

### "Too many attempts. Try again in 15 minutes."

Per-IP throttle (10 fails / 15 min) — same one shared with password
login. Set `TRUST_CF_CONNECTING_IP=true` if behind Cloudflare so the
counter tracks real visitors instead of edge IPs.

### Browser pops up but my Yubikey doesn't blink

- Make sure the key is inserted firmly (USB-A vs USB-C confusion is real)
- Try a different USB port (some hubs eat HID traffic)
- Try a different browser tab — sometimes the WebAuthn prompt fires on a
  background tab and you miss it

### "Malformed request." with no useful detail

The lib threw something specific but `publicErrorMessage()` mapped it to
a neutral string to avoid leaking internals. Check the PHP error log
(`error_log` destination per `php.ini`) — the full exception is logged
there:

```
webauthn: lbuchs\WebAuthn\WebAuthnException: signature counter went down
```

### iPhone Passkey doesn't appear

- iOS 16+ required (iCloud Keychain Passkey support)
- Sign in to iCloud, enable Keychain sync
- Safari only — Chrome on iOS uses Safari's WebKit but Passkey UI is
  Safari-specific

### iPhone NFC says "no credential" even though you registered the Yubikey

The Yubikey is fine and the registration completed — it's the **stored
credentials are bound to a different RP ID than the one iPhone is
hitting**. Usually one of:

- The Yubikey was registered on `localhost` (or a dev tunnel hostname)
  during testing, and the credential entry stayed in
  `content/admin/webauthn-credentials.json` after you deployed to prod
- Server is now serving `https://www.example.com` but the entries were
  registered at `https://example.com` (no `www.`) — `rpIdHash` differs
- `WEBAUTHN_RP_ID` env was added/changed after the keys were registered

Browser doesn't show "RP ID mismatch" — it reports the generic "no
credential" because the authenticator searches its memory for a
credential whose RP ID hash matches the current request, finds none,
and returns empty.

**Recovery (path B from the section above):**

1. SSH the server, set `WEBAUTHN=false` in `.env`, reload php-fpm
2. Log in via password from the actual production URL (the one iPhone
   uses)
3. Go to `/admin/security` and **revoke the stale entries** — they were
   bound to the old hostname and will never authenticate again
4. **Register the Yubikey fresh** at this URL — now the RP ID matches
   the iPhone's
5. Flip `WEBAUTHN=true` back, reload php-fpm
6. Test NFC tap on iPhone — should work

If you want to avoid this trap on future migrations, pin
`WEBAUTHN_RP_ID="example.com"` in `.env` **before** registering any
key, so the binding survives changes to `HTTP_HOST` (eg. www / non-www
switches, dev tunnels, port forwards).

### Lost ALL keys + cannot SSH

You're in deep trouble — design your recovery so this never happens.
At this point: restore from backup the prior `.env` (with WEBAUTHN=false)
+ the previous `content/admin/webauthn-credentials.json` (or wipe it).
See `docs/backup-and-restore.md`.

## Reference

### Env vars

| Var                       | Default        | Meaning                                                                  |
|---------------------------|----------------|--------------------------------------------------------------------------|
| `WEBAUTHN`                | `false`        | `true` → /admin/login uses tap-key flow when ≥1 key registered           |
| `WEBAUTHN_RP_ID`          | `HTTP_HOST`    | Pin RP ID for cross-deploy stability                                     |
| `ADMIN_PASSWORD_HASH`     | (required)     | Break-glass + bootstrap. Always keep set                                 |
| `TRUST_CF_CONNECTING_IP`  | `false`        | Use `CF-Connecting-IP` for rate-limit (only when actually behind CF)    |
| `SESSION_SECURE`          | `false`        | `true` in production (HTTPS) — enables `__Host-` cookie prefix          |

### Files

| Path                                       | Role                                                |
|--------------------------------------------|-----------------------------------------------------|
| `content/admin/webauthn-credentials.json`  | Credential store. **Gitignored**. Never commit      |
| `content/admin/.gitignore`                 | Excludes the credentials file                       |
| `src/WebAuthn.php`                         | Wrapper around lbuchs/webauthn                      |
| `src/WebAuthnCredential.php`               | DTO                                                 |
| `src/WebAuthnCredentialStore.php`          | Atomic JSON I/O                                     |
| `src/Controllers/AdminSecurityController.php` | HTTP handlers (register, login, revoke)          |
| `views/admin/security.php`                 | /admin/security page                                |
| `views/admin/login-webauthn.php`           | Tap-key login view                                  |
| `public/assets/admin-security.js`          | WebAuthn API calls                                  |

### Endpoints

| Method | Path                                    | Auth | CSRF | Rate-limit |
|--------|-----------------------------------------|------|------|------------|
| GET    | `/admin/security`                       | yes  | —    | —          |
| POST   | `/admin/security/revoke/{id}`           | yes  | yes  | —          |
| POST   | `/admin/webauthn/register/begin`        | yes  | yes  | shared 10/15min |
| POST   | `/admin/webauthn/register/complete`     | yes  | yes  | shared 10/15min |
| POST   | `/admin/webauthn/login/begin`           | **no** (this IS auth) | yes | shared 10/15min |
| POST   | `/admin/webauthn/login/complete`        | **no** | yes | shared 10/15min |

JSON request bodies are capped at 64 KB. Failed attempts (any of the
above except register-success) record into the same per-IP throttle as
password login.

### Related docs

- `docs/security.md` — overall threat model, session hardening, CSP, plugin sandboxing
- `docs/backup-and-restore.md` — what to back up (include `content/admin/`)
- `docs/configuration.md` — full env var catalogue
- Library: `lbuchs/webauthn` v2.x — `https://github.com/lbuchs/WebAuthn`
- WebAuthn spec: `https://www.w3.org/TR/webauthn-3/`
