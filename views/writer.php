<?php
/**
 * Writer Mode — standalone fullscreen view, deliberately bypasses layout.php.
 *
 * Goal: zero distraction. No header, no footer, no nav, no theme picker, no
 * back-to-top button, no CRT scanlines/noise overlays. Just the editor, two
 * action buttons, a tiny save-status pill, and a hidden modal for title +
 * summary capture.
 *
 * @var string $title
 * @var string $csrf
 * @var \App\Post|null $existingPost
 * @var string $existingFilename
 */

use App\Config;
use App\Http;

$existingPost = $existingPost ?? null;
$existingFilename = $existingFilename ?? '';

$siteDefaultTheme = strtolower((string) Config::get('SITE_DEFAULT_THEME', 'amber'));
$validThemes = ['amber', 'green', 'crypt', 'brutalist', 'p7', 'p11'];
if (!in_array($siteDefaultTheme, $validThemes, true)) {
    $siteDefaultTheme = 'amber';
}
$favicon = 'data:image/svg+xml,'
    . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
        . '<rect width="32" height="32" fill="#0a0e0a"/>'
        . '<text x="4" y="24" font-family="monospace" font-size="22" fill="#39ff14">&gt;_</text>'
        . '</svg>');
?>
<!DOCTYPE html>
<html lang="vi" data-theme="<?= Http::e($siteDefaultTheme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <meta name="robots" content="noindex, nofollow">
    <title><?= Http::e($title) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= $favicon ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Play:wght@400;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="<?= Http::e(Http::asset('assets/base.css')) ?>">
    <link rel="stylesheet" href="<?= Http::e(Http::asset('assets/writer.css')) ?>">

    <script>
    // Honor the visitor's stored theme pick so colour vars match the rest
    // of the site. Inline + sync so no FOUC of wrong phosphor tint.
    (function () {
        try {
            var t = localStorage.getItem('theme');
            var valid = ['amber','green','crypt','brutalist','p7','p11'];
            if (t && valid.indexOf(t) !== -1) {
                document.documentElement.setAttribute('data-theme', t);
            }
        } catch (e) {}
    })();
    </script>
</head>
<body class="writer-body">

<main class="writer-shell" role="main" aria-label="Writer">
    <div class="writer-status" id="writer-status" aria-live="polite" data-state="idle">
        <span class="writer-status-dot" aria-hidden="true"></span>
        <span class="writer-status-text">Ready</span>
    </div>

    <div class="writer-stats" id="writer-stats" aria-live="off">
        <span id="writer-stats-words">0 words</span>
        <span class="writer-stats-sep">·</span>
        <span id="writer-stats-time">0 min</span>
    </div>

    <div class="writer-actions">
        <a class="writer-btn" id="writer-view-btn" target="_blank" rel="noopener"
           href="<?= $existingPost !== null ? '/posts/' . Http::e(rawurlencode($existingPost->slug)) : '#' ?>"
           title="View live post (opens new tab)"
           <?= $existingPost === null || $existingPost->draft ? 'hidden' : '' ?>>[ VIEW ]</a>
        <button type="button" class="writer-btn" id="writer-draft-btn"
                title="Save as draft (Ctrl/Cmd + S)">[ DRAFT ]</button>
        <button type="button" class="writer-btn writer-btn-primary" id="writer-submit-btn"
                title="Publish (Ctrl/Cmd + Enter)">[ SUBMIT ]</button>
    </div>

    <div class="writer-stage">
        <div class="writer-editor"
             id="writer-editor"
             contenteditable="true"
             spellcheck="true"
             autocorrect="off"
             autocapitalize="off"
             aria-label="Write your post"
             data-placeholder="// start writing..."></div>
    </div>

    <aside class="writer-toc" id="writer-toc" hidden aria-label="Outline">
        <div class="writer-toc-tag">§ OUTLINE</div>
        <nav class="writer-toc-list" id="writer-toc-list"></nav>
        <div class="writer-toc-hint">Cmd/Ctrl + ] to close</div>
    </aside>

    <div class="writer-utility">
        <a class="writer-exit" href="/" title="Leave Writer">[ exit ]</a>
        <button type="button" class="writer-bg-toggle" id="writer-bg-toggle"
                title="Toggle light / dark surface">[ light ]</button>
        <button type="button" class="writer-toc-toggle" id="writer-toc-toggle"
                title="Toggle outline (Cmd/Ctrl + ])">[ outline ]</button>
    </div>
</main>

