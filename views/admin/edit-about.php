<?php
/** @var string $title */
/** @var bool $isNew */
/** @var string|null $formError */
/** @var array{name:string,callsign:string,location:string,status:string,avatar:string,contacts:string,stack:string,currently:string,body:string} $formValues */

use App\Csrf;
use App\Http;
?>

<section>
    <h2><?= $isNew ? 'CREATE ABOUT' : 'EDIT ABOUT' ?></h2>

    <?php if ($formError !== null): ?>
        <p class="admin-error">// <?= Http::e($formError) ?></p>
    <?php endif; ?>

    <form method="post" action="/admin/about/save" class="admin-form">
        <input type="hidden" name="_csrf" value="<?= Http::e(Csrf::token()) ?>">

        <!-- Identity row -->
        <div class="admin-form-row">
            <div class="admin-field admin-field-grow">
                <label class="admin-label" for="name">Name <span class="admin-label-hint">(required)</span></label>
                <input type="text" name="name" id="name" required
                       value="<?= Http::e($formValues['name']) ?>"
                       class="admin-input"
                       placeholder="Harry Ha">
            </div>
            <div class="admin-field" style="flex: 0 1 160px">
                <label class="admin-label" for="callsign">Callsign</label>
                <input type="text" name="callsign" id="callsign"
                       value="<?= Http::e($formValues['callsign']) ?>"
                       class="admin-input admin-mono"
                       placeholder="XV5HP">
            </div>
        </div>

        <div class="admin-form-row">
            <div class="admin-field admin-field-grow">
                <label class="admin-label" for="location">Location</label>
                <input type="text" name="location" id="location"
                       value="<?= Http::e($formValues['location']) ?>"
                       class="admin-input"
                       placeholder="Hải Phòng, Vietnam">
            </div>
            <div class="admin-field" style="flex: 0 1 200px">
                <label class="admin-label" for="status">Status <span class="admin-label-hint">(free-form)</span></label>
                <input type="text" name="status" id="status"
                       value="<?= Http::e($formValues['status']) ?>"
                       class="admin-input admin-mono"
                       placeholder="ONLINE">
            </div>
        </div>

        <!-- Avatar — text field + inline upload button -->
        <div class="admin-field">
            <label class="admin-label" for="avatar">Avatar <span class="admin-label-hint">(path or absolute URL · square image looks best)</span></label>
            <div class="admin-form-row">
                <input type="text" name="avatar" id="avatar"
                       value="<?= Http::e($formValues['avatar']) ?>"
                       class="admin-input admin-field-grow"
                       placeholder="/uploads/2026/06/avatar.webp">
                <input type="file" id="avatar-file" accept="image/png,image/jpeg,image/webp" hidden>
                <button type="button" id="avatar-upload-btn" class="admin-btn"
                        style="flex: 0 0 auto"
                        data-target="avatar"
                        data-file-input="avatar-file">[ UPLOAD ]</button>
            </div>
            <div id="avatar-upload-status" class="admin-label-hint" style="margin-top: 6px"></div>
        </div>

        <!-- Contacts — pipe-separated, one per line -->
        <div class="admin-field">
            <label class="admin-label" for="contacts">Contacts <span class="admin-label-hint">(one per line: <code>LABEL | value | url</code> — url optional)</span></label>
            <textarea name="contacts" id="contacts" rows="6"
                      class="admin-input admin-mono"
                      placeholder="EMAIL | hello@example.com | mailto:hello@example.com
GITHUB | @hieuha | https://github.com/hieuha
TELEGRAM | @xv5hp | https://t.me/xv5hp"><?= Http::e($formValues['contacts']) ?></textarea>
        </div>

        <!-- Stack — comma-separated chips of tech / tools / topics -->
        <div class="admin-field">
            <label class="admin-label" for="stack">Stack <span class="admin-label-hint">(comma-separated · short labels work best · e.g. <code>Go, PHP, TypeScript, Linux, Postgres</code>)</span></label>
            <input type="text" name="stack" id="stack"
                   value="<?= Http::e($formValues['stack']) ?>"
                   class="admin-input admin-mono"
                   placeholder="Go, PHP, TypeScript, Linux, Postgres">
        </div>

        <!-- Currently — short "what I'm doing now" -->
        <div class="admin-field">
            <label class="admin-label" for="currently">Currently <span class="admin-label-hint">(1–3 sentences · what you're working on right now)</span></label>
            <textarea name="currently" id="currently" rows="3"
                      class="admin-input"
                      placeholder="Building a self-hosted blog. Reading about CRDTs. Tinkering with RTL-SDR."><?= Http::e($formValues['currently']) ?></textarea>
        </div>

        <!-- Hidden slug so admin-editor.js' autosave namespace ("smde_lazyblog-
             {slug}") doesn't collide with /admin/new (which uses
             "smde_lazyblog-new"). Treating the about page as its own slug
             also lets the editor's stale-cache guard treat this view as
             edit-mode and drop the localStorage draft on every load,
             matching /admin/edit/{slug} semantics. -->
        <input type="hidden" id="slug" value="__about__">

        <!-- Body — bio, rendered as markdown -->
        <div class="admin-field">
            <label class="admin-label" for="body">Bio <span class="admin-label-hint">(markdown · same syntax as posts)</span></label>
            <textarea name="body" id="body" rows="20"
                      class="admin-input admin-textarea-body"
                      placeholder="# Hi, I'm …

Free-form bio. Same markdown features as posts work here — admonitions,
code blocks, freq-tags, story-cards, etc."><?= Http::e($formValues['body']) ?></textarea>
        </div>

        <div class="admin-actions">
            <button type="submit" class="admin-btn admin-btn-primary">[ <?= $isNew ? 'CREATE' : 'SAVE' ?> ]</button>
            <a class="admin-btn" href="/admin">[ CANCEL ]</a>
        </div>
    </form>
</section>

<!-- EasyMDE: same editor as the post form, scoped to #body -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.css">
<!-- Font Awesome 4 loaded universally from layout.php. -->
<script defer src="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.js"></script>
<script defer src="<?= Http::e(\App\Http::asset('assets/admin-editor.js')) ?>"></script>
