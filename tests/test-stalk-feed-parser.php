<?php

declare(strict_types=1);

/**
 * Stalk plugin — FeedParser (strict LazyBlog generator + extraction).
 *
 * Run: php tests/test-stalk-feed-parser.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../plugins/stalk/src/FeedParser.php';

use Plugins\Stalk\FeedParser;

$failures = 0;
function section(string $n): void { echo "==> {$n}\n"; }
function ok(string $m): void      { echo "  ok: {$m}\n"; }
function fail(string $m): void
{
    global $failures;
    $failures++;
    fwrite(STDERR, "  FAIL: {$m}\n");
}
function assertTrue(bool $c, string $m): void { $c ? ok($m) : fail($m); }
function assertEq(mixed $e, mixed $a, string $m): void
{
    $e === $a ? ok($m) : fail("{$m} — expected " . var_export($e, true) . ", got " . var_export($a, true));
}
function assertThrows(callable $fn, string $needle, string $m): void
{
    try {
        $fn();
        fail("{$m} — no exception");
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), $needle)) {
            ok($m);
        } else {
            fail("{$m} — wrong message: " . $e->getMessage());
        }
    }
}

function feed(string $generator, array $items, string $channelTitle = 'Test Blog'): string
{
    $itemXml = '';
    foreach ($items as $i) {
        $title = htmlspecialchars($i['title'] ?? '', ENT_XML1);
        $link  = htmlspecialchars($i['link']  ?? '', ENT_XML1);
        $pub   = htmlspecialchars($i['pubDate'] ?? '', ENT_XML1);
        $guid  = isset($i['guid']) ? '<guid>' . htmlspecialchars($i['guid'], ENT_XML1) . '</guid>' : '';
        $linkTag = $link !== '' ? "<link>{$link}</link>" : '';
        $itemXml .= "<item><title>{$title}</title>{$linkTag}<pubDate>{$pub}</pubDate>{$guid}</item>";
    }
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>{$channelTitle}</title>
    <generator>{$generator}</generator>
    {$itemXml}
  </channel>
</rss>
XML;
}

$p = new FeedParser();

// ---------------------------------------------------------------------------
section('Happy path — LazyBlog generator + 2 items');

$xml = feed('LazyBlog', [
    ['title' => 'Post Alpha', 'link' => 'https://blog.example/posts/alpha', 'pubDate' => 'Wed, 01 Jan 2025 12:00:00 +0000', 'guid' => 'guid-alpha'],
    ['title' => 'Post Beta',  'link' => 'https://blog.example/posts/beta',  'pubDate' => 'Wed, 02 Jan 2025 12:00:00 +0000', 'guid' => 'guid-beta'],
]);
$out = $p->parse($xml);
assertEq('LazyBlog', $out['generator'], 'generator captured');
assertEq('Test Blog', $out['channel_title'], 'channel_title captured');
assertEq(2, count($out['items']), 'two items returned');
// pub_date DESC — Beta (later) first
assertEq('Post Beta', $out['items'][0]['title'], 'first item is newer (Beta)');
assertEq('guid-alpha', $out['items'][1]['guid'], 'guid preserved for older item');

// ---------------------------------------------------------------------------
section('Backwards compat — old generator with suffix still accepted');

$xmlOld = feed('LazyBlog (PHP + Markdown)', [
    ['title' => 'X', 'link' => 'https://b.example/x', 'pubDate' => 'Thu, 01 Jan 2026 00:00:00 +0000'],
]);
$out2 = $p->parse($xmlOld);
assertEq(1, count($out2['items']), 'old "LazyBlog (PHP + Markdown)" still parses');

// ---------------------------------------------------------------------------
section('Strict generator check');

$wp = feed('WordPress 6.5', [['title' => 'x', 'link' => 'https://x.example/']]);
assertThrows(fn () => $p->parse($wp), 'not a LazyBlog blog', 'WordPress generator rejected');

$noGen = '<?xml version="1.0"?><rss version="2.0"><channel><title>x</title></channel></rss>';
assertThrows(fn () => $p->parse($noGen), 'not a LazyBlog blog', 'missing generator rejected');

// ---------------------------------------------------------------------------
section('Malformed input');

assertThrows(fn () => $p->parse(''), 'empty XML body', 'empty body rejected');
assertThrows(fn () => $p->parse('<rss><channel><generator>LazyBlog'), 'malformed XML', 'truncated XML rejected');

// ---------------------------------------------------------------------------
section('Item skip rules');

$mixed = feed('LazyBlog', [
    ['title' => 'has-link',    'link' => 'https://b.example/a', 'pubDate' => 'Thu, 01 Jan 2026 00:00:00 +0000'],
    ['title' => 'no-link',     'link' => '',                     'pubDate' => 'Thu, 01 Jan 2026 00:00:00 +0000'],
    ['title' => '',            'link' => 'https://b.example/b', 'pubDate' => 'Thu, 01 Jan 2026 00:00:00 +0000'],
    ['title' => 'bad-scheme',  'link' => 'ftp://b.example/c',   'pubDate' => 'Thu, 01 Jan 2026 00:00:00 +0000'],
]);
$out3 = $p->parse($mixed);
assertEq(1, count($out3['items']), 'only the http(s) item with both title+link survives');
assertEq('has-link', $out3['items'][0]['title'], 'survivor is "has-link"');

// ---------------------------------------------------------------------------
section('pubDate unparseable → ts=0');

$badDate = feed('LazyBlog', [
    ['title' => 'X', 'link' => 'https://b.example/x', 'pubDate' => 'not a date'],
]);
$out4 = $p->parse($badDate);
assertEq(0, $out4['items'][0]['pub_date_ts'], 'unparseable pubDate → 0');

// ---------------------------------------------------------------------------
section('guid fallback to link');

$noGuid = feed('LazyBlog', [
    ['title' => 'X', 'link' => 'https://b.example/x', 'pubDate' => 'Wed, 01 Jan 2025 00:00:00 +0000'],
]);
$out5 = $p->parse($noGuid);
assertEq('https://b.example/x', $out5['items'][0]['guid'], 'guid falls back to link when omitted');

// ---------------------------------------------------------------------------
section('HARD_CEILING — 25 items → top 10 by pub_date');

$items = [];
for ($i = 1; $i <= 25; $i++) {
    $items[] = [
        'title'   => "Post-{$i}",
        'link'    => "https://b.example/{$i}",
        'pubDate' => gmdate('D, d M Y H:i:s', 1700000000 + $i) . ' +0000',
    ];
}
$out6 = $p->parse(feed('LazyBlog', $items));
assertEq(10, count($out6['items']), 'capped at HARD_CEILING=10');
assertEq('Post-25', $out6['items'][0]['title'], 'newest first (Post-25)');
assertEq('Post-16', $out6['items'][9]['title'], '10th item is Post-16');

// ---------------------------------------------------------------------------
section('Out-of-order input still produces newest-first output');

$rev = feed('LazyBlog', [
    ['title' => 'old',  'link' => 'https://b.example/old',  'pubDate' => 'Wed, 01 Jan 2020 00:00:00 +0000'],
    ['title' => 'new',  'link' => 'https://b.example/new',  'pubDate' => 'Wed, 01 Jan 2030 00:00:00 +0000'],
    ['title' => 'mid',  'link' => 'https://b.example/mid',  'pubDate' => 'Wed, 01 Jan 2025 00:00:00 +0000'],
]);
$out7 = $p->parse($rev);
assertEq(['new', 'mid', 'old'], array_column($out7['items'], 'title'), 'sorted DESC by pub_date');

// ---------------------------------------------------------------------------
echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures}\n");
    exit(1);
}
echo "ALL OK\n";