<div class="writer-modal" id="writer-modal" hidden role="dialog" aria-modal="true" aria-labelledby="writer-modal-title">
    <div class="writer-modal-backdrop" data-modal-dismiss></div>
    <form class="writer-modal-panel" id="writer-form" method="post" action="/writer/save">
        <input type="hidden" name="_csrf" value="<?= Http::e($csrf) ?>">
        <input type="hidden" name="mode" id="writer-mode" value="draft">
        <input type="hidden" name="body" id="writer-body-hidden" value="">
        <input type="hidden" name="existing_slug" id="writer-existing-slug"
               value="<?= Http::e($existingPost?->slug ?? '') ?>">
        <input type="hidden" name="existing_filename" id="writer-existing-filename"
               value="<?= Http::e($existingFilename) ?>">

        <div class="writer-modal-tag" id="writer-modal-title">§ <span id="writer-modal-mode-label">DRAFT</span></div>

        <div class="writer-modal-phase" data-phase="input">
            <label class="writer-modal-label" for="writer-title-input">Title <span class="writer-modal-req">*</span></label>
            <input type="text" name="title" id="writer-title-input" class="writer-modal-input"
                   maxlength="200" required autocomplete="off">

            <label class="writer-modal-label" for="writer-summary-input">Summary <span class="writer-modal-opt">optional</span></label>
            <textarea name="summary" id="writer-summary-input" class="writer-modal-textarea"
                      rows="3" maxlength="500" autocomplete="off"></textarea>
        </div>

        <div class="writer-modal-phase" data-phase="confirm" hidden>
            <p class="writer-modal-confirm-text">
                Publish "<span class="writer-modal-confirm-title" id="writer-confirm-title"></span>"
                as a live post?
            </p>
            <p class="writer-modal-confirm-hint">
                The post will be available at
                <span class="writer-modal-confirm-url" id="writer-confirm-url"></span>
                immediately.
            </p>
        </div>

        <div class="writer-modal-error" id="writer-modal-error" hidden></div>

        <div class="writer-modal-actions">
            <button type="button" class="writer-btn" id="writer-modal-cancel" data-modal-dismiss>[ CANCEL ]</button>
            <button type="submit" class="writer-btn writer-btn-primary" id="writer-modal-confirm">[ CONFIRM ]</button>
        </div>
    </form>
</div>

<div class="writer-modal" id="writer-exit-modal" hidden role="dialog" aria-modal="true" aria-labelledby="writer-exit-modal-title">
    <div class="writer-modal-backdrop" data-exit-modal-dismiss></div>
    <div class="writer-modal-panel">
        <div class="writer-modal-tag" id="writer-exit-modal-title">§ EXIT ZEN</div>
        <p class="writer-modal-confirm-text">Thoát chế độ Zen?</p>
        <p class="writer-modal-confirm-hint">
            Bản nháp đã được lưu trong trình duyệt — bạn có thể quay lại tiếp tục viết bất cứ lúc nào.
        </p>
        <div class="writer-modal-actions">
            <button type="button" class="writer-btn" id="writer-exit-cancel" data-exit-modal-dismiss>[ CANCEL ]</button>
            <button type="button" class="writer-btn writer-btn-primary" id="writer-exit-confirm">[ EXIT ]</button>
        </div>
    </div>
</div>

<div class="writer-modal" id="writer-link-modal" hidden role="dialog" aria-modal="true" aria-labelledby="writer-link-modal-title">
    <div class="writer-modal-backdrop" data-link-modal-dismiss></div>
    <form class="writer-modal-panel" id="writer-link-form">
        <div class="writer-modal-tag" id="writer-link-modal-title">§ INSERT LINK</div>

        <label class="writer-modal-label" for="writer-link-text">Text <span class="writer-modal-opt">optional</span></label>
        <input type="text" id="writer-link-text" class="writer-modal-input"
               maxlength="200" autocomplete="off" placeholder="link text">

        <label class="writer-modal-label" for="writer-link-url">URL <span class="writer-modal-req">*</span></label>
        <input type="url" id="writer-link-url" class="writer-modal-input"
               required autocomplete="off" placeholder="https://">

        <div class="writer-modal-error" id="writer-link-modal-error" hidden></div>

        <div class="writer-modal-actions">
            <button type="button" class="writer-btn" data-link-modal-dismiss>[ CANCEL ]</button>
            <button type="submit" class="writer-btn writer-btn-primary" id="writer-link-modal-confirm">[ INSERT ]</button>
        </div>
    </form>
</div>

<script>
// When the writer was opened on an existing post (admin → [ WRITE ]),
// hydrate the editor from disk instead of localStorage so the writer
// always sees the canonical body. Null means "fresh blank doc — try the
// localStorage draft restore path."
window.LB_WRITER_EXISTING = <?= json_encode(
    $existingPost !== null
        ? [
            'slug' => $existingPost->slug,
            'title' => $existingPost->title,
            'summary' => $existingPost->summary ?? '',
            'body' => $existingPost->bodyMarkdown,
            'draft' => $existingPost->draft,
        ]
        : null,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG
        | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
) ?>;
</script>
<script defer src="<?= Http::e(Http::asset('assets/writer.js')) ?>"></script>

</body>
</html>
