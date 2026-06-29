<?php
/** @var string $title */
/** @var string $next */
/** @var string|null $error */

use App\Auth;
use App\Csrf;
use App\Http;

// WebAuthn route: when enabled AND at least one key is registered, replace
// the password form entirely with the tap-key flow. When enabled but 0 keys
// registered, fall back to password (bootstrap mode) so the operator can
// log in once to register their first key.
if (Auth::webauthnEnabled() && Auth::webauthnHasCredentials()) {
    include __DIR__ . '/login-webauthn.php';
    return;
}

$bootstrapHint = Auth::webauthnEnabled() && !Auth::webauthnHasCredentials();
?>

<section class="admin-card">
    <div class="section-tag">§ ADMIN — AUTHENTICATION</div>
    <h2>Login</h2>

    <?php if ($error !== null): ?>
        <p class="admin-error">// <?= Http::e($error) ?></p>
    <?php endif; ?>

    <?php if ($bootstrapHint): ?>
        <p class="admin-mode-summary">// Bootstrap mode — register a security key at /admin/security after login.</p>
    <?php endif; ?>

    <form method="post" action="/admin/login" class="admin-form">
        <input type="hidden" name="_csrf" value="<?= Http::e(Csrf::token()) ?>">
        <input type="hidden" name="next" value="<?= Http::e($next) ?>">

        <label class="admin-label" for="password">PASSWORD</label>
        <input type="password" name="password" id="password" required autofocus
               autocomplete="current-password" class="admin-input">

        <div class="admin-actions">
            <button type="submit" class="admin-btn admin-btn-primary">[ LOG IN ]</button>
            <a class="admin-btn" href="/">[ BACK ]</a>
        </div>
    </form>
</section>
