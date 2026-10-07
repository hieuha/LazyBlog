<?php

declare(strict_types=1);

namespace App\Controllers;

use App\ArtifactStore;
use App\Auth;
use App\Csrf;

/**
 * Upload + serve interactive HTML artifacts.
 *
 *   POST /admin/upload-artifact  — auth + CSRF, multipart `file`
 *   GET  /artifacts/{id}         — the artifact page itself
 *
 * Isolation model: every artifact response carries a `sandbox` CSP
 * directive WITHOUT `allow-same-origin`, so the document runs in an opaque
 * origin whether it is framed by a post or opened directly in a tab. Its
 * scripts can draw, animate and handle input, but cannot read the blog's
 * cookies or storage, reach the parent DOM, or make same-origin requests
 * with the admin session. The post-side iframe repeats the same sandbox
 * flags as a second layer.
 */
final class ArtifactController
{
    /**
     * Reports the document's content height to the embedding page so the
     * iframe can grow/shrink to fit (artifact-embed.js applies it). Measures
     * <body>, not <html>: the root element is never shorter than the frame,
     * so measuring it would let a frame grow but never shrink. No-op when
     * the artifact is opened top-level.
     */
    private const HEIGHT_REPORTER = <<<'HTML'
<script>
/* LazyBlog: report content height to the embedding post. */
(function () {
  if (window.parent === window) return;
  var last = -1;
  function send() {
    var b = document.body;
    if (!b) return;
    var cs = getComputedStyle(b);
    var h = Math.ceil(b.scrollHeight + parseFloat(cs.marginTop) + parseFloat(cs.marginBottom));
    if (h === last) return;
    last = h;
    window.parent.postMessage({ type: 'lazyblog-artifact-height', height: h }, '*');
  }
  if ('ResizeObserver' in window) {
    var ro = new ResizeObserver(send);
    ro.observe(document.documentElement);
    if (document.body) ro.observe(document.body);
  }
  window.addEventListener('load', send);
  send();
})();
</script>
HTML;

    /**
     * The sandbox's opaque origin makes `localStorage` / `sessionStorage`
     * throw SecurityError on access, and generated artifacts routinely
     * persist UI state there without a try/catch — one uncaught throw at
     * startup and the whole demo is dead. Swap in a per-page-load memory
     * store so they run (state simply doesn't survive a reload). Must run
     * before any artifact script, hence injected at the top of <head>.
     */
    private const STORAGE_SHIM = <<<'HTML'
<script>
/* LazyBlog: in-memory Web Storage for the sandboxed (opaque-origin) artifact. */
(function () {
  function memoryStorage() {
    var d = {};
    return {
      getItem: function (k) { k = String(k); return Object.prototype.hasOwnProperty.call(d, k) ? d[k] : null; },
      setItem: function (k, v) { d[String(k)] = String(v); },
      removeItem: function (k) { delete d[String(k)]; },
      clear: function () { d = {}; },
      key: function (i) { var ks = Object.keys(d); return i < ks.length ? ks[i] : null; },
      get length() { return Object.keys(d).length; }
    };
  }
  ['localStorage', 'sessionStorage'].forEach(function (name) {
    try { window[name].getItem('x'); return; } catch (e) { /* blocked: shim below */ }
    try { Object.defineProperty(window, name, { value: memoryStorage(), configurable: true }); } catch (e) {}
  });
})();
</script>
HTML;

