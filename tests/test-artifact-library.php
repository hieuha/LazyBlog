<?php

declare(strict_types=1);

/**
 * Admin artifact library backing code:
 *   - ArtifactStore::all / replace / delete
 *   - ArtifactUsageScanner: which posts + /about embed which artifact
 *
 * Run: php tests/test-artifact-library.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\ArtifactStore;
use App\ArtifactUsageScanner;
use App\PostRepository;

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

$root = sys_get_temp_dir() . '/lazyblog-test-artifact-lib-' . bin2hex(random_bytes(4));
mkdir($root . '/posts', 0775, true);
register_shutdown_function(static function () use ($root): void {
    @exec('rm -rf ' . escapeshellarg($root));
});

function upload(string $root, string $bytes): string
{
    $p = $root . '/up-' . bin2hex(random_bytes(4));
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

// --- Store: list / replace / delete --------------------------------------
$store = new ArtifactStore($root);
check('all: empty store lists nothing', $store->all() === []);

$a = $store->save(upload($root, '<title>Alpha</title><p>v1</p>'), 'alpha.html');
touch((string) $store->path($a['id']), time() - 100);
$b = $store->save(upload($root, '<p>no title</p>'), 'beta.html');

$all = $store->all();
check('all: newest first', array_column($all, 'id') === [$b['id'], $a['id']], implode(',', array_column($all, 'id')));
check('all: title from <title>, falls back to id', $all[1]['title'] === 'Alpha' && $all[0]['title'] === $b['id']);
check('all: size reported', $all[1]['size'] === strlen('<title>Alpha</title><p>v1</p>'));

$r = $store->replace($a['id'], upload($root, '<title>Alpha v2</title><p>v2</p>'), 'whatever-name.html');
check('replace: keeps the id', $r['id'] === $a['id']);
check('replace: new bytes on disk', str_contains((string) file_get_contents((string) $store->path($a['id'])), 'v2'));
check('replace: still one file per artifact', count($store->all()) === 2);
check('replace: same validation as upload', rejects(fn () => $store->replace($a['id'], upload($root, 'x'), 'x.svg')));
check('replace: unknown id refused', rejects(fn () => $store->replace('ghost-000000', upload($root, '<p>x</p>'), 'x.html')));
check('replace: traversal id refused', rejects(fn () => $store->replace('../posts/x', upload($root, '<p>x</p>'), 'x.html')));

check('delete: removes file', $store->delete($b['id']) && $store->path($b['id']) === null);
check('delete: unknown id is false', $store->delete('ghost-000000') === false);
check('delete: traversal id is false', $store->delete('../posts/anything') === false);

// --- Usage scanner -------------------------------------------------------
file_put_contents($root . '/posts/2026-10-01-uses-alpha.md', <<<MD
---
title: Uses Alpha
date: 2026-10-01
---

::: artifact id="{$a['id']}" title="Alpha"
note
:::

::: artifact id="{$a['id']}"
:::
MD);
file_put_contents($root . '/posts/2026-10-02-draft-uses-other.md', <<<MD
---
title: Draft post
date: 2026-10-02
draft: true
---

::: artifact title="x" id="other-abc123"
:::

The text /artifacts/ghost-000000 is not an embed.
MD);
file_put_contents($root . '/about.md', "::: artifact id=\"other-abc123\"\n:::\n");

$scanner = new ArtifactUsageScanner(new PostRepository($root), $root . '/about.md');
$usage = $scanner->usageById();

check('usage: post found, duplicate embeds counted once', count($usage[$a['id']] ?? []) === 1
    && $usage[$a['id']][0]['title'] === 'Uses Alpha'
    && $usage[$a['id']][0]['editUrl'] === '/admin/edit/uses-alpha');
check('usage: attribute order does not matter, drafts flagged', ($usage['other-abc123'][0]['draft'] ?? null) === true);
check('usage: /about counted', ($usage['other-abc123'][1]['url'] ?? '') === '/about');
check('usage: bare path in prose is not usage', !isset($usage['ghost-000000']));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
