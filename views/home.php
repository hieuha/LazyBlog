<?php
/** @var string $title */
/** @var list<array{slug:string,title:string,date:string,tags:list<string>,draft:bool,icon:?string,summary:?string,file:string,mtime:int}> $posts */

use App\Auth;
use App\Http;

// Group posts by calendar day so each date becomes a section header.
// $posts arrives newest-first from the repository, so preserving insertion
// order in the map keeps the visual order chronological.
$postsByDay = [];
foreach ($posts as $entry) {
    $day = substr((string) $entry['date'], 0, 10);
    $postsByDay[$day][] = $entry;
}
?>

<section>
    <h2>> LATEST BROADCASTS</h2>

    <?php if ($posts === []): ?>
        <p style="color: var(--text-dim);">// NO POSTS YET. AIRWAVES QUIET.</p>
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
                            <?php if (!empty($entry['protected']) || !empty($entry['series'])): ?>
                                <div class="post-tags-row">
                                    <?php if (!empty($entry['protected'])):
                                        $unlocked = Auth::isPostUnlocked((string) $entry['slug']);
                                    ?>
                                        <span class="post-lock<?= $unlocked ? ' post-lock--unlocked' : '' ?>" title="<?= $unlocked ? 'Unlocked this session' : 'Password protected' ?>" aria-label="<?= $unlocked ? 'Unlocked this session' : 'Password protected' ?>">[ <?= $unlocked ? 'UNLOCKED' : 'LOCKED' ?> ]</span>
                                    <?php endif; ?>
                                    <?php if (!empty($entry['series'])): ?>
                                        <a class="post-series-tag" href="/series/<?= Http::e((string) $entry['series']) ?>">
                                            <?= Http::e((string) $entry['series']) ?><?php
                                                if (isset($entry['part']) && $entry['part'] !== null) echo ' · PART ' . (int) $entry['part'];
                                            ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>

        <?php include __DIR__ . '/_pagination.php'; ?>
    <?php endif; ?>
</section>
