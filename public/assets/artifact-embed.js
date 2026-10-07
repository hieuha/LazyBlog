/* Embedded HTML artifacts — host side.
 *
 * Each artifact iframe (rendered by MarkdownRenderer::preprocessArtifacts)
 * loads /artifacts/{id}, whose injected reporter posts
 * {type: 'lazyblog-artifact-height', height} whenever its content resizes.
 * Here we match the message to its iframe by `event.source` (the artifact
 * runs in an opaque sandboxed origin, so origin checks can't identify it)
 * and fit the frame to the content.
 *
 * Runaway guard: an artifact styled `min-height: 100vh` plus a body margin
 * reports "frame height + margin" after every resize, which would grow the
 * frame forever. A frame that keeps growing in rapid succession is reset to
 * the height it had when the burst began and frozen there; it then scrolls
 * internally like a normal iframe.
 *
 * Also wires the per-embed fullscreen button (left `hidden` server-side so
 * it never shows where the Fullscreen API is missing, e.g. iPhone Safari —
 * the OPEN link covers that case). Lookups happen per event rather than on
 * load, so embeds injected later (admin live preview) work too.
 */
(function () {
    'use strict';

    var MIN_HEIGHT = 120;
    var MAX_HEIGHT = 4000;
    var GROWTH_BURST = 8;        // consecutive growth reports …
    var GROWTH_WINDOW_MS = 1500; // … inside this window freeze the frame

    function frameFor(source) {
        var frames = document.querySelectorAll('iframe[data-artifact]');
        for (var i = 0; i < frames.length; i++) {
            if (frames[i].contentWindow === source) return frames[i];
        }
        return null;
    }

    function applyHeight(frame, reported) {
        if (frame.dataset.artifactFrozen === '1') return;
        var h = Math.max(MIN_HEIGHT, Math.min(MAX_HEIGHT, Math.round(reported)));
        var current = frame.getBoundingClientRect().height;
        if (Math.abs(h - current) < 2) return;

        var now = Date.now();
        if (h > current) {
            var since = Number(frame.dataset.artifactGrowStart || 0);
            var count = Number(frame.dataset.artifactGrowCount || 0);
            if (now - since > GROWTH_WINDOW_MS) {
                since = now;
                count = 0;
                frame.dataset.artifactGrowFrom = String(current);
            }
            count++;
            frame.dataset.artifactGrowStart = String(since);
            frame.dataset.artifactGrowCount = String(count);
            if (count > GROWTH_BURST) {
                // Roll back the creep to where this burst began.
                frame.style.height = frame.dataset.artifactGrowFrom + 'px';
                frame.dataset.artifactFrozen = '1';
                return;
            }
        } else {
            frame.dataset.artifactGrowCount = '0';
        }
        frame.style.height = h + 'px';
    }

    window.addEventListener('message', function (e) {
        var d = e.data;
        if (!d || d.type !== 'lazyblog-artifact-height' || typeof d.height !== 'number' || !isFinite(d.height)) return;
        var frame = frameFor(e.source);
        if (frame) applyHeight(frame, d.height);
    });

    if (!document.fullscreenEnabled) return;

    function revealFullscreenButtons() {
        var btns = document.querySelectorAll('[data-artifact-fullscreen][hidden]');
        for (var i = 0; i < btns.length; i++) btns[i].hidden = false;
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', revealFullscreenButtons);
    } else {
        revealFullscreenButtons();
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-artifact-fullscreen]');
        if (!btn) return;
        var figure = btn.closest('.artifact-embed');
        if (!figure) return;
        if (document.fullscreenElement === figure) {
            document.exitFullscreen();
        } else {
            figure.requestFullscreen().catch(function () { /* user-gesture or policy refusal: nothing to do */ });
        }
    });
})();
