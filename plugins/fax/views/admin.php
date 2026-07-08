<?php

declare(strict_types=1);

use App\Http;

/**
 * Admin config for the fax plugin. Uses the core `admin-series-edit-*`
 * design tokens so it matches the series edit/config page chrome (loaded
 * from public/assets/admin.css on every /admin/* path).
 *
 * @var bool    $ready     token + endpoint configured
 * @var string  $token     current bearer token (shown in a password field)
 * @var string  $endpoint  current webhook URL
 * @var string  $csrf
 * @var ?string $flash
 */
?>
<section>
    <div class="admin-header-row">
        <h2>&gt; FAX // admin</h2>
        <div class="admin-actions">
            <a class="admin-btn" href="/admin">[ ← BACK ]</a>
        </div>
    </div>

    <?php if ($flash !== null): ?>
        <p class="admin-flash">// <?= Http::e($flash) ?></p>
    <?php endif; ?>

    <div class="admin-series-edit-grid">
      <div class="admin-series-edit-left">
        <form class="admin-series-edit-form" method="post" action="/admin/fax/save">
            <input type="hidden" name="_csrf" value="<?= Http::e($csrf) ?>">

            <label class="admin-label" for="fax-token">Webhook token
                <span class="admin-label-hint">(fxwh_… bearer secret · kept server-side, never sent to the browser)</span>
            </label>
            <input type="password" name="api_token" id="fax-token"
                   class="admin-input"
                   value="<?= Http::e($token) ?>"
                   placeholder="fxwh_…" autocomplete="off" spellcheck="false">

            <label class="admin-label" for="fax-endpoint">Webhook endpoint
                <span class="admin-label-hint">(HTTPS only · leave blank to use the default FaxxMe endpoint)</span>
            </label>
            <input type="url" name="endpoint" id="fax-endpoint"
                   class="admin-input"
                   value="<?= Http::e($endpoint) ?>"
                   placeholder="https://fax.hatrunghieu.com/api/fax/inbound"
                   autocomplete="off" spellcheck="false">

            <div class="admin-form-actions">
                <button type="submit" class="admin-btn admin-btn-primary">[ SAVE ]</button>
            </div>
        </form>

        <?php if ($ready): ?>
            <form method="post" action="/admin/fax/test" class="admin-series-edit-form" style="margin-top: 22px;">
                <input type="hidden" name="_csrf" value="<?= Http::e($csrf) ?>">
                <label class="admin-label">Test the wiring
                    <span class="admin-label-hint">(fires a canned message at the webhook — counts against its rate limit)</span>
                </label>
                <div class="admin-form-actions">
                    <button type="submit" class="admin-btn">[ SEND A TEST FAX ]</button>
                </div>
            </form>
        <?php endif; ?>
      </div>

      <aside class="admin-series-edit-aside">
        <h3 class="admin-aside-heading">Status</h3>
        <p>
            <?php if ($ready): ?>
                <code class="admin-mono" style="color: var(--primary);">LIVE</code>
                — readers can highlight text on any post and fax it to you.
            <?php else: ?>
                <code class="admin-mono" style="color: var(--accent);">OFF</code>
                — add a token to switch the fax button on.
            <?php endif; ?>
        </p>

        <h3 class="admin-aside-heading" style="margin-top: 22px;">Rate limits</h3>
        <p style="color: var(--text-dim); font-size: 12px;">
            // Not our circus, not our monkeys 🐒 — the FaxxMe webhook rate-limits
            per author + per calling site. When a reader faxes too hard they just get
            a cheeky "out of paper, out of ink, go touch grass" message instead of a
            scary error. Nothing to configure here.
        </p>

        <h3 class="admin-aside-heading" style="margin-top: 22px;">How it works</h3>
        <p style="color: var(--text-dim); font-size: 12px;">
            // Reader highlights a passage → a "Fax this" button appears → the
            selection becomes the fax body and their name the sender. The post title
            + URL are filled in server-side from the slug, so attribution can't be
            spoofed and your token never reaches the browser.
        </p>
      </aside>
    </div>
</section>
