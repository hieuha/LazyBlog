/**
 * LazyBlog Writer Mode.
 *
 * One contenteditable div, one block per paragraph (`<div class="wb">`).
 * Each block renders a tiny subset of markdown live while preserving the
 * raw syntax characters as visible text — so caret offsets in the rendered
 * DOM map 1:1 to the source markdown. No marked.js, no codemirror, no
 * build step.
 *
 *   Block detection: # ## ### #### / > / - / * / ordered list / ``` / ---
 *   Inline: **bold**, *italic*, `code`, [text](url) — markers stay visible
 *
 * Typewriter focus: only the block containing the caret gets `.is-current`.
 * Typewriter scrolling: after every caret move, the editor stage scrolls so
 * the caret rect sits at the vertical centre of the viewport.
 */

(function () {
    'use strict';

    var editor = document.getElementById('writer-editor');
    var stage = editor && editor.parentElement;
    if (!editor || !stage) return;

    var statusEl = document.getElementById('writer-status');
    var statusText = statusEl.querySelector('.writer-status-text');
    var draftBtn = document.getElementById('writer-draft-btn');
    var submitBtn = document.getElementById('writer-submit-btn');
    var modal = document.getElementById('writer-modal');
    var modalForm = document.getElementById('writer-form');
    var modalMode = document.getElementById('writer-mode');
    var modalBody = document.getElementById('writer-body-hidden');
    var modalTitleInput = document.getElementById('writer-title-input');
    var modalSummaryInput = document.getElementById('writer-summary-input');
    var modalLabel = document.getElementById('writer-modal-mode-label');
    var modalError = document.getElementById('writer-modal-error');
    var modalConfirm = document.getElementById('writer-modal-confirm');
    var modalCancel = document.getElementById('writer-modal-cancel');
    var phaseInput = modalForm.querySelector('[data-phase="input"]');
    var phaseConfirm = modalForm.querySelector('[data-phase="confirm"]');
    var confirmTitleEcho = document.getElementById('writer-confirm-title');
    var confirmUrlEcho = document.getElementById('writer-confirm-url');

    // Two-phase publish: first the title/summary form, then a confirmation
    // dialog. Tracked here so the form submit handler knows whether to
    // advance (input → confirm) or actually POST (confirm → /writer/save).
    var modalPhase = 'input';
    // True when the SUBMIT button currently maps to "save updates" (the
    // edited post is already live). Skips the confirmation step because
    // no publish-state change is happening.
    var isLiveUpdate = false;

    var LS_KEY = 'lazyblog.writer.draft';
    var LS_CARET = 'lazyblog.writer.caret';
    var LS_SURFACE = 'lazyblog.writer.surface';
    var bgToggle = document.getElementById('writer-bg-toggle');
    var tocPanel = document.getElementById('writer-toc');
    var tocList = document.getElementById('writer-toc-list');
    var tocToggle = document.getElementById('writer-toc-toggle');
    var statsWordsEl = document.getElementById('writer-stats-words');
    var statsTimeEl = document.getElementById('writer-stats-time');

    // CSRF token for any in-session POST the writer needs to make (image
    // uploads from clipboard paste). Read once from the hidden form field
    // so we don't have to plumb it through every call site.
    var csrfToken = (modalForm.querySelector('input[name="_csrf"]') || {}).value || '';

    // Edit-mode shifts localStorage keys to a per-slug namespace so the
    // "new post" autosave slot isn't clobbered while the writer is editing
    // an existing post (and vice versa).
    function draftKey() {
        var ex = window.LB_WRITER_EXISTING;
        return (ex && ex.slug) ? (LS_KEY + ':' + ex.slug) : LS_KEY;
    }
    function caretKey() {
        var ex = window.LB_WRITER_EXISTING;
        return (ex && ex.slug) ? (LS_CARET + ':' + ex.slug) : LS_CARET;
    }

    var renderTimer = null;
    var saveTimer = null;
    var scrollRAF = null;
    var statusResetTimer = null;
    var suspendInput = false;
    // IME composition guard. Vietnamese Telex (and CJK IMEs) deliver each
    // intermediate character through `compositionstart` → `compositionupdate`
    // → `compositionend`. If we re-render the block's innerHTML mid-compose
    // the browser loses the composition target node and the partial keys
    // commit as plain Latin ("chua" instead of "chưa"). Skip the render +
    // autosave loop while composing, then run one pass on compositionend.
    var isComposing = false;
    // True once the writer has made changes that haven't been committed to
    // disk through Submit/Save. Used by the exit-confirm path so unsaved
    // work (auto-stashed in localStorage but still not in /posts/...) gets
    // an explicit prompt before navigation away.
    var isDirty = false;
    // Flag toggled true after init + after every Enter that opens a new
    // block. The next non-empty input/composition into that block
    // uppercases its first letter — standard sentence-cap behavior every
    // mobile keyboard ships and that desktop browsers don't.
    var pendingCapitalize = true;

    /* ---------------- Status pill ---------------- */

    function setStatus(state, text) {
        statusEl.setAttribute('data-state', state);
        statusText.textContent = text;
        if (statusResetTimer) clearTimeout(statusResetTimer);
        if (state === 'saved') {
            statusResetTimer = setTimeout(function () {
                statusEl.setAttribute('data-state', 'idle');
            }, 2500);
        }
    }

    /* ---------------- Caret offset helpers ---------------- */

    function getCurrentBlock() {
        var sel = window.getSelection();
        if (!sel || !sel.rangeCount) return null;
        var node = sel.getRangeAt(0).startContainer;
        while (node && node !== editor) {
            if (node.nodeType === 1 && node.classList && node.classList.contains('wb')) {
                return node;
            }
            node = node.parentNode;
        }
        return null;
    }

    function getCaretOffsetInBlock(block) {
        var sel = window.getSelection();
        if (!sel.rangeCount) return 0;
        var range = sel.getRangeAt(0);
        if (!block.contains(range.startContainer)) return 0;
        var pre = document.createRange();
        pre.selectNodeContents(block);
        pre.setEnd(range.startContainer, range.startOffset);
        return pre.toString().length;
    }

    function setCaretOffsetInBlock(block, offset) {
        var walker = document.createTreeWalker(block, NodeFilter.SHOW_TEXT, null);
        var acc = 0;
        var node;
        while ((node = walker.nextNode())) {
            var len = node.nodeValue.length;
            if (acc + len >= offset) {
                var r = document.createRange();
                r.setStart(node, Math.max(0, offset - acc));
                r.collapse(true);
                var sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(r);
                return;
            }
            acc += len;
        }
        // No text nodes (block is just <br>) — caret at start of block.
        var r2 = document.createRange();
        r2.selectNodeContents(block);
        r2.collapse(true);
        var sel2 = window.getSelection();
        sel2.removeAllRanges();
        sel2.addRange(r2);
    }

    /* ---------------- Markdown render (per-block) ----------------
     * Preserves syntax markers as visible text so caret stays anchored
     * to the same character offset before/after re-render. */

    function esc(s) {
        return s
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // Whitelist schemes the writer can preview as <img>. Anything else
    // (javascript:, data:, file:) is rendered as inert syntax-only text
    // so a hostile paste cannot ride the live preview into XSS-land.
    function safeImageUrl(u) {
        if (!u) return null;
        if (/^https?:\/\//i.test(u)) return u;
        if (u.charAt(0) === '/') return u;
        return null;
    }

    function renderInline(text) {
        var html = esc(text);
        // Wrap every syntax marker in `<span class="md-syntax">` so CSS can
        // dim it visually while the character stays in textContent (caret
        // offset math depends on textContent staying byte-equal to source).
        var S_OPEN = '<span class="md-syntax">';
        var S_CLOSE = '</span>';

        // Inline code first — contents are not re-processed.
        html = html.replace(/`([^`\n]+)`/g,
            '<code>' + S_OPEN + '`' + S_CLOSE + '$1' + S_OPEN + '`' + S_CLOSE + '</code>');
        // Bold+italic ***text*** / ___text___ — must run before the 2-marker
        // and 1-marker passes so the triple run is consumed atomically.
        // CommonMark renders both as <em><strong>…</strong></em>.
        // The marker chars inside the .md-syntax spans are written as
        // private-use placeholders so the subsequent bold/italic regex
        // passes don't see literal `*` / `_` and re-wrap the inner content.
        // Both placeholders are swapped back to their real chars right
        // before return so textContent stays equal to the source markdown.
        var STAR_PH = '';
        var US_PH = '';
        html = html.replace(/\*\*\*([^*\n]+)\*\*\*/g,
            '<em>' + S_OPEN + STAR_PH + S_CLOSE
            + '<strong>' + S_OPEN + STAR_PH + STAR_PH + S_CLOSE + '$1'
            + S_OPEN + STAR_PH + STAR_PH + S_CLOSE + '</strong>'
            + S_OPEN + STAR_PH + S_CLOSE + '</em>');
        html = html.replace(/(^|[^_\w])___([^_\n]+)___(?!_)/g,
            '$1<em>' + S_OPEN + US_PH + S_CLOSE
            + '<strong>' + S_OPEN + US_PH + US_PH + S_CLOSE + '$2'
            + S_OPEN + US_PH + US_PH + S_CLOSE + '</strong>'
            + S_OPEN + US_PH + S_CLOSE + '</em>');
        // Bold **text**
        html = html.replace(/\*\*([^*\n]+)\*\*/g,
            '<strong>' + S_OPEN + '**' + S_CLOSE + '$1' + S_OPEN + '**' + S_CLOSE + '</strong>');
        // Bold __text__ (underscore variant — CommonMark parity)
        html = html.replace(/(^|[^_\w])__([^_\n]+)__(?!_)/g,
            '$1<strong>' + S_OPEN + '__' + S_CLOSE + '$2' + S_OPEN + '__' + S_CLOSE + '</strong>');
        // Italic *text* (not part of **)
        html = html.replace(/(^|[^*\w])\*([^*\n]+)\*(?!\*)/g,
            '$1<em>' + S_OPEN + '*' + S_CLOSE + '$2' + S_OPEN + '*' + S_CLOSE + '</em>');
        // Italic _text_ (underscore variant — CommonMark word-boundary rules
        // mean `foo_bar_baz` stays literal; we mirror that by requiring a
        // non-word char before the opener and disallowing `__` flanking).
        html = html.replace(/(^|[^_\w])_([^_\n]+)_(?!_)/g,
            '$1<em>' + S_OPEN + '_' + S_CLOSE + '$2' + S_OPEN + '_' + S_CLOSE + '</em>');
        // Strikethrough ~~text~~ (GFM)
        html = html.replace(/~~([^~\n]+)~~/g,
            '<del>' + S_OPEN + '~~' + S_CLOSE + '$1' + S_OPEN + '~~' + S_CLOSE + '</del>');
        // Image ![alt](url) — render BEFORE link so the `[alt](url)` tail
        // isn't gobbled by the link regex. The <img> carries zero text
        // content so caret offsets stay aligned with the source string.
        // The dimmed source markdown lives inside `.md-img-source` so CSS
        // can collapse it to an SR-only sliver when the block is not the
        // active edit target — that hides the duplicate URL noise while
        // still keeping the source characters in textContent (so caret
        // offsets remain valid when the block regains focus and the source
        // text becomes visible again).
        html = html.replace(/!\[([^\]\n]*)\]\(([^)\s]+)\)/g, function (_, alt, u) {
            var url = safeImageUrl(u);
            var srcMarker = '<span class="md-img-source">'
                + S_OPEN + '![' + S_CLOSE + alt + S_OPEN + '](' + u + ')' + S_CLOSE
                + '</span>';
            if (url === null) return srcMarker;
            var safeUrl = url.replace(/"/g, '&quot;');
            var safeAlt = alt.replace(/"/g, '&quot;');
            // Wrap the <img> so a sibling `::after` can carry the theme-color
            // multiply overlay (LazyBlog's signature duotone wash). The
            // wrapper is contenteditable=false so the caret never lands
            // inside it; the wrapper itself produces no textContent so
            // caret offsets stay aligned with the source markdown.
            return srcMarker
                + '<span class="wb-img-wrap" contenteditable="false">'
                + '<img class="wb-img" src="' + safeUrl + '" alt="' + safeAlt + '" '
                + 'loading="lazy">'
                + '</span>';
        });
        // Link [text](url) — markers ([, ], (url)) are wrapped in
        // `.md-link-source` so CSS can SR-only-hide them when the block
        // is not the active edit target. Same trick as image source:
        // textContent stays length-equal to the markdown so caret offset
        // math still holds when the writer clicks back in to edit.
        html = html.replace(/\[([^\]\n]+)\]\(([^)\s]+)\)/g, function (_, t, u) {
            var safe = u.replace(/"/g, '&quot;');
            return '<a href="' + safe + '" tabindex="-1" rel="noopener">'
                + '<span class="md-link-source">' + S_OPEN + '[' + S_CLOSE + '</span>'
                + t
                + '<span class="md-link-source">' + S_OPEN + '](' + u + ')' + S_CLOSE + '</span>'
                + '</a>';
        });
        // Swap the triple-marker placeholders back to their real chars so
        // textContent equals the source markdown (caret offset invariant).
        if (html.indexOf(STAR_PH) >= 0) html = html.split(STAR_PH).join('*');
        if (html.indexOf(US_PH) >= 0) html = html.split(US_PH).join('_');
        return html;
    }

    function syntaxSpan(s) {
        return '<span class="md-syntax">' + esc(s) + '</span>';
    }

    // List/task markers stay visible even when the block is inactive — a
    // bullet without its `- ` looks like prose with random indentation
    // and the writer loses the structural cue. Distinct class so the
    // SR-only `md-syntax` collapse rule skips it.
    function listMarkerSpan(s) {
        return '<span class="md-list-marker">' + esc(s) + '</span>';
    }

    function renderBlock(block) {
        var md = block.textContent || '';
        block.setAttribute('data-md', md);
        if (md === '') {
            block.innerHTML = '<br>';
            block.removeAttribute('data-kind');
            return;
        }

        var headingMatch = md.match(/^(#{1,4}) /);
        if (headingMatch) {
            var lvl = headingMatch[1].length;
            var prefix = headingMatch[0]; // includes the trailing space
            var rest = md.substring(prefix.length);
            block.innerHTML = '<h' + lvl + '>'
                + syntaxSpan(prefix) + renderInline(rest)
                + '</h' + lvl + '>';
            block.dataset.kind = 'h' + lvl;
            return;
        }
        if (md.indexOf('> ') === 0 || md === '>') {
            // Multi-line quote: every line must start with `> ` (or be a
            // bare `>` for blank quote lines). Renders as ONE blockquote
            // with `\n` text nodes between lines — CSS `white-space:
            // pre-wrap` on the blockquote turns those into visual line
            // breaks while textContent stays equal to the source markdown
            // (caret offset invariant).
            var qLines = md.split('\n');
            var allQuote = true;
            for (var qi = 0; qi < qLines.length; qi++) {
                var ql = qLines[qi];
                if (ql !== '>' && ql.indexOf('> ') !== 0) {
                    allQuote = false;
                    break;
                }
            }
            if (allQuote) {
                var qHtml = '';
                for (var qj = 0; qj < qLines.length; qj++) {
                    var line = qLines[qj];
                    if (qj > 0) qHtml += '\n';
                    if (line === '>') {
                        qHtml += syntaxSpan('>');
                    } else {
                        qHtml += syntaxSpan('> ') + renderInline(line.substring(2));
                    }
                }
                block.innerHTML = '<blockquote>' + qHtml + '</blockquote>';
                block.dataset.kind = 'blockquote';
                return;
            }
        }
        if (md.indexOf('```') === 0) {
            // Multi-line code block: keep everything literal.
            block.innerHTML = '<pre><code>' + esc(md) + '</code></pre>';
            block.dataset.kind = 'pre';
            return;
        }
        if (md === '---' || md === '***' || md === '___') {
            block.innerHTML = '<hr>';
            block.dataset.kind = 'hr';
            return;
        }
        var liMatch = md.match(/^(\s*)([-*]) (.*)$/);
        if (liMatch) {
            var rest = liMatch[3];
            // Task-list extension: `- [ ] item` / `- [x] item`. Keep every
            // source character in textContent (`[`, ` ` or `x`, `]`) so
            // caret offsets stay aligned; just style the trio as a
            // checkbox via `.wb-task` + `[data-checked]`.
            var taskM = rest.match(/^\[( |x|X)\] (.*)$/);
            var liInner;
            if (taskM) {
                var checked = taskM[1] !== ' ';
                liInner = '<span class="wb-task" data-checked="' + (checked ? '1' : '0') + '">'
                    + '<span class="md-syntax">[</span>'
                    + '<span class="wb-task-mark">' + taskM[1] + '</span>'
                    + '<span class="md-syntax">]</span>'
                    + '</span>'
                    + ' '
                    + renderInline(taskM[2]);
                block.dataset.kind = 'task';
            } else {
                liInner = renderInline(rest);
                block.dataset.kind = 'li';
            }
            block.innerHTML = '<p class="wb-li">'
                + esc(liMatch[1])
                + listMarkerSpan(liMatch[2] + ' ')
                + liInner
                + '</p>';
            return;
        }
        var olMatch = md.match(/^(\s*)(\d+\. )(.*)$/);
        if (olMatch) {
            block.innerHTML = '<p class="wb-li">'
                + esc(olMatch[1])
                + listMarkerSpan(olMatch[2])
                + renderInline(olMatch[3])
                + '</p>';
            block.dataset.kind = 'li';
            return;
        }

        block.innerHTML = renderInline(md);
        block.dataset.kind = 'p';
    }

    function renderCurrentBlock() {
        var block = getCurrentBlock();
        if (!block) return;
        var offset = getCaretOffsetInBlock(block);
        renderBlock(block);
        setCaretOffsetInBlock(block, offset);
    }

    /* ---------------- Block normalization ---------------- */

    function makeBlock(md) {
        var b = document.createElement('div');
        b.className = 'wb';
        if (md === '') {
            b.innerHTML = '<br>';
            b.setAttribute('data-md', '');
        } else {
            b.textContent = md;
            renderBlock(b);
        }
        return b;
    }

    function normalizeBlocks() {
        // Wrap any orphan text/elements (from paste, browser-inserted divs)
        // into .wb wrappers so the document stays well-formed.
        var children = Array.prototype.slice.call(editor.childNodes);
        for (var i = 0; i < children.length; i++) {
            var node = children[i];
            if (node.nodeType === 1 && node.classList && node.classList.contains('wb')) continue;
            if (node.nodeType === 3) {
                if ((node.nodeValue || '').replace(/\s/g, '') === '') {
                    editor.removeChild(node);
                    continue;
                }
                var w = makeBlock(node.nodeValue || '');
                editor.replaceChild(w, node);
            } else if (node.nodeType === 1) {
                if (node.tagName === 'BR') {
                    editor.removeChild(node);
                    continue;
                }
                var text = node.textContent || '';
                var w2 = makeBlock(text);
                editor.replaceChild(w2, node);
            }
        }
        // Ensure at least one block exists.
        if (editor.children.length === 0) {
            editor.appendChild(makeBlock(''));
        }
    }

    function getAllBlocks() {
        return Array.prototype.slice.call(editor.querySelectorAll(':scope > .wb'));
    }

    var IMAGE_LINE_RE = /^\s*!\[[^\]]*\]\([^)\s]+\)\s*$/;

    function fullMarkdown() {
        var blocks = getAllBlocks();
        var parts = [];
        var prevImage = false;
        for (var i = 0; i < blocks.length; i++) {
            // Re-sync data-md from current textContent when the block is
            // the active edit target (textContent is fresh, data-md may lag).
            var md = blocks[i].textContent || '';
            var isImg = IMAGE_LINE_RE.test(md);
            if (i > 0) {
                // Two consecutive image-only blocks join with a single
                // newline so MarkdownRenderer::preprocessStandaloneImages
                // groups them into a `post-figure-gallery count-N` row.
                // Anything else gets the normal blank-line paragraph
                // separator. Matches the MD editor's tight-markdown
                // gallery convention (adjacent image lines = gallery).
                parts.push(prevImage && isImg ? '\n' : '\n\n');
            }
            parts.push(md);
            prevImage = isImg;
        }
        return parts.join('');
    }

    // Detect a line that should stand alone as a Zen block: a list item
    // (- / * / digit+dot), a heading (#…####), or a standalone image.
    // Tight markdown sources separate these by a single `\n` rather
    // than `\n\n`; without this rule three `- bullet` lines from
    // EasyMDE would collapse into one Zen block, and the multi-image
    // gallery format (`![](u1)\n![](u2)`) would load as a single
    // broken block instead of two image blocks.
    function isStandaloneLine(s) {
        return /^(\s*)([-*]|\d+\.) /.test(s)
            || /^#{1,4} /.test(s)
            || IMAGE_LINE_RE.test(s);
    }

    function splitParagraphIntoBlocks(para) {
        var lines = para.split('\n');
        if (lines.length === 1) return [para];
        var out = [];
        var buf = '';
        // Fenced code blocks (```…``` or ~~~…~~~) become their own Zen
        // block, even when the surrounding markdown isn't separated by
        // a blank line. Two reasons:
        //   1. Lines inside the fence (shell `# comment`, indented code)
        //      must NOT be lifted out by `isStandaloneLine`.
        //   2. Tight markdown with no blank line between text and fence
        //      would otherwise glue the text and the entire fence into
        //      one paragraph block, which the renderer can't dress as
        //      both prose and code at once.
        var inFence = false;
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            var fenceToggle = /^\s*(```|~~~)/.test(line);
            if (fenceToggle) {
                if (!inFence) {
                    // Opening fence: flush prose buffer, start code buffer.
                    if (buf !== '') { out.push(buf); buf = ''; }
                    buf = line;
                    inFence = true;
                } else {
                    // Closing fence: emit the complete code block.
                    buf = buf + '\n' + line;
                    out.push(buf);
                    buf = '';
                    inFence = false;
                }
                continue;
            }
            if (inFence) {
                buf = buf === '' ? line : (buf + '\n' + line);
                continue;
            }
            if (isStandaloneLine(line)) {
                if (buf !== '') { out.push(buf); buf = ''; }
                out.push(line);
            } else {
                buf = buf === '' ? line : (buf + '\n' + line);
            }
        }
        if (buf !== '') out.push(buf);
        return out;
    }

    // Fence-aware paragraph split — blank lines INSIDE a fenced code
    // block don't break the paragraph, so a code sample with empty lines
    // between statements stays in one Zen block instead of shattering
    // into prose paragraphs (which would parse the `# comment` lines as
    // headings on the next load).
    function splitParagraphsFenceAware(md) {
        // Normalize Windows/legacy line endings — without this a CRLF
        // file leaves a stray `\r` on every line, so `line === ''`
        // never matches and the whole body collapses into one paragraph.
        var lines = (md || '').replace(/\r\n?/g, '\n').split('\n');
        var paragraphs = [];
        var buf = [];
        var inFence = false;
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            var isFence = /^\s*(```|~~~)/.test(line);
            if (isFence) {
                inFence = !inFence;
                buf.push(line);
                continue;
            }
            if (inFence) {
                buf.push(line);
                continue;
            }
            if (line === '') {
                if (buf.length > 0) {
                    paragraphs.push(buf.join('\n'));
                    buf = [];
                }
                // Consecutive blank lines collapse to one paragraph break.
                continue;
            }
            buf.push(line);
        }
        if (buf.length > 0) paragraphs.push(buf.join('\n'));
        return paragraphs.length > 0 ? paragraphs : [''];
    }

    function loadMarkdown(md) {
        suspendInput = true;
        editor.innerHTML = '';
        var paragraphs = splitParagraphsFenceAware(md);
        var blocks = [];
        for (var p = 0; p < paragraphs.length; p++) {
            var pieces = splitParagraphIntoBlocks(paragraphs[p]);
            for (var k = 0; k < pieces.length; k++) blocks.push(pieces[k]);
        }
        for (var i = 0; i < blocks.length; i++) {
            editor.appendChild(makeBlock(blocks[i]));
        }
        updateEmptyState();
        suspendInput = false;
    }

    function updateEmptyState() {
        var blocks = getAllBlocks();
        var empty = blocks.length === 0
            || (blocks.length === 1 && (blocks[0].textContent || '').trim() === '');
        editor.setAttribute('data-empty', empty ? 'true' : 'false');
    }

    /* ---------------- Typewriter focus + scroll ---------------- */

    function updateFocus() {
        var blocks = getAllBlocks();
        var current = getCurrentBlock();
        for (var i = 0; i < blocks.length; i++) {
            if (blocks[i] === current) blocks[i].classList.add('is-current');
            else blocks[i].classList.remove('is-current');
        }
    }

    function centerCaret() {
        if (scrollRAF) cancelAnimationFrame(scrollRAF);
        scrollRAF = requestAnimationFrame(function () {
            scrollRAF = null;
            var sel = window.getSelection();
            if (!sel || sel.rangeCount === 0) return;
            var range = sel.getRangeAt(0);
            // Only operate on a selection that actually lives inside the
            // editor — otherwise rect math reads from somewhere unrelated
            // (e.g. the title input inside the modal) and the stage
            // scrolls for no good reason.
            if (!editor.contains(range.startContainer)) return;
            var rect = range.getBoundingClientRect();
            // Collapsed selections at the start of an empty block report
            // a zero rect on Webkit/Firefox; fall back to the block's
            // bounding box so we still have a Y to centre on.
            if (rect.width === 0 && rect.height === 0) {
                var block = getCurrentBlock();
                if (!block) return;
                rect = block.getBoundingClientRect();
            }
            var stageRect = stage.getBoundingClientRect();
            var caretY = rect.top + (rect.height || 0) / 2;
            var targetY = stageRect.top + stageRect.height / 2;
            var delta = caretY - targetY;
            // Sub-pixel deltas churn scrollTop without visible movement —
            // skip them so we don't fight the user's own scroll wheel.
            if (Math.abs(delta) < 1) return;
            stage.scrollTop += delta;
        });
    }

    /* ---------------- Autosave ---------------- */

    // Snapshot the caret as `{blockIndex, offset}`. Persisting alongside
    // the draft text lets us land the cursor right back at the last edit
    // point after a reload — the writer doesn't have to scroll and find
    // where they were.
    function snapshotCaret() {
        var block = getCurrentBlock();
        if (!block) return null;
        var blocks = getAllBlocks();
        var idx = blocks.indexOf(block);
        if (idx < 0) return null;
        return { i: idx, o: getCaretOffsetInBlock(block) };
    }

    // Synchronously commit the current document to localStorage. Used by
    // the debounced autosave timer AND by the Ctrl/Cmd-S shortcut so the
    // draft is on disk before the title-capture modal interrupts the
    // writer's flow (and before any potential network call later).
    function flushAutosave() {
        if (saveTimer) {
            clearTimeout(saveTimer);
            saveTimer = null;
        }
        try {
            localStorage.setItem(draftKey(), fullMarkdown());
            var caret = snapshotCaret();
            if (caret) {
                localStorage.setItem(caretKey(), JSON.stringify(caret));
            }
            setStatus('saved', 'Saved');
        } catch (e) {
            setStatus('error', 'Save failed');
        }
    }

    function scheduleAutosave() {
        if (saveTimer) clearTimeout(saveTimer);
        setStatus('saving', 'Saving...');
        saveTimer = setTimeout(flushAutosave, 700);
    }

    /* ---------------- Input handler ---------------- */

    function onInput(e) {
        if (suspendInput) return;
        if (isComposing) return;
        isDirty = true;
        if (renderTimer) clearTimeout(renderTimer);
        renderTimer = setTimeout(function () {
            renderTimer = null;
            if (isComposing) return;
            normalizeBlocks();
            renderCurrentBlock();
            autoSpaceListMarker();
            maybeAutoCapitalize();
            updateFocus();
            updateEmptyState();
            centerCaret();
            rebuildToc();
            updateStats();
            scheduleAutosave();
        }, 90);
    }

    function onCompositionStart() {
        isComposing = true;
        if (renderTimer) {
            clearTimeout(renderTimer);
            renderTimer = null;
        }
    }

    function onCompositionEnd() {
        isComposing = false;
        // Single render pass after the IME commits — keeps the block in sync
        // without ever touching innerHTML mid-composition.
        if (renderTimer) clearTimeout(renderTimer);
        renderTimer = setTimeout(function () {
            renderTimer = null;
            normalizeBlocks();
            renderCurrentBlock();
            autoSpaceListMarker();
            maybeAutoCapitalize();
            updateFocus();
            updateEmptyState();
            centerCaret();
            scheduleAutosave();
        }, 30);
    }

    /* ---------------- Key handler ---------------- */

    // Extract a list-marker prefix from the start of `md` so we can either
    // continue the list (Enter on a non-empty item) or break out of it
    // (Enter on an empty item).
    //
    // Returns `{ prefix, body }` for `- `, `* `, `1. `, and task variants
    // `- [ ] ` / `- [x] `. `null` when not a list block.
    function detectListMarker(md) {
        var m = md.match(/^(\s*)([-*]) /);
        if (m) {
            var rest = md.substring(m[0].length);
            var taskM = rest.match(/^\[( |x|X)\] /);
            if (taskM) {
                // Continuation always uses an empty checkbox so the next
                // item isn't pre-ticked.
                return {
                    prefix: m[1] + m[2] + ' [ ] ',
                    body: rest.substring(taskM[0].length),
                };
            }
            return { prefix: m[1] + m[2] + ' ', body: rest };
        }
        m = md.match(/^(\s*)(\d+)\. /);
        if (m) {
            var nextNum = (parseInt(m[2], 10) || 0) + 1;
            return {
                prefix: m[1] + nextNum + '. ',
                body: md.substring(m[0].length),
            };
        }
        return null;
    }

    function insertNewBlockAfterCaret() {
        var block = getCurrentBlock();
        if (!block) return;
        var offset = getCaretOffsetInBlock(block);
        var text = block.textContent || '';
        var before = text.substring(0, offset);
        var after = text.substring(offset);

        // List-aware Enter: when the current block IS a list item, decide
        // whether to continue the list or break out.
        var marker = detectListMarker(text);
        if (marker) {
            var bodyTrimmed = marker.body.trim();
            if (bodyTrimmed === '') {
                // Empty list item — second Enter breaks out: convert this
                // block to an empty paragraph and drop a fresh empty
                // block below it for the writer to keep typing in.
                block.textContent = '';
                block.setAttribute('data-md', '');
                renderBlock(block);
                var nbEmpty = makeBlock('');
                block.insertAdjacentElement('afterend', nbEmpty);
                setCaretOffsetInBlock(nbEmpty, 0);
                pendingCapitalize = true;
                updateFocus();
                centerCaret();
                scheduleAutosave();
                return;
            }
            // Continue the list — split the current line at caret, then
            // prefix the new block with the same marker so the writer
            // doesn't retype `- ` / `1. ` / `- [ ] ` every line.
            block.textContent = before;
            block.setAttribute('data-md', before);
            renderBlock(block);
            var contText = marker.prefix + after;
            var nbCont = makeBlock(contText);
            block.insertAdjacentElement('afterend', nbCont);
            setCaretOffsetInBlock(nbCont, marker.prefix.length);
            pendingCapitalize = true;
            updateFocus();
            centerCaret();
            scheduleAutosave();
            return;
        }

        // Plain block split.
        block.textContent = before;
        block.setAttribute('data-md', before);
        renderBlock(block);
        var nb = makeBlock(after);
        block.insertAdjacentElement('afterend', nb);
        setCaretOffsetInBlock(nb, 0);
        // Prime the auto-cap for the first letter typed into the new block.
        pendingCapitalize = true;
        updateFocus();
        centerCaret();
        scheduleAutosave();
    }

    // Enter on a closing ``` fence — exit the code block by parking the
    // caret in a fresh paragraph below. Returns true when handled.
    //
    // Triggers only when:
    //   * the caret line is exactly ``` (just three backticks)
    //   * that line is NOT the opening fence (i.e. there's text before it)
    //
    // Anything trailing the closing fence (the writer split mid-block)
    // becomes the body of the new paragraph so no characters are lost.
    function handleCodeExit(block) {
        if (!block) return false;
        var text = block.textContent || '';
        var offset = getCaretOffsetInBlock(block);
        var before = text.substring(0, offset);
        var after = text.substring(offset);
        var lastNlBefore = before.lastIndexOf('\n');
        if (lastNlBefore === -1) return false; // caret on opening fence line
        var firstNlAfter = after.indexOf('\n');
        var lineStartIdx = lastNlBefore + 1;
        var endOfLine = (firstNlAfter === -1)
            ? text.length
            : offset + firstNlAfter;
        var currentLine = text.substring(lineStartIdx, endOfLine);
        if (currentLine !== '```') return false;
        var keep = text.substring(0, endOfLine);
        var trailing = text.substring(endOfLine);
        if (trailing.indexOf('\n') === 0) trailing = trailing.substring(1);
        block.textContent = keep;
        block.setAttribute('data-md', keep);
        renderBlock(block);
        var nbExitCode = makeBlock(trailing);
        block.insertAdjacentElement('afterend', nbExitCode);
        setCaretOffsetInBlock(nbExitCode, 0);
        pendingCapitalize = true;
        updateFocus();
        centerCaret();
        scheduleAutosave();
        return true;
    }

    // Enter inside a blockquote — insert `\n> ` so the quote stays as one
    // multi-line block. Returns true when the keystroke was handled.
    //
    // Two flows:
    //   1. Caret line is just `> ` (empty body) → exit. Drops the empty
    //      trailing marker and inserts a fresh paragraph block below.
    //   2. Otherwise → insert `\n> ` at the caret, re-render, advance the
    //      caret past the new marker so the writer keeps typing the
    //      continuation body.
    function handleQuoteEnter(block) {
        if (!block) return false;
        var text = block.textContent || '';
        // Treat as a quote when the block is already rendered as one OR
        // the raw text starts with `> ` (covers the freshly-typed case
        // before the render debounce fires).
        if (block.dataset.kind !== 'blockquote' && text.indexOf('> ') !== 0) {
            return false;
        }
        var offset = getCaretOffsetInBlock(block);
        var before = text.substring(0, offset);
        var after = text.substring(offset);
        // Find the line that contains the caret. Exit when it's an empty
        // marker line (`> ` with no body before the caret on this line).
        var lastNl = before.lastIndexOf('\n');
        var currentLine = before.substring(lastNl + 1);
        if (currentLine === '> ' && after === '') {
            // Drop the empty trailing `> ` marker, fall back to either a
            // shorter quote block (when there was prior content) or a
            // fresh paragraph below this block.
            var trimmed = before.substring(0, lastNl);
            if (lastNl >= 0 && trimmed !== '') {
                block.textContent = trimmed;
                block.setAttribute('data-md', trimmed);
                renderBlock(block);
                var nbExit = makeBlock('');
                block.insertAdjacentElement('afterend', nbExit);
                setCaretOffsetInBlock(nbExit, 0);
            } else {
                // Whole block was just `> ` — convert to empty paragraph.
                block.textContent = '';
                block.setAttribute('data-md', '');
                renderBlock(block);
                setCaretOffsetInBlock(block, 0);
            }
            pendingCapitalize = true;
            updateFocus();
            centerCaret();
            scheduleAutosave();
            return true;
        }
        // Continue the quote: `\n> ` at caret. Stays inside the same block
        // so the renderer keeps everything inside one <blockquote>. No
        // auto-cap here — quote continuation is mid-thought by default
        // (matches the code-block newline behavior, which also skips it).
        var insertion = '\n> ';
        var newText = before + insertion + after;
        block.textContent = newText;
        block.setAttribute('data-md', newText);
        renderBlock(block);
        setCaretOffsetInBlock(block, before.length + insertion.length);
        updateFocus();
        centerCaret();
        scheduleAutosave();
        return true;
    }

    // Walk up from any node until we land on a `.wb` wrapper or the editor.
    function blockOf(node) {
        while (node && node !== editor) {
            if (node.nodeType === 1 && node.classList && node.classList.contains('wb')) {
                return node;
            }
            node = node.parentNode;
        }
        return null;
    }

    // Character offset from the start of `block` to the given DOM position.
    // Different from `getCaretOffsetInBlock` because it doesn't read the
    // current selection — caller supplies the (container, offset) pair.
    function offsetWithinBlock(block, container, contOffset) {
        var r = document.createRange();
        r.selectNodeContents(block);
        try {
            r.setEnd(container, contOffset);
        } catch (e) {
            // Container may not be a descendant of block in odd edge cases
            // (e.g. selection across editor and outside). Treat as end.
            return (block.textContent || '').length;
        }
        return r.toString().length;
    }

    // Delete the current non-collapsed selection ourselves. The native
    // contenteditable behavior merges adjacent blocks in unpredictable
    // ways (it commonly pulls the surviving tail of the END block up
    // into the START block, dropping classes/data-md and leaving an
    // orphan element); doing it by hand keeps the wrapper/render pipeline
    // intact. Returns true if a deletion happened.
    function deleteSelection() {
        var sel = window.getSelection();
        if (!sel || sel.rangeCount === 0 || sel.isCollapsed) return false;
        var range = sel.getRangeAt(0);

        var startBlock = blockOf(range.startContainer);
        var endBlock = blockOf(range.endContainer);
        if (!startBlock || !endBlock) return false;

        var startOffset = offsetWithinBlock(startBlock, range.startContainer, range.startOffset);
        var endOffset = offsetWithinBlock(endBlock, range.endContainer, range.endOffset);

        var startText = startBlock.textContent || '';
        var endText = endBlock.textContent || '';
        var before = startText.substring(0, startOffset);
        var after = (startBlock === endBlock)
            ? startText.substring(endOffset)
            : endText.substring(endOffset);

        // Remove blocks strictly between start and end, then end itself.
        if (startBlock !== endBlock) {
            var cursor = startBlock.nextElementSibling;
            while (cursor && cursor !== endBlock) {
                var nxt = cursor.nextElementSibling;
                cursor.remove();
                cursor = nxt;
            }
            if (endBlock.parentNode) endBlock.remove();
        }

        var merged = before + after;
        startBlock.textContent = merged;
        startBlock.setAttribute('data-md', merged);
        renderBlock(startBlock);

        // Make sure at least one block remains (full-select + delete case).
        if (editor.children.length === 0) {
            editor.appendChild(makeBlock(''));
        }

        // Caret at the deletion boundary.
        var landing = startBlock.parentNode ? startBlock : editor.children[0];
        setCaretOffsetInBlock(landing, before.length);
        updateFocus();
        updateEmptyState();
        centerCaret();
        scheduleAutosave();
        return true;
    }

    // Insert a raw text node at the caret. Used instead of
    // `document.execCommand('insertText', false, '\n')` because every
    // major browser silently converts that `\n` into a `<br>` when the
    // contenteditable is in default block mode — which means our renderer
    // (which reads `textContent`) never sees the line break, the block
    // re-renders identically, and the writer's only feedback is the
    // status pill ticking through "Saving / Saved" without anything
    // visibly happening. A direct text node insertion stays as a literal
    // newline character in textContent so `<pre>` (white-space:pre-wrap)
    // shows the line break and our line-break-aware render picks it up.
    function insertTextAtCaret(text) {
        var sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) return false;
        var r = sel.getRangeAt(0);
        r.deleteContents();
        var node = document.createTextNode(text);
        r.insertNode(node);
        r.setStartAfter(node);
        r.setEndAfter(node);
        sel.removeAllRanges();
        sel.addRange(r);
        return true;
    }

    // Tiny QoL auto-format: when the current block starts with `-X`
    // (no space after the marker) we insert the missing space so the
    // list renderer kicks in. Standard CommonMark requires `- text`, but
    // users routinely skip the space and expect a bullet anyway. Caret
    // offset shifts +1 only when the caret sits after the insertion point.
    //
    // `*` is intentionally excluded: `*word*` is italic, and auto-spacing
    // would clobber emphasis the moment the user typed `*w`.
    function autoSpaceListMarker() {
        var block = getCurrentBlock();
        if (!block) return;
        if (block.dataset.kind === 'pre') return;
        var text = block.textContent || '';
        var m = text.match(/^(\s*)(-)(\S.*)$/);
        if (!m) return;
        // Skip when the next char would form `--` (en-dash start) — not
        // a list intent.
        if (m[3][0] === m[2]) return;
        var insertAt = m[1].length + 1;
        var offset = getCaretOffsetInBlock(block);
        var newText = m[1] + m[2] + ' ' + m[3];
        block.textContent = newText;
        block.setAttribute('data-md', newText);
        renderBlock(block);
        if (offset > insertAt) offset += 1;
        setCaretOffsetInBlock(block, offset);
    }

    // Uppercase the first non-whitespace letter of the current block when
    // a recent action (init or Enter-new-block) primed the auto-cap. Skips
    // code blocks (case-sensitive content), empty blocks, and non-letter
    // leads. Caret offset is preserved because the replacement keeps the
    // same character length.
    function maybeAutoCapitalize() {
        if (!pendingCapitalize) return;
        var block = getCurrentBlock();
        if (!block) return;
        if (block.dataset.kind === 'pre') {
            pendingCapitalize = false;
            return;
        }
        var md = block.textContent || '';
        if (md.length === 0) return;
        var m = md.match(/^(\s*)(\S)/u);
        if (!m) {
            pendingCapitalize = false;
            return;
        }
        var first = m[2];
        var upper = first.toUpperCase();
        if (upper === first) {
            // Already uppercase, a digit, punctuation, or markdown marker
            // like `#`/`>` — leave it alone but stop watching this block.
            pendingCapitalize = false;
            return;
        }
        var offset = getCaretOffsetInBlock(block);
        var newMd = m[1] + upper + md.substring(m[1].length + first.length);
        block.textContent = newMd;
        block.setAttribute('data-md', newMd);
        renderBlock(block);
        setCaretOffsetInBlock(block, offset);
        pendingCapitalize = false;
    }

    function mergeWithPreviousBlock() {
        var block = getCurrentBlock();
        if (!block) return false;
        var prev = block.previousElementSibling;
        if (!prev || !prev.classList.contains('wb')) return false;
        var prevText = prev.textContent || '';
        var curText = block.textContent || '';
        var merged = prevText + curText;
        prev.textContent = merged;
        prev.setAttribute('data-md', merged);
        renderBlock(prev);
        block.remove();
        setCaretOffsetInBlock(prev, prevText.length);
        updateFocus();
        centerCaret();
        scheduleAutosave();
        return true;
    }

    function onKeyDown(e) {
        var meta = e.ctrlKey || e.metaKey;

        // Shortcuts must still work even mid-compose so the writer can save
        // a half-typed Vietnamese word with Ctrl+S; IME composition itself
        // doesn't use modifier+letter combos.
        if (meta && e.key.toLowerCase() === 's') {
            e.preventDefault();
            // Commit the latest document to localStorage BEFORE the modal
            // steals focus — guarantees a recoverable draft even if the
            // operator dismisses the title prompt or closes the tab.
            flushAutosave();
            // Live post → silent in-place save, stay in writer. New post
            // or draft → open the title modal.
            var existing = window.LB_WRITER_EXISTING;
            var isLivePost = existing && existing.draft === false;
            if (isLivePost) {
                saveLiveInline();
            } else {
                openModal('draft');
            }
            return;
        }
        if (meta && (e.key === 'Enter' || e.key === '\n')) {
            e.preventDefault();
            flushAutosave();
            var exE = window.LB_WRITER_EXISTING;
            if (exE && exE.draft === false) {
                saveLiveInline();
            } else {
                openModal('publish');
            }
            return;
        }

        // Cmd/Ctrl + ] toggles the outline panel. Bracket key avoids the
        // browser-reserved Cmd+. (Safari "Stop loading") and mirrors the
        // IDE convention for sidebar / inspector toggles.
        if (meta && (e.key === ']' || e.key === '}')) {
            e.preventDefault();
            toggleToc();
            return;
        }

        // Markdown formatting shortcuts. They wrap the selection (or
        // drop the markers at the caret with a placeholder when nothing
        // is selected) and survive the per-block render.
        if (meta && !e.shiftKey && !e.altKey) {
            var k = e.key.toLowerCase();
            if (k === 'b') { e.preventDefault(); wrapSelection('**', '**'); return; }
            if (k === 'i') { e.preventDefault(); wrapSelection('*',  '*');  return; }
            if (k === 'k') { e.preventDefault(); openLinkPrompt(); return; }
        }

        // During IME composition, all other keys belong to the IME. Browsers
        // also report `e.isComposing === true` for these keystrokes — both
        // signals are checked because some keyboards fire compositionend
        // before the final keydown.
        if (isComposing || e.isComposing || e.keyCode === 229) return;

        if (e.key === 'Tab' && !e.shiftKey) {
            e.preventDefault();
            // Plain two-space indent — markdown-friendly.
            try { document.execCommand('insertText', false, '  '); } catch (err) {}
            return;
        }

        // Printable key with a live multi-block selection: clear the
        // selection ourselves first, then let the typed char insert into
        // the surviving block. Without this the browser merges blocks
        // before our renderer can catch up.
        var isPrintable = e.key && e.key.length === 1 && !meta && !e.altKey;
        if (isPrintable) {
            var s = window.getSelection();
            if (s && s.rangeCount > 0 && !s.isCollapsed) {
                var startB = blockOf(s.getRangeAt(0).startContainer);
                var endB = blockOf(s.getRangeAt(0).endContainer);
                if (startB && endB && startB !== endB) {
                    e.preventDefault();
                    deleteSelection();
                    // Insert the typed character at the new caret position.
                    try { document.execCommand('insertText', false, e.key); } catch (err) {}
                    return;
                }
            }
        }

        if (e.key === 'Enter') {
            var curBlock = getCurrentBlock();
            // Inside a code block — Enter (with or without Shift) inserts a
            // real newline so the writer can build multi-line snippets.
            // Detection looks at both data-kind (set by renderer) and the
            // raw text prefix so a freshly-typed ``` line works before the
            // 90ms render debounce fires.
            var inCodeBlock = curBlock
                && (curBlock.dataset.kind === 'pre'
                    || (curBlock.textContent || '').indexOf('```') === 0);
            if (inCodeBlock || e.shiftKey) {
                e.preventDefault();
                // Closing fence + Enter (no Shift) exits the code block —
                // moves the caret into a fresh paragraph below so the
                // writer can keep going outside the fence. Shift+Enter
                // stays as a raw newline insertion (escape hatch).
                if (inCodeBlock && !e.shiftKey && handleCodeExit(curBlock)) {
                    return;
                }
                if (!insertTextAtCaret('\n')) return;
                if (renderTimer) { clearTimeout(renderTimer); renderTimer = null; }
                renderCurrentBlock();
                centerCaret();
                scheduleAutosave();
                return;
            }
            // Inside a blockquote — Enter inserts `\n> ` so the quote
            // continues as ONE multi-line block (matches the code-block
            // behavior above). Exit when the line immediately before the
            // caret is just `> ` with no body — drop that empty marker and
            // break out into a fresh paragraph below.
            if (handleQuoteEnter(curBlock)) {
                e.preventDefault();
                return;
            }
            e.preventDefault();
            insertNewBlockAfterCaret();
            return;
        }

        if (e.key === 'Backspace' || e.key === 'Delete') {
            var selRaw = window.getSelection();
            // Multi-char or multi-block selection: handle the deletion
            // ourselves. Browser-default merging in nested .wb blocks
            // pulls the END block's content up into the START block
            // (caret look) instead of just removing the selected range.
            if (selRaw && selRaw.rangeCount > 0 && !selRaw.isCollapsed) {
                e.preventDefault();
                deleteSelection();
                return;
            }
            if (e.key !== 'Backspace') return;
            var block = getCurrentBlock();
            if (!block) return;
            var offset = getCaretOffsetInBlock(block);
            if (offset === 0) {
                var merged = mergeWithPreviousBlock();
                if (merged) e.preventDefault();
            }
            return;
        }
    }

    /* ---------------- Selection change ---------------- */

    function onSelectionChange() {
        // Check via the live selection instead of document.activeElement —
        // contenteditable focus shifts to inner text nodes after every
        // render pass and activeElement can read as `body` even though the
        // caret is still anchored inside the editor. Reading the selection
        // anchor is the reliable signal that the writer is editing.
        var sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) return;
        if (!editor.contains(sel.getRangeAt(0).startContainer)) return;
        // During IME composition the caret rect is mid-flight; centering
        // mid-compose causes the viewport to jitter on every Telex keypress.
        if (isComposing) return;
        updateFocus();
        centerCaret();
    }

    /* ---------------- Paste — keep plain markdown ---------------- */

    function onPaste(e) {
        var cd = e.clipboardData;
        if (!cd) return;

        // Image paste — clipboard can carry one image (the common case)
        // or several (some apps + a phone screenshot tray can dump N at
        // once). Collect every image item, upload them in parallel via
        // `/admin/upload`, and insert the resulting `![](url)` blocks in
        // paste-order at the caret. Failed uploads drop out silently;
        // status pill reports aggregate progress so a single fail in a
        // batch of 3 doesn't look like total failure.
        if (cd.items && cd.items.length > 0) {
            var imageFiles = [];
            for (var ii = 0; ii < cd.items.length; ii++) {
                var item = cd.items[ii];
                if (item.kind === 'file' && item.type && item.type.indexOf('image/') === 0) {
                    var f = item.getAsFile();
                    if (f) imageFiles.push(f);
                }
            }
            if (imageFiles.length > 0) {
                e.preventDefault();
                uploadPastedImages(imageFiles).then(function (urls) {
                    var ok = [];
                    for (var k = 0; k < urls.length; k++) {
                        if (urls[k]) ok.push(urls[k]);
                    }
                    if (ok.length > 0) insertImageMarkdowns(ok);
                });
                return;
            }
        }

        var text = cd.getData('text/plain');
        if (text === '') return;
        e.preventDefault();

        // If pasted text contains paragraph breaks, split into blocks.
        var sel = window.getSelection();
        if (!sel || !sel.rangeCount) return;
        var range = sel.getRangeAt(0);

        var block = getCurrentBlock();
        if (!block) return;
        var offset = getCaretOffsetInBlock(block);
        var current = block.textContent || '';
        var before = current.substring(0, offset);
        var after = current.substring(offset);

        var paragraphs = text.split(/\n{2,}/);
        if (paragraphs.length === 1) {
            // Single-paragraph paste — insert inline.
            var combined = before + paragraphs[0] + after;
            block.textContent = combined;
            block.setAttribute('data-md', combined);
            renderBlock(block);
            setCaretOffsetInBlock(block, (before + paragraphs[0]).length);
        } else {
            // First paragraph merges into current block (before half).
            var firstText = before + paragraphs[0];
            block.textContent = firstText;
            block.setAttribute('data-md', firstText);
            renderBlock(block);
            var anchor = block;
            for (var i = 1; i < paragraphs.length - 1; i++) {
                var mid = makeBlock(paragraphs[i]);
                anchor.insertAdjacentElement('afterend', mid);
                anchor = mid;
            }
            // Last paragraph + after half.
            var lastBlock = makeBlock(paragraphs[paragraphs.length - 1] + after);
            anchor.insertAdjacentElement('afterend', lastBlock);
            setCaretOffsetInBlock(lastBlock, paragraphs[paragraphs.length - 1].length);
        }
        updateFocus();
        updateEmptyState();
        centerCaret();
        scheduleAutosave();
    }

    /* ---------------- Modal ---------------- */

    // Pull a default title from the first non-empty block of the document.
    // Aggressively strips every markdown decoration (heading hashes, list
    // bullets, task brackets, emphasis, links, images, inline code,
    // strikethrough, blockquote, leftover bracket/paren punctuation) so
    // the input lands with plain readable prose only. Caps at one
    // sentence / 120 chars so a long opener doesn't dump a wall of text.
    function deriveTitleFromBody() {
        var blocks = getAllBlocks();
        for (var i = 0; i < blocks.length; i++) {
            var md = (blocks[i].textContent || '').trim();
            if (!md) continue;
            // Block-level leading markers.
            md = md.replace(/^#{1,6}\s+/, '');
            md = md.replace(/^>\s+/, '');
            md = md.replace(/^(\s*)([-*]|\d+\.)\s+/, '');
            md = md.replace(/^\[( |x|X)\]\s*/, '');
            // Inline emphasis / code / strikethrough — keep label, drop wrappers.
            md = md.replace(/!\[([^\]]*)\]\([^)]+\)/g, '$1');  // images → alt
            md = md.replace(/\[([^\]]+)\]\([^)]+\)/g, '$1');   // links → label
            md = md.replace(/\*\*([^*]+)\*\*/g, '$1');         // bold
            md = md.replace(/\*([^*]+)\*/g, '$1');             // italic
            md = md.replace(/__([^_]+)__/g, '$1');             // bold underscore
            md = md.replace(/_([^_]+)_/g, '$1');               // italic underscore
            md = md.replace(/~~([^~]+)~~/g, '$1');             // strikethrough
            md = md.replace(/`([^`]+)`/g, '$1');               // inline code
            // Any leftover stray punctuation that only existed to mark
            // syntax (orphan brackets / parens / braces / backslashes).
            md = md.replace(/[\[\]\(\)\{\}`~_*\\]/g, '');
            // Collapse whitespace.
            md = md.replace(/\s+/g, ' ').trim();
            // First sentence — stop at . ! ? newline, capped at 120 chars.
            var m = md.match(/^[^.!?\n]{1,120}[.!?]?/);
            var t = m ? m[0] : md.substring(0, 120);
            return t.trim();
        }
        return '';
    }

    function setModalPhase(phase, mode) {
        modalPhase = phase;
        var isPublish = mode === 'publish';
        var existing = window.LB_WRITER_EXISTING || null;

        if (phase === 'input') {
            phaseInput.hidden = false;
            phaseConfirm.hidden = true;
            if (isPublish) {
                modalLabel.textContent = isLiveUpdate ? 'SAVE' : 'SUBMIT';
                // No confirmation for a live-update — single-press save.
                modalConfirm.textContent = isLiveUpdate ? '[ SAVE ]' : '[ NEXT ]';
            } else {
                modalLabel.textContent = 'DRAFT';
                modalConfirm.textContent = '[ SAVE DRAFT ]';
            }
            modalCancel.textContent = '[ CANCEL ]';
            setTimeout(function () {
                modalTitleInput.focus();
                modalTitleInput.select();
            }, 30);
            return;
        }

        // Confirm phase — only ever reached for new/draft publishes.
        phaseInput.hidden = true;
        phaseConfirm.hidden = false;
        modalLabel.textContent = 'CONFIRM PUBLISH';
        var titleVal = modalTitleInput.value.trim();
        confirmTitleEcho.textContent = titleVal;
        var slug = (existing && existing.slug) ? existing.slug : slugifyForPreview(titleVal);
        confirmUrlEcho.textContent = '/posts/' + slug;
        modalConfirm.textContent = '[ YES, PUBLISH ]';
        modalCancel.textContent = '[ BACK ]';
        setTimeout(function () { modalConfirm.focus(); }, 30);
    }

    // Cheap slug preview for the confirm screen — server has the canonical
    // SlugUtil; we mirror the kebab transform just enough for display.
    function slugifyForPreview(s) {
        return (s || '')
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .replace(/đ/g, 'd').replace(/Đ/g, 'D')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, 80);
    }

    function openModal(mode) {
        var existing = window.LB_WRITER_EXISTING || null;
        // Live-update detection: only when an existing post is loaded AND
        // it's already published (draft === false). New posts + drafts go
        // through the publish/confirm flow.
        isLiveUpdate = mode === 'publish'
            && existing !== null
            && existing.draft === false;

        modalMode.value = mode;
        modalError.hidden = true;
        modalError.textContent = '';
        // Only prefill an empty input so we never clobber a title the
        // writer already started typing during the same session.
        if (!modalTitleInput.value) {
            modalTitleInput.value = deriveTitleFromBody();
        }

        modal.hidden = false;
        // For publish on an existing post that already has a title saved,
        // skip the input phase and ask for confirmation directly — the
        // metadata is already on disk, nothing to retype.
        var hasTitle = modalTitleInput.value.trim() !== '';
        var goStraightToConfirm = mode === 'publish' && !isLiveUpdate && hasTitle && existing !== null;
        setModalPhase(goStraightToConfirm ? 'confirm' : 'input', mode);
    }

    function closeModal() {
        modal.hidden = true;
        modalConfirm.disabled = false;
        // Always wipe the password field on close — keeping it across modal
        // opens would leak a sensitive value into a context where the
        // writer may not realise the previous draft still has it queued.
        // Title/summary persist intentionally (they help retry flows).
        var pw = document.getElementById('writer-password-input');
        if (pw) pw.value = '';
        editor.focus();
    }

    function onModalSubmit(e) {
        e.preventDefault();
        var title = modalTitleInput.value.trim();
        if (!title) {
            modalError.hidden = false;
            modalError.textContent = 'Title is required.';
            setModalPhase('input', modalMode.value);
            modalTitleInput.focus();
            return;
        }

        // Publish (new/draft): show confirm screen between title entry and
        // the actual POST. Live-update + draft-save skip this step.
        if (modalMode.value === 'publish' && !isLiveUpdate && modalPhase === 'input') {
            setModalPhase('confirm', modalMode.value);
            return;
        }

        modalBody.value = fullMarkdown();
        sendForm();
    }

    // Cancel button doubles as "back to input" while we're on the confirm
    // screen — keeps the user's typed title/summary instead of dismissing
    // the whole modal.
    function onModalCancel(e) {
        if (modalPhase === 'confirm') {
            e.preventDefault();
            e.stopPropagation();
            setModalPhase('input', modalMode.value);
            return;
        }
        // input phase: real cancel — close modal.
        closeModal();
    }

    function sendForm() {
        setStatus('saving', modalMode.value === 'publish' ? 'Publishing...' : 'Saving draft...');
        modalConfirm.disabled = true;
        var formData = new FormData(modalForm);
        fetch('/writer/save', { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function (res) {
                return res.json().then(function (data) { return { ok: res.ok, data: data }; });
            })
            .then(function (out) {
                if (!out.ok || !out.data || !out.data.ok) {
                    modalError.hidden = false;
                    modalError.textContent = (out.data && out.data.error) || 'Save failed.';
                    setStatus('error', 'Save failed');
                    modalConfirm.disabled = false;
                    return;
                }
                try {
                    localStorage.removeItem(draftKey());
                    localStorage.removeItem(caretKey());
                } catch (e) {}
                isDirty = false;
                setStatus('saved', modalMode.value === 'publish' ? 'Published' : 'Draft saved');
                window.location.href = out.data.redirect;
            })
            .catch(function () {
                modalError.hidden = false;
                modalError.textContent = 'Network error.';
                setStatus('error', 'Save failed');
                modalConfirm.disabled = false;
            });
    }

    /* ---------------- Restore + boot ---------------- */

    function tryRestore() {
        // Edit mode: an existing post was opened via /writer?slug=foo.
        // The PHP layer hydrated `window.LB_WRITER_EXISTING` with the
        // canonical body from disk — always prefer that over any stray
        // localStorage draft so the writer never edits stale content.
        var existing = window.LB_WRITER_EXISTING || null;
        if (existing && typeof existing.body === 'string') {
            loadMarkdown(existing.body);
            // Seed the modal so the writer doesn't have to retype title.
            if (existing.title) modalTitleInput.value = existing.title;
            if (existing.summary) modalSummaryInput.value = existing.summary;
            setStatus('saved', 'Editing: ' + existing.slug);
            return null;
        }

        var saved = null;
        var savedCaret = null;
        try {
            saved = localStorage.getItem(draftKey());
            var caretJson = localStorage.getItem(caretKey());
            if (caretJson) savedCaret = JSON.parse(caretJson);
        } catch (e) {}
        if (saved && saved.trim() !== '') {
            loadMarkdown(saved);
            setStatus('saved', 'Draft restored');
            return savedCaret;
        }
        loadMarkdown('');
        setStatus('idle', 'Ready');
        return null;
    }

    // ---------- Word count + reading time ----------

    function updateStats() {
        if (!statsWordsEl || !statsTimeEl) return;
        // Pull plain text from every block, strip markdown syntax markers
        // (#, **, `, [], etc.) so the count reflects actual prose words.
        var raw = fullMarkdown();
        var plain = raw
            .replace(/```[\s\S]*?```/g, ' ')  // code blocks
            .replace(/`[^`]*`/g, ' ')         // inline code
            .replace(/!\[[^\]]*\]\([^)]+\)/g, ' ') // images
            .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1') // links → label
            .replace(/^#{1,6}\s+/gm, '')      // heading markers
            .replace(/^>\s+/gm, '')           // blockquote
            .replace(/^(\s*)([-*]|\d+\.)\s+/gm, '$1') // list markers
            .replace(/\[( |x|X)\]\s*/g, '')   // task checkboxes
            .replace(/[*_~`]/g, '');          // emphasis markers
        var words = plain.split(/\s+/).filter(function (w) { return w.length > 0; });
        var n = words.length;
        // 220 wpm is the median English silent reading speed; rounded up
        // so a 50-word post still reads as "1 min" instead of zero.
        var mins = Math.max(1, Math.round(n / 220));
        statsWordsEl.textContent = n + (n === 1 ? ' word' : ' words');
        statsTimeEl.textContent = mins + ' min';
    }

    // ---------- Formatting shortcuts ----------

    // Wrap the current selection with matching markers; if nothing is
    // selected, drop just the markers and park the caret between them so
    // the writer can start typing inside the formatting immediately.
    // Used by Cmd/Ctrl + B / I.
    function wrapSelection(left, right) {
        var sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) return;
        var range = sel.getRangeAt(0);
        if (!editor.contains(range.startContainer)) return;
        var block = blockOf(range.startContainer);
        if (!block) return;
        if (block.dataset.kind === 'pre') return; // skip inside code blocks

        var startOff = offsetWithinBlock(block, range.startContainer, range.startOffset);
        var endOff   = offsetWithinBlock(block, range.endContainer,   range.endOffset);
        if (endOff < startOff) { var t = startOff; startOff = endOff; endOff = t; }

        var src = block.textContent || '';
        var selectedText = src.substring(startOff, endOff);
        var newText = src.substring(0, startOff)
            + left + selectedText + right
            + src.substring(endOff);

        block.textContent = newText;
        block.setAttribute('data-md', newText);
        renderBlock(block);

        var innerStart = startOff + left.length;
        var innerEnd = innerStart + selectedText.length;

        if (selectedText === '') {
            // No selection — caret sits between the markers, ready to type.
            setCaretOffsetInBlock(block, innerStart);
        } else {
            // Re-highlight the wrapped inner text so the writer can stack
            // formatting (e.g. Cmd+B then Cmd+I) without re-selecting.
            setCaretOffsetInBlock(block, innerEnd);
            var s2 = window.getSelection();
            if (s2.rangeCount) {
                var r2 = document.createRange();
                var walker = document.createTreeWalker(block, NodeFilter.SHOW_TEXT, null);
                var acc = 0, node, started = false;
                while ((node = walker.nextNode())) {
                    var len = node.nodeValue.length;
                    if (!started && acc + len >= innerStart) {
                        r2.setStart(node, innerStart - acc);
                        started = true;
                    }
                    if (started && acc + len >= innerEnd) {
                        r2.setEnd(node, innerEnd - acc);
                        break;
                    }
                    acc += len;
                }
                if (started) {
                    s2.removeAllRanges();
                    s2.addRange(r2);
                }
            }
        }
        isDirty = true;
        updateFocus();
        centerCaret();
        scheduleAutosave();
    }

    // Cmd/Ctrl + K — open the styled link modal. Captures (text, url),
    // saves the current selection range so the writer can dismiss without
    // losing their cursor position, and inserts `[text](url)` on confirm.
    var linkModal = document.getElementById('writer-link-modal');
    var linkForm = document.getElementById('writer-link-form');
    var linkTextInput = document.getElementById('writer-link-text');
    var linkUrlInput = document.getElementById('writer-link-url');
    var linkError = document.getElementById('writer-link-modal-error');
    var linkConfirm = document.getElementById('writer-link-modal-confirm');
    // Snapshot of the editor selection at the moment the modal opens, so
    // we can restore it before insertion (opening the modal moves focus
    // into the URL input and the selection in the editor is lost).
    var savedLinkRange = null;

    function openLinkPrompt() {
        var sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) return;
        var range = sel.getRangeAt(0);
        if (!editor.contains(range.startContainer)) return;
        var block = blockOf(range.startContainer);
        if (!block) return;
        if (block.dataset.kind === 'pre') return;

        var startOff = offsetWithinBlock(block, range.startContainer, range.startOffset);
        var endOff   = offsetWithinBlock(block, range.endContainer,   range.endOffset);
        if (endOff < startOff) { var t = startOff; startOff = endOff; endOff = t; }
        var src = block.textContent || '';
        var selectedText = src.substring(startOff, endOff);

        savedLinkRange = { block: block, start: startOff, end: endOff };

        linkTextInput.value = selectedText;
        linkUrlInput.value = '';
        linkError.hidden = true;
        linkError.textContent = '';
        linkModal.hidden = false;
        setTimeout(function () {
            // If selection already filled the text field, jump straight to URL.
            if (selectedText !== '') linkUrlInput.focus();
            else linkTextInput.focus();
        }, 30);
    }

    function closeLinkPrompt() {
        linkModal.hidden = true;
        editor.focus();
        // Restore the original selection so the writer's caret position
        // doesn't drift to the top of the document on dismiss.
        if (savedLinkRange) {
            setCaretOffsetInBlock(savedLinkRange.block, savedLinkRange.end);
        }
    }

    function onLinkSubmit(e) {
        e.preventDefault();
        var url = (linkUrlInput.value || '').trim();
        if (url === '' || url === 'https://') {
            linkError.hidden = false;
            linkError.textContent = 'URL is required.';
            linkUrlInput.focus();
            return;
        }
        var label = (linkTextInput.value || '').trim();
        if (label === '') label = url;
        if (!savedLinkRange) { closeLinkPrompt(); return; }

        var block = savedLinkRange.block;
        var startOff = savedLinkRange.start;
        var endOff = savedLinkRange.end;
        var src = block.textContent || '';
        var insertion = '[' + label + '](' + url + ')';
        var newText = src.substring(0, startOff) + insertion + src.substring(endOff);
        block.textContent = newText;
        block.setAttribute('data-md', newText);
        renderBlock(block);
        setCaretOffsetInBlock(block, startOff + insertion.length);
        savedLinkRange = null;
        linkModal.hidden = true;
        editor.focus();
        isDirty = true;
        updateFocus();
        centerCaret();
        scheduleAutosave();
    }

    // ---------- Image paste upload ----------

    // Bare upload — no status side-effects. Returns Promise<url|null>.
    // Single-image and batch-image flows both go through this so the
    // POST + JSON-parse + error-fallback logic lives in one place; the
    // status-pill bookkeeping happens in the callers that own the UX
    // contract (one image: "Uploading image..." / "Image uploaded";
    // N images: "Uploading N images..." / "Uploaded N images" /
    // "Uploaded X / N images" partial).
    function uploadOneFile(file) {
        var fd = new FormData();
        // UploadController reads `$_FILES['file']` + CSRF via the
        // `X-CSRF-Token` header (or `_csrf` field). Match both.
        fd.append('file', file, file.name || 'paste.png');
        fd.append('_csrf', csrfToken);
        return fetch('/admin/upload', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
            body: fd,
            credentials: 'same-origin',
        })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
            .then(function (out) {
                if (!out.ok || !out.data || !out.data.url) return null;
                return out.data.url;
            })
            .catch(function () { return null; });
    }

    function uploadPastedImage(file) {
        setStatus('saving', 'Uploading image...');
        return uploadOneFile(file).then(function (url) {
            setStatus(url ? 'saved' : 'error', url ? 'Image uploaded' : 'Upload failed');
            return url;
        });
    }

    // Batch — parallel uploads via Promise.all. Resolves to an array
    // matching input order; failed slots are `null` so the caller can
    // skip them while preserving sequence for the successful ones.
    function uploadPastedImages(files) {
        if (files.length === 1) {
            return uploadPastedImage(files[0]).then(function (u) { return [u]; });
        }
        var n = files.length;
        setStatus('saving', 'Uploading ' + n + ' images...');
        return Promise.all(files.map(uploadOneFile)).then(function (results) {
            var ok = 0;
            for (var i = 0; i < results.length; i++) {
                if (results[i]) ok++;
            }
            if (ok === n) {
                setStatus('saved', 'Uploaded ' + n + ' images');
            } else if (ok > 0) {
                setStatus('error', 'Uploaded ' + ok + ' / ' + n + ' images');
            } else {
                setStatus('error', 'Upload failed');
            }
            return results;
        });
    }

    function insertImageMarkdown(url) {
        insertImageMarkdowns([url]);
    }

    // Insert N images at the caret in a single split: original block
    // keeps the "before caret" text, image blocks stack in input order,
    // and one trailing block holds the "after caret" text (caret lands
    // there). Calling insertImageMarkdown(url) N times in a row would
    // re-split at each insert and litter the document with empty
    // blocks between every image — this batch path avoids that.
    function insertImageMarkdowns(urls) {
        if (!urls || urls.length === 0) return;
        var block = getCurrentBlock();
        if (!block) return;
        var offset = getCaretOffsetInBlock(block);
        var text = block.textContent || '';
        var before = text.substring(0, offset);
        var after = text.substring(offset);

        block.textContent = before;
        block.setAttribute('data-md', before);
        renderBlock(block);

        var anchor = block;
        for (var i = 0; i < urls.length; i++) {
            var imgBlock = makeBlock('![](' + urls[i] + ')');
            anchor.insertAdjacentElement('afterend', imgBlock);
            anchor = imgBlock;
        }

        var nextBlock = makeBlock(after);
        anchor.insertAdjacentElement('afterend', nextBlock);
        setCaretOffsetInBlock(nextBlock, 0);

        isDirty = true;
        updateFocus();
        updateEmptyState();
        centerCaret();
        scheduleAutosave();
    }

    // ---------- Outline / TOC ----------

    function tocVisible() {
        return tocPanel && !tocPanel.hasAttribute('hidden');
    }

    function rebuildToc() {
        if (!tocPanel || !tocList) return;
        if (!tocVisible()) return; // only build when shown
        tocList.innerHTML = '';
        var blocks = getAllBlocks();
        var current = getCurrentBlock();
        var items = [];
        for (var i = 0; i < blocks.length; i++) {
            var b = blocks[i];
            var kind = b.dataset.kind || '';
            if (!/^h[1-4]$/.test(kind)) continue;
            var lvl = kind.charAt(1);
            // Strip the leading `#`/`##` marker for the label so the panel
            // reads cleanly even though the source keeps the markers.
            var raw = (b.textContent || '').replace(/^#{1,4}\s+/, '').trim();
            if (raw === '') continue;
            items.push({ block: b, level: lvl, text: raw });
        }
        if (items.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'writer-toc-empty';
            empty.textContent = '// no headings yet';
            tocList.appendChild(empty);
            return;
        }
        for (var j = 0; j < items.length; j++) {
            var it = items[j];
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'writer-toc-item';
            btn.dataset.level = it.level;
            btn.textContent = it.text;
            if (it.block === current) btn.classList.add('is-current');
            btn.addEventListener('click', (function (block) {
                return function () {
                    // Jump caret to the end of the heading block, then let
                    // centerCaret() pull it into view.
                    var endOff = (block.textContent || '').length;
                    setCaretOffsetInBlock(block, endOff);
                    editor.focus();
                    updateFocus();
                    centerCaret();
                };
            })(it.block));
            tocList.appendChild(btn);
        }
    }

    function openToc() {
        if (!tocPanel) return;
        tocPanel.hidden = false;
        if (tocToggle) tocToggle.classList.add('is-active');
        rebuildToc();
    }

    function closeToc() {
        if (!tocPanel) return;
        tocPanel.hidden = true;
        if (tocToggle) tocToggle.classList.remove('is-active');
        editor.focus();
    }

    function toggleToc() {
        tocVisible() ? closeToc() : openToc();
    }

    function applySurface(mode) {
        var isLight = mode === 'light';
        document.body.classList.toggle('is-light', isLight);
        if (bgToggle) {
            bgToggle.textContent = isLight ? '[ dark ]' : '[ light ]';
        }
    }

    function toggleSurface() {
        var isLight = !document.body.classList.contains('is-light');
        var mode = isLight ? 'light' : 'dark';
        applySurface(mode);
        try { localStorage.setItem(LS_SURFACE, mode); } catch (e) {}
    }

    function restoreSurface() {
        var mode = 'dark';
        try {
            var saved = localStorage.getItem(LS_SURFACE);
            if (saved === 'light' || saved === 'dark') mode = saved;
        } catch (e) {}
        applySurface(mode);
    }

    function refreshActionLabels() {
        var existing = window.LB_WRITER_EXISTING || null;
        if (existing && existing.draft === false) {
            // Already published — primary action is "save updates", not "publish".
            submitBtn.textContent = '[ SAVE ]';
            submitBtn.title = 'Save updates (Ctrl/Cmd + S)';
        }
    }

    // Silent in-place save for a post that's already live. No title prompt,
    // no redirect — the writer keeps editing right where they were. Uses
    // the title + summary already on disk; only body/draft state get pushed.
    function saveLiveInline() {
        var existing = window.LB_WRITER_EXISTING || null;
        if (!existing || existing.draft !== false) return false;
        var existingSlug = document.getElementById('writer-existing-slug').value;
        var existingFilename = document.getElementById('writer-existing-filename').value;

        var fd = new FormData();
        fd.append('_csrf', csrfToken);
        fd.append('mode', 'publish');
        fd.append('title', existing.title || '');
        fd.append('summary', existing.summary || '');
        fd.append('body', fullMarkdown());
        fd.append('existing_slug', existingSlug);
        fd.append('existing_filename', existingFilename);

        setStatus('saving', 'Saving...');
        fetch('/writer/save', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
        })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
            .then(function (out) {
                if (!out.ok || !out.data || !out.data.ok) {
                    setStatus('error', (out.data && out.data.error) || 'Save failed');
                    return;
                }
                setStatus('saved', 'Saved');
                isDirty = false;
                // Clear any stale autosave for this slug — disk is now
                // the source of truth.
                try {
                    localStorage.removeItem(draftKey());
                    localStorage.removeItem(caretKey());
                } catch (e) {}
            })
            .catch(function () {
                setStatus('error', 'Save failed');
            });
        return true;
    }

    function init() {
        restoreSurface();
        tryRestore();
        refreshActionLabels();
        editor.addEventListener('input', onInput);
        editor.addEventListener('keydown', onKeyDown);
        editor.addEventListener('paste', onPaste);
        editor.addEventListener('compositionstart', onCompositionStart);
        editor.addEventListener('compositionend', onCompositionEnd);
        document.addEventListener('selectionchange', onSelectionChange);
        if (bgToggle) bgToggle.addEventListener('click', toggleSurface);
        if (tocToggle) tocToggle.addEventListener('click', toggleToc);

        // Link prompt modal — dismiss + submit handlers.
        if (linkForm) linkForm.addEventListener('submit', onLinkSubmit);
        var linkDismissEls = document.querySelectorAll('[data-link-modal-dismiss]');
        for (var di = 0; di < linkDismissEls.length; di++) {
            linkDismissEls[di].addEventListener('click', closeLinkPrompt);
        }

        // Exit confirm — only when there are unsaved changes since the last
        // disk write. Draft IS auto-stashed in localStorage so the writer
        // can pick up later, but a one-shot prompt avoids the "I clicked
        // exit by mistake" feeling. Uses the styled modal (matches the
        // rest of the writer surface) instead of the native browser
        // confirm dialog so the theme accent + CRT aesthetic carry through.
        var exitLink = document.querySelector('.writer-exit');
        var exitModal = document.getElementById('writer-exit-modal');
        var exitConfirmBtn = document.getElementById('writer-exit-confirm');
        function closeExitModal() {
            if (exitModal) exitModal.hidden = true;
        }
        function openExitModal(targetHref) {
            if (!exitModal) {
                window.location.href = targetHref;
                return;
            }
            exitModal.hidden = false;
            if (exitConfirmBtn) {
                exitConfirmBtn.onclick = function () {
                    closeExitModal();
                    window.location.href = targetHref;
                };
                // Focus the confirm button so Enter accepts and Escape (handled
                // below) cancels — same keyboard semantics as native confirm.
                exitConfirmBtn.focus();
            }
        }
        if (exitLink) {
            exitLink.addEventListener('click', function (e) {
                if (!isDirty) return;
                e.preventDefault();
                openExitModal(exitLink.getAttribute('href') || '/');
            });
        }
        if (exitModal) {
            var exitDismissEls = exitModal.querySelectorAll('[data-exit-modal-dismiss]');
            for (var ei = 0; ei < exitDismissEls.length; ei++) {
                exitDismissEls[ei].addEventListener('click', closeExitModal);
            }
        }

        // Catch tab close / window close / refresh / back button — same
        // intent as the exit-link prompt but for navigation paths Writer
        // can't intercept directly.
        window.addEventListener('beforeunload', function (e) {
            if (!isDirty) return;
            e.preventDefault();
            e.returnValue = '';
        });
        draftBtn.addEventListener('click', function () { openModal('draft'); });
        submitBtn.addEventListener('click', function () {
            // [ SAVE ] on a live post: silent in-place save with no modal.
            // [ SUBMIT ] on a new/draft post: open the publish flow.
            var ex = window.LB_WRITER_EXISTING;
            if (ex && ex.draft === false) {
                saveLiveInline();
            } else {
                openModal('publish');
            }
        });
        modalForm.addEventListener('submit', onModalSubmit);
        // Backdrop click + the explicit cancel button share dismiss intent
        // but the cancel button has phase-aware behavior (back vs close)
        // while a backdrop click always closes the modal entirely.
        var backdrop = modal.querySelector('.writer-modal-backdrop');
        if (backdrop) backdrop.addEventListener('click', closeModal);
        if (modalCancel) modalCancel.addEventListener('click', onModalCancel);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                if (!modal.hidden) {
                    e.preventDefault();
                    closeModal();
                    return;
                }
                if (linkModal && !linkModal.hidden) {
                    e.preventDefault();
                    closeLinkPrompt();
                    return;
                }
                if (exitModal && !exitModal.hidden) {
                    e.preventDefault();
                    closeExitModal();
                    return;
                }
                if (tocVisible()) {
                    e.preventDefault();
                    closeToc();
                    return;
                }
            }
        });

        editor.focus();
        var blocks = getAllBlocks();
        if (blocks.length) {
            var last = blocks[blocks.length - 1];
            setCaretOffsetInBlock(last, (last.textContent || '').length);
        }
        updateFocus();
        centerCaret();
        updateEmptyState();
        updateStats();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
