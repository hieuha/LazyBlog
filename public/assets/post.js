/* =========================================================================
   LazyBlog — post.js
   Post-page interactions: reading progress bar, TOC scrollspy,
   floating-TOC fade-in, code-block copy buttons + language labels.
   Loaded with `defer` only when layout.php detects /posts/* — so /,
   /archive, /search, etc. don't parse this file.
   ========================================================================= */

/* ---------- Reading progress bar (CRT signal-strength style) ----------
   Sets .read-progress-fill width to scroll-through-article percentage.
   CSS handles the fill colour, glow, and tick marks. */
(function () {
    var bar = document.querySelector('.read-progress-fill');
    if (!bar) return;
    var ticking = false;
    function update() {
        var el = document.documentElement;
        var max = el.scrollHeight - el.clientHeight;
        var p = max > 0 ? Math.min(100, Math.max(0, (el.scrollTop / max) * 100)) : 0;
        bar.style.width = p + '%';
        ticking = false;
    }
    window.addEventListener('scroll', function () {
        if (!ticking) {
            window.requestAnimationFrame(update);
            ticking = true;
        }
    }, { passive: true });
    window.addEventListener('resize', update);
    update();
})();

/* ---------- Floating TOC fade-in ----------
   The desktop-only floating TOC (.post-toc-wrap) fades in after the
   reader scrolls past the threshold — kept in a separate scroll handler
   from back-to-top because the floating TOC only exists on post pages. */
(function () {
    var tocWrap = document.querySelector('.post-toc-wrap');
    if (!tocWrap) return;
    var threshold = 400;
    var ticking = false;
    function update() {
        tocWrap.classList.toggle('floating-visible', window.scrollY > threshold);
        ticking = false;
    }
    window.addEventListener('scroll', function () {
        if (!ticking) { window.requestAnimationFrame(update); ticking = true; }
    }, { passive: true });
    update();
})();

/* ---------- Scrollspy: highlight the TOC link for the current h2 ----------
   Uses IntersectionObserver — no scroll listener, no rAF needed. Heading
   is "active" while it sits inside the top 10–35% of the viewport band. */
(function () {
    if (!('IntersectionObserver' in window)) return;
    var headings = document.querySelectorAll('.post-body h2[id]');
    if (!headings.length) return;

    var tocLinks = document.querySelectorAll('.toc-list a');
    if (!tocLinks.length) return;

    var intersecting = new Set();

    function setActive(activeId) {
        var activeHref = activeId ? '#' + activeId : null;
        tocLinks.forEach(function (a) {
            a.classList.toggle('is-active', a.getAttribute('href') === activeHref);
        });
    }

    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
            if (e.isIntersecting) intersecting.add(e.target);
            else intersecting.delete(e.target);
        });
        var topmost = null;
        var topmostY = Infinity;
        intersecting.forEach(function (el) {
            var y = el.getBoundingClientRect().top;
            if (y < topmostY) { topmostY = y; topmost = el; }
        });
        setActive(topmost ? topmost.id : null);
    }, {
        rootMargin: '-10% 0px -65% 0px',
        threshold: 0,
    });

    headings.forEach(function (h) { observer.observe(h); });
})();

/* ---------- Code-block enhancements: language label + copy button ---------- */
(function () {
    var blocks = document.querySelectorAll('.post-body pre');
    if (!blocks.length) return;

    blocks.forEach(function (pre) {
        var code = pre.querySelector('code');
        var lang = '';
        if (code && code.className) {
            var match = code.className.match(/language-([\w-]+)/);
            if (match) lang = match[1];
        }

        // Wrap <pre> in a non-scrolling positioned container so the
        // language tag + COPY button can sit absolutely in its corner
        // without being dragged along when the <pre> itself scrolls
        // horizontally for long single-line code (absolute children
        // inside an `overflow:auto` element scroll with the content).
        var wrap = document.createElement('div');
        wrap.className = 'post-code-wrap';
        pre.parentNode.insertBefore(wrap, pre);
        wrap.appendChild(pre);

        if (lang) {
            var label = document.createElement('span');
            label.className = 'code-lang-tag';
            label.textContent = lang;
            wrap.appendChild(label);
        }

        var btn = document.createElement('button');
        btn.className = 'copy-btn';
        btn.type = 'button';
        btn.setAttribute('aria-label', 'Copy code');
        btn.textContent = 'COPY';
        btn.addEventListener('click', function () {
            var text = (code || pre).textContent || '';
            var done = function () {
                btn.textContent = 'COPIED';
                btn.classList.add('copied');
                setTimeout(function () {
                    btn.textContent = 'COPY';
                    btn.classList.remove('copied');
                }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done).catch(function () {
                    fallbackCopy(text, done);
                });
            } else {
                fallbackCopy(text, done);
            }
        });
        wrap.appendChild(btn);
    });

    function fallbackCopy(text, cb) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus(); ta.select();
        try { document.execCommand('copy'); cb(); } catch (e) {}
        document.body.removeChild(ta);
    }
})();
