/**
 * Artifact picker for the admin editors (post + about).
 *
 * The toolbar cube button opens this dialog instead of jumping straight to
 * the OS file chooser: pick an artifact that is already uploaded (with a
 * live, sandboxed preview) or fall through to uploading a new .html file.
 *
 *   window.LazyArtifactPicker.open({
 *       onInsert: function (block) {},  // ready `::: artifact` markdown
 *       onUpload: function () {}         // user wants to upload a new file
 *   });
 *
 * Data comes from GET /admin/artifacts?format=json, fetched on every open so a
 * file uploaded a minute ago (or in another tab) is always listed.
 * Keyboard: ↑/↓ move, Enter inserts, Esc closes; double-click inserts.
 */
(function () {
    'use strict';

    var PREVIEW_DELAY_MS = 150;   // debounce iframe reloads while arrowing

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function fmtSize(bytes) {
        return bytes >= 1048576
            ? (bytes / 1048576).toFixed(1) + ' MB'
            : Math.max(1, Math.round(bytes / 1024)) + ' KB';
    }

    function fmtDate(unix) {
        var d = new Date(unix * 1000);
        var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }

    function open(opts) {
        var onInsert = opts.onInsert;
        var onUpload = opts.onUpload;
        var returnFocus = document.activeElement;

        var items = [];          // full list from the server
        var visible = [];        // after search filter
        var selected = -1;       // index into `visible`
        var sandbox = '';
        var previewTimer = null;

        // ----- DOM -----
        var root = el('div', 'artifact-picker');
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');
        root.setAttribute('aria-labelledby', 'artifact-picker-title');

        var backdrop = el('div', 'artifact-picker-backdrop');
        var panel = el('div', 'artifact-picker-panel');

        var head = el('div', 'artifact-picker-head');
        var tag = el('span', 'artifact-picker-tag', '// INSERT ARTIFACT');
        tag.id = 'artifact-picker-title';
        var search = el('input', 'admin-input artifact-picker-search');
        search.type = 'search';
        search.placeholder = 'filter by title or id…';
        search.setAttribute('aria-label', 'Filter artifacts');
        var closeBtn = el('button', 'admin-btn admin-btn-sm', 'ESC');
        closeBtn.type = 'button';
        closeBtn.title = 'Close';
        head.append(tag, search, closeBtn);

        var body = el('div', 'artifact-picker-body');
        var list = el('ul', 'artifact-picker-list');
        list.setAttribute('role', 'listbox');
        list.setAttribute('aria-label', 'Uploaded artifacts');

        var preview = el('div', 'artifact-picker-preview');
        var previewBar = el('div', 'artifact-picker-preview-bar');
        var previewTitle = el('span', 'artifact-picker-preview-title', '');
        var previewOpen = el('a', 'artifact-picker-preview-open', 'OPEN ↗');
        previewOpen.target = '_blank';
        previewOpen.rel = 'noopener';
        previewOpen.hidden = true;
        previewBar.append(previewTitle, previewOpen);
        var frame = el('iframe', 'artifact-picker-frame');
        frame.title = 'Artifact preview';
        var placeholder = el('p', 'artifact-picker-placeholder', 'Loading artifacts…');
        preview.append(previewBar, frame, placeholder);
        body.append(list, preview);

        var foot = el('div', 'artifact-picker-foot');
        var uploadBtn = el('button', 'admin-btn admin-btn-sm', '[ UPLOAD NEW .HTML ]');
        uploadBtn.type = 'button';
        var spacer = el('span', 'artifact-picker-spacer');
        var cancelBtn = el('button', 'admin-btn admin-btn-sm artifact-picker-cancel', 'CANCEL');
        cancelBtn.type = 'button';
        var insertBtn = el('button', 'admin-btn admin-btn-sm admin-btn-primary', '[ INSERT ]');
        insertBtn.type = 'button';
        insertBtn.disabled = true;
        foot.append(uploadBtn, spacer, cancelBtn, insertBtn);

        panel.append(head, body, foot);
        root.append(backdrop, panel);
        document.body.appendChild(root);
        document.documentElement.classList.add('artifact-picker-open');
        search.focus();

        // ----- Behaviour -----
        function close() {
            clearTimeout(previewTimer);
            document.removeEventListener('keydown', onKey, true);
            document.documentElement.classList.remove('artifact-picker-open');
            root.remove();
            if (returnFocus && returnFocus.focus) returnFocus.focus();
        }

        function showPlaceholder(text) {
            placeholder.textContent = text;
            placeholder.hidden = false;
            frame.hidden = true;
            frame.removeAttribute('src');
            previewTitle.textContent = '';
            previewOpen.hidden = true;
        }

        function loadPreview(item) {
            clearTimeout(previewTimer);
            previewTitle.textContent = item.title;
            previewOpen.href = '/artifacts/' + encodeURIComponent(item.id);
            previewOpen.hidden = false;
            previewTimer = setTimeout(function () {
                placeholder.hidden = true;
                frame.hidden = false;
                frame.src = '/artifacts/' + encodeURIComponent(item.id);
            }, PREVIEW_DELAY_MS);
        }

        function select(index, scroll) {
            if (!visible.length) { selected = -1; insertBtn.disabled = true; return; }
            selected = Math.max(0, Math.min(index, visible.length - 1));
            Array.prototype.forEach.call(list.children, function (li, i) {
                li.setAttribute('aria-selected', i === selected ? 'true' : 'false');
            });
            var li = list.children[selected];
            if (scroll && li) li.scrollIntoView({ block: 'nearest' });
            insertBtn.disabled = false;
            loadPreview(visible[selected]);
        }

        function insertSelected() {
            if (selected < 0 || !visible[selected]) return;
            var block = visible[selected].block;
            close();
            onInsert(block);
        }

        function render() {
            var q = search.value.trim().toLowerCase();
            visible = items.filter(function (a) {
                return q === '' || a.title.toLowerCase().indexOf(q) !== -1 || a.id.indexOf(q) !== -1;
            });
            list.textContent = '';
            visible.forEach(function (a, i) {
                var li = el('li', 'artifact-picker-item');
                li.setAttribute('role', 'option');
                li.append(
                    el('span', 'artifact-picker-item-title', a.title),
                    el('span', 'artifact-picker-item-meta',
                        a.id + ' · ' + fmtSize(a.size) + ' · ' + fmtDate(a.mtime)),
                    el('span', 'artifact-picker-item-usage' + (a.usedIn ? '' : ' is-unused'),
                        a.usedIn ? 'USED ×' + a.usedIn : 'UNUSED')
                );
                li.addEventListener('click', function () { select(i, false); });
                li.addEventListener('dblclick', function () { select(i, false); insertSelected(); });
                list.appendChild(li);
            });
            if (!items.length) {
                showPlaceholder('No artifacts yet — upload a self-contained .html page.');
                insertBtn.disabled = true;
                uploadBtn.focus();
            } else if (!visible.length) {
                showPlaceholder('No artifact matches “' + search.value.trim() + '”.');
                selected = -1;
                insertBtn.disabled = true;
            } else {
                select(0, true);
            }
        }

        function onKey(e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();   // keep EasyMDE fullscreen etc. from also reacting
                close();
            } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                if (!visible.length) return;
                e.preventDefault();
                select(selected + (e.key === 'ArrowDown' ? 1 : -1), true);
            } else if (e.key === 'Enter' && e.target.tagName !== 'BUTTON' && e.target.tagName !== 'A') {
                e.preventDefault();
                insertSelected();
            }
        }

        document.addEventListener('keydown', onKey, true);
        backdrop.addEventListener('click', close);
        closeBtn.addEventListener('click', close);
        cancelBtn.addEventListener('click', close);
        insertBtn.addEventListener('click', insertSelected);
        search.addEventListener('input', render);
        uploadBtn.addEventListener('click', function () {
            close();
            onUpload();
        });

        fetch('/admin/artifacts?format=json', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function (data) {
                if (!root.isConnected) return;
                items = data.artifacts || [];
                sandbox = data.sandbox || '';
                // Same flags as the public embed and the serve-time CSP:
                // no allow-same-origin, so the preview can't reach the editor.
                frame.setAttribute('sandbox', sandbox);
                render();
            })
            .catch(function (err) {
                if (!root.isConnected) return;
                showPlaceholder('Could not load artifacts (' + err.message + '). You can still upload a new one.');
            });
    }

    window.LazyArtifactPicker = { open: open };
})();
