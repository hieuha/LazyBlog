---
title: Plugin system — brainstorm + plan
date: 2026-06-23
type: design
status: planning-complete
---

# Plugin system — brainstorm + plan

## What

Designed a plugin system for LazyBlog so optional features (guestbook, /now, /uses, contact form, etc.) can ship as opt-in folders instead of bloating core. No code written yet — brainstorm + plan only.

- Brainstorm: `plans/reports/brainstorm-260623-1909-plugin-system.md`
- Plan: `plans/260623-1909-plugin-system/` (plan.md + phase-01..05)

## Decisions locked

| Question | Answer |
|---|---|
| Plugin authors | Third-party allowed (hybrid: ship hello-world first-party as canonical example, anyone can drop folder + `git clone` to install) |
| API style | Class implementing `App\Plugin`, receives `App\PluginContext` in `register()` |
| Plugin surfaces | Routes (GET/POST), nav (header/footer), CSS/JS assets (route-scoped), admin pages (auto-wrapped with `Auth::requireAuth()`), private storage at `content/plugins/{slug}/` |
| Enable mechanism | `.env` `PLUGINS=slug1,slug2` (CSV) |
| Asset serving | PHP route `/plugin-assets/{slug}/{path}` with extension allowlist + path-traversal guard + mtime cache-bust |
| API versioning | `manifest.json` declares `api_version`; supported = `[1]`; mismatch = skip + warn |
| Trust model | Full PHP access. No sandbox. Documented loudly. |
| First reference plugin | `hello-world` — demonstrates all surfaces (public GET, public POST + CSRF, header nav, admin page, storage write) |
| Boot resilience | try/catch each plugin's `register()`; broken plugin = log + skip + site stays up |

## Cut from scope (v1)

- Render-pipeline hooks (markdown filters, badge injection, sidebar widgets) — deferred to `api_version: 2` if ever asked for
- CSP overrides — plugins cannot weaken site-wide CSP
- Admin install UI / plugin marketplace — env var is enough
- Composer-integrated plugins — plugins use core deps only, vendor anything else themselves
- Asset bundling / minification — one CSS + one JS per plugin, served as-is

## Why this shape (not alternatives)

- **Drop-in folder beats composer-package plugins** — matches LazyBlog "drop a markdown file" ethos. Composer plugins would force every operator to run `composer require` to enable a feature → kills the appeal.
- **Class API beats array config** — type-safe, IDE-friendly, easy to evolve via interface additions. Slight ceremony cost is worth the API stability.
- **No render-pipeline hooks in v1** — was the biggest API-surface decision. Cutting it shrunk the design from "WP-style framework" to "drop-in pages with assets." Far less to maintain, far easier to document.
- **Self-contained `plugins/{slug}/assets/` over `public/plugin-assets/`** — plugin folder owns everything; distribution = clone one folder.

## What surprised me

- The strict CSP from `public/index.php` actually simplifies the design: by refusing to let plugins weaken it, we sidestep the whole "plugin needs CDN A, another needs CDN B, merge conflict" problem WordPress has. Plugin authors externalize JS or use no JS. End of story.
- Reserved-path collision handling matters more than expected. Without it, a plugin could shadow `/posts/foo` or `/admin/login`. With it (registry rejects, plugin loses), core routes stay always-on regardless of plugin order.

## Open questions for implementation

1. `Http::plugins()` global accessor vs passing registry through `Http::render()` data — picked static accessor for KISS parity; revisit if it gets ugly.
2. `PluginContext::csrf()`/`auth()` return class strings (because `App\Csrf` + `App\Auth` are static-only). Alternative is adapter objects. Strings are honest about the API; can change later.
3. Whether to add PHPUnit at Phase 5 or keep matching `tests/test-gamification.php` (plain assertion script). Chose the latter — consistent with project precedent.

## Next

`/ck:cook plans/260623-1909-plugin-system` when ready to implement. Phases are strictly sequential.
