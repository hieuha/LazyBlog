# Plugin development

LazyBlog plugins are opt-in folders that add routes, nav links, route-scoped
assets, admin pages, and private storage to the site. They are NOT bundled
into core — operators enable them with the `PLUGINS` env var.

This guide is everything a third-party author needs to ship one. The reference
implementation lives at `plugins/hello-world/` and demonstrates every surface.

## Trust model

> A plugin runs as PHP inside the same process as core. It has full access to
> the filesystem, sessions, and outbound network. There is no sandbox.
> Operators MUST vet a plugin's source before adding it to `PLUGINS=`.

If you publish a plugin: pin a license, sign tags, and document what it
touches. If you install a plugin: read the code first.

## Folder layout

```
plugins/{slug}/
├── manifest.json                # required — name, version, namespace, api_version
├── plugin.php                   # required — must `return new YourPlugin();`
├── src/
│   └── YourPlugin.php           # implements App\Plugin (PSR-4 from manifest namespace)
├── views/
│   ├── *.php                    # rendered via $ctx->view('name', $data)
├── assets/
│   ├── *.css                    # served at /plugin-assets/{slug}/{file}
│   └── *.js
├── content/                     # ignored — plugin-private storage at runtime
└── README.md                    # plugin docs
```

`{slug}` must match `^[a-z][a-z0-9-]*$` and match the `slug` field in the
manifest. It is the URL prefix for the plugin's admin namespace
(`/admin/{slug}/...`) and the path segment in asset URLs.

## `manifest.json`

| Field | Type | Required | Purpose |
|-------|------|----------|---------|
| `slug` | string | yes | Lowercase kebab. Must match folder name and the field in `PLUGINS=` |
| `name` | string | yes | Human-readable plugin name |
| `version` | string | yes | Plugin's own version. Free-form (semver recommended) |
| `api_version` | int | yes | LazyBlog plugin API major version. Currently `1` |
| `namespace` | string | yes | PSR-4 namespace prefix mapped to `src/`. Example: `"Plugins\\HelloWorld"` |
| `author` | string | no | Author name or handle |
| `description` | string | no | One-line summary shown in `/admin` |
| `homepage` | string | no | Source or docs URL |

A wrong `api_version` causes the plugin to be skipped at boot with a logged
warning. The site stays up.

## The `App\Plugin` interface

```php
namespace App;

interface Plugin {
    public function manifest(): PluginManifest;
    public function register(PluginContext $ctx): void;
}
```

`manifest()` is called for introspection; the canonical implementation
re-reads `manifest.json`. `register()` is called once at boot — use it to
declare every surface the plugin contributes.

## The `App\PluginContext` API

The context is the ONLY stable surface. Method signatures will not break
between minor versions; bumping `api_version` is reserved for incompatible
changes.

| Method | Purpose |
|--------|---------|
| `$ctx->get($pattern, $handler)` | Public GET route |
| `$ctx->post($pattern, $handler)` | Public POST route |
| `$ctx->adminGet($pattern, $handler)` | Admin GET, auto-wrapped with `Auth::requireAuth()` |
| `$ctx->adminPost($pattern, $handler)` | Admin POST, auto-wrapped with `Auth::requireAuth()` |
| `$ctx->nav($label, $href, $placement = 'header')` | Header or footer nav link |
| `$ctx->css($file)` | Route-scoped CSS — loads on routes registered by this plugin |
| `$ctx->js($file)` | Route-scoped JS |
| `$ctx->view($name, $data)` | Render `views/{name}.php` through the main layout |
| `$ctx->storagePath()` | Absolute path to `content/plugins/{slug}/` (created lazily) |
| `$ctx->csrf()` | Returns `App\Csrf::class` — use static helpers on it |
| `$ctx->auth()` | Returns `App\Auth::class` |
| `$ctx->onPostView($listener)` | Subscribe to `post.view` event (fired before response body flushed; listeners may call `setcookie()`/`header()`) |
| `$ctx->onPostMeta($renderer)` | Contribute HTML fragment to `post.meta` slot (rendered inline in transmission tag line) |

