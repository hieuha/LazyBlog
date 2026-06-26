<?php

declare(strict_types=1);

/**
 * Stalk plugin — RefreshService global gate + batch path.
 *
 * Uses test doubles (anonymous subclasses) for FeedFetcher and FeedParser
 * so we never touch the network.
 *
 * Run: php tests/test-stalk-refresh-service.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../plugins/stalk/src/FriendStore.php';
require __DIR__ . '/../plugins/stalk/src/PostCache.php';
require __DIR__ . '/../plugins/stalk/src/Config.php';
require __DIR__ . '/../plugins/stalk/src/FeedFetcher.php';
require __DIR__ . '/../plugins/stalk/src/FeedParser.php';
require __DIR__ . '/../plugins/stalk/src/RefreshService.php';

use Plugins\Stalk\Config;
use Plugins\Stalk\FeedFetcher;
use Plugins\Stalk\FeedParser;
use Plugins\Stalk\FriendStore;
use Plugins\Stalk\PostCache;
use Plugins\Stalk\RefreshService;

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

function makeStorage(string $label): string
{
    $p = sys_get_temp_dir() . "/stalk-refresh-{$label}-" . posix_getpid() . '-' . bin2hex(random_bytes(4));
    @mkdir($p, 0o755, recursive: true);
    return $p;
}

/** Fetcher double — returns a canned body per URL or throws. */
class FakeFetcher extends FeedFetcher
{
    /** @var array<string,string> URL → body */
    public array $bodies = [];
    /** @var array<string,string> URL → error message (overrides body) */
    public array $errors = [];
    /** @var list<string> URLs the test saw fetched */
    public array $calls = [];

    public function fetch(string $url): string
    {
        $this->calls[] = $url;
        if (isset($this->errors[$url])) {
            throw new \RuntimeException($this->errors[$url]);
        }
        if (!isset($this->bodies[$url])) {
            throw new \RuntimeException("no fixture for {$url}");
        }
        return $this->bodies[$url];
    }

    public function fetchMany(array $urls): array
    {
        $out = [];
        foreach ($urls as $label => $url) {
            $this->calls[] = $url;
            if (isset($this->errors[$url])) {
                $out[$label] = ['ok' => false, 'error' => $this->errors[$url]];
            } elseif (isset($this->bodies[$url])) {
                $out[$label] = ['ok' => true, 'body' => $this->bodies[$url]];
            } else {
                $out[$label] = ['ok' => false, 'error' => "no fixture for {$url}"];
            }
        }
        return $out;
    }
}

function feedXml(string $generator, array $items, string $title = 'Friend Blog'): string
{
    $itemXml = '';
    foreach ($items as $i) {
        $t = htmlspecialchars($i['title'], ENT_XML1);
        $l = htmlspecialchars($i['link'], ENT_XML1);
        $p = htmlspecialchars($i['pub'], ENT_XML1);
        $itemXml .= "<item><title>{$t}</title><link>{$l}</link><pubDate>{$p}</pubDate><guid>{$l}</guid></item>";
    }
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel>
<title>{$title}</title>
<generator>{$generator}</generator>
{$itemXml}
</channel></rss>
XML;
}

// ---------------------------------------------------------------------------
function makeSvc(string $label, FakeFetcher $fetcher): array
{
    $dir = makeStorage($label);
    $store  = new FriendStore($dir);
    $cache  = new PostCache($dir);
    $config = new Config($dir);
    $parser = new FeedParser();
    $svc    = new RefreshService($store, $cache, $config, $fetcher, $parser);
    return [$svc, $store, $cache, $config];
}

// ---------------------------------------------------------------------------
section('refreshStale — gate skips when fresh');

$fetcher = new FakeFetcher();
[$svc, $store, $cache, $config] = makeSvc('gate-fresh', $fetcher);
$store->create(['blog_url' => 'https://a.example', 'handle' => 'a']);
$store->create(['blog_url' => 'https://b.example', 'handle' => 'b']);

