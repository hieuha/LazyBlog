<?php
/**
 * Shared admin section tabs.
 *
 * Renders `[ ALL POSTS ]  [ PLUGINS ]  [ SECURITY ]  [ SERIES ]  [ LOG OUT ]`
 * consistently across every admin index view (post list, series list,
 * security keys). The active tab carries the dotted underline + bright
 * color + an inline count `(N)` so the operator sees the metric for
 * whatever section they're currently looking at. Inactive tabs render
 * label-only — counts on every tab would multiply visual noise across
 * the row without paying its way.
 *
 * Caller contract:
 *   `$activeTab`   : one of 'posts' | 'plugins' | 'security' | 'series'
 *   `$total`       : optional int — post count (only used when activeTab='posts')
 *   `$seriesCount` : optional int — series count (only used when activeTab='series')
 *
 * Plugin + security active counts are derived inline (cheap: plugin
 * registry already booted, credentials store is one small JSON read).
 */

/** @var string $activeTab */
/** @var int|null $total */
/** @var int|null $seriesCount */

use App\Auth;
use App\Csrf;
use App\Http;

$pluginRegistry = Http::plugins();
$enabledPlugins = $pluginRegistry !== null ? $pluginRegistry->enabledSlugs() : [];

// Build each tab's label: append "(N)" only when this tab is the active one.
$postsLabel = $activeTab === 'posts' && isset($total)
    ? '[ ALL POSTS (' . (int) $total . ') ]'
    : '[ ALL POSTS ]';
$pluginsLabel = $activeTab === 'plugins'
    ? '[ PLUGINS (' . count($enabledPlugins) . ') ]'
    : '[ PLUGINS ]';
$securityLabel = $activeTab === 'security'
    ? '[ SECURITY (' . Auth::webauthnKeyCount() . ') ]'
    : '[ SECURITY ]';
$seriesLabel = $activeTab === 'series' && isset($seriesCount)
    ? '[ SERIES (' . (int) $seriesCount . ') ]'
    : '[ SERIES ]';

$ariaCurrent = static fn (string $name): string => $activeTab === $name
    ? 'aria-current="page" aria-selected="true"'
    : 'aria-selected="false"';
?>
<div class="admin-tabs" role="tablist" aria-label="Admin sections">
    <a class="admin-tab" role="tab" href="/admin" <?= $ariaCurrent('posts') ?>>
        <?= Http::e($postsLabel) ?>
    </a>
    <?php if ($enabledPlugins !== []): ?>
        <a class="admin-tab" role="tab" href="/admin?tab=plugins" <?= $ariaCurrent('plugins') ?>>
            <?= Http::e($pluginsLabel) ?>
        </a>
    <?php endif; ?>
    <a class="admin-tab" role="tab" href="/admin/security" <?= $ariaCurrent('security') ?>>
        <?= Http::e($securityLabel) ?>
    </a>
    <a class="admin-tab" role="tab" href="/admin/series" <?= $ariaCurrent('series') ?>>
        <?= Http::e($seriesLabel) ?>
    </a>
    <form method="post" action="/admin/logout" class="admin-tab-form">
        <input type="hidden" name="_csrf" value="<?= Http::e(Csrf::token()) ?>">
        <button type="submit" class="admin-tab admin-tab-logout">
            [ LOG OUT ]
        </button>
    </form>
</div>
<script>
/* Center the active tab inside the horizontally-scrolling row so the
   dotted-underline indicator is always visible on phones (the row's
   `flex-wrap: nowrap; overflow-x: auto` rule on mobile means later
   tabs sit off-screen by default). No-op on desktop because there is
   no horizontal overflow there. */
(function () {
    var active = document.currentScript.previousElementSibling
        && document.currentScript.previousElementSibling.querySelector
        && document.currentScript.previousElementSibling.querySelector('.admin-tab[aria-current="page"]');
    if (!active || typeof active.scrollIntoView !== 'function') return;
    active.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'instant' });
})();
</script>
