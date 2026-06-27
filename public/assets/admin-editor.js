/**
 * LazyBlog admin editor enhancements:
 *   1. Mount EasyMDE on the #body textarea (live-preview markdown editor).
 *   2. Convert the comma-separated #tags input into a chip UI.
 *   3. Guard against navigating away with unsaved changes.
 *
 * Pure vanilla — no build step. EasyMDE is loaded from CDN by edit.php.
 */

(function () {
    'use strict';

    var form = document.querySelector('.admin-form');
    if (!form) return;

    /* ---------- 1. EasyMDE ---------- */
    var bodyEl = document.getElementById('body');
    var easyMDE = null;
    var slugEl = document.getElementById('slug');

    if (bodyEl && window.EasyMDE) {
        var autosaveId = 'lazyblog-' + (slugEl && slugEl.value ? slugEl.value : 'new');

        // Mirror fullscreen state to the body so we can hide the CRT scanline
        // overlay while the editor takes over the viewport.
        var observer = new MutationObserver(function () {
            var fs = document.querySelector('.CodeMirror-fullscreen, .editor-toolbar.fullscreen');
            document.body.classList.toggle('editor-fullscreen-active', !!fs);
        });
        observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });

        // Server-side preview — POSTs raw markdown to /admin/preview and
        // uses the real PHP MarkdownRenderer so admonitions (::: highlight,
        // ::: story) and freq-tag chips render the same as the public page.
        // Debounced so we don't hammer the server on every keystroke.
        var previewCache = '';
        var previewTimer = null;
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

        // Upload status overlay — a small fixed pill at the top-right that
        // shows "Uploading {filename}…" while the request is in flight.
        // Auto-hides on success/error so the writer always knows whether
        // anything is happening.
        var uploadStatusEl = null;
        function showUploadStatus(text) {
            if (!uploadStatusEl) {
                uploadStatusEl = document.createElement('div');
                uploadStatusEl.id = 'upload-status';
                document.body.appendChild(uploadStatusEl);
            }
            uploadStatusEl.textContent = text;
            uploadStatusEl.classList.add('visible');
        }
        function hideUploadStatus() {
            if (uploadStatusEl) uploadStatusEl.classList.remove('visible');
        }

        // Shared upload helper used by both EasyMDE's image-upload pipeline
        // (drag/drop, paste, cloud-icon picker) and the bespoke "Bayer
        // dither" toolbar button below. `dither=true` flips the server
        // routing to PostImageDitherer instead of ImageProcessor; the
        // response shape is identical.
        function runImageUpload(file, dither, onSuccess, onError) {
            var label = dither ? 'Dithering ' : 'Uploading ';
            showUploadStatus(label + (file.name || 'image') + '…');
            // Re-read the meta tag each call so token rotation (e.g. on
            // login) doesn't leave a stale value cached in closure.
            var meta = document.querySelector('meta[name="csrf-token"]');
            var token = meta ? meta.getAttribute('content') : '';

            var form = new FormData();
            form.append('file', file);
            if (dither) form.append('dither', '1');

            fetch('/admin/upload', {
                method: 'POST',
                headers: { 'X-CSRF-Token': token },
                body: form,
                credentials: 'same-origin',
            }).then(function (r) {
                // If we followed a redirect (fetch default), we landed on
                // the login page — session expired.
                if (r.redirected && r.url.indexOf('/admin/login') !== -1) {
                    hideUploadStatus();
                    onError('Session expired — refresh the page and log in again.');
                    return;
                }
                // Detect non-JSON responses (PHP error page, plain-text
                // 403, HTML login page) and surface the actual content.
                var ctype = r.headers.get('content-type') || '';
                if (ctype.indexOf('application/json') === -1) {
                    return r.text().then(function (body) {
                        hideUploadStatus();
                        var excerpt = body.replace(/\s+/g, ' ').slice(0, 200);
                        onError('HTTP ' + r.status + ' ' + ctype + ' — ' + (excerpt || '(empty body)'));
                    });
                }
                return r.json().then(function (data) {
                    hideUploadStatus();
                    if (!r.ok) {
                        onError('Upload failed (HTTP ' + r.status + '): ' + (data.error || 'unknown'));
                        return;
                    }
                    onSuccess(data.url);
                });
            }).catch(function (e) {
                hideUploadStatus();
                onError('Network error: ' + (e.message || e));
            });
        }

        function previewRender(plainText, previewEl) {
            clearTimeout(previewTimer);
            previewTimer = setTimeout(function () {
                fetch('/admin/preview', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'text/plain; charset=utf-8',
                        'X-CSRF-Token': csrfToken,
                    },
                    body: plainText,
                    credentials: 'same-origin',
                }).then(function (r) { return r.text(); })
                  .then(function (html) {
                      previewCache = html;
                      previewEl.innerHTML = html;
                  })
                  .catch(function () {
                      previewEl.innerHTML = '<p style="color:#ff8a8a">// Preview render failed.</p>';
                  });
            }, 300);
            // Return last cached HTML so the panel doesn't flash blank between updates.
            return previewCache || '<p style="opacity:0.5">Rendering…</p>';
        }

        easyMDE = new EasyMDE({
            element: bodyEl,
            spellChecker: false,
            forceSync: true,            // mirror back to the underlying textarea so form submit works
            // Font Awesome is loaded explicitly from jsdelivr in edit.php — disabling
            // EasyMDE's auto-download keeps the request inside our CSP allow-list.
            autoDownloadFontAwesome: false,
            previewRender: previewRender,
            autosave: {
                enabled: true,
                uniqueId: autosaveId,
                delay: 1500,
            },
            placeholder: bodyEl.placeholder || '',
            status: ['lines', 'words', 'cursor'],
            tabSize: 2,
            indentWithTabs: false,
            // Drag/drop + paste + image-button upload. EasyMDE inserts
            // ![alt](returned-url) at the cursor on success. Routes
            // through the shared runImageUpload helper above with the
            // dither flag off — plain re-encode through ImageProcessor.
            uploadImage: true,
            imageUploadFunction: function (file, onSuccess, onError) {
                runImageUpload(file, false, onSuccess, onError);
            },
            imageMaxSize: 10 * 1024 * 1024,
            imageAccept: 'image/png, image/jpeg, image/webp',
            imageTexts: {
                sbInit: 'Drop image or click to upload',
                sbOnDragEnter: 'Drop to upload',
                sbOnDrop: 'Uploading…',
                sbProgress: 'Uploading {{progress}}%',
                sbOnUploaded: 'Uploaded',
                sizeUnits: ' B, KB, MB',
            },
            toolbar: [
                'bold', 'italic', 'strikethrough', 'heading', '|',
                'quote', 'unordered-list', 'ordered-list',
                {
                    name: 'task-list',
                    action: function (editor) {
                        var cm = editor.codemirror;
                        var sel = cm.getSelection();
                        // Wrap each selected line (or insert a single empty
                        // item when nothing's selected) as a `- [ ]` task.
                        var lines = sel !== '' ? sel.split('\n') : [''];
                        var out = lines.map(function (l) {
                            return '- [ ] ' + l;
                        }).join('\n');
                        cm.replaceSelection(out);
                    },
                    className: 'fa fa-check-square-o',
                    title: 'Task list (- [ ] item)',
                },
                {
                    name: 'mark',
                    action: function (editor) {
                        var cm = editor.codemirror;
                        var sel = cm.getSelection();
                        if (sel === '') {
                            cm.replaceSelection('==highlight==');
                            // Position cursor inside the marks so user can
                            // start typing the highlighted phrase.
                            var pos = cm.getCursor();
                            cm.setSelection(
                                { line: pos.line, ch: pos.ch - 11 },
                                { line: pos.line, ch: pos.ch - 2 },
                            );
                        } else {
                            cm.replaceSelection('==' + sel + '==');
                        }
                    },
                    className: 'fa fa-paint-brush',
                    title: 'Highlight (==text==)',
                },
                '|',
                'code', 'link', 'image', 'upload-image',
                {
                    name: 'upload-image-dither',
                    action: function (editor) {
                        // EasyMDE's `upload-image` opens a built-in file
                        // picker we can't repurpose, so synthesize one
                        // ourselves and feed the result to the shared
                        // runImageUpload helper with dither=true.
                        var input = document.createElement('input');
                        input.type = 'file';
                        input.accept = 'image/png, image/jpeg, image/webp';
                        input.style.display = 'none';
                        document.body.appendChild(input);
                        input.onchange = function () {
                            var f = input.files && input.files[0];
                            document.body.removeChild(input);
                            if (!f) return;
                            runImageUpload(f, true, function (url) {
                                // Same insertion shape EasyMDE's image
                                // button uses — `![](url)` at the cursor.
                                // Trailing newline so consecutive uploads
                                // land on their own lines.
                                editor.codemirror.replaceSelection('![](' + url + ')\n');
                            }, function (msg) {
                                // Match EasyMDE's onError convention — a
                                // browser-native alert is loud enough that
                                // a writer working in fullscreen mode
                                // notices without a status pill timeout.
                                window.alert(msg);
                            });
                        };
                        input.click();
                    },
                    className: 'fa fa-th',
                    title: 'Upload + Bayer dither',
                },
                'table', '|',
                {
                    name: 'highlight',
                    action: function (editor) {
                        var cm = editor.codemirror;
                        var output = '::: highlight\nKey fact or callout.\n:::';
                        cm.replaceSelection('\n' + output + '\n');
                    },
                    className: 'fa fa-exclamation-triangle',
                    title: 'Insert highlight admonition',
                },
                {
                    name: 'story',
                    action: function (editor) {
                        var cm = editor.codemirror;
                        var output = '::: story icon="🌕" title="A story"\nBody.\n:::';
                        cm.replaceSelection('\n' + output + '\n');
                    },
                    className: 'fa fa-comment',
                    title: 'Insert story card',
                },
                '|',
                'preview', 'side-by-side', 'fullscreen', '|',
                'guide',
            ],
            shortcuts: {
                togglePreview: 'Cmd-P',
                toggleSideBySide: 'F9',
                toggleFullScreen: 'F11',
            },
        });

        // EasyMDE's default `afterImageUploaded` inserts `![](url)` with
        // no trailing newline, so multi-select / drag-drop of several
        // images all land on a single line glued together. Override at
        // the instance level: same image-extension check as upstream
        // (fall back to a link for non-images), but append `\n` so each
        // upload lands on its own line.
        var IMG_EXTS = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'apng', 'avif', 'webp'];
        easyMDE.afterImageUploaded = function (url) {
            var cm = easyMDE.codemirror;
            var name = url.substr(url.lastIndexOf('/') + 1);
            var ext = name.substring(name.lastIndexOf('.') + 1).toLowerCase();
            var snippet = IMG_EXTS.indexOf(ext) !== -1
                ? '![](' + url + ')\n'
                : '[' + name + '](' + url + ')\n';
            cm.replaceSelection(snippet);
        };

        // EasyMDE ships both `image` (insert by URL) and `upload-image`
        // (file picker) with picture-frame icons by default, so the two
        // adjacent toolbar buttons look identical. Swap the upload one
        // to a cloud-upload glyph so the affordance is obvious at a glance.
        //
        // EasyMDE may render the glyph via the button's own ::before OR
        // via an inner <i>; pick whichever exists so we don't end up with
        // two stacked cloud icons. Strip existing fa-* classes first, then
        // add FontAwesome 4 cloud-upload (loaded from CDN above).
        var uploadBtn = document.querySelector('.editor-toolbar .upload-image');
        if (uploadBtn) {
            var target = uploadBtn.querySelector('i') || uploadBtn;
            Array.from(target.classList).forEach(function (c) {
                if (c !== 'fa' && c.indexOf('fa-') === 0) {
                    target.classList.remove(c);
                }
            });
            if (!target.classList.contains('fa')) target.classList.add('fa');
            target.classList.add('fa-cloud-upload');
        }
    }

    /* ---------- 2. Tag chip input ---------- */
    var tagsEl = document.getElementById('tags');
    if (tagsEl) {
        var wrap = document.createElement('div');
        wrap.className = 'tag-chip-wrap';

        var chipsBox = document.createElement('div');
        chipsBox.className = 'tag-chip-list';

        var entry = document.createElement('input');
        entry.type = 'text';
        entry.className = 'tag-chip-entry admin-input admin-mono';
        entry.placeholder = 'add tag + comma or Enter';
        entry.autocomplete = 'off';

        wrap.appendChild(chipsBox);
        wrap.appendChild(entry);

        // Hide the original input but keep it in the form for submission.
        tagsEl.classList.add('tag-chip-hidden');
        tagsEl.parentNode.insertBefore(wrap, tagsEl.nextSibling);

        var current = [];

        function syncHidden() {
            tagsEl.value = current.join(', ');
        }

        function normalize(raw) {
            return (raw || '')
                .toLowerCase()
                .trim()
                .replace(/ +/g, '-')
                .replace(/[^a-z0-9-]/g, '');
        }

        function renderChips() {
            chipsBox.innerHTML = '';
            current.forEach(function (t, i) {
                var chip = document.createElement('span');
                chip.className = 'tag-chip-pill';
                chip.textContent = '#' + t;

                var x = document.createElement('button');
                x.type = 'button';
                x.className = 'tag-chip-x';
                x.setAttribute('aria-label', 'Remove ' + t);
                x.textContent = '×';
                x.addEventListener('click', function () {
                    current.splice(i, 1);
                    syncHidden();
                    renderChips();
                });
                chip.appendChild(x);
                chipsBox.appendChild(chip);
            });
        }

        function addFromEntry() {
            var raw = entry.value;
            var parts = raw.split(',');
            parts.forEach(function (p) {
                var t = normalize(p);
                if (t && current.indexOf(t) === -1) current.push(t);
            });
            entry.value = '';
            syncHidden();
            renderChips();
        }

        // Seed from existing value.
        (tagsEl.value || '').split(',').forEach(function (p) {
            var t = normalize(p);
            if (t && current.indexOf(t) === -1) current.push(t);
        });
        syncHidden();
        renderChips();

        // Live convert spaces to hyphens so the operator sees "Ordered-Bayer"
        // as they type instead of being surprised by silent space-collapsing
        // at submit time. Preserves caret position; only fires when a space
        // is present so the textbox stays inert for ordinary typing.
        entry.addEventListener('input', function () {
            if (entry.value.indexOf(' ') === -1) return;
            var pos = entry.selectionStart;
            entry.value = entry.value.replace(/ +/g, '-');
            if (typeof pos === 'number') {
                try { entry.setSelectionRange(pos, pos); } catch (_) {}
            }
        });

        entry.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                addFromEntry();
            } else if (e.key === 'Backspace' && entry.value === '' && current.length > 0) {
                current.pop();
                syncHidden();
                renderChips();
            }
        });
        entry.addEventListener('blur', addFromEntry);
    }

    /* ---------- 3. Auto-slug from title ----------
     * Mirrors `SlugUtil::fromTitle` (PHP) so the preview matches the
     * server's filename-derivation. Auto-fill stays on until the user
     * manually edits the slug field, or until the page loads with a
     * slug that doesn't match the current title's derived value
     * (existing post with a hand-picked slug — leave it alone).
     */
    var titleEl = document.getElementById('title');
    var slugInputEl = document.getElementById('slug');
    if (titleEl && slugInputEl) {
        // NFD splits combined glyphs (e.g. `ế` → `e` + combining mark);
        // drop the marks, then handle `đ`/`Đ` which don't decompose.
        function slugifyTitle(s) {
            return (s || '')
                .normalize('NFD')
                .replace(/[̀-ͯ]/g, '')
                .replace(/đ/g, 'd')
                .replace(/Đ/g, 'D')
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '')
                .slice(0, 80);
        }

        var slugAuto = slugInputEl.value === ''
            || slugInputEl.value === slugifyTitle(titleEl.value);

        titleEl.addEventListener('input', function () {
            if (!slugAuto) return;
            slugInputEl.value = slugifyTitle(titleEl.value);
        });

        // Programmatic `value =` doesn't fire `input`, so this listener
        // only catches real user keystrokes / paste / cut on the slug
        // field — flipping the auto-sync off the moment the writer
        // takes manual control of the URL.
        slugInputEl.addEventListener('input', function () {
            slugAuto = false;
        });
    }

    /* ---------- 4. Unsaved-changes guard ---------- */
    var dirty = false;

    function markDirty() {
        dirty = true;
    }

    Array.prototype.slice.call(form.querySelectorAll('input, select, textarea')).forEach(function (el) {
        el.addEventListener('input', markDirty);
        el.addEventListener('change', markDirty);
    });

    if (easyMDE) {
        easyMDE.codemirror.on('change', markDirty);
    }

    window.addEventListener('beforeunload', function (e) {
        if (!dirty) return;
        e.preventDefault();
        e.returnValue = '';
        return '';
    });

    // Clear dirty when the form is actually submitted.
    form.addEventListener('submit', function () {
        dirty = false;
    });

    /* ---------- Inline file uploaders (Social image, etc.) ----------
     * Generic helper for any "[ UPLOAD ]" button whose
     * data-target=<input id> + data-file-input=<file input id> point
     * at a sibling pair. Reuses the /admin/upload endpoint that
     * EasyMDE drives from the body editor, so the response shape +
     * CSRF handling stay identical. Status pings into an optional
     * `<id>-status` element next to the field.
     */
    Array.prototype.slice.call(document.querySelectorAll('[data-target][data-file-input]')).forEach(function (btn) {
        var target = document.getElementById(btn.dataset.target);
        var fileInput = document.getElementById(btn.dataset.fileInput);
        if (!target || !fileInput) return;
        var statusEl = document.getElementById(btn.dataset.target + '-upload-status');
        var mirrorEl = btn.dataset.mirror ? document.getElementById(btn.dataset.mirror) : null;

        function setStatus(text, isError) {
            if (!statusEl) return;
            statusEl.textContent = text;
            statusEl.hidden = !text;
            statusEl.style.color = isError ? 'var(--accent)' : '';
        }

        btn.addEventListener('click', function () { fileInput.click(); });

        fileInput.addEventListener('change', function () {
            var file = fileInput.files && fileInput.files[0];
            if (!file) return;

            // The OS file picker treats `accept` as a hint only — a user
            // who flips it to "All Files" could otherwise sneak through
            // any image type. Enforce the MIME list ourselves before
            // hitting the network so the constraint actually holds.
            var accept = (fileInput.accept || '')
                .split(',')
                .map(function (s) { return s.trim(); })
                .filter(Boolean);
            if (accept.length && accept.indexOf(file.type) === -1) {
                setStatus(
                    'Type not allowed (' + (file.type || 'unknown')
                    + '). Accept: ' + accept.join(', '),
                    true
                );
                fileInput.value = '';
                return;
            }

            setStatus('Uploading ' + file.name + '…', false);
            var meta = document.querySelector('meta[name="csrf-token"]');
            var token = meta ? meta.getAttribute('content') : '';
            var fd = new FormData();
            fd.append('file', file);
            // Per-button context flag — `kind=social` makes the server
            // narrow accepted MIME for the social-card field. Absent for
            // the avatar uploader where the full set still applies.
            if (btn.dataset.uploadKind) {
                fd.append('kind', btn.dataset.uploadKind);
            }
            fetch('/admin/upload', {
                method: 'POST',
                headers: { 'X-CSRF-Token': token },
                body: fd,
                credentials: 'same-origin',
            }).then(function (r) {
                var ctype = r.headers.get('content-type') || '';
                if (ctype.indexOf('application/json') === -1) {
                    return r.text().then(function (body) {
                        setStatus('Upload failed (' + r.status + ')', true);
                        throw new Error(body.slice(0, 200));
                    });
                }
                return r.json().then(function (data) {
                    if (!r.ok || !data.url) {
                        setStatus('Upload failed: ' + (data.error || 'unknown'), true);
                        return;
                    }
                    // Set via the native setter so any framework/observer
                    // sitting on the prototype can't intercept the change.
                    // Also mirror the value attribute so the form's
                    // initial-state inspector + any server-side fallback
                    // see a consistent URL. Fire both `input` and `change`
                    // so every listener tier picks it up.
                    var proto = window.HTMLInputElement && window.HTMLInputElement.prototype;
                    var nativeSetter = proto && Object.getOwnPropertyDescriptor(proto, 'value').set;
                    if (nativeSetter) {
                        nativeSetter.call(target, data.url);
                    } else {
                        target.value = data.url;
                    }
                    target.setAttribute('value', data.url);
                    // Write the mirror field so the form serialization
                    // always carries the URL even if the visible input's
                    // JS-set value gets dropped somewhere along the way.
                    if (mirrorEl) {
                        mirrorEl.value = data.url;
                        mirrorEl.setAttribute('value', data.url);
                    }
                    target.dispatchEvent(new Event('input', { bubbles: true }));
                    target.dispatchEvent(new Event('change', { bubbles: true }));
                    setStatus('Uploaded → ' + data.url, false);
                });
            }).catch(function (err) {
                setStatus('Upload error: ' + err.message, true);
            }).finally(function () {
                // Reset so re-selecting the same file still fires.
                fileInput.value = '';
            });
        });
    });
})();
