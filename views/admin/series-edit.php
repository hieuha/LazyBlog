<?php
/** @var string $title */
/** @var string $slug */
/** @var array{title:string, description:string} $values */
/** @var bool $hasCover */
/** @var bool $hasPreview */
/** @var list<array<string,mixed>> $postsInSeries */
/** @var list<array{slug:string,title:string,date:string,series:?string}> $candidatePosts */
/** @var bool $imagickAvailable */
/** @var ?string $formError */
/** @var ?string $flash */

use App\Csrf;
use App\Http;

$coverUrl = $hasCover ? Http::seriesAsset($slug, 'cover.webp') : null;
$previewUrl = $hasPreview ? Http::seriesAsset($slug, '.preview.webp') : null;
?>

<section>
    <div class="admin-header-row">
        <h2>> EDIT SERIES // <?= Http::e($slug) ?></h2>
        <div class="admin-actions">
            <a class="admin-btn" href="/admin/series">[ ← BACK ]</a>
            <a class="admin-btn" href="/series/<?= Http::e($slug) ?>" target="_blank">[ VIEW PUBLIC ]</a>
        </div>
    </div>

    <?php if ($flash !== null): ?>
        <p class="admin-flash">// <?= Http::e($flash) ?></p>
    <?php endif; ?>
    <?php if ($formError !== null): ?>
        <p class="admin-flash admin-flash-error">// ERROR: <?= Http::e($formError) ?></p>
    <?php endif; ?>

    <div class="admin-series-edit-grid">
        <form class="admin-series-edit-form" method="post" action="/admin/series/<?= Http::e($slug) ?>" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= Http::e(Csrf::token()) ?>">

            <label class="admin-label" for="title">Title <span class="admin-label-hint">(optional · overrides slug-derived)</span></label>
            <input type="text" name="title" id="title" maxlength="200"
                   value="<?= Http::e($values['title']) ?>"
                   placeholder="<?= Http::e(ucwords(str_replace(['-','_'], ' ', $slug))) ?>">

            <label class="admin-label" for="description">Description <span class="admin-label-hint">(optional · 1-2 sentences, shown on card + banner)</span></label>
            <textarea name="description" id="description" rows="4" maxlength="500"
                      placeholder="A short blurb that explains what this series is about."><?= Http::e($values['description']) ?></textarea>

            <fieldset class="admin-series-cover-block" <?= $imagickAvailable ? '' : 'disabled' ?>>
                <legend class="admin-label">Cover image</legend>

                <?php if (!$imagickAvailable): ?>
                    <p style="color: var(--text-dim);">// ext-imagick missing — cover upload disabled. Manifest fields above still save.</p>
                <?php endif; ?>

                <div class="admin-series-cover-preview-row">
                    <?php if ($coverUrl !== null): ?>
                        <div class="admin-series-cover-preview">
                            <span class="admin-series-cover-thumb-big"
                                  style="--dot-mask: url('<?= Http::e($coverUrl) ?>');"
                                  aria-label="Current cover"></span>
                            <p class="admin-series-cover-caption">// CURRENT COVER</p>
                        </div>
                    <?php endif; ?>
                    <?php if ($previewUrl !== null): ?>
                        <div class="admin-series-cover-preview admin-series-cover-preview-fresh">
                            <span class="admin-series-cover-thumb-big"
                                  style="--dot-mask: url('<?= Http::e($previewUrl) ?>');"
                                  aria-label="Pending preview"></span>
                            <p class="admin-series-cover-caption">// PENDING PREVIEW</p>
                        </div>
                    <?php endif; ?>
                </div>

                <label class="admin-label" for="cover">Upload new image (jpg / png / webp, max 5 MB)</label>
                <input type="file" name="cover" id="cover" accept="image/jpeg,image/png,image/webp" <?= $imagickAvailable ? '' : 'disabled' ?>>

                <div class="admin-series-cover-actions">
                    <button type="submit"
                            formaction="/admin/series/<?= Http::e($slug) ?>/preview"
                            class="admin-btn" <?= $imagickAvailable ? '' : 'disabled' ?>>
                        [ PREVIEW DITHER ]
                    </button>
                    <?php if ($previewUrl !== null): ?>
                        <label class="admin-series-promote-toggle">
                            <input type="checkbox" name="promote_preview" value="1" checked>
                            Promote pending preview to live on save
                        </label>
                    <?php endif; ?>
                </div>
            </fieldset>

            <div class="admin-form-actions">
                <button type="submit" class="admin-btn admin-btn-primary">[ SAVE ]</button>
            </div>
        </form>

        <aside class="admin-series-edit-aside">
            <h3 class="admin-aside-heading">Posts in this series (<?= count($postsInSeries) ?>)</h3>
            <?php if ($postsInSeries === []): ?>
                <p style="color: var(--text-dim);">// none — orphan manifest will be ignored on /series.</p>
            <?php else: ?>
                <ul class="admin-series-posts-list">
                    <?php foreach ($postsInSeries as $i => $entry): ?>
                        <li>
                            <span class="admin-mono">
                                <?= str_pad((string) ($entry['part'] ?? ($i + 1)), 2, '0', STR_PAD_LEFT) ?>
                            </span>
                            <a href="/admin/edit/<?= Http::e((string) $entry['slug']) ?>">
                                <?= Http::e((string) $entry['title']) ?>
                            </a>
                            <span class="admin-mono" style="color: var(--text-dim);">
                                <?= Http::e(substr((string) $entry['date'], 0, 10)) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <h3 class="admin-aside-heading" style="margin-top: 22px;">+ Attach a post</h3>
            <?php if (empty($candidatePosts)): ?>
                <p style="color: var(--text-dim);">// no other posts available.</p>
            <?php else: ?>
                <form method="post" action="/admin/series/<?= Http::e($slug) ?>/attach" class="admin-series-attach-form">
                    <input type="hidden" name="_csrf" value="<?= Http::e(Csrf::token()) ?>">
                    <label class="admin-label" for="attach-slug">Post slug
                        <span class="admin-label-hint">(pick existing or type)</span>
                    </label>
                    <input type="text" name="post_slug" id="attach-slug"
                           list="series-attach-candidates"
                           class="admin-input"
                           autocomplete="off"
                           placeholder="post-slug-here" required>
                    <datalist id="series-attach-candidates">
                        <?php foreach ($candidatePosts as $c): ?>
                            <option value="<?= Http::e($c['slug']) ?>">
                                <?= Http::e($c['title']) ?><?= $c['series'] !== null ? ' · ' . $c['series'] : '' ?> · <?= Http::e($c['date']) ?>
                            </option>
                        <?php endforeach; ?>
                    </datalist>
                    <label class="admin-label" for="attach-part" style="margin-top: 8px;">Part #
                        <span class="admin-label-hint">(optional)</span>
                    </label>
                    <input type="number" name="part" id="attach-part" min="1" step="1"
                           class="admin-input"
                           placeholder="1">
                    <button type="submit" class="admin-btn admin-btn-primary" style="margin-top: 10px;">[ ATTACH ]</button>
                </form>
                <p class="admin-series-attach-hint" style="color: var(--text-dim); font-size: 11px; margin-top: 8px;">
                    // Re-writes the post's <code>series:</code> frontmatter to <?= Http::e($slug) ?>. Posts already in another series get moved.
                </p>
            <?php endif; ?>
        </aside>
    </div>
</section>
