<?php

declare(strict_types=1);

use App\Http;

/**
 * @var bool    $ready     token + endpoint configured
 * @var string  $token     current bearer token (shown masked-ish in a password field)
 * @var string  $endpoint  current webhook URL
 * @var string  $csrf
 * @var ?string $flash
 */
?>
<article class="fax-admin">
    <h1 class="post-page-title">// FAX // ADMIN</h1>

    <?php if ($flash !== null): ?>
        <p class="fax-flash"><?= Http::e($flash) ?></p>
    <?php endif; ?>

    <p class="fax-status">
        Status:
        <?php if ($ready): ?>
            <strong style="color:#39ff14">LIVE</strong> — readers can highlight text on any post and fax it to you.
        <?php else: ?>
            <strong style="color:#ff7700">OFF</strong> — add a token below to switch the fax button on.
        <?php endif; ?>
    </p>

    <form method="post" action="/admin/fax/save" class="fax-form">
        <input type="hidden" name="_csrf" value="<?= Http::e($csrf) ?>">

        <label class="fax-label" for="fax-token">Webhook token</label>
        <input class="admin-input" type="password" id="fax-token" name="api_token"
               value="<?= Http::e($token) ?>" autocomplete="off" spellcheck="false"
               placeholder="fxwh_…">
        <p class="fax-hint">The <code>fxwh_…</code> bearer secret from FaxxMe. Kept server-side; never sent to the browser.</p>

        <label class="fax-label" for="fax-endpoint">Webhook endpoint</label>
        <input class="admin-input" type="url" id="fax-endpoint" name="endpoint"
               value="<?= Http::e($endpoint) ?>" autocomplete="off" spellcheck="false"
               placeholder="https://fax.hatrunghieu.com/api/fax/inbound">
        <p class="fax-hint">Must be HTTPS. Leave blank to use the default FaxxMe endpoint.</p>

        <div class="fax-actions">
            <button type="submit" class="admin-btn admin-btn-primary">SAVE</button>
        </div>
    </form>

    <?php if ($ready): ?>
        <form method="post" action="/admin/fax/test" class="fax-form-test">
            <input type="hidden" name="_csrf" value="<?= Http::e($csrf) ?>">
            <button type="submit" class="admin-btn admin-btn-sm">SEND A TEST FAX</button>
            <span class="fax-hint">Fires a canned message at the webhook so you can confirm the wiring.</span>
        </form>
    <?php endif; ?>

    <p class="fax-hint fax-note">
        Rate limits? Not our circus, not our monkeys. 🐒 That's the FaxxMe sysadmin's
        problem over on the server. We, here at the client side, blissfully do not care.
        When a reader faxes too hard they just get a cheeky "out of paper, out of ink,
        go touch grass" message instead of a scary error. Nothing to configure. You're welcome.
    </p>
</article>
