<?php

declare(strict_types=1);

use App\Http;

/** @var list<array{ts:int,text:string}> $recent */
/** @var string $csrf */
?>
<article class="hello-page">
    <h1 class="post-page-title">// HELLO WORLD</h1>

    <p>
        Reference plugin for LazyBlog. Type a message, hit
        <code>[ TRANSMIT ]</code>, and the last five entries echo back
        below. Read the plugin's <code>README.md</code> for the source
        walkthrough.
    </p>

    <form method="post" action="/hello/echo" class="hello-form">
        <input type="hidden" name="_csrf" value="<?= Http::e($csrf) ?>">
        <label for="hello-text">message</label>
        <input id="hello-text" name="text" type="text" maxlength="200"
               autocomplete="off" required placeholder="hello, world">
        <button type="submit">[ TRANSMIT ]</button>
    </form>

    <?php if ($recent !== []): ?>
        <h2 class="hello-subhead">// RECENT TRANSMISSIONS</h2>
        <ul class="hello-list">
            <?php foreach ($recent as $entry): ?>
                <li>
                    <time><?= Http::e(date('Y-m-d H:i', $entry['ts'])) ?></time>
                    <span class="hello-sep">—</span>
                    <span class="hello-text"><?= Http::e($entry['text']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</article>
