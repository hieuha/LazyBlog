<?php
/** @var string $title */
/** @var string $mode  'new' | 'edit' */
/** @var \App\Post|null $post */
/** @var string $originalFilename */
/** @var string|null $formError */
/** @var string|null $flash */
/** @var array{date:string,time:string,slug:string,title:string,author:string,tags:string,draft:bool,icon:string,summary:string,image:string,series:string,part:string,body:string,password:string,remove_password:bool,is_protected:bool} $formValues */

use App\Csrf;
use App\Http;

$isEdit = $mode === 'edit';

// Flash from /admin/set-password and /admin/remove-password redirects.
// Treat anything starting with "Failed" / "Password must" / "No password
// to remove" as a soft error so the operator gets a clear "did/didn't"
// signal — the controller deliberately uses one channel for both
// success and failure to keep the redirect target single.
$flashIsError = is_string($flash ?? null) && (
    str_starts_with($flash, 'Failed')
    || str_starts_with($flash, 'Password must')
    || str_starts_with($flash, 'No password')
);
?>

<section>
    <h2><?= $isEdit ? 'EDIT: ' . Http::e($formValues['title']) : 'NEW POST' ?></h2>

    <?php if ($formError !== null): ?>
        <p class="admin-error">// <?= Http::e($formError) ?></p>
    <?php endif; ?>
    <?php if ($flash !== null): ?>
        <p class="<?= $flashIsError ? 'admin-error' : 'admin-flash' ?>">// <?= Http::e($flash) ?></p>
    <?php endif; ?>

    <form method="post" action="/admin/save" class="admin-form">
        <input type="hidden" name="_csrf" value="<?= Http::e(Csrf::token()) ?>">
        <input type="hidden" name="mode" value="<?= Http::e($mode) ?>">
        <input type="hidden" name="original_filename" value="<?= Http::e($originalFilename) ?>">

        <!-- Row 1: Title + Draft — the two fields a writer always cares about.
             Author/Icon/Date/Time/Slug/Tags/Social image now live inside the
             collapsible META block below so they don't compete for attention
             with the writing flow. -->
        <div class="admin-form-row">
            <div class="admin-field admin-field-grow">
                <label class="admin-label" for="title">Title</label>
                <input type="text" name="title" id="title" required
                       value="<?= Http::e($formValues['title']) ?>"
                       class="admin-input"
                       placeholder="Your post title">
            </div>
            <div class="admin-field" style="flex: 0 0 auto">
                <label class="admin-label">&nbsp;</label>
                <label class="admin-checkbox-pill">
                    <input type="checkbox" name="draft" value="1" <?= $formValues['draft'] ? 'checked' : '' ?>>
                    <span>Draft</span>
                </label>
            </div>
        </div>

        <!-- Summary — kept compact, single line. Sits above the META collapse
             so the writer sees: Title → Summary → [META ▸] → Body. -->
        <div class="admin-field">
            <label class="admin-label" for="summary">Summary</label>
            <input type="text" name="summary" id="summary"
                   value="<?= Http::e($formValues['summary']) ?>"
                   class="admin-input"
                   placeholder="One-line description shown in post lists and meta tags.">
        </div>

        <!-- Collapsible META block — closed by default so the writing surface
             stays focused. Uses the native <details> element so no JS is
             needed and keyboard / mobile support comes for free. -->
        <details class="admin-meta-collapse">
            <summary class="admin-meta-toggle">
                <span class="admin-meta-toggle-glyph" aria-hidden="true"></span>
                <span class="admin-meta-toggle-label">
                    <span class="admin-meta-toggle-label-main">META</span><span class="admin-meta-toggle-label-fields"> · DATE · SLUG · TAGS · AUTHOR · ICON · SOCIAL · SERIES · PASSWORD</span>
                </span>
            </summary>

            <div class="admin-meta-collapse-body">
                <!-- Slug + Series + Part share one compact row. Slug and
                     Series each grow; Part is fixed-narrow since it only
                     holds a 1–2 digit number. Hints trimmed to fit. -->
                <div class="admin-form-row">
                    <div class="admin-field" style="flex: 1 1 200px">
                        <label class="admin-label" for="slug">Slug</label>
                        <input type="text" name="slug" id="slug"
                               value="<?= Http::e($formValues['slug']) ?>"
                               maxlength="80"
                               placeholder="auto-from-title"
                               class="admin-input admin-mono">
                    </div>
                    <div class="admin-field" style="flex: 1 1 200px">
                        <label class="admin-label" for="series">Series</label>
                        <input type="text" name="series" id="series"
                               value="<?= Http::e($formValues['series']) ?>"
                               class="admin-input"
                               list="series-suggestions"
                               autocomplete="off"
                               placeholder="rtl-sdr-cho-nguoi-moi">
                        <datalist id="series-suggestions">
                            <?php foreach (($seriesSuggestions ?? []) as $s): ?>
                                <option value="<?= Http::e((string) $s['slug']) ?>">
                                    <?= Http::e((string) $s['title']) ?> · <?= (int) $s['count'] ?> part<?= (int) $s['count'] === 1 ? '' : 's' ?>
                                </option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="admin-field" style="flex: 0 0 90px">
                        <label class="admin-label" for="part">Part # <span class="admin-label-hint">(opt)</span></label>
                        <input type="number" name="part" id="part" min="1" step="1"
                               value="<?= Http::e($formValues['part']) ?>"
                               class="admin-input"
                               placeholder="1">
                    </div>
                </div>

                <!-- Tags — pulled onto its own row because the chip UI grows
                     taller than the thin date/time/icon inputs in the row
                     below, so packing it inline left the row looking uneven. -->
                <div class="admin-form-row">
                    <div class="admin-field admin-field-grow">
                        <label class="admin-label" for="tags">Tags</label>
                        <input type="text" name="tags" id="tags"
                               value="<?= Http::e($formValues['tags']) ?>"
                               class="admin-input admin-mono"
                               placeholder="ham-radio, sstv, history">
                    </div>
                </div>

                <!-- Compact meta row: Author · Icon · Date · Time · Social image.
                     Five fields share one flex row; on viewports < 600px the
                     .admin-form-row rule makes each field span 100% so they
                     stack cleanly on mobile. -->
                <div class="admin-form-row">
                    <div class="admin-field" style="flex: 0 1 160px">
                        <label class="admin-label" for="author">Author</label>
                        <input type="text" name="author" id="author"
                               value="<?= Http::e($formValues['author']) ?>"
                               class="admin-input admin-mono">
                    </div>
                    <div class="admin-field" style="flex: 0 0 56px">
                        <label class="admin-label" for="icon">Icon</label>
                        <input type="text" name="icon" id="icon"
                               value="<?= Http::e($formValues['icon']) ?>"
                               maxlength="8"
                               class="admin-input"
                               placeholder="📺">
                    </div>
                    <div class="admin-field" style="flex: 0 0 150px">
                        <label class="admin-label" for="date">Date</label>
                        <input type="date" name="date" id="date" required
                               value="<?= Http::e($formValues['date']) ?>"
                               class="admin-input admin-mono">
                    </div>
                    <div class="admin-field" style="flex: 0 0 120px">
                        <label class="admin-label" for="time">Time <span class="admin-label-hint">(opt)</span></label>
                        <input type="time" name="time" id="time" step="1"
                               value="<?= Http::e($formValues['time']) ?>"
                               class="admin-input admin-mono">
                    </div>
                    <!-- Social-card image (per-post og:image override). The input
                         accepts a path or absolute URL; the upload button POSTs the
                         chosen file to /admin/upload and writes the returned path
                         back into the input. Hint trimmed to fit the compact row. -->
                    <div class="admin-field" style="flex: 2 2 240px">
                        <label class="admin-label" for="image">Social image <span class="admin-label-hint">(og · jpg/png)</span></label>
                        <div class="admin-input-with-upload">
                            <input type="text" name="image" id="image"
                                   value="<?= Http::e($formValues['image']) ?>"
                                   class="admin-input"
                                   placeholder="/uploads/2026/06/cover.webp  or  https://…">
                            <!-- Mirror field — JS upload writes here too, server reads
                                 either one. Belt + braces against any browser quirk
                                 that drops the visible input's JS-assigned value
                                 from the form serialization. -->
                            <input type="hidden" name="image_mirror" id="image-mirror"
                                   value="<?= Http::e($formValues['image']) ?>">
                            <input type="file" id="image-upload" accept="image/jpeg,image/png" hidden>
                            <button type="button" id="image-upload-btn"
                                    class="admin-btn admin-btn-sm"
                                    data-target="image"
                                    data-mirror="image-mirror"
                                    data-file-input="image-upload"
                                    data-upload-kind="social">UPLOAD</button>
                        </div>
                        <div id="image-upload-status" class="admin-label-hint" hidden></div>
                    </div>
                </div>

                <!-- Password protection — bcrypt hash stored in frontmatter.
                     Field is always rendered blank: existing hash is NEVER echoed.
                     3-state save: blank field = keep existing, value = replace,
                     "remove" checkbox = drop the hash entirely. -->
                <div class="admin-form-row">
                    <div class="admin-field admin-field-grow">
                        <label class="admin-label" for="post-password">
                            Password
                            <?php if ($formValues['is_protected']): ?>
                                <span class="admin-label-hint">[ LOCKED ]</span>
                            <?php elseif ($isEdit): ?>
                                <span class="admin-label-hint">(optional · type a password and click [ Set Password ] to lock instantly)</span>
                            <?php else: ?>
                                <span class="admin-label-hint">(optional · type a password to lock this post — saved together with the post on [ Create ])</span>
                            <?php endif; ?>
                        </label>
                        <input type="password" name="password" id="post-password"
                               value=""
                               autocomplete="new-password"
                               class="admin-input admin-mono"
                               placeholder="<?= $formValues['is_protected'] ? 'Leave blank to keep current password' : 'Leave blank for no protection' ?>">
                    </div>
                    <?php if ($isEdit && $formValues['slug'] !== ''): ?>
                        <div class="admin-field" style="flex: 0 0 auto">
                            <label class="admin-label">&nbsp;</label>
                            <!-- Side-channel submit: formaction overrides /admin/save
                                 just for this click, so password set/remove happens
                                 without saving any other unsaved field on the form.
                                 CSRF token rides via the parent form. -->
                            <button type="submit"
                                    formaction="/admin/set-password/<?= Http::e($formValues['slug']) ?>"
                                    class="admin-btn admin-btn-primary">
                                [ <?= $formValues['is_protected'] ? 'Update' : 'Set' ?> Password ]
                            </button>
                        </div>
                    <?php endif; ?>
                    <?php if ($formValues['is_protected'] && $formValues['slug'] !== ''): ?>
                        <div class="admin-field" style="flex: 0 0 auto">
                            <label class="admin-label">&nbsp;</label>
                            <button type="submit"
                                    formaction="/admin/remove-password/<?= Http::e($formValues['slug']) ?>"
                                    class="admin-btn admin-btn-danger"
                                    onclick="return confirm('Remove password protection from this post?');">
                                [ Remove Password ]
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </details>

        <!-- Body — the focus -->
        <div class="admin-field">
            <label class="admin-label" for="body">Body <span class="admin-label-hint">(markdown)</span></label>
            <textarea name="body" id="body" rows="28" required
                      class="admin-input admin-textarea-body"
                      placeholder="# Heading

Write in **markdown**. Use `code`, [links](https://example.com), and admonitions:

::: highlight
Key fact or callout.
:::

::: story icon=&quot;🌕&quot; title=&quot;A story&quot;
Body of the story card.
:::"><?= Http::e($formValues['body']) ?></textarea>
        </div>

        <div class="admin-actions">
            <button type="submit" class="admin-btn admin-btn-primary">[ <?= $isEdit ? 'SAVE' : 'CREATE' ?> ]</button>
            <a class="admin-btn" href="/admin">[ CANCEL ]</a>
        </div>
    </form>
</section>

<!-- EasyMDE: markdown editor with live preview, autosave, fullscreen, etc. -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.css">
<!-- Font Awesome 4 is loaded universally from layout.php (drives the
     EasyMDE toolbar glyphs + the .post-lock fa-lock badge). -->
<script defer src="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.js"></script>
<script defer src="<?= App\Http::e(App\Http::asset('assets/admin-editor.js')) ?>"></script>
