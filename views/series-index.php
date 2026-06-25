<?php
/** @var string $title */
/** @var list<array{slug:string,title:string,count:int,firstDate:string,lastDate:string}> $series */

use App\Config;
use App\Http;
use App\QrCache;

$siteUrl = rtrim((string) Config::get('SITE_URL'), '/');
$qr = new QrCache();
?>

<section class="series-page">
    <h2 class="series-page-title">> ALL SERIES (<?= count($series) ?>)</h2>

    <?php if ($series === []): ?>
        <p style="color: var(--text-dim);">// NO SERIES YET. Add `series: my-slug` frontmatter to any post to start one.</p>
    <?php else: ?>
        <ul class="series-card-grid">
            <?php foreach ($series as $s): ?>
                <?php
                // Calendar-day collapse — frontmatter may carry ISO datetime
                // but the card only needs the day.
                $firstDate = substr((string) $s['firstDate'], 0, 10);
                $lastDate = substr((string) $s['lastDate'], 0, 10);
                ?>
                <?php
                $seriesPath = '/series/' . $s['slug'];
                $hasCover = !empty($s['hasCover']);
                if (!$hasCover) {
                    $seriesUrl = $siteUrl !== '' ? $siteUrl . $seriesPath : $seriesPath;
                    $qrSvg = $qr->svg($seriesUrl);
                    $qrMark = QrCache::mark($s['slug']);
                }
                $coverUrl = $hasCover ? Http::seriesAsset((string) $s['slug'], 'cover.webp') : null;
                ?>
                <li class="series-card">
                    <a class="series-card-link" href="<?= Http::e($seriesPath) ?>">
                        <div class="series-card-cover">
                            <?php if ($hasCover): ?>
                                <span class="series-card-cover-dot"
                                      style="--dot-mask: url('<?= Http::e((string) $coverUrl) ?>');"
                                      aria-hidden="true"></span>
                            <?php else: ?>
                                <span class="series-card-cover-qr" aria-hidden="true"><?= $qrSvg ?></span>
                                <span class="series-card-cover-mark" aria-hidden="true"><?= Http::e($qrMark) ?></span>
                            <?php endif; ?>
                            <span class="series-card-tag"><?= (int) $s['count'] ?> PART<?= $s['count'] === 1 ? '' : 'S' ?></span>
                        </div>
                        <div class="series-card-body">
                            <h3 class="series-card-title"><?= Http::e($s['title']) ?></h3>
                            <?php if (!empty($s['description'])): ?>
                                <p class="series-card-desc"><?= Http::e((string) $s['description']) ?></p>
                            <?php endif; ?>
                            <div class="series-card-meta">
                                <?= Http::e($firstDate) ?>
                                <?php if ($firstDate !== $lastDate): ?>
                                    → <?= Http::e($lastDate) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p style="margin-top: 32px;">
        <a class="view-source-link" href="/">← BACK TO INDEX</a>
    </p>
</section>