### Routing rules

- Patterns use the same `{name}` placeholder syntax as core (`Router` doc).
- Placeholders match a single path segment (`[^/]+`). No multi-segment routes.
- Public routes may NOT collide with reserved core prefixes: `/`, `/admin`,
  `/posts`, `/tags`, `/series`, `/archive`, `/search`, `/about`, `/feed.xml`,
  `/llms.txt`, `/llms-full.txt`, `/plugin-assets`, `/healthz`. Collisions are
  logged and skipped.
- Admin routes MUST start with `/admin/{your-slug}`. Anything else is rejected.

### Asset rules (v1)

- Files live under `plugins/{slug}/assets/` — flat layout, NO subdirectories.
- Allowed extensions: `css`, `js`, `mjs`, `svg`, `png`, `webp`, `jpg`, `jpeg`, `gif`, `woff2`.
- URLs are served as `/plugin-assets/{slug}/{file}?v=<mtime>`. Cache headers:
  `Cache-Control: public, max-age=31536000, immutable`.
- Plugin assets count as same-origin under the site CSP — no policy changes
  needed for `'self'`-served resources.

## Event hooks

Plugins can subscribe to lifecycle events and contribute content fragments.

### `onPostView` — React to post views

Called once per `GET /posts/{slug}` render, **before the response body is flushed**.
Listeners receive a `PostViewEvent` and may call `setcookie()` or `header()` safely.
Listener exceptions are caught and logged (never 500s the page).

```php
$ctx->onPostView(function (App\PostViewEvent $event): void {
    setcookie('lz_uid', '...', ['httponly' => true]);
    // $event->slug, $event->userAgent, $event->requestTime available
});
```

### `onPostMeta` — Contribute post metadata fragment

Called once per post render; contributes plain HTML to the transmission tag line
(the `§ TRANSMISSION — DATE` line). Renderer receives `['slug' => $slug]` and
must return a string or null.  Exceptions are caught and logged.

```php
$ctx->onPostMeta(function (array $context): ?string {
    $slug = (string) ($context['slug'] ?? '');
    $count = getViewCount($slug); // your logic
    return $count > 0 ? "{$count} views" : null;
});
```

## CSP — what you cannot do

LazyBlog ships a strict CSP set in `public/index.php`. Plugins CANNOT relax
it. Concretely:

- NO inline `<script>` in your views. Externalize all JS into `assets/`.
- NO external script CDNs. Vendor what you need into `assets/`.
- NO inline `<style>` blocks. Use `assets/{file}.css`.
- NO `<iframe>` outside the already-allowed YouTube hosts.

If an operator legitimately wants to whitelist an external host for your
plugin, they edit `public/index.php` themselves. A plugin must not document
"please loosen your CSP" as part of its happy path.

## CSRF — mandatory on every POST

Every state-changing handler MUST verify CSRF first:

```php
private function submit(): void {
    \App\Csrf::requireValid();   // 403 + exit on mismatch
    // ...handle...
}
```

Every form MUST include the token:

```php
<input type="hidden" name="_csrf" value="<?= App\Http::e(App\Csrf::token()) ?>">
```

The reference plugin's `views/index.php` shows the pattern.

## Storage

`$ctx->storagePath()` returns the absolute path to
`content/plugins/{slug}/`, created on first call. Write all persistent state
there. Anything else is your problem to clean up.

Use `LOCK_EX` on `file_put_contents` for any file you write more than once
to be safe under concurrent admin sessions.

## Views

```php
$ctx->view('index', [
    'title' => 'My page',
    'echoes' => $rows,
    'csrf' => \App\Csrf::token(),
]);
```

The view file lives at `plugins/{slug}/views/index.php`. The `$data` array
is `extract()`-ed into scope. Always escape with `\App\Http::e()`.

## Enabling

`.env`:

```ini
PLUGINS="slug-one,slug-two,slug-three"
```

