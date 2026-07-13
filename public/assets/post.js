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

/* ---------- Scrollspy: highlight the TOC link for the current heading ----------
   Tracks the last heading whose top has crossed the activation line (25% from
   the viewport top). This way the active section stays lit while scrolling
   through the body BETWEEN two headings — a pure IntersectionObserver band
   would lose the highlight as soon as the heading leaves the band.
   Watches h1/h2/h3 so `#`-level headings in markdown still spy. */
(function () {
    var headings = document.querySelectorAll('.post-body h1[id], .post-body h2[id], .post-body h3[id]');
    if (!headings.length) return;

    var tocLinks = document.querySelectorAll('.toc-list a');
    if (!tocLinks.length) return;

    // Click on a TOC link pins the active state briefly so the smooth-scroll
    // doesn't flicker through intermediate sections before settling.
    var pinUntil = 0;

    function setActive(activeHref) {
        tocLinks.forEach(function (a) {
            a.classList.toggle('is-active', a.getAttribute('href') === activeHref);
        });
    }

    function compute() {
        if (Date.now() < pinUntil) return;
        var line = window.innerHeight * 0.25;
        var current = null;
        for (var i = 0; i < headings.length; i++) {
            if (headings[i].getBoundingClientRect().top <= line) {
                current = headings[i];
            } else {
                break;
            }
        }
        setActive(current ? '#' + current.id : null);
    }

    var ticking = false;
    function onScroll() {
        if (ticking) return;
        ticking = true;
        window.requestAnimationFrame(function () {
            compute();
            ticking = false;
        });
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', compute);

    tocLinks.forEach(function (a) {
        a.addEventListener('click', function () {
            setActive(a.getAttribute('href'));
            pinUntil = Date.now() + 700;
        });
    });

    compute();
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

/* ---------- Footnote <-> reference cross-highlight ----------
   Clicking a `[N]` reference lights its note; clicking a note's number lights
   the `[N]` reference. Both directions use the same `fn-lit` highlight. Works
   in both render modes:
     - wide (>=1440px): the note is the `.sidenote` span right after the ref's
       <sup>; the bottom .footnotes list is hidden.
     - narrow: the note is the `<li id="fn:LABEL">` in the bottom list; the
       `.sidenote` is hidden. The `↩` backref covers note→ref there.
   Element relationships (sup#fnref:LABEL, li#fn:LABEL, sidenote = next sibling
   of the sup) do the mapping — no server-side data attributes needed. */
(function () {
    var refs = document.querySelectorAll('.post-body sup[id^="fnref:"] .footnote-ref');
    if (!refs.length) { return; }

    function flash(el, cls) {
        if (!el) { return; }
        el.classList.remove(cls);
        void el.offsetWidth; // reflow so re-adding restarts the animation
        el.classList.add(cls);
        el.addEventListener('animationend', function handler() {
            el.classList.remove(cls);
            el.removeEventListener('animationend', handler);
        });
    }

    // Light BOTH copies of the note — the `.sidenote` span (wide) and the
    // bottom-list `<li>` (narrow). Only one is displayed at a time, and a
    // display:none element simply won't animate, so this needs no viewport
    // check.
    refs.forEach(function (ref) {
        ref.addEventListener('click', function () {
            var sup = ref.parentNode;
            var side = sup.nextElementSibling;
            if (side && side.classList.contains('sidenote')) { flash(side, 'fn-lit'); }
            flash(document.getElementById('fn:' + sup.id.slice('fnref:'.length)), 'fn-lit');
        });
    });

    // Sidenote number → light up the matching reference.
    document.querySelectorAll('.post-body .sidenote').forEach(function (side) {
        var num = side.querySelector('.sidenote-num') || side;
        num.addEventListener('click', function () {
            var sup = side.previousElementSibling;
            var ref = sup ? sup.querySelector('.footnote-ref') : null;
            flash(ref, 'fn-lit');
        });
    });

    // Bottom-list backref (↩) → light up the matching reference (narrow mode).
    document.querySelectorAll('.post-body .footnote-backref').forEach(function (back) {
        back.addEventListener('click', function () {
            var sup = document.getElementById((back.getAttribute('href') || '#').slice(1));
            var ref = sup ? sup.querySelector('.footnote-ref') : null;
            flash(ref, 'fn-lit');
        });
    });
})();

/* ---------- Sidenote de-overlap (wide-screen margin notes) ----------
   Each `.sidenote` is `position:absolute` anchored to its reference line, so
   a long note whose next reference sits a line or two below will overlap the
   following note. Walk the notes top-to-bottom and, whenever one starts
   before the previous one ends, push it down with margin-top so they stack
   with a gap. No-op on narrow screens where the notes are `display:none`. */
(function () {
    var notes = document.querySelectorAll('.post-body .sidenote');
    if (!notes.length) { return; }
    var GAP = 14;

    function layout() {
        // Reset first so shrinking the note count / widening the screen recomputes cleanly.
        notes.forEach(function (n) { n.style.transform = ''; });
        // Bail entirely when the notes aren't rendered as margin notes.
        if (getComputedStyle(notes[0]).display === 'none') { return; }

        var prevBottom = -Infinity;
        notes.forEach(function (n) {
            // Natural (untransformed) document-relative top of this note.
            var t = n.getBoundingClientRect().top + window.pageYOffset;
            var shift = 0;
            if (t < prevBottom + GAP) {
                shift = (prevBottom + GAP) - t;
                // translateY reliably moves a position:absolute element and,
                // unlike margin, doesn't get folded into the static-position math.
                n.style.transform = 'translateY(' + shift + 'px)';
            }
            prevBottom = t + shift + n.offsetHeight;
        });
    }

    var raf;
    function schedule() {
        window.cancelAnimationFrame(raf);
        raf = window.requestAnimationFrame(layout);
    }

    schedule();
    window.addEventListener('load', schedule);   // re-run once webfonts settle
    window.addEventListener('resize', schedule);
})();
