/* graffiti.js — in-page spray controls for the post-page admin view.
 *
 * Bootstraps from the JSON data island injected by GraffitiPlugin only
 * when an admin is logged in. Flow:
 *
 *   1. Click spray button         → open modal
 *   2. Pick sticker / write text  → modal closes, body enters "placing" mode
 *   3. Click anywhere on .post-article → POST to /admin/graffiti/send/submit
 *      with friend_id=self, normalised x/y/rotation (random tilt ±15°)
 *   4. Server stores + redirects  → location.reload() shows the new sticker
 *
 * ESC cancels placing mode. The data island uses type=application/json so
 * its contents never execute even if a catalogue name contains markup.
 */
(function () {
    'use strict';

    var article = document.querySelector('.post-article');
    if (!article) return;

    // --------------------------------------------------------------------
    // Per-item dismiss (every visitor, admin or not). Removes DOM node;
    // reload re-fetches all from server. Click/tap anywhere on the item
    // removes it — links/buttons inside the item are skipped so the
    // attribution link still works. Same behavior on hover + touch.
    // --------------------------------------------------------------------
    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || !t.closest) return;
        var item = t.closest('.graffiti-overlay-item');
        if (!item) return;

        // Let interactive children (e.g. attribution <a>) do their job.
        var interactive = t.closest('a, button, input, select, textarea');
        var isDismissBtn = interactive && interactive.matches('[data-graffiti-dismiss]');
        if (interactive && !isDismissBtn && interactive !== item) {
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        item.remove();
    });

    // --------------------------------------------------------------------
    // Spray-can modal — admin only. Bail early if the context island isn't
    // present (anonymous visitor never sees these surfaces).
    // --------------------------------------------------------------------
    var ctxEl = document.getElementById('graffiti-ctx');
    var btn   = document.getElementById('graffiti-spray-btn');
    var modal = document.getElementById('graffiti-modal');
    if (!ctxEl || !btn || !modal) return;

    var ctx;
    try {
        ctx = JSON.parse(ctxEl.textContent || '{}');
    } catch (e) {
        return;
    }
    // admin mode requires csrf; friend mode authenticates by signed cookie.
    if (!ctx.slug) return;
    if (ctx.mode === 'self' && !ctx.csrf) return;

    var grid    = modal.querySelector('[data-grid]');
    var textIn  = modal.querySelector('[data-text]');
    var fontSel = modal.querySelector('[data-font]');
    var colorSel = modal.querySelector('[data-color]');
    var textGo  = modal.querySelector('[data-text-go]');
    var closeBtn = modal.querySelector('.graffiti-modal-close');

    // Mirror the server-side maps in OverlayRenderer + PayloadValidator so
    // the text input previews the chosen font/color live as the operator
    // picks them — no surprise when the sticker lands on the post.
    var FONT_MAP = {
        marker: "'Caveat', cursive",
        spray:  "'Bangers', cursive",
        tag:    "'Russo One', sans-serif",
        block:  "'Bungee Spice', cursive",
    };
    var COLOR_MAP = {
        green:  '#39ff14', white: '#f5f5f5', pink:  '#ff3399', yellow: '#ffd700',
        orange: '#ff7700', red:   '#ff3344', blue:  '#00b3ff', purple: '#a855f7',
    };

    // --- Custom dropdown driver ----------------------------------------
    // Each .graffiti-dd root holds the selected token in data-value.
    // Reading: getDD(root) → token. Wiring is shared across all dropdowns
    // in the modal so we don't repeat listener boilerplate.
    function getDD(root) {
        return root ? (root.getAttribute('data-value') || '') : '';
    }
    function closeAllDD() {
        modal.querySelectorAll('.graffiti-dd-menu').forEach(function (m) { m.hidden = true; });
        modal.querySelectorAll('.graffiti-dd.is-open').forEach(function (d) { d.classList.remove('is-open'); });
    }
    modal.querySelectorAll('.graffiti-dd').forEach(function (dd) {
        var trigger = dd.querySelector('.graffiti-dd-trigger');
        var menu    = dd.querySelector('.graffiti-dd-menu');
        var label   = dd.querySelector('.graffiti-dd-label');
        if (!trigger || !menu || !label) return;

        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            var wasOpen = !menu.hidden;
            closeAllDD();
            if (!wasOpen) {
                menu.hidden = false;
                dd.classList.add('is-open');
            }
        });

        menu.querySelectorAll('[role="option"]').forEach(function (li) {
            li.addEventListener('click', function (e) {
                e.stopPropagation();
                var val = li.getAttribute('data-value') || '';
                dd.setAttribute('data-value', val);
                // Adopt the option's inline styling for the trigger label
                // so the chosen font/color preview shows on the closed
                // button too (not just inside the menu).
                label.textContent = li.textContent.trim();
                label.style.cssText = li.getAttribute('style') || '';
                closeAllDD();
                syncTextPreview();
            });
        });
    });
    // Click outside any dropdown → close all
    document.addEventListener('click', function () { closeAllDD(); });

    function syncTextPreview() {
        if (!textIn) return;
        var f = getDD(fontSel) || 'marker';
        var c = getDD(colorSel) || 'green';
        textIn.style.fontFamily = FONT_MAP[f] || '';
        textIn.style.color = COLOR_MAP[c] || '';
        textIn.style.fontSize = '20px';
    }
    syncTextPreview();

    var placing = null; // {type: 'sticker'|'text', sticker_id?, text?}

    // Render sticker buttons in the modal.
    (ctx.catalogue || []).forEach(function (s) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'graffiti-modal-sticker-btn';
        b.title = s.name + ' — ' + s.price + ' energy';
        b.innerHTML =
            '<img src="/plugin-assets/graffiti/' + encodeURIComponent(s.svg) + '" alt="">' +
            '<small>' + escapeHtml(s.name) + '<br>' + s.price + 'e</small>';
        b.addEventListener('click', function () {
            startPlacing({ type: 'sticker', sticker_id: s.id });
        });
        grid.appendChild(b);
    });

    btn.addEventListener('click', openModal);
    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });

    textGo.addEventListener('click', function () {
        var t = (textIn.value || '').trim();
        if (t.length === 0) return;
        startPlacing({
            type: 'text',
            text: t,
            font: getDD(fontSel) || 'marker',
            color: getDD(colorSel) || 'green',
        });
    });
    textIn.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            textGo.click();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            // Priority: cancel placing > close open dropdown > close modal.
            if (placing) { cancelPlacing(); return; }
            if (modal.querySelector('.graffiti-dd.is-open')) { closeAllDD(); return; }
            if (!modal.hidden) closeModal();
        }
    });

    article.addEventListener('click', function (e) {
        if (!placing) return;
        e.preventDefault();
        e.stopPropagation();
        var rect = article.getBoundingClientRect();
        var x = clamp01((e.clientX - rect.left) / rect.width);
        var y = clamp01((e.clientY - rect.top) / rect.height);
        var rotation = Math.floor(Math.random() * 31) - 15; // ±15°
        submit(placing, x, y, rotation);
    }, true);

    function openModal() {
        modal.hidden = false;
        if (textIn) textIn.value = '';
        if (textIn) setTimeout(function () { textIn.focus(); }, 30);
    }
    function closeModal() {
        modal.hidden = true;
    }

    function startPlacing(state) {
        placing = state;
        closeModal();
        document.body.classList.add('graffiti-placing');
    }
    function cancelPlacing() {
        placing = null;
        document.body.classList.remove('graffiti-placing');
    }

    function submit(state, x, y, rotation) {
        var fd = new FormData();
        // mode=self uses CSRF (admin session); mode=friend uses the signed
        // visit cookie (no extra CSRF in v1 — relies on SameSite=Lax).
        if (ctx.csrf) fd.append('_csrf', ctx.csrf);
        fd.append('friend_id', ctx.friend_id || (ctx.mode === 'self' ? 'self' : ''));
        fd.append('post_slug', ctx.slug);
        fd.append('type', state.type);
        if (state.sticker_id) fd.append('sticker_id', state.sticker_id);
        if (state.text)       fd.append('text', state.text);
        if (state.font)       fd.append('font', state.font);
        if (state.color)      fd.append('color', state.color);
        fd.append('x', x.toFixed(3));
        fd.append('y', y.toFixed(3));
        fd.append('rotation', String(rotation));

        document.body.classList.add('graffiti-submitting');
        fetch(ctx.endpoint || '/admin/graffiti/send/submit', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            redirect: 'follow',
        }).then(function (r) {
            // Either flow ends with the server redirecting (admin) or
            // returning JSON (cross-spray); reload to show the new graffiti.
            if (!r.ok && r.status >= 400) {
                document.body.classList.remove('graffiti-submitting');
                cancelPlacing();
                // Parse the JSON reason so the visitor knows whether to
                // retry (transport flake) or top up energy (cross-spray
                // pre-flight failed). Falls back to status code if the
                // body isn't JSON (e.g. server crash, redirect HTML).
                r.json().catch(function () { return {}; }).then(function (body) {
                    var reason = (body && body.reason) || ('HTTP ' + r.status);
                    var msg = 'Graffiti rejected: ' + reason;
                    if (reason === 'insufficient_energy') {
                        msg = 'Not enough energy on your home blog '
                            + '(need ' + body.price + ', have ' + body.balance + ').';
                    } else if (reason === 'balance_unreachable') {
                        msg = "Couldn't reach your home blog to check energy. "
                            + 'Try again when it\'s back online.';
                    }
                    alert(msg);
                });
                return;
            }
            location.reload();
        }).catch(function () {
            document.body.classList.remove('graffiti-submitting');
            cancelPlacing();
            alert('Graffiti submit failed — check your network and try again.');
        });
    }

    function clamp01(v) { return Math.max(0, Math.min(1, v)); }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
        });
    }
})();
