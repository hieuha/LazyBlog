<?php
/** @var string $next */
/** @var string|null $error */

use App\Csrf;
use App\Http;
?>

<section class="admin-card">
    <div class="section-tag">§ ADMIN — AUTHENTICATION</div>
    <h2>Login</h2>

    <?php if ($error !== null): ?>
        <p class="admin-error">// <?= Http::e($error) ?></p>
    <?php endif; ?>

    <div class="admin-actions" style="margin-top: 18px;">
        <button id="webauthn-tap" class="admin-btn admin-btn-primary admin-btn-tap" data-state="idle"
                data-csrf="<?= Http::e(Csrf::token()) ?>"
                data-next="<?= Http::e($next) ?>">
            [ <i class="fa-solid fa-fingerprint" aria-hidden="true"></i> TAP YOUR SECURITY KEY ]
        </button>
        <a class="admin-btn" href="/">[ BACK ]</a>
    </div>

    <p class="admin-mode-summary" id="webauthn-status" hidden></p>
</section>

<script defer src="<?= Http::e(Http::asset('assets/admin-security.js')) ?>"></script>
