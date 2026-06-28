<?php
/** @var string $title */
/** @var list<array<string,mixed>> $posts */
/** @var int $page */
/** @var int $totalPages */
/** @var int $total */
/** @var string $pageBaseUrl */
/** @var string|null $flash */

use App\Csrf;
use App\Http;

// Tab state — read from ?tab=... query so each tab is its own bookmarkable
// URL (no JS state machine). The PLUGINS tab is only reachable when at
// least one plugin booted; otherwise we silently snap back to posts.
$pluginRegistry = Http::plugins();
$enabledPlugins = $pluginRegistry !== null ? $pluginRegistry->enabledSlugs() : [];
$requestedTab = isset($_GET['tab']) ? (string) $_GET['tab'] : 'posts';
$activeTab = ($requestedTab === 'plugins' && $enabledPlugins !== []) ? 'plugins' : 'posts';
?>

<section>
    <div class="admin-header-row">
        <div class="admin-tabs" role="tablist" aria-label="Admin sections">
            <a class="admin-tab"
               role="tab"
               href="/admin"
               <?= $activeTab === 'posts' ? 'aria-current="page" aria-selected="true"' : 'aria-selected="false"' ?>>
                [ ALL POSTS (<?= (int) $total ?>) ]
            </a>
            <?php if ($enabledPlugins !== []): ?>
                <a class="admin-tab"
                   role="tab"
                   href="/admin?tab=plugins"
                   <?= $activeTab === 'plugins' ? 'aria-current="page" aria-selected="true"' : 'aria-selected="false"' ?>>
                    [ PLUGINS (<?= count($enabledPlugins) ?>) ]
                </a>
            <?php endif; ?>
        </div>
        <div class="admin-actions">
            <a class="admin-btn admin-btn-primary" href="/admin/new">[ NEW POST ]</a>
            <a class="admin-btn" href="/admin/series">[ SERIES ]</a>
            <a class="admin-btn" href="/admin/about">[ <?= (new \App\AboutRepository(__DIR__ . '/../../content'))->exists() ? 'EDIT' : 'CREATE' ?> ABOUT ]</a>
            <form method="post" action="/admin/logout" style="display:inline">
                <input type="hidden" name="_csrf" value="<?= Http::e(Csrf::token()) ?>">
                <button type="submit" class="admin-btn">[ LOG OUT ]</button>
            </form>
        </div>
    </div>

    <?php if ($flash !== null): ?>
        <?php $savedSlug = preg_match('/^Saved: (.+)$/', $flash, $sm) ? $sm[1] : null; ?>
        <p class="admin-flash">// <?php if ($savedSlug !== null): ?>Saved: <a href="/posts/<?= Http::e($savedSlug) ?>" target="_blank" rel="noopener noreferrer"><?= Http::e($savedSlug) ?></a><?php else: ?><?= Http::e($flash) ?><?php endif; ?></p>
        <?php
        // Match a successful save / delete and drop the editor's
        // localStorage draft for that slug. Also nuke the generic
        // "new post" autosave key (`lazyblog-new`) so a fresh
        // /admin/new doesn't repopulate from the just-saved post.
        if (preg_match('/^(?:Saved|Deleted): (.+)$/', $flash, $m)):
            $clearedSlug = $m[1];
        ?>
            <script>
            (function () {
                try {
                    localStorage.removeItem('smde_lazyblog-' + <?= json_encode($clearedSlug) ?>);
                    localStorage.removeItem('smde_lazyblog-new');
                } catch (e) { /* localStorage unavailable — ignore */ }
            })();
            </script>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($activeTab === 'posts'): ?>
        <div role="tabpanel" aria-label="Posts">
            <?php if ($posts === []): ?>
                <p style="color: var(--text-dim);">// No posts yet. <a href="/admin/new">Create the first one.</a></p>
            <?php else: ?>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>DATE</th>
                            <th>TITLE</th>
                            <th class="admin-col-series">SERIES</th>
                            <th>TAGS</th>
                            <th>STATUS</th>
                            <th>ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($posts as $entry): ?>
                            <tr>
                                <td class="admin-mono"><?= Http::e(substr((string) $entry['date'], 0, 10)) ?></td>
                                <td class="admin-title-cell">
                                    <a href="/posts/<?= Http::e((string) $entry['slug']) ?>" target="_blank"
                                       title="<?= Http::e((string) $entry['title']) ?>">
                                        <?php if (!empty($entry['icon'])): ?><?= Http::e((string) $entry['icon']) ?> <?php endif; ?>
                                        <?= Http::e((string) $entry['title']) ?>
                                    </a>
                                </td>
                                <td class="admin-col-series admin-mono">
                                    <?php if (!empty($entry['series'])): ?>
                                        <a class="admin-series-chip" href="/series/<?= Http::e((string) $entry['series']) ?>" target="_blank">
                                            <?= Http::e((string) $entry['series']) ?>
                                            <?php if (isset($entry['part']) && $entry['part'] !== null): ?>
                                                · P<?= (int) $entry['part'] ?>
                                            <?php endif; ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: var(--text-dim);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="admin-mono"><?= Http::e(implode(', ', (array) $entry['tags'])) ?></td>
                                <td class="admin-mono">
                                    <?php if (!empty($entry['draft'])): ?>
                                        <span class="admin-status admin-status-draft" title="Draft" aria-label="Draft">Draft</span>
                                    <?php elseif (substr((string) $entry['date'], 0, 10) > date('Y-m-d')): ?>
                                        <span class="admin-status admin-status-scheduled" title="Scheduled" aria-label="Scheduled">Scheduled</span>
                                    <?php else: ?>
                                        <span class="admin-status admin-status-live" title="Live" aria-label="Live">Live</span>
                                    <?php endif; ?>
                                    <?php if (!empty($entry['protected'])): ?>
                                        <span class="post-lock" title="Password protected" aria-label="Password protected">[ <i class="fa fa-lock" aria-hidden="true"></i> ]</span>
                                    <?php endif; ?>
                                </td>
                                <td class="admin-row-actions">
                                    <a class="admin-btn admin-btn-sm" href="/admin/edit/<?= Http::e((string) $entry['slug']) ?>">EDIT</a>
                                    <form method="post" action="/admin/delete/<?= Http::e((string) $entry['slug']) ?>"
                                          style="display:inline"
                                          onsubmit="return confirm('Delete <?= Http::e((string) $entry['slug']) ?>? This is permanent.');">
                                        <input type="hidden" name="_csrf" value="<?= Http::e(Csrf::token()) ?>">
                                        <button type="submit" class="admin-btn admin-btn-sm admin-btn-danger">DEL</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php include __DIR__ . '/../_pagination.php'; ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div role="tabpanel" aria-label="Plugins">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>SLUG</th>
                        <th>NAME</th>
                        <th>VERSION</th>
                        <th>AUTHOR</th>
                        <th>ADMIN</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($enabledPlugins as $pluginSlug):
                        $pluginManifest = $pluginRegistry->manifest($pluginSlug);
                        if ($pluginManifest === null) {
                            continue;
                        }
                    ?>
                        <tr>
                            <td class="admin-mono"><?= Http::e($pluginManifest->slug) ?></td>
                            <td class="admin-title-cell">
                                <?php if ($pluginManifest->homepage !== ''): ?>
                                    <a href="<?= Http::e($pluginManifest->homepage) ?>" target="_blank" rel="noopener noreferrer"
                                       title="<?= Http::e($pluginManifest->description) ?>">
                                        <?= Http::e($pluginManifest->name) ?>
                                    </a>
                                <?php else: ?>
                                    <span title="<?= Http::e($pluginManifest->description) ?>"><?= Http::e($pluginManifest->name) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="admin-mono"><?= Http::e($pluginManifest->version) ?></td>
                            <td class="admin-mono">
                                <?php if ($pluginManifest->author !== ''): ?>
                                    <?= Http::e($pluginManifest->author) ?>
                                <?php else: ?>
                                    <span style="color: var(--text-dim);">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="admin-row-actions">
                                <?php if ($pluginRegistry->hasAdminRoute($pluginSlug)): ?>
                                    <a class="admin-btn admin-btn-sm" href="/admin/<?= Http::e($pluginSlug) ?>">OPEN</a>
                                <?php else: ?>
                                    <span style="color: var(--text-dim);">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
