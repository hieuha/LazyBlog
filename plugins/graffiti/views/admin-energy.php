<?php

declare(strict_types=1);

use App\Http;

/**
 * @var int     $balance
 * @var list<array{ts:int,delta:int,reason:string}> $ledger
 * @var int     $mintPerPost
 * @var int     $page
 * @var int     $totalPages
 * @var int     $total
 * @var string  $pageBaseUrl
 */

$activeTab = 'energy';
require __DIR__ . '/admin-shell.php';
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
                    <td><?= Http::e((string) $row['reason']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php include __DIR__ . '/../../../views/_pagination.php'; ?>
    <?php endif; ?>
</article>