$config->markRefreshed(time());
$r = $svc->refreshStale();
assertEq(true, $r['gated'], 'gated when fresh');
assertEq(0, $r['refreshed'], 'no fetch when gated');
assertEq(2, $r['skipped'], 'skipped=N total friends');
assertEq([], $fetcher->calls, 'fetcher never invoked when gated');

// ---------------------------------------------------------------------------
section('refreshStale — batch fires when stale');

$fetcher2 = new FakeFetcher();
$fetcher2->bodies['https://a.example/feed.xml'] = feedXml('LazyBlog', [
    ['title' => 'A-post', 'link' => 'https://a.example/p/1', 'pub' => 'Wed, 01 Jan 2025 00:00:00 +0000'],
]);
$fetcher2->bodies['https://b.example/feed.xml'] = feedXml('LazyBlog', [
    ['title' => 'B-post', 'link' => 'https://b.example/p/1', 'pub' => 'Wed, 02 Jan 2025 00:00:00 +0000'],
]);
[$svc2, $store2, $cache2, $config2] = makeSvc('stale', $fetcher2);
$idA = $store2->create(['blog_url' => 'https://a.example', 'handle' => 'a']);
$idB = $store2->create(['blog_url' => 'https://b.example', 'handle' => 'b']);

assertTrue($config2->isStale(), 'fresh install is stale');
$r2 = $svc2->refreshStale();
assertEq(false, $r2['gated'], 'not gated when stale');
assertEq(2, $r2['refreshed'], 'both friends refreshed');
assertEq(0, $r2['errored'], 'no errors');
assertEq(2, count($cache2->all()), '2 items cached total');
assertEq('A-post', $cache2->forFriend($idA)[0]['title'], 'A cached');
assertEq('B-post', $cache2->forFriend($idB)[0]['title'], 'B cached');
assertTrue(!$config2->isStale(), 'no longer stale after batch');

// Second call within interval → gated
$r3 = $svc2->refreshStale();
assertEq(true, $r3['gated'], 'second call gated');

// ---------------------------------------------------------------------------
section('error isolation — bad friend never aborts batch');

$fetcher3 = new FakeFetcher();
$fetcher3->errors['https://bad.example/feed.xml'] = 'connection refused';
$fetcher3->bodies['https://good.example/feed.xml'] = feedXml('LazyBlog', [
    ['title' => 'G1', 'link' => 'https://good.example/g/1', 'pub' => 'Wed, 01 Jan 2026 00:00:00 +0000'],
]);
[$svc3, $store3, $cache3, $config3] = makeSvc('error-iso', $fetcher3);
$idBad  = $store3->create(['blog_url' => 'https://bad.example',  'handle' => 'bad']);
$idGood = $store3->create(['blog_url' => 'https://good.example', 'handle' => 'good']);

$r4 = $svc3->refreshAll();
assertEq(1, $r4['refreshed'], 'good friend refreshed');
assertEq(1, $r4['errored'],   'bad friend errored');

$bad  = $store3->find($idBad);
$good = $store3->find($idGood);
assertEq('error', $bad['last_status'], 'bad friend last_status=error');
assertTrue(str_contains((string)$bad['last_error'], 'connection refused'), 'bad error message recorded');
assertEq(0, $bad['last_fetched_at'], 'bad last_fetched_at NOT bumped (preserves prior success time)');
assertEq('ok', $good['last_status'], 'good last_status=ok');
assertTrue($good['last_fetched_at'] > 0, 'good last_fetched_at bumped');
assertEq(1, count($cache3->forFriend($idGood)), 'good has cached post');
assertEq([], $cache3->forFriend($idBad), 'bad has no cached posts');

// ---------------------------------------------------------------------------
section('cache preservation — friend goes from ok to error keeps cache');