Order matters only for collision resolution: the first plugin to register
a pattern wins.

## Failure modes — all caught, all logged

| Cause | Result |
|-------|--------|
| Slug in `PLUGINS=` but folder missing | log + skip, site continues |
| `manifest.json` missing or invalid JSON | log + skip |
| Manifest `slug` does not match folder name | log + skip |
| Unsupported `api_version` | log + skip |
| `plugin.php` missing or does not return a `Plugin` instance | log + skip |
| `register()` throws | log + skip |
| Route collides with reserved core prefix | log + skip that route |
| Route already registered by another plugin | log + skip (second loses) |
| Admin route outside plugin's namespace | log + skip |

Errors go to `error_log()`. A broken plugin never takes the site down.

## Worked example — `/now` page from markdown

Bare minimum: read a markdown file and render it through the layout.

`plugins/now-page/manifest.json`:

```json
{
    "slug": "now-page",
    "name": "Now Page",
    "version": "1.0.0",
    "api_version": 1,
    "namespace": "Plugins\\NowPage"
}
```

`plugins/now-page/plugin.php`:

```php
<?php
require_once __DIR__ . '/src/NowPagePlugin.php';
return new Plugins\NowPage\NowPagePlugin();
```

`plugins/now-page/src/NowPagePlugin.php`:

```php
<?php
namespace Plugins\NowPage;

use App\MarkdownRenderer;
use App\Plugin;
use App\PluginContext;
use App\PluginManifest;

final class NowPagePlugin implements Plugin
{
    public function manifest(): PluginManifest
    {
        return PluginManifest::fromArray(
            json_decode((string) file_get_contents(__DIR__ . '/../manifest.json'), true)
        );
    }

    public function register(PluginContext $ctx): void
    {
        $ctx->nav('Now', '/now', 'header');
        $ctx->get('/now', function () use ($ctx) {
            $src = $ctx->storagePath() . '/now.md';
            $body = is_file($src) ? (string) file_get_contents($src) : '# now\n\nNothing yet.';
            $rendered = (new MarkdownRenderer())->render($body);
            $ctx->view('show', ['title' => '/now', 'html' => $rendered['html']]);
        });
    }
}
```

`plugins/now-page/views/show.php`:

```php
<article class="now-page">
    <h1 class="post-page-title">// NOW</h1>
    <?= $html ?>
</article>
```

Enable with `PLUGINS="now-page"` and drop a markdown file at
`content/plugins/now-page/now.md`.

## Distribution

Publish your plugin as its own git repo. Operators install with:

```bash
git clone https://github.com/you/lazyblog-plugin-foo plugins/foo
# edit .env: PLUGINS="foo"
```

That is the whole install procedure. There is no plugin registry.

## What plugins cannot do in v1

- Hook into the markdown render pipeline (filters, syntax extensions, badges)
- Inject server-side widgets into core templates
- Modify the site CSP
- Schedule background tasks or cron
- Ship Composer dependencies (use only what core provides; vendor your own
  if you really must — but understand the implications for operator audit)
- Be loaded twice from different folders
- Override core routes

These are intentional v1 cuts and may be revisited in a future
`api_version: 2`.

## Pre-ship checklist

- [ ] `manifest.json` validates as JSON and parses with `PluginManifest::fromArray`
- [ ] Every POST handler calls `Csrf::requireValid()` first
- [ ] Every form includes the `_csrf` hidden input
- [ ] Every user input is escaped with `Http::e()` before rendering
- [ ] Storage writes only happen under `$ctx->storagePath()`
- [ ] No inline scripts or styles in views; no external CDNs in assets
- [ ] Admin routes live under `/admin/{your-slug}`
- [ ] Plugin enables cleanly with `PLUGINS="{your-slug}"` and disables
      cleanly with `PLUGINS=""`
- [ ] Plugin README documents what state it writes, what permissions it
      assumes, and how to uninstall (`rm -rf plugins/{slug}` + remove from
      `PLUGINS=`)
