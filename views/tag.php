<?php
/** @var string $title */
/** @var string $tag */
/** @var list<array{slug:string,title:string,date:string,tags:list<string>,draft:bool,icon:?string,summary:?string,file:string,mtime:int}> $posts */

use App\Http;

// Group by calendar day — same timeline pattern as home.php so /tags/*
// reads as the same kind of feed, just scoped to one tag.
$postsByDay = [];
foreach ($posts as $entry) {
    $day = substr((string) $entry['date'], 0, 10);
    $postsByDay[$day][] = $entry;
}
?>

<section>
    <h2 class="tag-page-title">#<?= Http::e($tag) ?></h2>

    <?php if ($posts === []): ?>
        <p style="color: var(--text-dim);">// NO TRANSMISSIONS ON THIS FREQUENCY.</p>
    <?php else: ?>
        <?php foreach ($postsByDay as $day => $entries): ?>
            <section class="post-date-group">
                <h3 class="post-date-header"><span class="post-date-header-text"><?= Http::e(str_replace('-', '·', $day)) ?></span></h3>
                <ul class="post-list">
                    <?php foreach ($entries as $entry): ?>
                        <li class="post-item">
                            <a class="post-title-link" href="/posts/<?= Http::e($entry['slug']) ?>">
                                <span class="post-title">
                                    <?php if (!empty($entry['icon'])): ?><span class="post-icon"><?= Http::e($entry['icon']) ?></span> <?php endif; ?>
                                    <?= Http::e($entry['title']) ?>
                                </span>
                            </a>
                            <?php if (!empty($entry['summary'])): ?>
                                <p class="post-summary"><?= Http::e($entry['summary']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($entry['series']) || $entry['tags'] !== []): ?>
                                <div class="post-tags-row">
                                    <?php if (!empty($entry['series'])): ?>
                                        <a class="post-series-tag" href="/series/<?= Http::e((string) $entry['series']) ?>">
                                            <?= Http::e((string) $entry['series']) ?><?php
                                                if (isset($entry['part']) && $entry['part'] !== null) echo ' · PART ' . (int) $entry['part'];
                                            ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php foreach ($entry['tags'] as $t): ?>
                                        <a class="tag-chip" href="/tags/<?= Http::e($t) ?>">#<?= Http::e($t) ?></a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>

        <?php include __DIR__ . '/_pagination.php'; ?>
    <?php endif; ?>

    <p style="margin-top: 32px;">
        <a class="view-source-link" href="/">← BACK TO INDEX</a>
    </p>
</section>
