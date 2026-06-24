<?php

declare(strict_types=1);

use App\Http;

/**
 * @var list<array<string,mixed>> $friends         active friends
 * @var array<string,mixed>       $selfStub        synthetic self entry (id='self')
 * @var ?array<string,mixed>      $selectedFriend  currently selected target (friend or self) or null
 * @var list<array{id:string,name:string,price:int}> $catalogue  selected target's catalogue
 * @var int    $balance
 * @var string $csrf
 * @var ?string $flash
 */

$activeTab = 'send';
require __DIR__ . '/admin-shell.php';
?>
<article class="graffiti-section">
    <p class="graffiti-balance">
        My Energy: <strong>[ <?= (int) $balance ?> ]</strong>
    </p>

    <?php if ($flash !== null): ?>
        <p class="graffiti-flash"><?= Http::e($flash) ?></p>
    <?php endif; ?>

        <section>
            <h2>// PICK A TARGET</h2>
            <ul class="graffiti-friend-list">
                <li>
                    <a href="/admin/graffiti/send?friend=self">
                        ✦ Yourself <small>(decorate your own posts — costs energy at your own prices)</small>
                    </a>
                </li>
                <?php foreach ($friends as $f): ?>
                    <li>
                        <a href="/admin/graffiti/send?friend=<?= Http::e((string) $f['id']) ?>">
                            <?= Http::e((string) $f['handle']) ?>
                            <small>(<?= Http::e((string) $f['blog_url']) ?>)</small>
                        </a>
                    </li>
                <?php endforeach; ?>
                <?php if ($friends === []): ?>
                    <li><small>No friends yet — add one on the <a href="/admin/graffiti/friends">[ FRIENDS ]</a> tab.</small></li>
                <?php endif; ?>
            </ul>
        </section>

        <?php if ($selectedFriend !== null): $isSelf = ($selectedFriend['id'] ?? '') === 'self'; ?>
            <section>
                <h2>// COMPOSE → <?= $isSelf ? '✦ YOURSELF' : Http::e((string) $selectedFriend['handle']) ?></h2>

                <?php if ($catalogue === []): ?>
                    <p>
                        <?php if ($isSelf): ?>
                            Your sticker catalogue is empty or fully disabled. Toggle some on
                            in the <a href="/admin/graffiti/stickers">[ STICKERS ]</a> tab.
                        <?php else: ?>
                            Target's catalogue is empty or unreachable. Try again in a minute
                            or check their <code>/graffiti/stickers.json</code>.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

                <form method="post" action="/admin/graffiti/send/submit" class="graffiti-form">
                    <input type="hidden" name="_csrf" value="<?= Http::e($csrf) ?>">
                    <input type="hidden" name="friend_id" value="<?= Http::e((string) $selectedFriend['id']) ?>">

                    <label>Target post slug
                        <input type="text" name="post_slug" required maxlength="80"
                               placeholder="some-existing-slug">
                    </label>

                    <fieldset>
                        <legend>Type</legend>
                        <label><input type="radio" name="type" value="sticker" checked> Sticker</label>
                        <label><input type="radio" name="type" value="spray"> Spray</label>
                        <label><input type="radio" name="type" value="text"> Text</label>
                    </fieldset>

                    <?php if ($catalogue !== []): ?>
                        <fieldset>
                            <legend>Sticker (skip for type=text)</legend>
                            <select name="sticker_id">
                                <option value="">— pick one —</option>
                                <?php foreach ($catalogue as $row): ?>
                                    <option value="<?= Http::e($row['id']) ?>">
                                        <?= Http::e($row['name']) ?> — <?= (int) $row['price'] ?> energy
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </fieldset>
                    <?php endif; ?>

                    <label>Text (only when type=text, ≤140 chars)
                        <input type="text" name="text" maxlength="140">
                    </label>

                    <fieldset>
                        <legend>Position</legend>
                        <label>x (0–1) <input type="number" name="x" min="0" max="1" step="0.01" value="0.5"></label>
                        <label>y (0–1) <input type="number" name="y" min="0" max="1" step="0.01" value="0.5"></label>
                        <label>rotation (-180–180) <input type="number" name="rotation" min="-180" max="180" step="1" value="0"></label>
                    </fieldset>

                    <button type="submit" class="admin-btn admin-btn-primary">SEND</button>
                </form>
            </section>
        <?php endif; ?>
</article>
