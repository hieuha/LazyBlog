<?php
/** @var string $title */
/** @var string $seriesSlug */
/** @var string $seriesTitle */
/** @var list<array<string,mixed>> $posts */

use App\Http;
?>

<section class="series-page">
    <h2 class="series-page-title">> SERIE: <?= Http::e($seriesTitle) ?></h2>
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

    <p style="margin-top: 32px;">
        <a class="view-source-link" href="/">← BACK TO INDEX</a>
    </p>
</section>
