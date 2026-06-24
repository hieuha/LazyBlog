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
    if (!ctx.csrf || !ctx.slug) return;

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
    function syncTextPreview() {
        if (!textIn) return;
        var f = fontSel ? fontSel.value : 'marker';
        var c = colorSel ? colorSel.value : 'green';
        textIn.style.fontFamily = FONT_MAP[f] || '';
        textIn.style.color = COLOR_MAP[c] || '';
        textIn.style.fontSize = '20px';
    }
    if (fontSel) fontSel.addEventListener('change', syncTextPreview);
    if (colorSel) colorSel.addEventListener('change', syncTextPreview);
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
            font: fontSel ? fontSel.value : 'marker',
            color: colorSel ? colorSel.value : 'green',
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
            if (placing) cancelPlacing();
            else if (!modal.hidden) closeModal();
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
        fd.append('_csrf', ctx.csrf);
        fd.append('friend_id', 'self');
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
        fetch('/admin/graffiti/send/submit', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            redirect: 'follow',
        }).then(function () {
            // Server flashes via session + redirects; reload to pick up
            // both the new graffiti AND the flash on /admin/graffiti/send.
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