    /**
     * Auto-fit changes the iframe's height, and every height change fires a
     * `resize` event inside the artifact. Generated artifacts often re-lay
     * out canvases on `resize`, and a layout that grows on each pass (or
     * just reads back a size it wrote) turns that into a feedback loop with
     * the auto-fit: frame grows → resize → content grows → frame grows …
     *
     * A height-only viewport change in an embed is never something the
     * artifact needs to react to — it is the embed fitting itself to the
     * artifact. So window `resize` listeners (addEventListener + onresize)
     * are wrapped and skip events where the width did not change. Width
     * changes (phone rotation, fullscreen, window resize) pass through.
     * Top-level opens are left untouched.
     */
    private const RESIZE_FILTER = <<<'HTML'
<script>
/* LazyBlog: hide the embed's own height-only resizes from the artifact. */
(function () {
  if (window.parent === window) return;
  var add = window.addEventListener, remove = window.removeEventListener;
  var lastWidth = window.innerWidth, heightOnly = false, onresize = null;
  var wrapped = new WeakMap();
  // Capture + registered before any artifact script: runs first per event.
  add.call(window, 'resize', function () {
    var w = window.innerWidth;
    heightOnly = w === lastWidth;
    lastWidth = w;
  }, true);
  function wrap(fn) {
    if (!fn || (typeof fn !== 'function' && typeof fn.handleEvent !== 'function')) return fn;
    if (!wrapped.has(fn)) {
      wrapped.set(fn, function (e) {
        if (heightOnly) return;
        return typeof fn === 'function' ? fn.call(window, e) : fn.handleEvent(e);
      });
    }
    return wrapped.get(fn);
  }
  window.addEventListener = function (type, fn, opts) {
    return add.call(window, type, type === 'resize' ? wrap(fn) : fn, opts);
  };
  window.removeEventListener = function (type, fn, opts) {
    return remove.call(window, type, type === 'resize' && fn && wrapped.has(fn) ? wrapped.get(fn) : fn, opts);
  };
  try {
    Object.defineProperty(window, 'onresize', {
      configurable: true,
      get: function () { return onresize; },
      set: function (fn) { onresize = typeof fn === 'function' ? fn : null; }
    });
    add.call(window, 'resize', function (e) { if (!heightOnly && onresize) onresize.call(window, e); });
  } catch (e) {}
})();
</script>
HTML;

    /** Everything that must run before the artifact's own scripts. */
    private const HEAD_RUNTIME = self::STORAGE_SHIM . "\n" . self::RESIZE_FILTER;

    public function __construct(private readonly ArtifactStore $store)
    {
    }

    public function upload(): void
    {
        Auth::requireAuth();
        Csrf::requireValid();

        header('Content-Type: application/json; charset=utf-8');

        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $code = (int) (is_array($file) ? ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE);
            $this->fail(400, $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
                ? 'File too large.'
                : 'No artifact uploaded.');
        }

        try {
            $saved = $this->store->save((string) $file['tmp_name'], (string) ($file['name'] ?? ''));
        } catch (\RuntimeException $e) {
            $this->fail(400, $e->getMessage());
        }

        $url = '/artifacts/' . $saved['id'];
        echo json_encode([
            'id' => $saved['id'],
            'url' => $url,
            'title' => $saved['title'],
            'markdown' => self::embedBlock($saved['id'], $saved['title']),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string,string> $params */
    public function serve(array $params): void
    {
        $path = $this->store->path($params['id'] ?? '');
        if ($path === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "artifact not found\n";
            return;
        }

        // Replaces the site-wide CSP set in index.php: artifacts pull their
        // own CDNs (Tailwind, React, D3, …), so fetch directives stay open
        // and the sandbox does the isolating.
        header('Content-Security-Policy: sandbox ' . ArtifactStore::SANDBOX_FLAGS . "; frame-ancestors 'self'");
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex');
        // `private`: the shared session bootstrap may attach a Set-Cookie.
        header('Cache-Control: private, max-age=300');

        echo self::injectRuntime((string) file_get_contents($path));
    }

    /**
     * Add the head runtime — storage shim + resize filter — as early as
     * possible and the height reporter as late as possible. The head
     * runtime goes right after the opening <head>,
     * falling back to after <html> or the doctype — never before the
     * doctype, which would flip the artifact into quirks mode.
     */
    public static function injectRuntime(string $html): string
    {
        $pos = strripos($html, '</body>');
        $html = $pos === false
            ? $html . "\n" . self::HEIGHT_REPORTER . "\n"
            : substr($html, 0, $pos) . self::HEIGHT_REPORTER . "\n" . substr($html, $pos);

        foreach (['/<head\b[^>]*>/i', '/<html\b[^>]*>/i', '/<!doctype\b[^>]*>/i'] as $anchor) {
            if (preg_match($anchor, $html, $m, PREG_OFFSET_CAPTURE)) {
                $at = $m[0][1] + strlen($m[0][0]);
                return substr($html, 0, $at) . "\n" . self::HEAD_RUNTIME . substr($html, $at);
            }
        }
        return self::HEAD_RUNTIME . "\n" . $html;
    }

    /** Markdown block the editor inserts — parsed by MarkdownRenderer. */
    public static function embedBlock(string $id, string $title): string
    {
        $caption = trim(str_replace('"', "'", $title));
        return '::: artifact id="' . $id . '"' . ($caption !== '' ? ' title="' . $caption . '"' : '') . "\n:::";
    }

    /** @return never */
    private function fail(int $status, string $message): void
    {
        http_response_code($status);
        echo json_encode(['error' => $message]);
        exit;
    }
}
