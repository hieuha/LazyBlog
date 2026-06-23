<?php

declare(strict_types=1);

use App\Http;

/** @var list<array{ts:int,text:string}> $echoes */
?>
<article class="hello-admin">
    <h1 class="post-page-title">// HELLO WORLD // ADMIN</h1>

    <p>Total echoes: <strong><?= count($echoes) ?></strong></p>

    <?php if ($echoes === []): ?>
        <p>No transmissions yet. Visit <a href="/hello">/hello</a> to submit one.</p>
    <?php else: ?>
        <table class="hello-admin-table">
            <thead>
                <tr><th>Timestamp</th><th>Message</th></tr>
            </thead>
            <tbody>
                <?php foreach ($echoes as $entry): ?>
                    <tr>
                        <td><time><?= Http::e(date('Y-m-d H:i:s', $entry['ts'])) ?></time></td>
                        <td><?= Http::e($entry['text']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</article>
