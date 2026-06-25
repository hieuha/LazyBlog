<?php

declare(strict_types=1);

/**
 * Smoke test for App\Controllers\SeriesAssetController security guards.
 *
 * Run: php tests/test-series-asset-controller.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Controllers\SeriesAssetController;
use App\SeriesManifest;

$tmpRoot = sys_get_temp_dir() . '/lazyblog-test-asset-' . bin2hex(random_bytes(4));
mkdir($tmpRoot . '/series/legit-slug', 0775, true);
file_put_contents($tmpRoot . '/series/legit-slug/cover.webp', 'WEBPDATA');
file_put_contents($tmpRoot . '/series/legit-slug/manifest.yaml', "title: x\n");

register_shutdown_function(static function () use ($tmpRoot): void {
    if (is_dir($tmpRoot)) {
        @exec('rm -rf ' . escapeshellarg($tmpRoot));
    }
});

$m = new SeriesManifest($tmpRoot);
$ctl = new SeriesAssetController($m);

/**
 * Each guard case is a single dispatch — we measure success by whether the
 * output buffer contains the file bytes. The 5 guards (slug regex, file
 * regex, MIME allowlist, realpath jail, missing file) all fall through to
 * a `return` before readfile() runs, so output stays empty.
 */
$cases = [
    ['valid',         ['slug' => 'legit-slug', 'file' => 'cover.webp'],            true],
    ['slug-upper',    ['slug' => 'Legit-Slug', 'file' => 'cover.webp'],            false],
    ['slug-dotdot',   ['slug' => '..',         'file' => 'cover.webp'],            false],
    ['slug-empty',    ['slug' => '',           'file' => 'cover.webp'],            false],
    ['file-traversal',['slug' => 'legit-slug', 'file' => '../../../etc/passwd'],   false],
    ['file-yaml',     ['slug' => 'legit-slug', 'file' => 'manifest.yaml'],         false],
    ['file-php',      ['slug' => 'legit-slug', 'file' => 'cover.php'],             false],
    ['file-empty',    ['slug' => 'legit-slug', 'file' => ''],                      false],
    ['file-null',     ['slug' => 'legit-slug', 'file' => "cover\x00.webp"],        false],
    ['file-uppercase',['slug' => 'legit-slug', 'file' => 'cover.WEBP'],            false],
    ['missing-file',  ['slug' => 'legit-slug', 'file' => 'ghost.webp'],            false],
];
$failed = 0;
foreach ($cases as [$name, $params, $shouldServe]) {
    ob_start();
    @$ctl->serve($params);
    $body = ob_get_clean();
    $served = ($body === 'WEBPDATA');
    if ($shouldServe !== $served) {
        echo "FAIL: {$name} (expected " . ($shouldServe ? 'served' : 'blocked')
            . ', got ' . ($served ? 'served' : 'blocked') . ")\n";
        $failed++;
    } else {
        echo "OK:   {$name}\n";
    }
}

if ($failed > 0) {
    echo "\n{$failed} guard case(s) FAILED.\n";
    exit(1);
}
echo "\nAll SeriesAssetController guards passed.\n";
