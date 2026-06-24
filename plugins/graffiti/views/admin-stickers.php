<?php

declare(strict_types=1);

use App\Http;

/**
 * @var list<array<string,mixed>> $stickers  merged catalogue (ship + override)
 * @var string  $csrf
 * @var ?string $flash
 */

$activeTab = 'stickers';
require __DIR__ . '/admin-shell.php';
?>
<article class="graffiti-section">
    <?php if ($flash !== null): ?>
        <p class="graffiti-flash"><?= Http::e($flash) ?></p>
    <?php endif; ?>

    <table class="admin-table">
        <thead>
            <tr><th>Preview</th><th>ID</th><th>Name</th><th>Energy</th><th>State</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($stickers as $s):
                $id      = (string) ($s['id'] ?? '');
                $name    = (string) ($s['name'] ?? '');
                $svg     = (string) ($s['svg_filename'] ?? '');
                $price   = (int)    ($s['default_price'] ?? 0);
                $enabled = (bool)   ($s['enabled'] ?? false);
            ?>
                <tr>
                    <td>
                        <img src="/plugin-assets/graffiti/<?= Http::e($svg) ?>"
                             alt="<?= Http::e($name) ?>"
                             width="32" height="32"
                             style="background:#0a0a0a;padding:2px;">
                    </td>
                    <td><code><?= Http::e($id) ?></code></td>
                    <td><?= Http::e($name) ?></td>
                    <td>
                        <form method="post" action="/admin/graffiti/stickers/update"
                              class="graffiti-form graffiti-form-inline">
                            <input type="hidden" name="_csrf" value="<?= Http::e($csrf) ?>">
                            <input type="hidden" name="id" value="<?= Http::e($id) ?>">
                            <input class="admin-input" type="number" name="price"
                                   min="0" max="999" value="<?= $price ?>"
                                   style="width:5em;">
                            <button type="submit" class="admin-btn admin-btn-sm">SAVE</button>
                        </form>
                    </td>
                    <td>
                        <code style="<?= $enabled ? '' : 'opacity:0.5' ?>"><?= $enabled ? 'active' : 'hidden' ?></code>
                    </td>
                    <td>
                        <form method="post"
                              action="/admin/graffiti/stickers/toggle/<?= Http::e($id) ?>"
                              class="graffiti-form graffiti-form-inline">
                            <input type="hidden" name="_csrf" value="<?= Http::e($csrf) ?>">
                            <button type="submit"
                                    class="admin-btn admin-btn-sm<?= $enabled ? ' admin-btn-danger' : ' admin-btn-primary' ?>">
                                <?= $enabled ? 'DISABLE' : 'ENABLE' ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</article>
