/* =========================================================================
   LazyBlog — site.js
   Universal-page interactions: theme toggle, back-to-top.
   Loaded with `defer` on every page. Post-page-only behaviours live in
   post.js to avoid parsing them on /, /archive, /search, etc.
   ========================================================================= */

/* ---------- Theme picker dropdown ----------
   Open/close a small menu of valid themes, set data-theme + persist in
   localStorage on pick. Initial theme is set by the inline bootstrap in
   layout.php's <head> so there's no FOUC; this only handles user-driven
   switches. */
(function () {
    var picker = document.getElementById('theme-picker');
    if (!picker) return;
    var toggle = picker.querySelector('.theme-picker-toggle');
    var menu = picker.querySelector('.theme-picker-menu');
    var label = picker.querySelector('[data-theme-label]');
    var items = picker.querySelectorAll('[data-theme-set]');
    var VALID = ['amber', 'green', 'crypt', 'brutalist', 'c64', 'lcd'];

    function syncUI(theme) {
        if (label) label.textContent = '[ THEME: ' + theme.toUpperCase() + ' ]';
        for (var i = 0; i < items.length; i++) {
            items[i].setAttribute(
                'aria-current',
                items[i].getAttribute('data-theme-set') === theme ? 'true' : 'false'
            );
        }
    }
    function open() {
        menu.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
    }
    function close() {
        menu.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
    }

    syncUI(document.documentElement.getAttribute('data-theme') || 'amber');

    toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        if (menu.hidden) open(); else close();
    });
    menu.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-theme-set]');
        if (!btn) return;
        var t = btn.getAttribute('data-theme-set');
        if (VALID.indexOf(t) < 0) return;
        document.documentElement.setAttribute('data-theme', t);
        try { localStorage.setItem('theme', t); } catch (err) {}
        syncUI(t);
        close();
        toggle.focus();
    });
    document.addEventListener('click', function (e) {
        if (!picker.contains(e.target)) close();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !menu.hidden) { close(); toggle.focus(); }
    });
})();

/* ---------- Back-to-top floating button ----------
   Fades in after scrolling past a threshold; smooth-scrolls to top on click.
   Uses a passive scroll listener + rAF batching so it never blocks scroll
   on slower devices. */
(function () {
    var btt = document.getElementById('back-to-top');
    if (!btt) return;
    var threshold = 400;
    var ticking = false;

    function update() {
        btt.classList.toggle('visible', window.scrollY > threshold);
        ticking = false;
    }
    window.addEventListener('scroll', function () {
        if (!ticking) { window.requestAnimationFrame(update); ticking = true; }
    }, { passive: true });
    btt.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
    update();
})();
