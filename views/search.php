<?php
/** @var string $title */
/** @var string $q */
/** @var list<array{slug:string,title:string,date:string,tags:list<string>,icon:?string,score:int,snippet:string}> $hits */
/** @var \Closure $fold */

use App\Auth;
use App\Http;

/**
 * Wrap each search term (case- AND diacritic-insensitive) in <mark>. Walks
 * the folded text to find match offsets, then maps them back to the
 * original string char-by-char (fold is 1:1 so positions align).
 */
$highlight = static function (string $text, string $q) use ($fold): string {
    if ($q === '') {
        return Http::e($text);
    }
    $terms = array_values(array_filter(
        preg_split('/\s+/u', $fold(trim($q))) ?: [],
        static fn (string $t): bool => mb_strlen($t) >= 2,
    ));
    if ($terms === []) {
        return Http::e($text);
    }

    $folded = $fold($text);
    $ranges = [];
    foreach ($terms as $term) {
        $offset = 0;
        while (($pos = mb_strpos($folded, $term, $offset)) !== false) {
            $ranges[] = [$pos, $pos + mb_strlen($term)];
            $offset = $pos + mb_strlen($term);
        }
    }
    if ($ranges === []) {
        return Http::e($text);
    }

    // Merge overlapping ranges so nested <mark>s don't form.
    usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
    $merged = [];
    foreach ($ranges as $r) {
        if ($merged !== [] && $r[0] <= $merged[count($merged) - 1][1]) {
            $merged[count($merged) - 1][1] = max($merged[count($merged) - 1][1], $r[1]);
        } else {
            $merged[] = $r;
        }
    }

    $out = '';
    $cursor = 0;
    foreach ($merged as [$start, $end]) {
        $out .= Http::e(mb_substr($text, $cursor, $start - $cursor));
        $out .= '<mark>' . Http::e(mb_substr($text, $start, $end - $start)) . '</mark>';
        $cursor = $end;
    }
    $out .= Http::e(mb_substr($text, $cursor));
    return $out;
};
?>

<section class="search-page">
    <h2>> SEARCH TRANSMISSIONS</h2>
    <form class="search-form" method="get" action="/search" role="search">
        <input
            type="search"
            name="q"
            value="<?= Http::e($q) ?>"
            placeholder="tune the dial…"
            autofocus
            autocomplete="off"
            class="search-input"
            aria-label="Search query"
        >
        <button type="submit" class="search-submit">[ SCAN ]</button>
    </form>

    <?php if ($q === ''): ?>
        <p class="search-hint">// Type a query to scan all transmissions. Vietnamese diacritics optional.</p>
    <?php elseif ($hits === []): ?>
        <p class="search-empty">// NO SIGNAL ON FREQUENCY "<?= Http::e($q) ?>"</p>
    <?php else: ?>
        <p class="search-meta">
            <?= count($hits) ?> result<?= count($hits) === 1 ? '' : 's' ?>
            for "<?= Http::e($q) ?>"
        </p>
        <ul class="search-results">
            <?php foreach ($hits as $hit): ?>
                <li class="search-item">
                    <div class="search-result-meta">
                        <span class="search-date"><?= Http::e(substr((string) $hit['date'], 0, 10)) ?></span>
                        <?php foreach ($hit['tags'] as $tag): ?>
                            <a class="tag-chip" href="/tags/<?= Http::e($tag) ?>">#<?= Http::e($tag) ?></a>
                        <?php endforeach; ?>
                    </div>
                    <a class="search-title-link" href="/posts/<?= Http::e($hit['slug']) ?>">
                        <span class="search-title">
                            <?php if (($hit['snippet'] ?? '') === '// protected post'):
                                $unlocked = Auth::isPostUnlocked((string) $hit['slug']);
                            ?><i class="fa fa-<?= $unlocked ? 'unlock-alt' : 'lock' ?> post-title-lock" title="<?= $unlocked ? 'Unlocked this session' : 'Password protected' ?>" aria-label="<?= $unlocked ? 'Unlocked this session' : 'Password protected' ?>"></i> <?php endif; ?>
                            <?php if (!empty($hit['icon'])): ?><span class="search-icon"><?= Http::e($hit['icon']) ?></span> <?php endif; ?>
                            <?= $highlight($hit['title'], $q) ?>
                        </span>
                    </a>
                    <?php if (($hit['snippet'] ?? '') !== '// protected post'): ?>
                        <p class="search-snippet"><?= $highlight($hit['snippet'], $q) ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
