<?php

declare(strict_types=1);

/**
 * Graffiti plugin — Phase 7: OverlayRenderer + StickerCatalogue overrides.
 *
 * Run: php tests/test-graffiti-render.php
 *
 * Covers:
 *   - render(slug) returns '' when no graffiti exists for slug
 *   - Hidden rows excluded from render output
 *   - Sticker → <img> with correct src, position percentages, rotation, alt
 *   - Text → <div class=graffiti-text> with HTML-escaped content + attribution
 *   - <script> payload escaped, no raw HTML leak
 *   - Position values clamped (x>1 → 100%, rotation>180 → 180)
 *   - DOM order matches received_at ASC (newer items later in HTML → on top)
 *   - StickerCatalogue::setOverride persists price + enabled
 *   - StickerCatalogue::setOverride never lets storage override the svg_filename
 *
 * Exits non-zero on any failure; prints `ALL OK` on full pass.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../plugins/graffiti/src/TokenGenerator.php';
require __DIR__ . '/../plugins/graffiti/src/InviteCodec.php';
require __DIR__ . '/../plugins/graffiti/src/FriendStore.php';
require __DIR__ . '/../plugins/graffiti/src/StickerCatalogue.php';
require __DIR__ . '/../plugins/graffiti/src/GraffitiStore.php';
require __DIR__ . '/../plugins/graffiti/src/OverlayRenderer.php';

use Plugins\Graffiti\FriendStore;
use Plugins\Graffiti\GraffitiStore;
use Plugins\Graffiti\OverlayRenderer;
use Plugins\Graffiti\StickerCatalogue;

$failures = 0;
function section(string $n): void { echo "==> {$n}\n"; }
function ok(string $m): void      { echo "  ok: {$m}\n"; }
function fail(string $m): void
{
    global $failures;
    $failures++;
    fwrite(STDERR, "  FAIL: {$m}\n");
}
function assertEq(mixed $e, mixed $a, string $m): void
{
    $e === $a ? ok($m) : fail("{$m} — expected " . var_export($e, true) . ", got " . var_export($a, true));
}
function assertTrue(bool $c, string $m): void { $c ? ok($m) : fail($m); }
function assertContains(string $needle, string $haystack, string $m): void
{
    str_contains($haystack, $needle) ? ok($m) : fail("{$m} — missing '{$needle}' in:\n{$haystack}");
}
function assertNotContains(string $needle, string $haystack, string $m): void
{
    !str_contains($haystack, $needle) ? ok($m) : fail("{$m} — '{$needle}' present in:\n{$haystack}");
}

function tempStorage(string $label): string
{
    $p = sys_get_temp_dir() . "/graffiti-render-{$label}-" . posix_getpid() . '-' . bin2hex(random_bytes(4));
    @mkdir($p, 0o755, recursive: true);
    return $p;
}

$pluginRoot = realpath(__DIR__ . '/../plugins/graffiti');

// ---------------------------------------------------------------------------
section('Empty for unknown slug');

$s = tempStorage('empty');
copy($pluginRoot . '/content/stickers.json', $s . '/stickers.json');
$store = new GraffitiStore($s);
$friends = new FriendStore($s);
$cat = new StickerCatalogue($s, $pluginRoot);
$renderer = new OverlayRenderer($store, $friends, $cat);
assertEq('', $renderer->render('no-such-slug'), 'no items → empty string');

// ---------------------------------------------------------------------------
section('Sticker render — image with position + rotation + tooltip');

$s = tempStorage('sticker');
copy($pluginRoot . '/content/stickers.json', $s . '/stickers.json');
$store   = new GraffitiStore($s);
$friends = new FriendStore($s);
$cat     = new StickerCatalogue($s, $pluginRoot);

$fid = $friends->create([
    'handle' => 'alice', 'blog_url' => 'https://a.example',
    'graffiti_endpoint' => 'https://a.example/graffiti/receive',
    'incoming_token' => str_repeat('A', 43),
    'outgoing_token' => str_repeat('B', 43),
    'state' => 'active',
]);
$store->append([
    'from_friend_id' => $fid,
    'post_slug' => 'hello',
    'type' => 'sticker',
    'payload' => ['sticker_id' => 'ufo-1', 'position' => ['x' => 0.42, 'y' => 0.71, 'rotation' => 17]],
    'nonce' => 'n1',
]);

$renderer = new OverlayRenderer($store, $friends, $cat);
$html = $renderer->render('hello');

assertContains('class="graffiti-layer"', $html, 'wrapper layer present');
assertContains('aria-hidden="true"', $html, 'overlay marked aria-hidden');
assertContains('src="/plugin-assets/graffiti/ufo-1.svg"', $html, 'src points at correct SVG');
assertContains('left:42.00%', $html, 'x percentage encoded');
assertContains('top:71.00%', $html, 'y percentage encoded');
assertContains('rotate(17deg)', $html, 'rotation encoded as deg');
assertContains('title="from alice (a.example)"', $html, 'tooltip shows handle + host');

// ---------------------------------------------------------------------------
section('Text render — escaped + attribution');

$s = tempStorage('text');
copy($pluginRoot . '/content/stickers.json', $s . '/stickers.json');
$store   = new GraffitiStore($s);
$friends = new FriendStore($s);
$cat     = new StickerCatalogue($s, $pluginRoot);

$fid = $friends->create([
    'handle' => 'bob', 'blog_url' => 'https://b.example',
    'graffiti_endpoint' => 'https://b.example/graffiti/receive',
    'incoming_token' => str_repeat('A', 43),
    'outgoing_token' => str_repeat('B', 43),
    'state' => 'active',
]);
$store->append([
    'from_friend_id' => $fid,
    'post_slug' => 'p1',
    'type' => 'text',
    'payload' => ['text' => '<script>alert(1)</script> & "fun"', 'position' => ['x' => 0.1, 'y' => 0.2]],
    'nonce' => 'n2',
]);

$renderer = new OverlayRenderer($store, $friends, $cat);
$html = $renderer->render('p1');

assertContains('graffiti-text', $html, 'text class on div');
assertContains('graffiti-overlay-item', $html, 'overlay-item shell present');
assertContains('&lt;script&gt;', $html, '<script> tag escaped');
assertNotContains('<script>alert', $html, 'no raw <script> leak');
assertContains('&amp;', $html, '& escaped');
assertContains('&quot;fun&quot;', $html, '" escaped');
assertContains('href="https://b.example"', $html, 'attribution link to blog');
assertContains('bob', $html, 'attribution handle present');

// ---------------------------------------------------------------------------
section('Hidden rows excluded');

$s = tempStorage('hidden');
copy($pluginRoot . '/content/stickers.json', $s . '/stickers.json');
$store   = new GraffitiStore($s);
$friends = new FriendStore($s);
$cat     = new StickerCatalogue($s, $pluginRoot);
$fid = $friends->create([
    'handle' => 'h', 'blog_url' => 'https://h.example',
    'graffiti_endpoint' => 'https://h.example/graffiti/receive',
    'incoming_token' => str_repeat('A', 43),
    'outgoing_token' => str_repeat('B', 43),
    'state' => 'active',
]);
$id1 = $store->append(['from_friend_id' => $fid, 'post_slug' => 'p', 'type' => 'sticker',
    'payload' => ['sticker_id' => 'ufo-1'], 'nonce' => 'a']);
$id2 = $store->append(['from_friend_id' => $fid, 'post_slug' => 'p', 'type' => 'sticker',
    'payload' => ['sticker_id' => 'fire-1'], 'nonce' => 'b']);
$store->update($id1, ['hidden' => true]);

$renderer = new OverlayRenderer($store, $friends, $cat);
$html = $renderer->render('p');
assertNotContains('ufo-1.svg', $html, 'hidden sticker not rendered');
assertContains('fire-1.svg', $html, 'visible sticker rendered');

// ---------------------------------------------------------------------------
section('Position values clamped');

$s = tempStorage('clamp');
copy($pluginRoot . '/content/stickers.json', $s . '/stickers.json');
$store = new GraffitiStore($s);
$friends = new FriendStore($s);
$cat = new StickerCatalogue($s, $pluginRoot);
$fid = $friends->create([
    'handle' => 'c', 'blog_url' => 'https://c.example',
    'graffiti_endpoint' => 'https://c.example/graffiti/receive',
    'incoming_token' => str_repeat('A', 43),
    'outgoing_token' => str_repeat('B', 43),
    'state' => 'active',
]);
$store->append(['from_friend_id' => $fid, 'post_slug' => 'p', 'type' => 'sticker',
    'payload' => ['sticker_id' => 'ufo-1', 'position' => ['x' => 99, 'y' => -5, 'rotation' => 9999]],
    'nonce' => 'c']);

$html = (new OverlayRenderer($store, $friends, $cat))->render('p');
assertContains('left:100.00%', $html, 'x>1 clamped to 100%');
assertContains('top:0.00%', $html, 'y<0 clamped to 0%');
assertContains('rotate(180deg)', $html, 'rotation>180 clamped to 180deg');

// ---------------------------------------------------------------------------
section('DOM order matches received_at ASC (newer items later → on top)');

$s = tempStorage('order');
copy($pluginRoot . '/content/stickers.json', $s . '/stickers.json');
$store = new GraffitiStore($s);
$friends = new FriendStore($s);
$cat = new StickerCatalogue($s, $pluginRoot);
$fid = $friends->create([
    'handle' => 'o', 'blog_url' => 'https://o.example',
    'graffiti_endpoint' => 'https://o.example/graffiti/receive',
    'incoming_token' => str_repeat('A', 43),
    'outgoing_token' => str_repeat('B', 43),
    'state' => 'active',
]);
$idOld = $store->append(['from_friend_id' => $fid, 'post_slug' => 'p', 'type' => 'sticker',
    'payload' => ['sticker_id' => 'ufo-1'], 'nonce' => 'old']);
$idNew = $store->append(['from_friend_id' => $fid, 'post_slug' => 'p', 'type' => 'sticker',
    'payload' => ['sticker_id' => 'fire-1'], 'nonce' => 'new']);
$store->update($idOld, ['received_at' => time() - 100]);
$store->update($idNew, ['received_at' => time()]);

$html = (new OverlayRenderer($store, $friends, $cat))->render('p');
$posOld = strpos($html, 'ufo-1.svg');
$posNew = strpos($html, 'fire-1.svg');
assertTrue($posOld !== false && $posNew !== false, 'both stickers in output');
assertTrue($posOld < $posNew, "older (ufo-1) declared first, newer (fire-1) later → on top via DOM order");

// ---------------------------------------------------------------------------
section('StickerCatalogue::setOverride');

$s = tempStorage('override');
copy($pluginRoot . '/content/stickers.json', $s . '/stickers.json');
$cat = new StickerCatalogue($s, $pluginRoot);

$cat->setOverride('ufo-1', ['price' => 42]);
$row = $cat->find('ufo-1');
assertEq(42, (int) $row['default_price'], 'override price persisted');
assertEq('ufo-1.svg', (string) $row['svg_filename'], 'svg_filename still from ship file (operator override cannot redirect)');

$cat->setOverride('ufo-1', ['enabled' => false]);
$row = $cat->find('ufo-1');
assertEq(false, (bool) $row['enabled'], 'enabled=false persisted');

$enabled = $cat->enabled();
$ids = array_map(static fn (array $r): string => (string) $r['id'], $enabled);
assertTrue(!in_array('ufo-1', $ids, true), 'disabled sticker excluded from enabled()');

// Try to redirect svg via storage edit — manual hack to confirm catalogue
// ignores any storage-side svg_filename change.
$storageRaw = json_decode((string) file_get_contents($s . '/stickers.json'), true);
foreach ($storageRaw as &$r) {
    if (($r['id'] ?? '') === 'ufo-1') {
        $r['svg_filename'] = 'malicious.svg';
    }
}
unset($r);
file_put_contents($s . '/stickers.json', (string) json_encode($storageRaw));
$row = (new StickerCatalogue($s, $pluginRoot))->find('ufo-1');
assertEq('ufo-1.svg', (string) $row['svg_filename'], 'storage svg_filename cannot override ship value');

// ---------------------------------------------------------------------------
echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures}\n");
    exit(1);
}
echo "ALL OK\n";
