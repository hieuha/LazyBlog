<?php
/** @var string $title */
/** @var list<array<string,mixed>> $series */
/** @var ?string $flash */
/** @var bool $imagickAvailable */

use App\Csrf;
use App\Http;
?>

<section>
    <div class="admin-header-row">
        <?php
        $activeTab = 'series';
        $seriesCount = count($series);
        include __DIR__ . '/_tabs.php';
        ?>
    </div>

    <?php if ($flash !== null): ?>
        <p class="admin-flash">// <?= Http::e($flash) ?></p>
    <?php endif; ?>

    <?php if (!$imagickAvailable): ?>
        <p class="admin-flash admin-flash-warn">
            // ext-imagick missing. Title/description still editable, but cover uploads are disabled.
        </p>
    <?php endif; ?>

    <?php if ($series === []): ?>
        <p style="color: var(--text-dim);">
            // No series yet. Add <code>series: my-slug</code> frontmatter to any post,
            then this page lists it. Manifest (title, description, cover) is optional and edited here.
        </p>
    <?php else: ?>
        <table class="admin-table admin-series-table">
            <thead>
                <tr>
                    <th>SLUG</th>
                    <th>TITLE</th>
                    <th>POSTS</th>
                    <th>MANIFEST</th>
                    <th>COVER</th>
                    <th>LAST ACTIVITY</th>
                    <th>ACTIONS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($series as $s): ?>
                    <?php
                    $hasManifest = !empty($s['hasManifest']);
                    $hasCover = !empty($s['hasCover']);
                    $slug = (string) $s['slug'];
                    ?>
                    <tr>
                        <td class="admin-mono">
                            <a class="admin-series-chip" href="/series/<?= Http::e($slug) ?>" target="_blank">
                                <?= Http::e($slug) ?>
                            </a>
                        </td>
                        <?php /* Title falls back to slug when no manifest title is set —
                               mobile hides the SLUG column entirely, so the cell needs to
                               carry a meaningful identifier on its own. */ ?>
                        <td class="admin-title-cell">
                            <?= Http::e($s['title'] !== '' ? (string) $s['title'] : (string) $slug) ?>
                        </td>
                        <td class="admin-mono admin-col-mobile-hide"><?= (int) $s['count'] ?></td>
                        <td class="admin-mono admin-col-mobile-hide">
                            <?php if ($hasManifest): ?>
                                <span class="admin-series-flag" title="Manifest present" aria-label="Manifest present"></span>
                            <?php else: ?>
                                <span style="color: var(--text-dim);">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="admin-mono admin-col-mobile-hide">
                            <?php if ($hasCover): ?>
                                <span class="admin-series-cover-thumb"
                                      style="--dot-mask: url('<?= Http::e(Http::seriesAsset($slug, 'cover.webp')) ?>');"
                                      aria-label="Cover present"></span>
                            <?php else: ?>
                                <span style="color: var(--text-dim);">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="admin-mono"><?= Http::e(substr((string) $s['lastDate'], 0, 10)) ?></td>
                        <td class="admin-row-actions">
                            <a class="admin-btn admin-btn-sm" href="/admin/series/<?= Http::e($slug) ?>">EDIT</a>
                            <?php if ($hasManifest): ?>
                                <form method="post" action="/admin/series/<?= Http::e($slug) ?>/delete"
                                      style="display:inline"
                                      data-confirm="Delete manifest + cover for &quot;<?= Http::e($slug) ?>&quot;? Posts in this series stay put."
                                      data-confirm-title="Delete manifest"
                                      data-confirm-label="[ DELETE ]"
                                      data-confirm-danger="1">
                                    <input type="hidden" name="_csrf" value="<?= Http::e(Csrf::token()) ?>">
                                    <button type="submit" class="admin-btn admin-btn-sm admin-btn-danger" title="Delete manifest + cover (posts untouched)">DEL</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
