<?php
/** @var string $title */
/** @var \App\Post $post */
/** @var string $body_html */
/** @var list<array{level:int,id:string,text:string}> $toc */
/** @var array{slug:string,title:string,position:int,total:int,prev:?array<string,mixed>,next:?array<string,mixed>}|null $seriesNav */

use App\Auth;
use App\Http;

$showToc = isset($toc) && count($toc) >= 2;
$isAdmin = Auth::check();
?>

<?php
// Reusable TOC list HTML so we can render both inline + floating without duplicating logic.
$renderTocList = function () use ($toc): void {
    foreach ($toc as $item) {
        echo '<li class="level-' . (int) $item['level'] . '">';
        echo '<a href="#' . htmlspecialchars($item['id'], ENT_QUOTES) . '">' . htmlspecialchars($item['text'], ENT_QUOTES) . '</a>';
        echo '</li>';
    }
};
?>

<article class="post-article">
    <?php
    // Plugin-contributed meta fragments (e.g. view-counter "1 lượt xem").
    // Returned as plain text and appended inline to the transmission tag
    // line — no separate badge box, no extra styling.
    $metaSlots = Http::plugins()?->slotPostMeta(['slug' => $post->slug]) ?? [];
    ?>
    <?php
    // Show wall-clock time alongside the date when the frontmatter `date:`
    // carries an ISO datetime. Legacy date-only entries stay date-only so
    // a one-day-precision post doesn't grow a fake `00:00` tail.
    $postWhen = $post->hasExplicitTime()
        ? $post->dateTime()->format('Y-m-d H:i')
        : $post->displayDate();
    ?>
    <div class="section-tag">
        § <?php if ($post->isProtected()): ?><span class="post-lock post-lock--unlocked" title="Unlocked this session" aria-label="Unlocked this session">[ <i class="fa-solid fa-unlock-keyhole" aria-hidden="true"></i> ]</span> <?php endif; ?>TRANSMISSION — <?= Http::e($postWhen) ?><?php
            if ($post->author !== null && $post->author !== '') {
                echo ' — ' . Http::e($post->author);
            }
            foreach ($metaSlots as $fragment) {
                echo ' — ' . $fragment; /* trusted: plugin output */
            }
        ?>
    </div>
    <h1 class="post-page-title">
        <?php if ($post->icon !== null && $post->icon !== ''): ?>
            <span class="post-icon"><?= Http::e($post->icon) ?></span>
        <?php endif; ?>
        <?= Http::e($post->title) ?>
    </h1>

    <?php if ($seriesNav !== null): ?>
        <a class="series-banner" href="/series/<?= Http::e($seriesNav['slug']) ?>" aria-label="View full series">
            <span class="series-banner-label">§ PART <?= $seriesNav['position'] ?> OF <?= $seriesNav['total'] ?></span>
            <span class="series-banner-title"><?= Http::e($seriesNav['title']) ?></span>
        </a>
    <?php endif; ?>

    <?php if ($showToc): ?>
        <nav class="toc toc-inline" aria-label="Table of contents">
            <div class="toc-label">§ NAVIGATION</div>
            <ul class="toc-list"><?php $renderTocList(); ?></ul>
        </nav>
    <?php endif; ?>

    <div class="post-body">
        <?= $body_html /* trusted: rendered by MarkdownRenderer, source HTML escaped */ ?>
    </div>

    <?php if ($seriesNav !== null && ($seriesNav['prev'] !== null || $seriesNav['next'] !== null)): ?>
        <nav class="series-nav" aria-label="Series navigation">
            <?php if ($seriesNav['prev'] !== null): ?>
                <a class="series-nav-link series-nav-prev" href="/posts/<?= Http::e($seriesNav['prev']['slug']) ?>">
                    <span class="series-nav-direction">← PREV · PART <?= $seriesNav['position'] - 1 ?> OF <?= $seriesNav['total'] ?></span>
                    <span class="series-nav-title"><?= Http::e($seriesNav['prev']['title']) ?></span>
                </a>
            <?php else: ?>
                <span class="series-nav-link series-nav-empty" aria-hidden="true"></span>
            <?php endif; ?>
            <?php if ($seriesNav['next'] !== null): ?>
                <a class="series-nav-link series-nav-next" href="/posts/<?= Http::e($seriesNav['next']['slug']) ?>">
                    <span class="series-nav-direction">NEXT · PART <?= $seriesNav['position'] + 1 ?> OF <?= $seriesNav['total'] ?> →</span>
                    <span class="series-nav-title"><?= Http::e($seriesNav['next']['title']) ?></span>
                </a>
            <?php else: ?>
                <span class="series-nav-link series-nav-empty" aria-hidden="true"></span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>

    <?php if ($post->tags !== []): ?>
        <div class="section-tag post-tags-label">§ TAGS</div>
        <div class="post-meta-tags">
            <?php foreach ($post->tags as $t): ?>
                <a class="tag-chip" href="/tags/<?= Http::e($t) ?>">#<?= Http::e($t) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="post-footer">
        <a class="view-source-link" href="<?= Http::e($post->rawUrl()) ?>" title="View source .md">[ .MD ]</a>
        <?php if ($isAdmin): ?>
            <a class="view-source-link view-source-link-edit" href="/admin/edit/<?= Http::e($post->slug) ?>">[ EDIT ]</a>
            <a class="view-source-link view-source-link-edit" href="/writer?slug=<?= Http::e($post->slug) ?>">[ ZEN ]</a>
        <?php endif; ?>
        <a class="view-source-link" href="/" title="Back to home">[ HOME ]</a>
    </div>

    <?php
    // Plugin-contributed overlays anchored to the article box (e.g. graffiti
    // stickers). Rendered last so `.post-article { position: relative }` set
    // by a plugin asset can anchor `position:absolute; inset:0` containers.
    foreach (Http::plugins()?->slotPostArticleEnd(['slug' => $post->slug]) ?? [] as $fragment) {
        echo $fragment; /* trusted: plugin output */
    }
    ?>
</article>

<?php if ($showToc): ?>
    <aside class="post-toc-wrap" aria-hidden="true">
        <nav class="toc" aria-label="Floating table of contents">
            <div class="toc-label">§ NAVIGATION</div>
            <ul class="toc-list"><?php $renderTocList(); ?></ul>
        </nav>
    </aside>
<?php endif; ?>
