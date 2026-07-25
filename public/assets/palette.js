/* =========================================================================
   LazyBlog — palette.js
   Universal command palette, styled as a terminal prompt.

   Shortcuts:
     Ctrl/Cmd + K  → open: live post search + page navigation (tab switch)
     Ctrl + ,      → open in theme mode (toggle color)
                     (Cmd+, is browser Preferences on macOS — not interceptable)

   Commands are harvested from the DOM the server already rendered: header
   nav links (#header-nav-list) and theme buttons ([data-theme-set]) — so
   plugin nav items and auth-gated links appear without duplicating any PHP
   logic here. Post search hits stream from /search?format=json (same
   Searcher as the /search page, protected-post rules included).

   Shortcuts are ignored while an input/textarea/contenteditable has focus,
   so EasyMDE's Cmd+K (insert link) in the admin editor keeps working.
   /writer bypasses layout.php entirely and never loads this file.
   ========================================================================= */
(function () {
    'use strict';

    /* ---------- Command sources (read once — header is static) ---------- */

    function navCommands() {
        var links = document.querySelectorAll('#header-nav-list .header-btn');
        var cmds = [];
        for (var i = 0; i < links.length; i++) {
            var label = links[i].textContent.replace(/[\[\]]/g, '').trim();
            cmds.push({
                kind: 'nav',
                label: label,
                hint: links[i].getAttribute('href'),
                href: links[i].getAttribute('href')
            });
        }
        return cmds;
    }

    function adminCommands() {
        // Seeded by layout.php only when an admin session exists — the
        // auth gate lives server-side, this just consumes the list.
        var defs = window.LB_ADMIN_COMMANDS || [];
        var cmds = [];
        for (var i = 0; i < defs.length; i++) {
            cmds.push({ kind: 'nav', label: defs[i].label, hint: defs[i].href, href: defs[i].href });
        }
        return cmds;
    }

    function contextCommands() {
        // Post pages render [ EDIT ] / [ ZEN ] links for admins — surface
        // them as commands scoped to the post being read.
        var links = document.querySelectorAll('.view-source-link-edit');
        var cmds = [];
        for (var i = 0; i < links.length; i++) {
            var label = links[i].textContent.replace(/[\[\]]/g, '').trim();
            cmds.push({
                kind: 'nav',
                label: label + ' THIS POST',
                hint: links[i].getAttribute('href'),
                href: links[i].getAttribute('href')
            });
        }
        return cmds;
    }

    function themeCommands() {
        var btns = document.querySelectorAll('#theme-picker [data-theme-set]');
        var cmds = [];
        for (var i = 0; i < btns.length; i++) {
            cmds.push({
                kind: 'theme',
                label: 'THEME: ' + btns[i].getAttribute('data-theme-set').toUpperCase(),
                hint: 'set color',
                btn: btns[i]
            });
        }
        return cmds;
    }

    /* ---------- Palette DOM (built lazily on first open) ---------- */

    var overlay = null, input = null, list = null, statusEl = null;
    var items = [];          // currently rendered commands
    var selected = 0;
    var mode = 'all';        // 'all' | 'theme'
    var lastFocus = null;
    var fetchTimer = null;
    var fetchSeq = 0;        // discard out-of-order search responses

    function build() {
        overlay = document.createElement('div');
        overlay.className = 'cmdk-overlay';
        overlay.hidden = true;

        var panel = document.createElement('div');
        panel.className = 'cmdk-panel';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'true');
        panel.setAttribute('aria-label', 'Command palette');

        var promptRow = document.createElement('div');
        promptRow.className = 'cmdk-prompt-row';
        var prompt = document.createElement('span');
        prompt.className = 'cmdk-prompt';
        prompt.setAttribute('aria-hidden', 'true');
        prompt.textContent = '>';
        input = document.createElement('input');
        input.className = 'cmdk-input';
        input.type = 'text';
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('autocapitalize', 'off');
        input.setAttribute('spellcheck', 'false');
        input.setAttribute('aria-label', 'Search posts or type a command');
        promptRow.appendChild(prompt);
        promptRow.appendChild(input);

        list = document.createElement('ul');
        list.className = 'cmdk-results';
        list.setAttribute('role', 'listbox');

        statusEl = document.createElement('div');
        statusEl.className = 'cmdk-status';
        statusEl.setAttribute('aria-live', 'polite');

        var hints = document.createElement('div');
        hints.className = 'cmdk-hints';
        hints.textContent = '↑↓ select · ↵ run · esc close';

        panel.appendChild(promptRow);
        panel.appendChild(list);
        panel.appendChild(statusEl);
        panel.appendChild(hints);
        overlay.appendChild(panel);
        document.body.appendChild(overlay);

        input.addEventListener('input', function () { refresh(); });
        input.addEventListener('keydown', onInputKey);
        overlay.addEventListener('mousedown', function (e) {
            if (e.target === overlay) close();
        });
        list.addEventListener('click', function (e) {
            var li = e.target.closest('[data-idx]');
            if (li) run(items[parseInt(li.getAttribute('data-idx'), 10)]);
        });
    }

    /* ---------- Rendering ---------- */

    function render() {
        list.textContent = '';
        for (var i = 0; i < items.length; i++) {
            var it = items[i];
            var li = document.createElement('li');
            li.className = 'cmdk-item cmdk-item-' + it.kind;
            li.setAttribute('role', 'option');
            li.setAttribute('data-idx', String(i));
            li.setAttribute('aria-selected', i === selected ? 'true' : 'false');
            if (i === selected) li.classList.add('is-selected');

            var marker = document.createElement('span');
            marker.className = 'cmdk-marker';
            marker.setAttribute('aria-hidden', 'true');
            marker.textContent = i === selected ? '>' : ' ';

            var label = document.createElement('span');
            label.className = 'cmdk-label';
            label.textContent = it.label;

            var hint = document.createElement('span');
            hint.className = 'cmdk-hint';
            hint.textContent = it.hint || '';

            li.appendChild(marker);
            li.appendChild(label);
            li.appendChild(hint);

            if (it.snippet) {
                var snip = document.createElement('div');
                snip.className = 'cmdk-snippet';
                snip.textContent = it.snippet;
                li.appendChild(snip);
            }
            list.appendChild(li);
        }
        var sel = list.children[selected];
        if (sel && sel.scrollIntoView) sel.scrollIntoView({ block: 'nearest' });
    }

    function setStatus(msg) { statusEl.textContent = msg; }

    /* ---------- Filtering + search ---------- */

    function refresh() {
        var q = input.value.trim();
        var ql = q.toLowerCase();

        var cmds = mode === 'theme'
            ? themeCommands()
            : contextCommands().concat(navCommands(), adminCommands(), themeCommands());
        if (ql !== '') {
            cmds = cmds.filter(function (c) {
                return c.label.toLowerCase().indexOf(ql) >= 0
                    || (c.href || '').toLowerCase().indexOf(ql) >= 0;
            });
        }
        items = cmds;
        selected = 0;
        render();
        setStatus('');

        if (fetchTimer) clearTimeout(fetchTimer);
        if (mode === 'theme' || q.length < 2) return;

        setStatus('// searching…');
        fetchTimer = setTimeout(function () { search(q, cmds); }, 180);
    }

    function search(q, cmds) {
        var seq = ++fetchSeq;
        fetch('/search?format=json&q=' + encodeURIComponent(q), {
            headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.ok ? r.json() : { hits: [] }; })
            .then(function (data) {
                if (seq !== fetchSeq || overlay.hidden) return; // stale
                var hits = (data && data.hits) || [];
                items = cmds.concat(hits.map(function (h) {
                    return {
                        kind: 'post',
                        label: h.title,
                        hint: h.date,
                        href: h.url,
                        snippet: h.snippet
                    };
                }));
                selected = 0;
                render();
                setStatus(hits.length
                    ? ''
                    : (cmds.length ? '' : '// no signal — 0 hits'));
            })
            .catch(function () { if (seq === fetchSeq) setStatus(''); });
    }

    /* ---------- Actions ---------- */

    function run(it) {
        if (!it) return;
        if (it.kind === 'theme') {
            // Delegate to the theme picker's own handler (site.js) so the
            // localStorage persist + theme-color meta sync live in one place.
            it.btn.click();
            close();
            return;
        }
        if (it.href) {
            close();
            window.location.href = it.href;
        }
    }

    function open(nextMode) {
        if (!overlay) build();
        mode = nextMode;
        lastFocus = document.activeElement;
        overlay.hidden = false;
        input.value = '';
        input.placeholder = mode === 'theme'
            ? 'toggle color…'
            : 'search posts, jump to page…';
        refresh();
        input.focus();
    }

    function close() {
        if (!overlay || overlay.hidden) return;
        overlay.hidden = true;
        if (fetchTimer) clearTimeout(fetchTimer);
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    /* ---------- Keys ---------- */

    function onInputKey(e) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (items.length) { selected = (selected + 1) % items.length; render(); }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (items.length) { selected = (selected - 1 + items.length) % items.length; render(); }
        } else if (e.key === 'Enter') {
            e.preventDefault();
            run(items[selected]);
        }
        /* Escape is handled by the document-level listener below so it
           works regardless of where focus sits. */
    }

    function isEditable(t) {
        if (!t) return false;
        var tag = t.tagName;
        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || t.isContentEditable;
    }

    document.addEventListener('keydown', function (e) {
        var paletteOpen = overlay && !overlay.hidden;
        /* Escape always dismisses the palette, no matter where focus sits
           (the input has its own handler, but focus can land on the panel
           after a mouse interaction). */
        if (e.key === 'Escape' && paletteOpen) {
            e.preventDefault();
            close();
            return;
        }
        /* Ctrl/Cmd+K — toggle palette. Skipped inside form fields so editor
           shortcuts (EasyMDE link insert) keep their binding. */
        if ((e.metaKey || e.ctrlKey) && !e.altKey && !e.shiftKey
                && (e.key === 'k' || e.key === 'K')) {
            if (!paletteOpen && isEditable(e.target)) return;
            e.preventDefault();
            if (paletteOpen) close(); else open('all');
            return;
        }
        /* Ctrl+, — theme / configuration mode. */
        if (e.ctrlKey && !e.metaKey && !e.altKey && !e.shiftKey && e.key === ',') {
            if (!paletteOpen && isEditable(e.target)) return;
            e.preventDefault();
            if (paletteOpen && mode === 'theme') close(); else open('theme');
        }
    });
})();
