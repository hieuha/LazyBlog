<?php
/** @var string $title */
/** @var string $seriesSlug */
/** @var string $seriesTitle */
/** @var ?string $description */
/** @var ?string $coverUrl */
/** @var ?string $qrSvg */
/** @var ?string $qrMark */
/** @var list<array<string,mixed>> $posts */

use App\Auth;
use App\Http;

$hasArt = !empty($coverUrl) || !empty($qrSvg);
?>

<section class="series-page">
    <header class="series-detail-header<?= $hasArt ? ' has-cover' : '' ?>">
        <?php if (!empty($coverUrl) || !empty($qrSvg)): ?>
            <div class="series-detail-cover">
                <?php if (!empty($coverUrl)): ?>
                    <span class="series-detail-cover-dot"
                          style="--dot-mask: url('<?= Http::e((string) $coverUrl) ?>');"
                          aria-hidden="true"></span>
                <?php else: ?>
                    <span class="series-detail-cover-qr" aria-hidden="true">
                        <?= $qrSvg ?>
                        <?php if (!empty($qrMark)): ?>
                            <span class="series-detail-cover-mark"><?= Http::e((string) $qrMark) ?></span>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
                <span class="series-detail-cover-tag">
                    <?= count($posts) ?> PART<?= count($posts) === 1 ? '' : 'S' ?>
                </span>
            </div>
        <?php endif; ?>
        <div class="series-detail-meta">
            <h2 class="series-page-title"><span class="series-page-title-mark"><?= Http::e($seriesTitle) ?></span></h2>
            <?php if (!empty($description)): ?>
                <p class="series-detail-desc"><?= Http::e((string) $description) ?></p>
            <?php endif; ?>
            <?php if (empty($coverUrl) && empty($qrSvg)): ?>
                <p class="series-meta"><?= count($posts) ?> PART<?= count($posts) === 1 ? '' : 'S' ?></p>
            <?php endif; ?>
        </div>
    </header>

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
                    <?php if (!empty($entry['protected'])):
                        $unlocked = Auth::isPostUnlocked((string) $entry['slug']);
                    ?>
                        <div class="series-item-lock">
                            <span class="post-lock<?= $unlocked ? ' post-lock--unlocked' : '' ?>" title="<?= $unlocked ? 'Unlocked this session' : 'Password protected' ?>" aria-label="<?= $unlocked ? 'Unlocked this session' : 'Password protected' ?>">[ <?= $unlocked ? 'UNLOCKED' : 'LOCKED' ?> ]</span>
                        </div>
                    <?php endif; ?>
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
