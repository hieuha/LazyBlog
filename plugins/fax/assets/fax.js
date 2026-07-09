// Fax plugin client. Injected on post pages only when a webhook token is set.
//
// Flow: reader selects text inside the post article → a floating "Fax this"
// pill appears → clicking it opens a small card (optional name + Send) → the
// selection is POSTed form-encoded to /fax/send → the server's JSON message is
// shown as a toast. All copy (including the "out of faxes" nudge) comes from
// the server so there's a single source of truth.
(function () {
    var island = document.getElementById('fax-ctx');
    if (!island) return;

    var cfg;
    try { cfg = JSON.parse(island.textContent || '{}'); } catch (e) { return; }
    var endpoint = cfg.endpoint || '/fax/send';
    var slug = cfg.slug || '';

    // Per-field char limits. body = highlighted quote + comment (500 total on
    // the webhook); name is a separate 40-char field. Each field shows its own
    // counter, so quote and comment each display against BODY_MAX.
    var BODY_MAX = 500;
    var NAME_MAX = 40;

    // Wrap a field element with a char counter pinned inside its bottom-right
    // corner. `lengthFn` returns the current char count; pass live=true to
    // refresh on the field's own input event (static fields update once).
    function withCounter(fieldEl, lengthFn, max, live) {
        var wrap = document.createElement('div');
        wrap.className = 'fax-field';
        var count = document.createElement('div');
        count.className = 'fax-count';
        var update = function () { count.textContent = lengthFn() + '/' + max; };
        update();
        if (live) fieldEl.addEventListener('input', update);
        wrap.appendChild(fieldEl);
        wrap.appendChild(count);
        return wrap;
    }

    var article = document.querySelector('.post-article');
    if (!article) return;

    var pill = null;   // floating "Fax this" button
    var card = null;   // expanded send card
    var captured = ''; // selection text snapshotted when the pill was shown

    function clearUi() {
        if (pill) { pill.remove(); pill = null; }
        if (card) { card.remove(); card = null; }
    }

    // Return the current in-article selection as {text, rect}, or null.
    function selectionInfo() {
        var sel = window.getSelection();
        if (!sel || sel.isCollapsed || sel.rangeCount === 0) return null;
        var range = sel.getRangeAt(0);
        if (!article.contains(range.commonAncestorContainer)) return null;
        var text = (sel.toString() || '').replace(/\s+/g, ' ').trim();
        if (text.length < 2) return null;
        var rect = range.getBoundingClientRect();
        if (!rect || (rect.width === 0 && rect.height === 0)) return null;
        return { text: text, rect: rect };
    }

    // Position a fixed-width element above the selection (or below if there's
    // no room up top), horizontally centred on it.
    function place(el, rect) {
        var top = window.scrollY + rect.top - el.offsetHeight - 8;
        if (top < window.scrollY + 4) {
            top = window.scrollY + rect.bottom + 8;
        }
        el.style.top = top + 'px';
        el.style.left = (window.scrollX + rect.left + rect.width / 2) + 'px';
    }

    function showPill() {
        if (card) return; // don't fight an open card
        var info = selectionInfo();
        if (!info) { if (pill) { pill.remove(); pill = null; } return; }
        captured = info.text;

        if (!pill) {
            pill = document.createElement('button');
            pill.type = 'button';
            // header-btn = the page's shared button style (nav buttons);
            // fax-pill only adds float positioning.
            pill.className = 'header-btn fax-pill';
            pill.textContent = '[ FAX THIS ]';
            // Keep the selection alive when pressing the pill.
            pill.addEventListener('mousedown', function (e) { e.preventDefault(); });
            pill.addEventListener('click', openCard);
            document.body.appendChild(pill);
        }
        placePill(pill, info.rect);
    }

    // Anchor the pill just below the bottom-right corner of the selection,
    // right-aligned to the selection's right edge (clamped to the viewport).
    function placePill(el, rect) {
        var top = window.scrollY + rect.bottom + 6;
        var left = window.scrollX + rect.right - el.offsetWidth;
        if (left < window.scrollX + 4) left = window.scrollX + 4;
        el.style.top = top + 'px';
        el.style.left = left + 'px';
    }

    function openCard() {
        var rect = pill.getBoundingClientRect();
        var anchor = { top: rect.top, bottom: rect.bottom, left: rect.left, width: rect.width };
        pill.remove(); pill = null;

        card = document.createElement('div');
        card.className = 'fax-card';

        // Read-only preview of the highlighted selection. Counts the full
        // captured length (not the truncated preview) against the body budget.
        var quote = document.createElement('div');
        quote.className = 'fax-card-quote';
        quote.textContent = '“' + (captured.length > 160 ? captured.slice(0, 160) + '…' : captured) + '”';
        var quoteWrap = withCounter(quote, function () { return captured.length; }, BODY_MAX, false);

        // Reader's own note.
        var comment = document.createElement('textarea');
        comment.className = 'fax-comment';
        comment.rows = 2;
        comment.maxLength = BODY_MAX;
        comment.placeholder = 'Add a comment (optional)…';
        var commentWrap = withCounter(comment, function () { return comment.value.length; }, BODY_MAX, true);

        var name = document.createElement('input');
        name.className = 'fax-name';
        name.type = 'text';
        name.maxLength = NAME_MAX;
        name.placeholder = 'Your name (optional)';
        var nameWrap = withCounter(name, function () { return name.value.length; }, NAME_MAX, true);

        var actions = document.createElement('div');
        actions.className = 'fax-card-actions';

        var send = document.createElement('button');
        send.type = 'button';
        send.className = 'header-btn fax-send';
        send.textContent = '[ SEND FAX ]';

        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'header-btn fax-cancel';
        cancel.textContent = '[ CANCEL ]';

        var status = document.createElement('span');
        status.className = 'fax-card-status';

        actions.appendChild(send);
        actions.appendChild(cancel);
        actions.appendChild(status);
        card.appendChild(quoteWrap);
        card.appendChild(commentWrap);
        card.appendChild(nameWrap);
        card.appendChild(actions);
        document.body.appendChild(card);
        place(card, anchor);
        comment.focus();

        cancel.addEventListener('click', clearUi);
        send.addEventListener('click', function () {
            doSend(send, comment, name, status);
        });
        // Cmd/Ctrl+Enter sends from anywhere in the card; plain Enter in the
        // name field sends too (textarea keeps Enter for newlines).
        card.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
                e.preventDefault(); doSend(send, comment, name, status);
            }
        });
        name.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); doSend(send, comment, name, status); }
        });
    }

    function doSend(sendBtn, commentInput, nameInput, statusEl) {
        // Re-entry guard: a single Ctrl/Cmd+Enter in the name field bubbles to
        // BOTH the name and card keydown handlers, so without this the fax
        // would be sent twice. The disabled button also blocks click re-entry.
        if (sendBtn.disabled) return;
        sendBtn.disabled = true;
        statusEl.textContent = 'Faxing…';

        var params = new URLSearchParams();
        params.set('quote', captured.slice(0, BODY_MAX));
        params.set('comment', (commentInput.value || '').slice(0, BODY_MAX));
        params.set('name', (nameInput.value || '').slice(0, 40));
        params.set('slug', slug);

        fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json'
            },
            body: params.toString()
        })
            .then(function (r) {
                return r.json().catch(function () {
                    return { ok: false, message: 'The fax machine mumbled something unintelligible.' };
                });
            })
            .then(function (data) {
                clearUi();
                toast(data.message || (data.ok ? 'Fax sent!' : 'Fax failed.'), !!data.ok);
            })
            .catch(function () {
                clearUi();
                toast('Could not reach the fax machine — check your connection.', false);
            });
    }

    function toast(message, ok) {
        var el = document.createElement('div');
        el.className = 'fax-toast ' + (ok ? 'fax-toast-ok' : 'fax-toast-err');
        el.textContent = message;
        document.body.appendChild(el);
        setTimeout(function () {
            el.classList.add('fax-toast-hide');
            setTimeout(function () { el.remove(); }, 260);
        }, 5000);
    }

    // Show the pill after a selection settles (mouse or keyboard).
    document.addEventListener('mouseup', function () { setTimeout(showPill, 10); });
    document.addEventListener('keyup', function (e) {
        if (e.shiftKey || e.key === 'Shift') setTimeout(showPill, 10);
    });

    // Drop the pill when the selection collapses.
    document.addEventListener('selectionchange', function () {
        if (card) return;
        if (!selectionInfo() && pill) { pill.remove(); pill = null; }
    });

    // Scrolling drifts the anchor — retire the pill (the card stays put).
    window.addEventListener('scroll', function () {
        if (pill) { pill.remove(); pill = null; }
    }, { passive: true });

    // Click outside an open card closes it.
    document.addEventListener('mousedown', function (e) {
        if (card && !card.contains(e.target)) clearUi();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') clearUi();
    });
})();
