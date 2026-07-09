# fax — highlight-to-fax for LazyBlog

Let readers highlight a passage on any post and fax it straight to your real
fax machine via the [FaxxMe](https://github.com/hieuha/LazyFaxxMee) inbound
webhook.

## Enable

Add the slug to `.env` (this is the on/off switch):

```ini
PLUGINS="fax"
```

Then open **`/admin/fax`** and paste your webhook token. The reader-facing
button only appears on posts once a token is set — an unconfigured plugin adds
zero markup to your pages.

## How it works

| Surface | Where |
|---|---|
| Reader UI | Injected on `GET /posts/{slug}` — a "📠 Fax this" pill next to any text selection, expanding into a comment + name + Send card |
| Public POST | `POST /fax/send` — proxies to the webhook with your secret token (never exposed to the browser) |
| Admin page | `GET /admin/fax` — set token + endpoint, send a test fax. Reached from the admin **PLUGINS** tab (`/admin?tab=plugins` → OPEN); no header nav link |
| Admin save | `POST /admin/fax/save`, `POST /admin/fax/test` |
| Private storage | `content/plugins/fax/config.json` |

The highlighted quote and the reader's comment (required) are merged into the
fax `body`; their name becomes `name`, defaulting to `anonymous` when left
blank; the post title and canonical URL are resolved **server-side** from the
slug (so attribution can't be spoofed) and sent as `post` + `url`. A send with
an empty comment is rejected both in the browser and with a 400 from `/fax/send`.

## Configuration

All config lives in `/admin/fax`, not in env:

- **Webhook token** — the `fxwh_…` bearer secret from FaxxMe. Stored in
  `content/plugins/fax/config.json`, kept server-side.
- **Webhook endpoint** — full HTTPS URL. Defaults to the public FaxxMe
  endpoint (`https://fax.hatrunghieu.com/api/fax/inbound`) if left blank.

## Rate limiting & the "out of paper / out of ink" message

There is **no local send cap**. The webhook already rate-limits per author and
per calling-site IP (5 faxes / 300s by default). When a reader hits that limit
the webhook returns `429`, which this plugin turns into a light-hearted "the
fax machine needs a nap" toast rather than a scary error.

Field limits mirror the webhook contract and are clamped before sending:
`body` ≤ 500, `name` ≤ 40, `post` ≤ 120, `url` ≤ 200 chars. The fax `body` is
the highlighted quote + the reader's comment, sharing one 500-char budget. A
single counter lives in the comment box's bottom-right corner and shows the
combined usage as `(quoteLen + commentLen)/500`; the comment's own max shrinks
with the quote (`500 − quoteLen`) so the two never exceed 500. The name field
is simply hard-capped at 40 with no counter. Server-side `composeBody` still
clamps the merged body to 500 as a backstop.

## Folder layout

```
plugins/fax/
├── manifest.json          # name, version, api_version, namespace
├── plugin.php             # entry point — returns Plugin instance
├── src/
│   ├── FaxPlugin.php      # routes, reader UI injection, send proxy
│   ├── FaxSettings.php    # config.json read/write (token + endpoint)
│   └── FaxSender.php      # form-encoded bearer POST to the webhook
├── views/
│   └── admin.php          # settings + test-fax form
├── assets/
│   ├── fax.css            # selection pill / card / toast (themed via tokens)
│   └── fax.js             # selection detection + send
└── README.md              # this file
```

## Security notes

- `POST /fax/send` is public (readers have no admin session) and therefore
  **CSRF-exempt by design** — abuse is bounded by the webhook's own per-IP
  rate limit, not a local token. Admin routes (`save`, `test`) are CSRF-checked.
- The bearer token never reaches the browser; only `/fax/send` sees it.
- The endpoint must be HTTPS (`FaxSender` refuses anything else).
- All admin output is escaped with `Http::e(...)`; the reader's selected text
  is inserted via `textContent`, never `innerHTML`.

## License

MIT — same as LazyBlog.
