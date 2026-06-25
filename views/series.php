<?php
/** @var string $title */
/** @var string $seriesSlug */
/** @var string $seriesTitle */
/** @var ?string $description */
/** @var ?string $coverUrl */
/** @var list<array<string,mixed>> $posts */

use App\Auth;
use App\Http;
?>

<section class="series-page">
    <?php if (!empty($coverUrl)): ?>
        <div class="series-detail-cover">
            <span class="series-detail-cover-dot"
                  style="--dot-mask: url('<?= Http::e((string) $coverUrl) ?>');"
                  aria-hidden="true"></span>
        </div>
    <?php endif; ?>
    <h2 class="series-page-title">> SERIE: <?= Http::e($seriesTitle) ?></h2>
    <?php if (!empty($description)): ?>
        <p class="series-detail-desc"><?= Http::e((string) $description) ?></p>
    <?php endif; ?>
    <p class="series-meta"><?= count($posts) ?> PART<?= count($posts) === 1 ? '' : 'S' ?></p>

    <ul class="series-list">
        <?php foreach ($posts as $i => $entry): ?>
            <li class="series-item">
                <span class="series-part-no"><?= str_pad((string) ($entry['part'] ?? ($i + 1)), 2, '0', STR_PAD_LEFT) ?></span>
                <div class="series-item-body">
                    <a class="series-item-title" href="/posts/<?= Http::e($entry['slug']) ?>">
                        <?php if (!empty($entry['icon'])): ?><span class="series-icon"><?= Http::e($entry['icon']) ?></span> <?php endif; ?>
                        <?= Http::e($entry['title']) ?>
                    </a>
                    <div class="series-item-meta">
                        <span class="series-date"><?= Http::e(substr((string) $entry['date'], 0, 10)) ?></span>
                        <?php if (!empty($entry['summary'])): ?>
                            · <span class="series-summary"><?= Http::e($entry['summary']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>

    <p class="series-detail-actions">
        <a class="view-source-link" href="/">← BACK TO INDEX</a>
        <?php if (Auth::check()): ?>
            <a class="view-source-link" href="/admin/series/<?= Http::e($seriesSlug) ?>">[ EDIT SERIES ]</a>
        <?php endif; ?>
    </p>
</section>
