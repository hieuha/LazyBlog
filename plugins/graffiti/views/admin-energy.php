<?php

declare(strict_types=1);

use App\Http;

/**
 * @var int     $balance
 * @var list<array{ts:int,delta:int,reason:string,details?:array<string,mixed>}> $ledger
 * @var int     $mintPerPost
 * @var int     $page
 * @var int     $totalPages
 * @var int     $total
 * @var string  $pageBaseUrl
 */

$activeTab = 'energy';
require __DIR__ . '/admin-shell.php';

/**
 * Render a human sentence from a ledger entry as escaped HTML. Falls
 * back to the raw reason string for legacy rows that predate the
 * details snapshot. All variable substitutions are escaped at the leaf
 * so the caller can echo the return value directly.
 *
 * @param array{reason:string,details?:array<string,mixed>} $row
 */
$describe = static function (array $row): string {
    $reason = (string) $row['reason'];
    $d = (array) ($row['details'] ?? []);

    $sticker = (string) ($d['sticker_id'] ?? '');
    $text    = (string) ($d['text']       ?? '');
    $slug    = (string) ($d['post_slug']  ?? '');
    $type    = (string) ($d['type']       ?? '');
    $handle  = (string) ($d['friend_handle'] ?? 'friend');
    $blog    = (string) ($d['friend_blog']   ?? '');

    if ($sticker !== '') {
        $what = '[' . Http::e($sticker) . ']';
    } elseif ($text !== '') {
        $what = '"' . Http::e(mb_substr($text, 0, 32)) . '"';
    } elseif ($type !== '') {
        $what = '(' . Http::e($type) . ')';
    } else {
        $what = '(graffiti)';
    }
    $slugTag = '<em>' . Http::e($slug) . '</em>';

    if (str_starts_with($reason, 'post:')) {
        return 'Minted from publishing post: ' . Http::e(substr($reason, 5));
    }
    if ($reason === 'graffiti:self') {
        return "Self-decorated {$slugTag} with {$what}";
    }
    if ($reason === 'graffiti:pending') {
        return "Queued {$what} to @" . Http::e($handle) . "'s {$slugTag}"
            . ($blog !== '' ? ' (' . Http::e($blog) . ')' : '');
    }
    if (str_starts_with($reason, 'graffiti:xs:')) {
        if ($slug === '' && $sticker === '' && $text === '' && $type === '') {
            // Legacy xs row from before the details snapshot landed.
            return Http::e($reason);
        }
        return "Cross-sprayed {$what} on @" . Http::e($handle) . "'s {$slugTag}"
            . ($blog !== '' ? ' (' . Http::e($blog) . ')' : '')
            . ' &mdash; they billed you';
    }
    return Http::e($reason);
};
?>
<article class="graffiti-section">
    <?php if ($ledger === []): ?>
        <p>Empty. Publish a post to earn your first energy.</p>
    <?php else: ?>
        <table class="admin-table">
            <thead><tr><th>When</th><th>Delta</th><th>Reason</th></tr></thead>
            <tbody>
            <?php foreach ($ledger as $row):
                $delta = (int) $row['delta'];
            ?>
                <tr>
                    <td>
                        <time datetime="<?= date('c', (int) $row['ts']) ?>">
                            <?= date('Y-m-d H:i:s', (int) $row['ts']) ?>
                        </time>
                    </td>
                    <td><?= $delta > 0 ? '+' : '' ?><?= $delta ?></td>
                    <td title="<?= Http::e((string) $row['reason']) ?>"><?= $describe($row) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php include __DIR__ . '/../../../views/_pagination.php'; ?>
    <?php endif; ?>
</article>
