<?php
/**
 * Shared admin section tabs.
 *
 * Renders the row of `[ ALL POSTS ]  [ PLUGINS ]  [ SECURITY ]  [ SERIES ]
 * [ LOG OUT ]` consistently across every admin index view (post list,
 * series list, security keys). Including views pass `$activeTab` to mark
 * the current page; optional counts come from the caller's local scope:
 *
 *   `$activeTab`     : one of 'posts' | 'plugins' | 'security' | 'series'
 *   `$total`         : optional int — post count, shown when set
 *   `$seriesCount`   : optional int — series count, shown when set
 *
 * Plugin + security counts are derived inline (cheap — registry + JSON
 * read).
 */

/** @var string $activeTab */
/** @var int|null $total */
/** @var int|null $seriesCount */

use App\Auth;
use App\Csrf;
use App\Http;

$pluginRegistry = Http::plugins();
$enabledPlugins = $pluginRegistry !== null ? $pluginRegistry->enabledSlugs() : [];
$postsLabel = isset($total) ? '[ ALL POSTS (' . (int) $total . ') ]' : '[ ALL POSTS ]';
$seriesLabel = isset($seriesCount) ? '[ SERIES (' . (int) $seriesCount . ') ]' : '[ SERIES ]';
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
            [ PLUGINS (<?= count($enabledPlugins) ?>) ]
        </a>
    <?php endif; ?>
    <a class="admin-tab" role="tab" href="/admin/security" <?= $ariaCurrent('security') ?>>
        [ SECURITY (<?= Auth::webauthnKeyCount() ?>) ]
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
