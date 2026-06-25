<?php
/** @var string $title */
/** @var list<array{slug:string,title:string,date:string,tags:list<string>,draft:bool,icon:?string,summary:?string,file:string,mtime:int}> $posts */
/** @var array<string,list<array<string,mixed>>> $byYear */
/** @var array{weeks:list<list<array{date:string,count:int,posts:list<array<string,mixed>>,inRange:bool}>>,monthLabels:array<int,string>,maxCount:int,firstDate:string,lastDate:string} $heatmap */
/** @var int $total */

use App\Http;

/**
 * Map post count → intensity bucket for cell coloring. 0 = empty,
 * 1/2/3+ = three increasing levels. Kept inline since it's only used here.
 */
$intensity = static function (int $count, bool $inRange): string {
    if (!$inRange) {
        return 'cell-outside';
    }
    if ($count === 0) {
        return 'cell-0';
    }
    if ($count === 1) {
        return 'cell-1';
    }
    if ($count === 2) {
        return 'cell-2';
    }
    return 'cell-3';
};
?>

<section class="archive-page">
    <h2 class="archive-title">> TRANSMISSION<?= $total === 1 ? '' : 'S' ?> (<?= $total ?>)</h2>
    <p class="archive-range">
        FROM <?= Http::e($heatmap['firstDate']) ?>
        &nbsp;→&nbsp;
        <?= Http::e($heatmap['lastDate']) ?>
    </p>

    <div class="heatmap-wrap" role="figure" aria-label="Posting activity heatmap">
        <div class="heatmap-months">
            <?php foreach ($heatmap['weeks'] as $weekIndex => $_): ?>
                <span class="heatmap-month-label" data-col="<?= $weekIndex ?>">
                    <?= Http::e($heatmap['monthLabels'][$weekIndex] ?? '') ?>
                </span>
            <?php endforeach; ?>
        </div>
        <div class="heatmap-body">
            <div class="heatmap-dow" aria-hidden="true">
                <span>Mon</span><span></span><span>Wed</span><span></span><span>Fri</span><span></span><span></span>
            </div>
            <div class="heatmap-grid">
                <?php foreach ($heatmap['weeks'] as $week): ?>
                    <div class="heatmap-week">
                        <?php foreach ($week as $day):
                            $cls = $intensity($day['count'], $day['inRange']);
                            $title = $day['count'] === 0
                                ? $day['date'] . ' — no transmission'
                                : $day['date'] . ' — ' . $day['count'] . ' transmission' . ($day['count'] === 1 ? '' : 's');
                            $href = null;
                            if ($day['count'] === 1) {
                                $href = '/posts/' . rawurlencode($day['posts'][0]['slug']);
                            } elseif ($day['count'] > 1) {
                                $href = '#day-' . $day['date'];
                            }
                        ?>
                            <?php if ($href !== null): ?>
                                <a class="heatmap-cell <?= $cls ?>" href="<?= Http::e($href) ?>" title="<?= Http::e($title) ?>" aria-label="<?= Http::e($title) ?>"></a>
                            <?php else: ?>
                                <span class="heatmap-cell <?= $cls ?>" title="<?= Http::e($title) ?>" aria-hidden="true"></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="heatmap-legend">
            <span>Less</span>
            <span class="heatmap-cell cell-0"></span>
            <span class="heatmap-cell cell-1"></span>
            <span class="heatmap-cell cell-2"></span>
            <span class="heatmap-cell cell-3"></span>
            <span>More</span>
        </div>
    </div>

    <?php if ($posts === []): ?>
        <p style="color: var(--text-dim); margin-top: 32px;">// CARRIER SILENT — NO TRANSMISSIONS YET.</p>
    <?php else: ?>
        <?php foreach ($byYear as $year => $yearPosts): ?>
            <div class="archive-year">
                <h3 class="archive-year-label">─ <?= Http::e($year) ?> ─</h3>
                <ul class="archive-list">
                    <?php foreach ($yearPosts as $entry): ?>
                        <?php $entryDate = substr((string) $entry['date'], 0, 10); ?>
                        <li class="archive-item" id="day-<?= Http::e($entryDate) ?>">
                            <span class="archive-date"><?= Http::e($entryDate) ?></span>
                            <?php if (!empty($entry['series'])): ?>
                                <a class="post-series-tag" href="/series/<?= Http::e((string) $entry['series']) ?>" onclick="event.stopPropagation()">
                                    <?= Http::e((string) $entry['series']) ?><?php
                                        if (isset($entry['part']) && $entry['part'] !== null) echo ' · P' . (int) $entry['part'];
                                    ?>
                                </a>
                            <?php endif; ?>
                            <a class="archive-link" href="/posts/<?= Http::e($entry['slug']) ?>">
                                <?php if (!empty($entry['icon'])): ?><span class="archive-icon"><?= Http::e($entry['icon']) ?></span> <?php endif; ?>
                                <?= Http::e($entry['title']) ?>
                                <?php if (!empty($entry['protected'])): ?> <span class="post-lock" title="Password protected" aria-label="Password protected">🔒</span><?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <p style="margin-top: 32px;">
        <a class="view-source-link" href="/">← BACK TO INDEX</a>
    </p>
</section>
