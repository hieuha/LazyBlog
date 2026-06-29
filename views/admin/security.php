<?php
/** @var list<App\WebAuthnCredential> $credentials */
/** @var bool $webauthnEnabled */
/** @var array{type:string,message:string}|null $flash */

use App\Csrf;
use App\Http;

$pluginRegistry = Http::plugins();
$enabledPlugins = $pluginRegistry !== null ? $pluginRegistry->enabledSlugs() : [];

// Render an ISO-8601 timestamp as "YYYY-MM-DD HH:MM" in the configured
// site timezone so operators can tell registrations made on the same day
// apart at a glance. Falls back to the date-only prefix if parsing fails.
$fmtStamp = static function (?string $iso): string {
    if ($iso === null || $iso === '') {
        return '—';
    }
    $ts = strtotime($iso);
    if ($ts === false) {
        return substr($iso, 0, 10);
    }
    $tz = new DateTimeZone((string) App\Config::get('TIMEZONE', 'UTC'));
    return (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d H:i');
};
?>

<section>
    <div class="admin-header-row">
        <div class="admin-tabs" role="tablist" aria-label="Admin sections">
            <a class="admin-tab" role="tab" href="/admin" aria-selected="false">[ ALL POSTS ]</a>
            <?php if ($enabledPlugins !== []): ?>
                <a class="admin-tab" role="tab" href="/admin?tab=plugins" aria-selected="false">
                    [ PLUGINS (<?= count($enabledPlugins) ?>) ]
                </a>
            <?php endif; ?>
            <a class="admin-tab" role="tab" href="/admin/security" aria-current="page" aria-selected="true">
                [ SECURITY (<?= count($credentials) ?>) ]
            </a>
        </div>
        <div class="admin-actions">
            <button type="button" class="admin-btn admin-btn-primary" id="open-add-key-modal">[ + ADD KEY ]</button>
            <a class="admin-btn" href="/admin">[ BACK ]</a>
        </div>
    </div>

    <?php if ($flash !== null): ?>
        <p class="<?= $flash['type'] === 'error' ? 'admin-error' : 'admin-flash' ?>">
            // <?= Http::e($flash['message']) ?>
        </p>
    <?php endif; ?>

    <?php if ($credentials === []): ?>
        <p style="color: var(--text-dim);">// No keys registered yet. Click [ + ADD KEY ] above.</p>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>NAME</th>
                    <th>TYPE</th>
                    <th>ADDED</th>
                    <th>LAST USED</th>
                    <th>STATUS</th>
                    <th>ACTIONS</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $isLastWhenEnabled = $webauthnEnabled && count($credentials) <= 1;
                foreach ($credentials as $cred):
                    $transports = $cred->transports !== [] ? implode(',', $cred->transports) : '—';
                    $added = $fmtStamp($cred->createdAt);
                    $lastUsed = $fmtStamp($cred->lastUsedAt);
                    if ($cred->lastUsedAt === null) {
                        $statusClass = 'admin-status admin-status-draft';
                        $statusLabel = 'Never used';
                    } elseif (strtotime($cred->lastUsedAt) > strtotime('-30 days')) {
                        $statusClass = 'admin-status admin-status-live';
                        $statusLabel = 'Active';
                    } else {
                        $statusClass = 'admin-mono';
                        $statusLabel = 'Idle';
                    }
                ?>
                    <tr>
                        <td><?= Http::e($cred->name) ?></td>
                        <td class="admin-mono"><?= Http::e($transports) ?></td>
                        <td class="admin-mono"><?= Http::e($added) ?></td>
                        <td class="admin-mono"><?= Http::e($lastUsed) ?></td>
                        <td><span class="<?= $statusClass ?>"><?= Http::e($statusLabel) ?></span></td>
                        <td class="admin-row-actions">
                            <?php if ($isLastWhenEnabled): ?>
                                <button type="button" class="admin-btn admin-btn-sm admin-btn-danger js-last-key-warn">
                                    REVOKE
                                </button>
                            <?php else: ?>
                                <form method="post" action="/admin/security/revoke/<?= Http::e($cred->id) ?>"
                                      style="display:inline"
                                      data-confirm="Revoke key &quot;<?= Http::e($cred->name) ?>&quot;? You'll need another key to log in."
                                      data-confirm-title="Revoke security key"
                                      data-confirm-label="[ REVOKE ]"
                                      data-confirm-danger="1">
                                    <input type="hidden" name="_csrf" value="<?= Http::e(Csrf::token()) ?>">
                                    <button type="submit" class="admin-btn admin-btn-sm admin-btn-danger">REVOKE</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php /* ── Add-key modal — reuses admin-confirm-modal CSS so visual style
       stays in lockstep with the REVOKE confirm. Open/close wired by
       admin-security.js (button id="open-add-key-modal" + data-add-key-dismiss). */ ?>
<div class="admin-confirm-modal" id="add-key-modal" hidden role="dialog" aria-modal="true" aria-labelledby="add-key-modal-title">
    <div class="admin-confirm-modal-backdrop" data-add-key-dismiss></div>
    <div class="admin-confirm-modal-panel">
        <div class="admin-confirm-modal-tag">
            § <span id="add-key-modal-title">ADD KEY</span>
        </div>
        <p class="admin-confirm-modal-message">
            Give this key a nickname (e.g. <em>Yubikey 5C primary</em>) so you can tell it apart in the list. After clicking REGISTER, tap your security key or use Touch ID / Face ID.
        </p>
        <form class="admin-form" id="webauthn-register-form" style="gap: 10px; margin-top: 0;">
            <input type="hidden" name="_csrf" value="<?= Http::e(Csrf::token()) ?>">
            <label class="admin-label" for="key-nickname">NICKNAME</label>
            <input class="admin-input" id="key-nickname" name="nickname"
                   placeholder="Yubikey 5C primary" maxlength="64" required>
            <p class="admin-error" id="webauthn-register-error" hidden></p>
            <div class="admin-confirm-modal-actions">
                <button type="button" class="admin-btn" data-add-key-dismiss>[ CANCEL ]</button>
                <button type="submit" class="admin-btn admin-btn-primary">[ + REGISTER ]</button>
            </div>
        </form>
    </div>
</div>

<?php /* Last-key warning — opens when operator clicks REVOKE on the only
       registered key while WEBAUTHN=true. Info-only modal: no submit. */ ?>
<div class="admin-confirm-modal" id="last-key-warn-modal" hidden role="dialog" aria-modal="true" aria-labelledby="last-key-warn-title">
    <div class="admin-confirm-modal-backdrop" data-last-key-dismiss></div>
    <div class="admin-confirm-modal-panel">
        <div class="admin-confirm-modal-tag">
            § <span id="last-key-warn-title">CANNOT REVOKE</span>
        </div>
        <p class="admin-confirm-modal-message">
            This is your <strong>last registered key</strong> and <code>WEBAUTHN=true</code> is set. Revoking it now would lock you out — there would be no way to log in. Two paths forward:
        </p>
        <ol class="admin-confirm-modal-message" style="padding-left: 20px; margin: 0;">
            <li>Click <strong>[ + ADD KEY ]</strong> to register a backup key first, then come back and revoke this one.</li>
            <li>SSH into the server, set <code>WEBAUTHN=false</code> in <code>.env</code>, and reload php-fpm. Password login is restored; this key stays registered but inactive.</li>
        </ol>
        <div class="admin-confirm-modal-actions">
            <button type="button" class="admin-btn admin-btn-primary" data-last-key-dismiss>[ OK ]</button>
        </div>
    </div>
</div>

<?php include __DIR__ . '/_confirm-modal.php'; ?>

<script defer src="<?= Http::e(Http::asset('assets/admin-security.js')) ?>"></script>