// 1st refresh succeeds → cache populated
$fetcher4 = new FakeFetcher();
$fetcher4->bodies['https://flaky.example/feed.xml'] = feedXml('LazyBlog', [
    ['title' => 'oldgood', 'link' => 'https://flaky.example/o', 'pub' => 'Wed, 01 Jan 2025 00:00:00 +0000'],
]);
[$svc4, $store4, $cache4, $config4] = makeSvc('preserve', $fetcher4);
$idFlaky = $store4->create(['blog_url' => 'https://flaky.example', 'handle' => 'flaky']);
$svc4->refreshAll();
assertEq(1, count($cache4->forFriend($idFlaky)), 'first refresh cached 1 item');

// 2nd refresh now errors out — cache should remain intact
$fetcher4->errors['https://flaky.example/feed.xml'] = 'oops';
unset($fetcher4->bodies['https://flaky.example/feed.xml']);
$svc4->refreshAll();
$rows = $cache4->forFriend($idFlaky);
assertEq(1, count($rows), 'cache preserved after error');
assertEq('oldgood', $rows[0]['title'], 'previous content still there');

// ---------------------------------------------------------------------------
section('max_items_per_friend slice applied by RefreshService');

$fetcher5 = new FakeFetcher();
$fetcher5->bodies['https://bulk.example/feed.xml'] = feedXml('LazyBlog', [
    ['title' => 'p1', 'link' => 'https://bulk.example/1', 'pub' => 'Mon, 01 Jan 2024 00:00:00 +0000'],
    ['title' => 'p2', 'link' => 'https://bulk.example/2', 'pub' => 'Tue, 02 Jan 2024 00:00:00 +0000'],
    ['title' => 'p3', 'link' => 'https://bulk.example/3', 'pub' => 'Wed, 03 Jan 2024 00:00:00 +0000'],
    ['title' => 'p4', 'link' => 'https://bulk.example/4', 'pub' => 'Thu, 04 Jan 2024 00:00:00 +0000'],
    ['title' => 'p5', 'link' => 'https://bulk.example/5', 'pub' => 'Fri, 05 Jan 2024 00:00:00 +0000'],
]);
[$svc5, $store5, $cache5, $config5] = makeSvc('cap', $fetcher5);
$config5->setMaxItemsPerFriend(2);
$idBulk = $store5->create(['blog_url' => 'https://bulk.example', 'handle' => 'bulk']);

$svc5->refreshAll();
$kept = $cache5->forFriend($idBulk);
assertEq(2, count($kept), 'cap=2 trims to 2 items');
$titles = array_column($kept, 'title');
sort($titles);
assertEq(['p4', 'p5'], $titles, 'kept the two newest');

// ---------------------------------------------------------------------------
section('per-friend max_items overrides config default');

$fetcher5b = new FakeFetcher();
$fetcher5b->bodies['https://global.example/feed.xml'] = feedXml('LazyBlog', [
    ['title' => 'g1', 'link' => 'https://global.example/1', 'pub' => 'Mon, 01 Jan 2024 00:00:00 +0000'],
    ['title' => 'g2', 'link' => 'https://global.example/2', 'pub' => 'Tue, 02 Jan 2024 00:00:00 +0000'],
    ['title' => 'g3', 'link' => 'https://global.example/3', 'pub' => 'Wed, 03 Jan 2024 00:00:00 +0000'],
    ['title' => 'g4', 'link' => 'https://global.example/4', 'pub' => 'Thu, 04 Jan 2024 00:00:00 +0000'],
]);
$fetcher5b->bodies['https://override.example/feed.xml'] = feedXml('LazyBlog', [
    ['title' => 'o1', 'link' => 'https://override.example/1', 'pub' => 'Mon, 01 Jan 2024 00:00:00 +0000'],
    ['title' => 'o2', 'link' => 'https://override.example/2', 'pub' => 'Tue, 02 Jan 2024 00:00:00 +0000'],
    ['title' => 'o3', 'link' => 'https://override.example/3', 'pub' => 'Wed, 03 Jan 2024 00:00:00 +0000'],
    ['title' => 'o4', 'link' => 'https://override.example/4', 'pub' => 'Thu, 04 Jan 2024 00:00:00 +0000'],
]);
[$svc5b, $store5b, $cache5b, $config5b] = makeSvc('per-friend-cap', $fetcher5b);
$config5b->setMaxItemsPerFriend(2);   // global default

