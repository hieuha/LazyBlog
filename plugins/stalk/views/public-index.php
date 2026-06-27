<?php

declare(strict_types=1);

use App\Auth;
use App\Http;

/**
 * @var list<array<string,mixed>>           $items                posts.json rows (sorted DESC by pub_date)
 * @var array<string,array{handle:string,blog_url:string}> $handles  friend_id => handle/blog_url
 * @var int                                  $friend_count
 * @var int                                  $previous_refresh_at  gate boundary BEFORE the most recent batch
 * @var int                                  $last_refresh_at      gate boundary FOR the most recent batch (= when the most recent fetch ran)
 */

$isAdmin = Auth::check();

// Filter unrenderable rows up-front so counts match what we actually show.
// IMPORTANT: do NOT name any loop-local "$title" — extract() in PluginContext
// makes the layout's `$title` (page title for <title>) live in this scope,
// and a loop variable would clobber it with the last post's title.
$valid = [];
foreach ($items as $row) {
    $itemTitle = (string) ($row['title'] ?? '');
    $itemLink  = (string) ($row['link']  ?? '');
    if ($itemTitle === '' || $itemLink === '') {
        continue;
    }
    $scheme = parse_url($itemLink, PHP_URL_SCHEME);
    if (!in_array($scheme, ['http', 'https'], true)) {
        continue;
    }
    $valid[] = $row;
}

$total = count($valid);

// "NEW" = first cached on or after the boundary BEFORE the last batch
// refresh. Equivalently: this item showed up in the most recent batch.
// On the first-ever refresh `$previous_refresh_at === 0`, so every cached
// item is correctly tagged NEW.
$newCount = 0;
foreach ($valid as $row) {
    $seen = (int) ($row['first_seen_at'] ?? 0);
    if ($seen >= $previous_refresh_at) {
        $newCount++;
    }
}

// Group by year for archive-style separators.
$byYear = [];
foreach ($valid as $row) {
    $ts   = (int) ($row['pub_date'] ?? 0);
    // date() (not gmdate()) honors the TIMEZONE set by core in public/index.php
    $year = $ts > 0 ? date('Y', $ts) : '—';
    $byYear[$year][] = $row;
}

// Short relative-time label for the "LAST REFRESH" line. Feed readers care
// about "how fresh is this" more than the exact timestamp, so we surface
// "2H AGO" / "3D AGO" inline and keep the absolute string in <time title>
// for hover/long-press. Caps + short suffix matches the surrounding
// monospaced status-bar style. Past 30 days falls back to "YYYY-MM-DD" so
// the row still tells the truth on a stale install instead of "120D AGO".
$lastRefreshRel = '';
if ($last_refresh_at > 0) {
    $diff = time() - $last_refresh_at;
    if ($diff < 0) {
        $lastRefreshRel = 'JUST NOW';                              // clock skew safety
    } elseif ($diff < 60) {
        $lastRefreshRel = 'JUST NOW';
    } elseif ($diff < 3600) {
        $lastRefreshRel = ((int) floor($diff / 60))    . 'M AGO';
    } elseif ($diff < 86400) {
        $lastRefreshRel = ((int) floor($diff / 3600))  . 'H AGO';
    } elseif ($diff < 86400 * 30) {
        $lastRefreshRel = ((int) floor($diff / 86400)) . 'D AGO';
    } else {
        $lastRefreshRel = date('Y-m-d', $last_refresh_at);
    }
}
?>

<section class="archive-page stalk-public">
    <h2 class="archive-title">&gt; STALK<?= $total === 0 ? '' : ' (' . $total . ')' ?></h2>
    <p class="archive-range">
        <?php if ($friend_count === 0): ?>
            // CARRIER SILENT — NO FRIENDS STALKED YET
        <?php else: ?>
            <?= Http::e((string) $friend_count) ?> FRIEND<?= $friend_count === 1 ? '' : 'S' ?>
            <?php if ($newCount > 0): ?>
                &nbsp;·&nbsp; <?= Http::e((string) $newCount) ?> NEW
            <?php else: ?>
                &nbsp;·&nbsp; NO NEW POSTS
            <?php endif; ?>
            <?php if ($last_refresh_at > 0): ?>
                &nbsp;·&nbsp; LAST REFRESH
                <time datetime="<?= Http::e(date('c', $last_refresh_at)) ?>"
                      title="<?= Http::e(date('Y-m-d H:i T', $last_refresh_at)) ?>">
                    <?= Http::e($lastRefreshRel) ?>
                </time>
            <?php endif; ?>
        <?php endif; ?>
    </p>

    <?php if ($isAdmin): ?>
        <p class="stalk-public-admin-row">
            <a class="admin-btn admin-btn-primary" href="/admin/stalk">[ + ADD FRIEND ]</a>
        </p>
    <?php endif; ?>

    <?php if ($total === 0): ?>
        <p style="color: var(--text-dim); margin-top: 32px;">
            <?= $isAdmin
                ? '// Add the first friend to start pulling their feed.'
                : '// The operator has not added any LazyBlog feeds yet.' ?>
        </p>
    <?php else: ?>
        <?php foreach ($byYear as $year => $yearItems): ?>
            <div class="archive-year">
                <h3 class="archive-year-label">─ <?= Http::e((string) $year) ?> ─</h3>
                <ul class="archive-list">
                    <?php foreach ($yearItems as $item):
                        $fid       = (string) ($item['friend_id'] ?? '');
                        $itemTitle = (string) ($item['title']     ?? '');
                        $itemLink  = (string) ($item['link']      ?? '');
                        $ts        = (int)    ($item['pub_date']  ?? 0);
                        $seen      = (int)    ($item['first_seen_at'] ?? 0);
                        $date      = $ts > 0 ? date('Y-m-d', $ts) : '—';
                        $hdl       = (string) ($handles[$fid]['handle']   ?? 'anon');
                        $blog      = (string) ($handles[$fid]['blog_url'] ?? '');
                        $isNew     = $seen >= $previous_refresh_at;
                    ?>
                        <li class="archive-item stalk-public-item">
                            <span class="archive-date"><?= Http::e($date) ?></span>
                            <?php if ($blog !== ''): ?>
                                <a class="stalk-public-handle"
                                   href="<?= Http::e(rtrim($blog, '/')) ?>"
                                   target="_blank" rel="noopener noreferrer"
                                   onclick="event.stopPropagation()"
                                   title="<?= Http::e(rtrim($blog, '/')) ?>">@<?= Http::e($hdl) ?></a>
                            <?php else: ?>
                                <span class="stalk-public-handle">@<?= Http::e($hdl) ?></span>
                            <?php endif; ?>
                            <?php if ($isNew): ?>
                                <span class="stalk-public-new">NEW</span>
                            <?php endif; ?>
                            <a class="archive-link stalk-public-link"
                               href="<?= Http::e($itemLink) ?>"
                               target="_blank" rel="noopener noreferrer">
                                <?= Http::e($itemTitle) ?>
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
