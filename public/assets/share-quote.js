/* =========================================================================
   LazyBlog — share-quote.js
   Bôi một đoạn trong .post-body → popup → modal canvas → tải PNG /
   copy deep-link. Render hoàn toàn client-side bằng Canvas 2D: không
   endpoint, không dependency, không ghi đĩa.

   Nạp defer, chỉ trên /posts/*. Tách khỏi post.js vì post.js đã 278 dòng
   và hai concern không liên quan nhau.
   ========================================================================= */
(function () {
    /* `data-quote-share` chỉ được views/post.php phát ra cho bài KHÔNG có
       password_hash. Bài protected không bao giờ mount module này — ảnh
       quote sẽ đưa nội dung gated ra ngoài tường mật khẩu vĩnh viễn. */
    var article = document.querySelector('.post-article[data-quote-share]');
    if (!article) return;

    /* Desktop-only: bôi text chính xác bằng ngón tay là trải nghiệm tệ,
       và modal canvas cần chiều ngang để preview có nghĩa. */
    if (!window.matchMedia('(pointer: fine)').matches) return;
    if (window.innerWidth < 900) return;

    /* ---------- Hằng ----------
       25: dưới ngưỡng này card ra trống trải, một cụm ba chữ không thành
       một câu trích. 300: trên ngưỡng này ở tỉ lệ 1:1 chữ phải hạ xuống cỡ
       không ai đọc nổi trên feed — cắt còn 300 + `…` tốt hơn là từ chối. */
    var MIN_LEN = 25;
    var MAX_LEN = 300;
    var DEBOUNCE_MS = 250;

    /* Vùng cấm bôi. Chặn selection BẮT ĐẦU hoặc KẾT THÚC trong đây; nhiễu
       nằm lọt giữa range do bước trích text gỡ, không phải bước này. */
    var BLOCKED = 'pre, code, table, figcaption, .footnotes, .sidenote';

    var lastQuote = null;    // { text, rect } — phase 3 đọc khi mở modal
    var popup = null;
    var showTimer = null;

    /* ---------- Trích text ----------
       KHÔNG dùng `sel.toString()`. MarkdownRenderer chèn <span class="sidenote">
       inline ngay sau mỗi tham chiếu [^N], nằm GIỮA đoạn văn (chỉ được CSS
       đẩy ra lề phải trên màn rộng). `toString()` trên một đoạn có footnote
       nuốt trọn nội dung note vào giữa câu trích. Lọc theo hai đầu range
       không cứu được vì nhiễu không nằm ở hai đầu.

       Clone range vào một phần tử rời, gỡ node nhiễu, rồi đọc textContent.
       `cloneContents()` trả DocumentFragment ngoài DOM nên remove() không
       đụng gì tới trang. `.sidenote-num` nằm trong `.sidenote` nên gỡ cha
       là đủ; `.footnote-ref` phải liệt kê riêng vì selector `.footnote`
       khớp trọn token, không khớp tiền tố. */
    function extractText(range) {
        var box = document.createElement('div');
        box.appendChild(range.cloneContents());
        box.querySelectorAll('.sidenote, .footnote-ref, .footnote-backref')
            .forEach(function (n) { n.remove(); });
        return (box.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function insideBody(node) {
        var el = node.nodeType === 1 ? node : node.parentElement;
        return !!(el && el.closest('.post-body') && !el.closest(BLOCKED));
    }

    /* Trả { text, rect } khi selection hợp lệ, null khi không.
       Chuỗi trả về là nguồn sự thật duy nhất: dùng để đo độ dài, vẽ canvas
       (phase 4) và dựng fragment #:~:text= (phase 5). Không tính lại. */
    function readSelection() {
        var sel = window.getSelection();
        if (!sel || sel.isCollapsed || sel.rangeCount === 0) return null;

        var range = sel.getRangeAt(0);
        /* Kiểm riêng hai đầu. `commonAncestorContainer` một mình lọt lưới:
           bôi từ giữa đoạn văn sang giữa code block cho ancestor là
           `.post-body`, hoàn toàn hợp lệ theo phép kiểm đó. */
        if (!insideBody(range.startContainer)) return null;
        if (!insideBody(range.endContainer)) return null;

        var text = extractText(range);
        if (text.length < MIN_LEN) return null;
        if (text.length > MAX_LEN) text = text.slice(0, MAX_LEN).trim() + '…';

        var rect = range.getBoundingClientRect();
        if (!rect || (rect.width === 0 && rect.height === 0)) return null;

        return { text: text, rect: rect };
    }

    /* ---------- Popup ----------
       Dựng lazy lần đầu cần, giống cách palette.js dựng overlay của nó. */
    function buildPopup() {
        popup = document.createElement('button');
        // header-btn = style nút dùng chung của site (giống nút [ FAX THIS ]);
        // sq-popup chỉ thêm phần định vị nổi trên trang.
        popup.className = 'header-btn sq-popup';
        popup.type = 'button';
        popup.hidden = true;
        popup.textContent = '[ SHARE QUOTE ]';
        /* Không dùng aria-live: popup là một hành động tuỳ chọn xuất hiện
           theo thao tác của người dùng, không phải thông tin cần thông báo
           chen ngang. Nhãn mô tả trên nút là đủ. */
        popup.setAttribute('aria-label', 'Share this quote as an image card');

        /* Không cho mousedown xoá selection trước khi click handler chạy —
           thiếu dòng này selection biến mất đúng lúc modal cần đọc nó. */
        popup.addEventListener('mousedown', function (e) { e.preventDefault(); });
        popup.addEventListener('click', openModal);

        document.body.appendChild(popup);
    }

    function showPopup(rect) {
        if (!popup) buildPopup();
        popup.hidden = false;   // bỏ hidden trước để đo được kích thước thật

        var pw = popup.offsetWidth;
        var ph = popup.offsetHeight;
        var margin = 8;

        /* `position: absolute` + toạ độ tài liệu: cuộn trang thì popup trôi
           theo đoạn text thay vì đứng yên giữa màn hình như `fixed`. */
        var top = rect.top + window.scrollY - ph - margin;
        if (rect.top - ph - margin < 0) {
            top = rect.bottom + window.scrollY + margin;   // sát đỉnh → lật xuống
        }

        var left = rect.left + window.scrollX + rect.width / 2;
        var half = pw / 2;
        var min = window.scrollX + margin + half;
        var max = window.scrollX + document.documentElement.clientWidth - margin - half;
        if (max > min) left = Math.min(Math.max(left, min), max);

        popup.style.top = Math.round(top) + 'px';
        popup.style.left = Math.round(left) + 'px';
    }

    function hidePopup() {
        if (popup) popup.hidden = true;
    }

    /* ---------- Listener ----------
       `mouseup` để HIỆN: bắn đúng một lần khi người dùng nhả chuột.
       `selectionchange` chỉ để ẨN: nó bắn liên tục suốt lúc kéo, dùng nó để
       hiện thì popup nhấp nháy và nhảy vị trí. */
    document.addEventListener('mouseup', function (e) {
        if (popup && popup.contains(e.target)) return;
        clearTimeout(showTimer);
        showTimer = setTimeout(function () {
            var q = readSelection();
            if (!q) { lastQuote = null; hidePopup(); return; }
            lastQuote = q;
            showPopup(q.rect);
        }, DEBOUNCE_MS);
    });

    document.addEventListener('selectionchange', function () {
        var sel = window.getSelection();
        if (!sel || sel.isCollapsed || sel.rangeCount === 0) {
            clearTimeout(showTimer);
            lastQuote = null;
            hidePopup();
        }
    });

    /* Ẩn khi cuộn thay vì tính lại vị trí mỗi frame — rẻ hơn nhiều và
       người dùng cuộn đi là đã bỏ ý định chia sẻ đoạn đó. */
    window.addEventListener('scroll', hidePopup, { passive: true });

    /* =====================================================================
       Modal
       ===================================================================== */

    /* Bề rộng nguồn cố định 1080 cho cả ba tỉ lệ: đủ nét cho IG/X mà PNG
       vẫn dưới ~1.5MB. Backing store nhân devicePixelRatio lúc vẽ. */
    var RATIOS = {
        '9:16': [1080, 1920],
        '4:5': [1080, 1350],
        '1:1': [1080, 1080]
    };

    var FOCUSABLE = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';

    var state = { bg: 'theme', ratio: '4:5', text: '', author: '', title: '', bgImage: null };

    var overlay = null, panel = null, canvas = null, ctx = null;
    var controls = null, imageSwatch = null, modalFocus = null;

    /* Ảnh nền chỉ dùng được khi same-origin: ảnh cross-origin làm taint
       canvas và `toBlob()` ném SecurityError, nút Download chết câm. Không
       có cách vá nếu server ngoài không gửi CORS header — ẩn swatch là
       hành vi trung thực duy nhất.

       Dùng `src`, KHÔNG `currentSrc`: MarkdownRenderer gắn loading="lazy"
       cho mọi ảnh trong body, nên ảnh nằm dưới màn hình có `currentSrc`
       rỗng và swatch bị ẩn oan trên chính những bài ảnh nằm sâu. `src`
       luôn trả URL tuyệt đối đã resolve, bất kể đã tải hay chưa; phase 4
       tự `new Image()` tải lại và trình duyệt dùng cache nếu có. Repo
       không dùng srcset ở đâu cả nên `currentSrc` không bù lại được gì. */
    function usableBgImage() {
        var img = document.querySelector('.post-body img');
        if (!img || !img.src) return null;
        try {
            if (new URL(img.src, location.href).origin !== location.origin) return null;
        } catch (e) {
            return null;
        }
        return img.src;
    }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text) n.textContent = text;
        return n;
    }

    function groupButton(cls, attr, value, label) {
        var b = el('button', cls, label);
        b.type = 'button';
        b.dataset[attr] = value;
        b.setAttribute('aria-pressed', 'false');
        return b;
    }

    function buildModal() {
        overlay = el('div', 'sq-overlay');
        overlay.hidden = true;

        panel = el('div', 'sq-panel');
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'true');
        panel.setAttribute('aria-label', 'Share quote');

        var head = el('div', 'sq-head');
        head.appendChild(el('span', 'sq-head-label', 'SHARE QUOTE'));
        var close = el('button', 'sq-close', '×');
        close.type = 'button';
        close.setAttribute('aria-label', 'Close');
        close.addEventListener('click', closeModal);
        head.appendChild(close);

        var wrap = el('div', 'sq-canvas-wrap');
        canvas = el('canvas', 'sq-canvas');
        ctx = canvas.getContext('2d');
        wrap.appendChild(canvas);

        controls = el('div', 'sq-controls');

        /* Không có nhãn chữ "BACKGROUND" / "RATIO": cả hàng phải nằm gọn
           trên một dòng, và hai chữ đó tốn nhiều bề ngang hơn chính các nút
           chúng mô tả. Ngữ nghĩa vẫn đủ cho screen reader qua
           `role="group"` + `aria-label`, và người dùng chuột có `title`. */
        var bgGroup = el('div', 'sq-group');
        bgGroup.setAttribute('role', 'group');
        bgGroup.setAttribute('aria-label', 'Background');
        var themeSwatch = groupButton('sq-swatch', 'bg', 'theme', '');
        themeSwatch.setAttribute('aria-label', 'Theme background');
        themeSwatch.title = 'Theme background';
        imageSwatch = groupButton('sq-swatch', 'bg', 'image', '');
        imageSwatch.setAttribute('aria-label', 'Post image background');
        imageSwatch.title = 'Post image background';
        bgGroup.appendChild(themeSwatch);
        bgGroup.appendChild(imageSwatch);

        var ratioGroup = el('div', 'sq-group');
        ratioGroup.setAttribute('role', 'group');
        ratioGroup.setAttribute('aria-label', 'Aspect ratio');
        Object.keys(RATIOS).forEach(function (r) {
            var b = groupButton('sq-ratio', 'ratio', r, r);
            b.title = 'Aspect ratio ' + r;
            ratioGroup.appendChild(b);
        });

        controls.appendChild(bgGroup);
        controls.appendChild(el('span', 'sq-divider'));
        controls.appendChild(ratioGroup);

        /* Event delegation trên cả hàng control thay vì 5 listener rời —
           thêm một tỉ lệ sau này chỉ là thêm một nút. */
        controls.addEventListener('click', function (e) {
            var b = e.target.closest('[data-bg], [data-ratio]');
            if (!b || !controls.contains(b)) return;
            if (b.dataset.bg) state.bg = b.dataset.bg;
            if (b.dataset.ratio) state.ratio = b.dataset.ratio;
            syncPressed();
            redraw();
        });

        var actions = el('div', 'sq-actions');
        var copyBtn = el('button', 'sq-copy', '[ COPY LINK ]');
        copyBtn.type = 'button';
        var dlBtn = el('button', 'sq-download', '[ ⬇ DOWNLOAD ]');
        dlBtn.type = 'button';
        dlBtn.title = 'Download PNG';
        actions.appendChild(copyBtn);
        actions.appendChild(dlBtn);

        /* Một hàng duy nhất: control bên trái, hành động dồn sang phải. Hai
           hàng riêng làm chân modal nặng và đẩy canvas lên cao. */
        var toolbar = el('div', 'sq-toolbar');
        toolbar.appendChild(controls);
        toolbar.appendChild(actions);

        panel.appendChild(head);
        panel.appendChild(wrap);
        panel.appendChild(toolbar);
        overlay.appendChild(panel);
        document.body.appendChild(overlay);

        overlay.addEventListener('mousedown', function (e) {
            if (e.target === overlay) closeModal();
        });
        panel.addEventListener('keydown', trapFocus);

        bindActions(copyBtn, dlBtn);   // phase 5
    }

    /* Trạng thái chọn phải đọc được bằng cả mắt lẫn screen reader — class
       riêng cho mắt, aria-pressed cho SR, không chỉ dựa vào màu. */
    function syncPressed() {
        controls.querySelectorAll('[data-bg]').forEach(function (b) {
            var on = b.dataset.bg === state.bg;
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
            b.classList.toggle('is-active', on);
        });
        controls.querySelectorAll('[data-ratio]').forEach(function (b) {
            var on = b.dataset.ratio === state.ratio;
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
            b.classList.toggle('is-active', on);
        });
    }

    /* palette.js không cần focus trap thật (nó chỉ có một input). Modal này
       có ~8 phần tử focus được nên Tab phải vòng lại trong panel, không
       thả người dùng ra trang phía sau. */
    function trapFocus(e) {
        if (e.key !== 'Tab') return;
        var nodes = Array.prototype.filter.call(
            panel.querySelectorAll(FOCUSABLE),
            function (n) { return !n.hidden && n.offsetParent !== null && !n.disabled; }
        );
        if (nodes.length === 0) return;
        var first = nodes[0];
        var last = nodes[nodes.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    }

    function openModal() {
        if (!lastQuote) return;
        if (!overlay) buildModal();

        modalFocus = document.activeElement;

        state.text = lastQuote.text;
        state.author = article.getAttribute('data-quote-author') || '';
        state.title = article.getAttribute('data-quote-title') || '';
        state.bgImage = usableBgImage();
        /* Reset mỗi lần mở: lần trước có thể đã chọn `image` trên một bài
           khác, bài này chưa chắc có ảnh dùng được. */
        state.bg = 'theme';

        imageSwatch.hidden = !state.bgImage;
        if (state.bgImage) {
            imageSwatch.style.backgroundImage = 'url("' + state.bgImage.replace(/"/g, '%22') + '")';
        } else {
            imageSwatch.style.backgroundImage = '';
        }

        syncPressed();
        hidePopup();
        overlay.hidden = false;
        panel.querySelector('.sq-close').focus();
        redraw();
    }

    function closeModal() {
        if (!overlay || overlay.hidden) return;
        overlay.hidden = true;
        if (modalFocus && typeof modalFocus.focus === 'function') modalFocus.focus();
    }

    /* Escape ở tầng document, tự kiểm overlay của mình đang mở — cùng hình
       dạng với handler của palette.js, hai cái độc lập nên không đụng nhau.
       Ctrl+K khi modal mở: palette (z 1200) mở đè lên modal (z 1150). */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay && !overlay.hidden) {
            e.preventDefault();
            closeModal();
        }
    });

    /* =====================================================================
       Canvas renderer
       ===================================================================== */

    var PAD = 88;                          // lề an toàn, toạ độ logic
    var LINE_H = 1.32;
    /* Thử từ lớn xuống, lấy cỡ đầu tiên vừa khung. Bậc trên cùng cao hơn hẳn
       chiều cao chữ "hợp lý" vì card rộng 1080: một quote hai dòng ở cỡ 64
       chỉ lấp được một phần tư chiều cao và phần còn lại thành khoảng trống
       chết. Để cỡ lớn thắng trước, quote ngắn tự phóng to lấp khung. */
    var SIZES = [112, 96, 82, 70, 60, 52, 44, 38, 32];
    var QUOTE_FONT = '"Play", system-ui, sans-serif';
    var META_FONT = '"Share Tech Mono", monospace';

    var fontsReady = null;
    var drawSeq = 0;

    /* `ctx.fillText` KHÔNG đợi webfont. Vẽ trước khi font sẵn sàng thì canvas
       âm thầm dùng font hệ thống — ảnh ra sai font mà không báo lỗi gì. Font
       đến từ Google CDN (layout.php) nên phải đợi tường minh.

       `document.fonts.load()` resolve cả khi font không tồn tại, nên
       `.catch()` ở đây là bảo hiểm cho lỗi mạng chứ không phải cho font
       thiếu — và mọi chuỗi font khi vẽ đều kèm fallback hệ thống. */
    function ensureFonts() {
        if (fontsReady) return fontsReady;
        if (!document.fonts || !document.fonts.load) {
            fontsReady = Promise.resolve();
            return fontsReady;
        }
        fontsReady = Promise.all([
            document.fonts.load('400 64px "Play"'),
            document.fonts.load('700 28px "Share Tech Mono"'),
            document.fonts.load('400 24px "Share Tech Mono"')
        ]).catch(function () { /* CDN chết — vẽ bằng fallback, đừng treo modal */ });
        return fontsReady;
    }

    /* Đọc MỖI LẦN vẽ, không cache: người đọc đổi theme bằng Ctrl+, thì lượt
       vẽ kế tiếp tự đúng màu. Cách này phủ cả 5+ theme mà không hardcode
       bảng màu nào. */
    function themeColors() {
        var cs = getComputedStyle(document.documentElement);
        return {
            bg: cs.getPropertyValue('--bg').trim() || '#100a04',
            primary: cs.getPropertyValue('--primary').trim() || '#ffb700',
            text: cs.getPropertyValue('--text').trim() || '#e8dcc8',
            dim: cs.getPropertyValue('--text-dim').trim() || '#7a6a55'
        };
    }

    /* Bẻ một token dài hơn cả dòng thành từng mảnh vừa khung. Cần thiết vì
       chia theo dấu cách không cứu được `STEVEN_SULLIVAN_OFFICE_WANG_COM`,
       một URL, hay một chuỗi hash — hạ cỡ chữ cũng không: token vẫn dài hơn
       khung ở mọi cỡ. Không bẻ thì chữ tràn thẳng ra ngoài canvas. */
    function breakLongWord(c, word, maxW) {
        var parts = [];
        var cur = '';
        for (var i = 0; i < word.length; i++) {
            var t = cur + word.charAt(i);
            if (cur && c.measureText(t).width > maxW) {
                parts.push(cur);
                cur = word.charAt(i);
            } else {
                cur = t;
            }
        }
        if (cur) parts.push(cur);
        return parts;
    }

    function wrap(c, text, maxW) {
        var words = text.split(' ');
        var lines = [];
        var line = '';
        for (var i = 0; i < words.length; i++) {
            var word = words[i];
            if (c.measureText(word).width > maxW) {
                if (line) { lines.push(line); line = ''; }
                var parts = breakLongWord(c, word, maxW);
                for (var j = 0; j < parts.length - 1; j++) lines.push(parts[j]);
                line = parts[parts.length - 1];
                continue;
            }
            var test = line ? line + ' ' + word : word;
            if (c.measureText(test).width > maxW && line) {
                lines.push(line);
                line = word;
            } else {
                line = test;
            }
        }
        if (line) lines.push(line);
        return lines;
    }

    /* Nhiều nhất 5 lượt measureText trên ≤300 ký tự — dưới 1ms, không cần
       tối ưu. Không cỡ nào vừa thì dùng cỡ nhỏ nhất: text đã bị cắt còn 300
       ký tự lúc đọc selection nên trường hợp này gần như không xảy ra. */
    function fitQuote(c, text, maxW, maxH) {
        var lines = [];
        var size = SIZES[SIZES.length - 1];
        for (var i = 0; i < SIZES.length; i++) {
            c.font = '400 ' + SIZES[i] + 'px ' + QUOTE_FONT;
            /* Wrap với khung hẹp hơn đúng bề rộng cặp “ ”: hai dấu đó được
               thêm vào dòng đầu và dòng cuối SAU khi wrap, nên nếu đo bằng
               khung đầy đủ thì chính hai dòng đó tràn lề. */
            var candidate = wrap(c, text, maxW - c.measureText('“”').width);
            if (candidate.length * SIZES[i] * LINE_H <= maxH) {
                return { size: SIZES[i], lines: candidate };
            }
            lines = candidate;
            size = SIZES[i];
        }
        return { size: size, lines: lines };
    }

    function ellipsize(c, text, maxW) {
        if (c.measureText(text).width <= maxW) return text;
        var t = text;
        while (t.length > 1 && c.measureText(t + '…').width > maxW) {
            t = t.slice(0, -1);
        }
        return t.trim() + '…';
    }

    /* Cover-fit: lấp kín khung, cắt phần tràn, giữ tỉ lệ gốc. */
    function coverDraw(c, img, w, h) {
        var iw = img.naturalWidth || img.width;
        var ih = img.naturalHeight || img.height;
        if (!iw || !ih) return;
        var scale = Math.max(w / iw, h / ih);
        var dw = iw * scale;
        var dh = ih * scale;
        c.drawImage(img, (w - dw) / 2, (h - dh) / 2, dw, dh);
    }

    /* Blur bằng `ctx.filter = 'blur(24px)'` trên canvas 1080×1350 vừa chậm
       vừa dính bug Safari <17 (blur canvas lớn cho kết quả sai hoặc bị bỏ
       qua). Blur nhẹ trên một canvas 64px rồi phóng to: nhanh hơn, né bug,
       và trông mượt hơn vì chính bước scale up đã làm nhoè. */
    function drawBg(c, img, w, h) {
        var SMALL = 64;
        var tmp = document.createElement('canvas');
        tmp.width = SMALL;
        tmp.height = Math.max(1, Math.round(SMALL * h / w));
        coverDraw(tmp.getContext('2d'), img, tmp.width, tmp.height);

        c.filter = 'blur(2px)';
        c.drawImage(tmp, 0, 0, w, h);
        /* BẮT BUỘC reset: quên dòng này thì mọi fillText sau đó đều nhoè. */
        c.filter = 'none';

        c.fillStyle = 'rgba(0, 0, 0, 0.58)';   // phủ tối cho chữ đọc được
        c.fillRect(0, 0, w, h);
    }

    /* Resolve null khi lỗi thay vì reject — ảnh 404 thì rơi về nền theme,
       không để canvas trắng. Không set `crossOrigin`: URL đã được lọc chỉ
       còn same-origin, và thêm thuộc tính đó vào ảnh same-origin có thể
       gây fail ngược nếu server không gửi CORS header. */
    function loadBg() {
        return new Promise(function (res) {
            var im = new Image();
            im.onload = function () { res(im); };
            im.onerror = function () { res(null); };
            im.src = state.bgImage;
        });
    }

    function paint(bgImg) {
        var dims = RATIOS[state.ratio];
        var w = dims[0];
        var h = dims[1];
        /* Trần dpr 2: trên nữa chỉ phình file PNG mà mắt không thấy khác. */
        var dpr = Math.min(window.devicePixelRatio || 1, 2);

        canvas.width = w * dpr;
        canvas.height = h * dpr;
        /* setTransform, KHÔNG scale: redraw() chạy nhiều lần và scale cộng dồn. */
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

        var colors = themeColors();
        /* Chữ phosphor trên ảnh tối đọc rất tệ — nền ảnh thì chuyển trắng. */
        var quoteColor = bgImg ? '#ffffff' : colors.text;
        var metaColor = bgImg ? 'rgba(255, 255, 255, 0.75)' : colors.dim;
        var nameColor = bgImg ? '#ffffff' : colors.primary;

        if (bgImg) {
            drawBg(ctx, bgImg, w, h);
        } else {
            ctx.fillStyle = colors.bg;
            ctx.fillRect(0, 0, w, h);
        }

        var maxW = w - PAD * 2;

        /* Khối metadata neo từ đáy lên, chiều cao cố định. Khối quote chiếm
           phần còn lại và được căn GIỮA theo chiều dọc trong phần đó — neo
           đỉnh thì mọi khoảng dư dồn hết xuống một chỗ và card mất cân đối
           ngay khi quote ngắn. */
        var AUTHOR_SIZE = 34;
        var TITLE_SIZE = 27;
        var DOMAIN_SIZE = 24;
        var metaH = AUTHOR_SIZE + 16 + TITLE_SIZE + 30 + 1 + 30 + DOMAIN_SIZE;

        var quoteAreaTop = PAD;
        var quoteAreaH = h - PAD * 2 - metaH - 48;
        var fit = fitQuote(ctx, state.text, maxW, quoteAreaH);
        var blockH = fit.lines.length * fit.size * LINE_H;
        var quoteTop = quoteAreaTop + Math.max(0, (quoteAreaH - blockH) / 2);

        /* Bao “ ” lúc vẽ, KHÔNG sửa state.text — chuỗi đó còn dùng để dựng
           fragment #:~:text=, thêm ngoặc vào sẽ làm nó không khớp trang. */
        ctx.font = '400 ' + fit.size + 'px ' + QUOTE_FONT;
        ctx.fillStyle = quoteColor;
        ctx.textBaseline = 'top';
        ctx.textAlign = 'left';
        var y = quoteTop;
        for (var i = 0; i < fit.lines.length; i++) {
            var line = fit.lines[i];
            if (i === 0) line = '“' + line;
            if (i === fit.lines.length - 1) line = line + '”';
            ctx.fillText(line, PAD, y);
            y += fit.size * LINE_H;
        }

        var baseY = h - PAD - metaH;

        ctx.font = '700 ' + AUTHOR_SIZE + 'px ' + META_FONT;
        ctx.fillStyle = nameColor;
        ctx.fillText((state.author || '').toUpperCase(), PAD, baseY);
        baseY += AUTHOR_SIZE + 16;

        ctx.font = '400 ' + TITLE_SIZE + 'px ' + META_FONT;
        ctx.fillStyle = metaColor;
        ctx.fillText(ellipsize(ctx, state.title, maxW), PAD, baseY);
        baseY += TITLE_SIZE + 30;

        ctx.fillStyle = metaColor;
        ctx.fillRect(PAD, baseY, maxW, 1);
        baseY += 1 + 30;

        ctx.font = '400 ' + DOMAIN_SIZE + 'px ' + META_FONT;
        ctx.fillStyle = metaColor;
        ctx.fillText(location.hostname.toUpperCase(), PAD, baseY);
        ctx.textAlign = 'right';
        ctx.fillText('§', w - PAD, baseY);
        ctx.textAlign = 'left';
    }

    /* redraw() là async (đợi font, có thể đợi ảnh). Bấm ratio ba lần thật
       nhanh thì ba lượt vẽ đua nhau và lượt cũ có thể ghi đè lượt mới. Token
       tăng dần loại lượt cũ — cùng pattern palette.js dùng cho fetch. */
    function redraw() {
        if (!ctx) return;
        var seq = ++drawSeq;
        ensureFonts().then(function () {
            if (seq !== drawSeq) return null;
            return state.bg === 'image' && state.bgImage ? loadBg() : null;
        }).then(function (bgImg) {
            if (seq !== drawSeq) return;
            paint(bgImg);
        });
    }

    /* =====================================================================
       Actions — download PNG, copy deep link
       ===================================================================== */

    /* Phản hồi ngay tại nút, cùng tinh thần với nút COPY của code block
       trong post.js — không alert(), không toast toàn cục. */
    function flash(btn, msg) {
        var old = btn.dataset.label || btn.textContent;
        btn.dataset.label = old;
        btn.textContent = msg;
        btn.disabled = true;
        setTimeout(function () {
            btn.textContent = old;
            btn.disabled = false;
        }, 1600);
    }

    /* Sao chép nguyên văn từ post.js:167 (nút copy của code block).
       `navigator.clipboard` cần secure context; trên HTTP thuần API vắng mặt
       hẳn nên `.catch()` không bao giờ chạy. Hai nút copy trên cùng một
       trang phải hành xử giống nhau — sửa hàm này thì sửa cả bản kia. */
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

    function bindActions(copyBtn, dlBtn) {
        dlBtn.addEventListener('click', function () {
            /* toBlob trả null khi canvas bị taint. Ảnh cross-origin đã bị
               lọc từ trước nên về lý thuyết không xảy ra — nhưng thất bại im
               lặng là kiểu lỗi tệ nhất, nên vẫn báo tại nút. */
            canvas.toBlob(function (blob) {
                if (!blob) { flash(dlBtn, '[ ✗ FAILED ]'); return; }
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'quote-' + (location.pathname.split('/').pop() || 'post') + '.png';
                a.click();
                /* Revoke ngay sau click() làm Firefox huỷ lượt tải giữa
                   chừng. Độ trễ này là cố ý — đừng "dọn dẹp" nó về 0. */
                setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
            }, 'image/png');
        });

        copyBtn.addEventListener('click', function () {
            /* Dùng state.text chưa bao ngoặc (paint() chỉ thêm “ ” lúc vẽ).
               Nếu text đã bị cắt thì đuôi mang `…`, ký tự đó không có trong
               DOM gốc nên fragment sẽ không khớp — cắt trước khi encode. */
            var frag = state.text.replace(/…$/, '');
            var url = location.origin + location.pathname
                + '#:~:text=' + encodeURIComponent(frag);
            var done = function () { flash(copyBtn, '[ ✓ COPIED ]'); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(done).catch(function () {
                    fallbackCopy(url, done);
                });
            } else {
                fallbackCopy(url, done);
            }
        });
    }
})();
