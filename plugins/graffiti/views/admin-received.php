<?php

declare(strict_types=1);

use App\Http;

/**
 * @var list<array<string,mixed>> $items   newest-first, pre-decorated with
 *                                          `_friend` row and `_preview` text
 * @var list<string> $unseenIds            ids that were unseen at page load
 * @var string  $csrf
 * @var ?string $flash
 * @var int     $page
 * @var int     $totalPages
 * @var int     $total
 * @var string  $pageBaseUrl
 */

$activeTab = 'received';
require __DIR__ . '/admin-shell.php';
$unseenSet = array_flip($unseenIds);
?>
<article class="graffiti-section">
    <?php if ($flash !== null): ?>
        <p class="graffiti-flash"><?= Http::e($flash) ?></p>
    <?php endif; ?>

    <?php if ($items === []): ?>
        <p>No graffiti yet. Share an invite from the
            <a href="/admin/graffiti/friends">[ FRIENDS ]</a> tab to start.</p>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr><th>When</th><th>From</th><th>Post</th><th>Type</th><th>Preview</th><th>State</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($items as $row):
                    $id    = (string) ($row['id'] ?? '');
                    $ts    = (int)    ($row['received_at'] ?? 0);
                    $type  = (string) ($row['type'] ?? '');
                    $slug  = (string) ($row['post_slug'] ?? '');
                    $hidden = (bool)  ($row['hidden'] ?? false);
                    $friend = (array) ($row['_friend'] ?? []);
                    $handle = (string) ($friend['handle'] ?? 'unknown');
                    $blog   = (string) ($friend['blog_url'] ?? '');
                    $preview = (string) ($row['_preview'] ?? '');
                    $isNew = isset($unseenSet[$id]);
                ?>
                    <tr<?= $hidden ? ' style="opacity:0.45"' : '' ?>>
                        <td>
                            <time datetime="<?= date('c', $ts) ?>"><?= date('Y-m-d H:i', $ts) ?></time>
                            <?php if ($isNew): ?> <span class="graffiti-badge-new">[NEW]</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($blog !== ''): ?>
                                <a href="<?= Http::e($blog) ?>" rel="noopener"><?= Http::e($handle) ?></a>
                            <?php else: ?>
                                <?= Http::e($handle) ?>
                            <?php endif; ?>
                        </td>
                        <td><a href="/posts/<?= Http::e($slug) ?>"><?= Http::e($slug) ?></a></td>
                        <td><code><?= Http::e($type) ?></code></td>
                        <td><?= Http::e($preview) ?></td>
                        <td>
                            <?php if ($hidden): ?>
                                <code>hidden</code>
                            <?php else: ?>
                                <code>visible</code>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="graffiti-row-actions">
                                <form method="post"
                                      action="/admin/graffiti/<?= $hidden ? 'unhide' : 'hide' ?>/<?= Http::e($id) ?>"
                                      class="graffiti-form graffiti-form-inline">
                                    <input type="hidden" name="_csrf" value="<?= Http::e($csrf) ?>">
                                    <button type="submit" class="admin-btn admin-btn-sm"><?= $hidden ? 'UNHIDE' : 'HIDE' ?></button>
                                </form>
                                <form method="post"
                                      action="/admin/graffiti/delete/<?= Http::e($id) ?>"
                                      class="graffiti-form graffiti-form-inline"
                                      onsubmit="return confirm('Permanently delete this graffiti? Cannot undo.');">
                                    <input type="hidden" name="_csrf" value="<?= Http::e($csrf) ?>">
                                    <button type="submit" class="admin-btn admin-btn-sm admin-btn-danger">DEL</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php include __DIR__ . '/../../../views/_pagination.php'; ?>
    <?php endif; ?>
</article>
