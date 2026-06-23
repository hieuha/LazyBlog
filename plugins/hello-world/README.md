# hello-world — LazyBlog reference plugin

Canonical example. Copy this folder and rename to start your own plugin.

## Enable

Add to `.env`:

```ini
PLUGINS="hello-world"
```

Then visit `/hello`.

## What it shows

| Surface | Where |
|---|---|
| Public GET route | `GET /hello` — renders `views/index.php` through main layout |
| Public POST route | `POST /hello/echo` — CSRF-checked, writes storage |
| Header nav link | `[ HELLO ]` — added between `[ ABOUT ]` and `[ ADMIN ]` |
| Route-scoped CSS | `assets/style.css` — loads only on `/hello*` |
| Route-scoped JS | `assets/script.js` — loads only on `/hello*` |
| Admin page | `GET /admin/hello-world` — auth-gated list of echoes |
| Private storage | `content/plugins/hello-world/echoes.json` |

## Folder layout

```
plugins/hello-world/
├── manifest.json                 # name, version, api_version, namespace
├── plugin.php                    # entry point — returns Plugin instance
├── src/
│   └── HelloWorldPlugin.php      # implements App\Plugin
├── views/
│   ├── index.php                 # public page template
│   └── admin.php                 # admin page template
├── assets/
│   ├── style.css                 # served at /plugin-assets/hello-world/style.css
│   └── script.js                 # served at /plugin-assets/hello-world/script.js
├── content/                      # plugin-private storage (gitignored)
└── README.md                     # this file
```

## Key API calls

Inside `register(PluginContext $ctx)`:

```php
$ctx->css('style.css');                            // route-scoped asset
$ctx->js('script.js');
$ctx->nav('Hello', '/hello', 'header');            // header or footer
$ctx->get('/hello',     fn () => $this->index());
$ctx->post('/hello/echo', fn () => $this->submit());
$ctx->adminGet('/admin/hello-world', fn () => $this->admin());
```

Admin routes MUST live under `/admin/{your-slug}`. They get auto-wrapped
with `Auth::requireAuth()` — no manual gating needed.

## Security checklist

- Every plugin POST handler MUST start with `Csrf::requireValid();`
- Every form MUST include `<input type="hidden" name="_csrf" value="<?= Http::e(Csrf::token()) ?>">`
- All user input escaped with `Http::e(...)` before rendering
- Writes ONLY under `$ctx->storagePath()` (which resolves to `content/plugins/your-slug/`)
- Plugin assets are served same-origin so CSP is satisfied. Do NOT
  ship inline `<script>` or load external CDNs — the site CSP blocks
  them and your operator can't relax it from a plugin.

## What to read next

- `docs/plugin-development.md` — full author guide
- LazyBlog `README.md` and `docs/configuration.md` for the surrounding setup

## License

MIT — same as LazyBlog.
