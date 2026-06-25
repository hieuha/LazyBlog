<?php
/** @var string $title */
/** @var \App\Post $post */
/** @var ?string $error */
/** @var bool $throttled */
/** @var string $csrf */

use App\Http;

$hasError = $error !== null;
$throttled = $throttled ?? false;
?>

<article class="post-article post-locked">
    <section class="alert-panel<?= $hasError ? ' alert-panel-error' : '' ?>" role="alert" aria-live="polite">
        <p class="alert-eyebrow">// ACCESS RESTRICTED</p>

        <h1 class="alert-headline"><?= Http::e($post->title) ?></h1>

        <div class="alert-meta">
            <?= Http::e(strtoupper($post->displayDate())) ?>
            <?php if ($post->author !== null && $post->author !== ''): ?>
                <span class="alert-meta-sep">·</span> <?= Http::e(strtoupper($post->author)) ?>
            <?php endif; ?>
        </div>

        <?php if ($post->summary !== null && $post->summary !== ''): ?>
            <p class="alert-summary"><?= Http::e($post->summary) ?></p>
        <?php endif; ?>

        <form method="post" action="/posts/<?= Http::e($post->slug) ?>/unlock" class="alert-form" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= Http::e($csrf) ?>">

            <label class="alert-input-label" for="post-password">
                <span class="alert-input-tick">►</span> ENTER ACCESS KEY
            </label>
            <input type="password" name="password" id="post-password" required
                   <?= $throttled ? 'disabled' : 'autofocus' ?>
                   autocomplete="one-time-code" autocorrect="off" spellcheck="false"
                   data-lpignore="true" data-1p-ignore="true" data-bwignore="true"
                   class="alert-input<?= $hasError ? ' alert-input-error' : '' ?>"
                   placeholder="<?= $hasError ? Http::e(strtoupper($error)) : '••••••••••' ?>">

            <div class="alert-actions">
                <button type="submit" class="alert-btn alert-btn-primary"
                        <?= $throttled ? 'disabled' : '' ?>>[ AUTHENTICATE ]</button>
                <a class="alert-btn" href="/">[ ABORT ]</a>
            </div>
        </form>

        <p class="alert-footer">
            SYSTEM ALERT AL-<?= Http::e(strtoupper(substr(md5($post->slug), 0, 4))) ?>
            <span class="alert-meta-sep">·</span> TRANSMISSION GATED
        </p>
    </section>
</article>