$idGlobal = $store5b->create([
    'blog_url' => 'https://global.example',
    'handle'   => 'g',
    // no max_items — should use config default (2)
]);
$idOverride = $store5b->create([
    'blog_url'  => 'https://override.example',
    'handle'    => 'o',
    'max_items' => 4,                  // per-friend override
]);

$svc5b->refreshAll();
assertEq(2, count($cache5b->forFriend($idGlobal)), 'no override → uses config default (2)');
assertEq(4, count($cache5b->forFriend($idOverride)), 'override=4 → stores 4 items');

// max_items > MAX_ITEMS_CEILING clamped at refresh time
$store5b->update($idOverride, ['max_items' => 999]);
$svc5b->refreshAll();
assertEq(4, count($cache5b->forFriend($idOverride)), 'corrupt over-ceiling clamped (feed has 4 items)');

// ---------------------------------------------------------------------------
section('refreshOne — first-time populate for handleAdd');

$fetcher6 = new FakeFetcher();
$fetcher6->bodies['https://new.example/feed.xml'] = feedXml('LazyBlog', [
    ['title' => 'fresh', 'link' => 'https://new.example/f', 'pub' => 'Wed, 01 Jan 2025 00:00:00 +0000'],
]);
[$svc6, $store6, $cache6, $config6] = makeSvc('one', $fetcher6);
$id6 = $store6->create(['blog_url' => 'https://new.example', 'handle' => 'new']);
$row6 = $store6->find($id6);

$priorRefresh = $config6->get()['last_refresh_at'];
$out = $svc6->refreshOne($row6);
assertEq(true, $out['ok'], 'refreshOne ok');
assertEq(1, $out['count'], 'refreshOne returned count=1');
assertEq(1, count($cache6->forFriend($id6)), 'cache populated');
assertEq($priorRefresh, $config6->get()['last_refresh_at'], 'refreshOne does NOT touch global gate');

// refreshOne on error
$fetcher6->errors['https://broken.example/feed.xml'] = 'no route to host';
$idBroken = $store6->create(['blog_url' => 'https://broken.example', 'handle' => 'broken']);
$rowBroken = $store6->find($idBroken);
$out2 = $svc6->refreshOne($rowBroken);
assertEq(false, $out2['ok'], 'refreshOne reports error');
assertTrue(str_contains((string)$out2['error'], 'no route'), 'error message bubbled');
assertEq('error', $store6->find($idBroken)['last_status'], 'broken friend recorded');

// ---------------------------------------------------------------------------
section('refreshAll bypasses gate');

$fetcher7 = new FakeFetcher();
$fetcher7->bodies['https://a.example/feed.xml'] = feedXml('LazyBlog', [
    ['title' => 'A', 'link' => 'https://a.example/a', 'pub' => 'Wed, 01 Jan 2025 00:00:00 +0000'],
]);
[$svc7, $store7, $cache7, $config7] = makeSvc('forceall', $fetcher7);
$store7->create(['blog_url' => 'https://a.example', 'handle' => 'a']);
$config7->markRefreshed(time());      // not stale

$r = $svc7->refreshAll();
assertEq(1, $r['refreshed'], 'refreshAll runs even when gate is fresh');

// ---------------------------------------------------------------------------
section('empty friend list — gate still bumps');

$fetcher8 = new FakeFetcher();
[$svc8, $store8, $cache8, $config8] = makeSvc('empty', $fetcher8);
$r = $svc8->refreshStale();
assertEq(false, $r['gated'], 'empty install not gated path (ran batch)');
assertEq(0, $r['refreshed'], 'nothing to refresh');
assertTrue(!$config8->isStale(), 'gate bumped to prevent thrashing on empty install');

// ---------------------------------------------------------------------------
echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures}\n");
    exit(1);
}
echo "ALL OK\n";
