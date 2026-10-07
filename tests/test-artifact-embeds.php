<?php

declare(strict_types=1);

/**
 * Interactive HTML artifact embeds:
 *   - ArtifactStore: upload validation, ID shape, title extraction
 *   - ArtifactController::serve: ID guards + height-reporter injection
 *   - MarkdownRenderer: `/artifacts/{id} "Caption"` line → sandboxed iframe
 *
 * Run: php tests/test-artifact-embeds.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\ArtifactStore;
use App\Controllers\ArtifactController;
use App\MarkdownRenderer;

$fail = 0;
$pass = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $fail, $pass;
    if ($cond) {
        $pass++;
        echo "  ok  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

$tmpRoot = sys_get_temp_dir() . '/lazyblog-test-artifacts-' . bin2hex(random_bytes(4));
mkdir($tmpRoot, 0775, true);
register_shutdown_function(static function () use ($tmpRoot): void {
    @exec('rm -rf ' . escapeshellarg($tmpRoot));
});

function makeUpload(string $root, string $bytes): string
{
    $p = $root . '/upload-' . bin2hex(random_bytes(4));
    file_put_contents($p, $bytes);
    return $p;
}

function rejects(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (\RuntimeException) {
        return true;
    }
}

$store = new ArtifactStore($tmpRoot);
$html = "<!doctype html><html><head><title> TCP  Handshake &amp; Retries </title></head>"
    . "<body><canvas></canvas><script>draw()</script></body></html>";

// --- Store ---------------------------------------------------------------
$saved = $store->save(makeUpload($tmpRoot, $html), 'Bắt tay TCP.html');
check('save: id = slug of filename + 6 hex', preg_match('/^bat-tay-tcp-[0-9a-f]{6}$/', $saved['id']) === 1, $saved['id']);
check('save: title from <title>, decoded + collapsed', $saved['title'] === 'TCP Handshake & Retries', $saved['title']);
check('save: bytes stored verbatim', file_get_contents((string) $store->path($saved['id'])) === $html);

$noTitle = $store->save(makeUpload($tmpRoot, '<div>hi</div>'), 'demo.htm');
check('save: .htm accepted, title falls back to filename', $noTitle['title'] === 'demo');

check('save: rejects non-html extension', rejects(fn () => $store->save(makeUpload($tmpRoot, $html), 'x.svg')));
check('save: rejects empty file', rejects(fn () => $store->save(makeUpload($tmpRoot, ''), 'x.html')));
check('save: rejects binary renamed .html', rejects(fn () => $store->save(
    makeUpload($tmpRoot, "\x89PNG\r\n\x1a\n" . str_repeat("\0\xff", 64)),
    'x.html',
)));

check('path: traversal id refused', $store->path('../../etc/passwd') === null);
check('path: uppercase id refused', $store->path(strtoupper($saved['id'])) === null);
check('path: missing id is null', $store->path('ghost-000000') === null);

// --- Serve ---------------------------------------------------------------
$ctl = new ArtifactController($store);
ob_start();
@$ctl->serve(['id' => $saved['id']]);
$body = (string) ob_get_clean();
check('serve: body is the artifact', str_contains($body, '<script>draw()</script>'));
check(
    'serve: reporter injected before </body>',
    preg_match('#lazyblog-artifact-height.*</script>\s*</body>#s', $body) === 1,
);

ob_start();
@$ctl->serve(['id' => '../' . $saved['id']]);
$blocked = (string) ob_get_clean();
check('serve: bad id does not leak file', !str_contains($blocked, 'draw()'));

check(
    'serve: resize filter injected before artifact scripts',
    strpos($body, 'hide the embed\'s own height-only resizes') < strpos($body, '<script>draw()'),
);
check(
    'serve: storage shim right after <head>, doctype stays first',
    str_starts_with($body, '<!doctype html><html><head>' . "\n<script>\n/* LazyBlog: in-memory Web Storage"),
);

$frag = ArtifactController::injectRuntime('<p>fragment</p>');
check('inject: fragment gets shim first, reporter last', str_starts_with($frag, "<script>\n/* LazyBlog: in-memory")
    && str_contains($frag, '<p>fragment</p>')
    && strpos($frag, 'lazyblog-artifact-height') > strpos($frag, '<p>fragment</p>'));

check(
    'embedBlock: caption quotes neutralised',
    ArtifactController::embedBlock('a-1b2c3d', 'Say "hi"') === "::: artifact id=\"a-1b2c3d\" title=\"Say 'hi'\"\n:::",
);
check(
    'embedBlock: no title attr when caption empty',
    ArtifactController::embedBlock('a-1b2c3d', '') === "::: artifact id=\"a-1b2c3d\"\n:::",
);

// --- Renderer ------------------------------------------------------------
$r = new MarkdownRenderer();
$id = $saved['id'];
$out = $r->render("Intro\n\n::: artifact id=\"{$id}\" title=\"TCP <demo>\"\nSee **step 3**.\n:::\n\nOutro\n")['html'];
check('render: figure emitted', str_contains($out, '<figure class="artifact-embed">'));
check('render: iframe sandboxed without allow-same-origin', str_contains($out, 'sandbox="' . ArtifactStore::SANDBOX_FLAGS . '"')
    && !str_contains($out, 'allow-same-origin'));
check('render: iframe src', str_contains($out, 'src="/artifacts/' . $id . '"'));
check('render: caption escaped', str_contains($out, 'TCP &lt;demo&gt;') && !str_contains($out, '<demo>'));
check('render: lazy-loaded', str_contains($out, 'loading="lazy"'));
check('render: body rendered as markdown note', str_contains($out, '<div class="artifact-embed-note"><p>See <strong>step 3</strong>.</p>'));
check('render: surrounding paragraphs intact', str_contains($out, '<p>Intro</p>') && str_contains($out, '<p>Outro</p>'));

$bare = $r->render("::: artifact id=\"{$id}\"\n:::\n")['html'];
check('render: empty block without title embeds', str_contains($bare, 'title="Interactive artifact"')
    && !str_contains($bare, 'artifact-embed-note'));

$badId = $r->render("::: artifact id=\"../etc\"\n:::\n")['html'];
check('render: malformed id left as text', !str_contains($badId, '<iframe'));

$noId = $r->render("::: artifact title=\"x\"\n:::\n")['html'];
check('render: missing id left as text', !str_contains($noId, '<iframe'));

$inline = $r->render("See /artifacts/{$id} for details.\n")['html'];
check('render: bare path is not an embed', !str_contains($inline, '<iframe'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
