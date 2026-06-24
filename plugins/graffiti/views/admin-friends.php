<?php

declare(strict_types=1);

use App\Http;

/**
 * @var list<array<string,mixed>> $friends
 * @var string $csrf
 * @var ?string $flash
 * @var ?string $block   invite block to display after invite/accept POST
 */

$activeTab = 'friends';
require __DIR__ . '/admin-shell.php';
?>
<article class="graffiti-section">
    <?php if ($flash !== null): ?>
        <p class="graffiti-flash"><?= Http::e($flash) ?></p>
    <?php endif; ?>

    <?php if ($block !== null): ?>
        <section class="graffiti-block">
            <h2>// INVITE BLOCK</h2>
            <p>Copy this and send to your friend over a private channel:</p>
            <textarea readonly rows="4" class="graffiti-block-text"
                      onclick="this.select()"><?= Http::e($block) ?></textarea>
        </section>
    <?php endif; ?>

    <section>
        <h2>// CURRENT FRIENDS</h2>
        <?php if ($friends === []): ?>
            <p>None yet.</p>
        <?php else: ?>
            <table class="admin-table">
                <thead><tr><th>Handle</th><th>Blog</th><th>State</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($friends as $f):
                    $id    = (string) ($f['id']    ?? '');
                    $hdl   = (string) ($f['handle'] ?? '');
                    $url   = (string) ($f['blog_url'] ?? '');
                    $state = (string) ($f['state']  ?? 'pending');
                ?>
                    <tr id="friend-<?= Http::e($id) ?>">
                        <td><?= Http::e($hdl) ?></td>
                        <td><a href="<?= Http::e($url) ?>" rel="noopener"><?= Http::e($url) ?></a></td>
                        <td><code><?= Http::e($state) ?></code></td>
                        <td>
                            <?php if ($state !== 'revoked'): ?>
                                <form method="post"
                                      action="/admin/graffiti/friends/revoke/<?= Http::e($id) ?>"
                                      onsubmit="return confirm('Revoke <?= Http::e($hdl) ?>?');">
                                    <input type="hidden" name="_csrf" value="<?= Http::e($csrf) ?>">
                                    <button type="submit" class="admin-btn admin-btn-sm admin-btn-danger">REVOKE</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section>
        <h2>// INVITE A FRIEND</h2>
        <form method="post" action="/admin/graffiti/friends/invite" class="admin-form-row">
            <input type="hidden" name="_csrf" value="<?= Http::e($csrf) ?>">
            <div class="admin-field admin-field-grow">
                <label class="admin-label">Their handle</label>
                <input class="admin-input" type="text" name="handle" maxlength="80" required>
            </div>
            <div class="admin-field admin-field-grow">
                <label class="admin-label">Their blog URL</label>
                <input class="admin-input" type="url" name="blog_url" placeholder="https://blog-of-friend.example" required>
            </div>
            <button type="submit" class="admin-btn admin-btn-primary">GENERATE INVITE</button>
        </form>
    </section>

    <section>
        <h2>// ACCEPT AN INVITE</h2>
        <form method="post" action="/admin/graffiti/friends/accept" class="admin-form">
            <input type="hidden" name="_csrf" value="<?= Http::e($csrf) ?>">
            <div class="admin-field">
                <label class="admin-label">Invite block</label>
                <textarea class="admin-input" name="block" rows="3" required
                          placeholder="paste invite block here"></textarea>
            </div>
            <div>
                <button type="submit" class="admin-btn">ACCEPT</button>
            </div>
        </form>
    </section>

</article>
