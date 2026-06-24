<?php
/** @var string $title */
/** @var string $tag */
/** @var list<array{slug:string,title:string,date:string,tags:list<string>,draft:bool,icon:?string,summary:?string,file:string,mtime:int}> $posts */

use App\Http;
?>

<section>
    <h2 class="tag-page-title">#<?= Http::e($tag) ?></h2>

    <?php if ($posts === []): ?>
        <p style="color: var(--text-dim);">// NO TRANSMISSIONS ON THIS FREQUENCY.</p>
    <?php else: ?>
        <ul class="post-list">
            <?php foreach ($posts as $entry): ?>
                <li class="post-item">
                    <div class="post-meta">
                        <span class="post-date"><?= Http::e(str_replace('-', '·', substr((string) $entry['date'], 0, 10))) ?></span>
                        <?php if (!empty($entry['series'])): ?>
                            <a class="post-series-tag" href="/series/<?= Http::e((string) $entry['series']) ?>">
                                <?= Http::e((string) $entry['series']) ?><?php
                                    if (isset($entry['part']) && $entry['part'] !== null) echo ' · PART ' . (int) $entry['part'];
                                ?>
                            </a>
                        <?php endif; ?>
                    </div>
                    <a class="post-title-link" href="/posts/<?= Http::e($entry['slug']) ?>">
                        <span class="post-title">
                            <?php if (!empty($entry['icon'])): ?><span class="post-icon"><?= Http::e($entry['icon']) ?></span> <?php endif; ?>
                            <?= Http::e($entry['title']) ?>
                        </span>
                    </a>
                    <?php if (!empty($entry['summary'])): ?>
                        <p class="post-summary"><?= Http::e($entry['summary']) ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php include __DIR__ . '/_pagination.php'; ?>
    <?php endif; ?>

    <p style="margin-top: 32px;">
        <a class="view-source-link" href="/">← BACK TO INDEX</a>
    </p>
</section>
