<?php

declare(strict_types=1);

/**
 * Stalk plugin — operator CLI refresh.
 *
 * Acts as a synthetic visitor: calls RefreshService::refreshStale() so the
 * admin-config interval is the single source of truth. Cron frequency can
 * safely be HIGHER than the configured interval — the gate will skip the
 * overshooting ticks.
 *
 * Wire into operator's own crontab (or systemd timer); the plugin itself
 * never invokes this script (plugin v1 contract forbids in-process cron).
 *
 * Cron example (every 30 minutes, with admin interval=3h):
 *   (asterisk)/30 (asterisk) (asterisk) (asterisk) (asterisk) /usr/bin/php
 *     /var/www/lazyblog/plugins/stalk/scripts/stalk-refresh.php
 *     >> /var/log/stalk.log 2>&1
 * (the literal asterisk form goes into crontab; written out here because
 *  `*<slash>` would close this PHP block comment early)
 *
 * Exit codes:
 *   0  on success (even partial — per-friend errors stay in friends.json)
 *   1  on misuse (called from web SAPI, missing autoload, no storage dir)
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "stalk-refresh: CLI-only\n");
    exit(1);
}

$repoRoot   = dirname(__DIR__, 3);
$autoload   = $repoRoot . '/vendor/autoload.php';
$storageDir = $repoRoot . '/content/plugins/stalk';

if (!is_file($autoload)) {
    fwrite(STDERR, "stalk-refresh: vendor/autoload.php not found at {$autoload}\n");
    exit(1);
}
require $autoload;

// Match public/index.php — load .env (safeLoad = no-op if absent).
if (is_file($repoRoot . '/.env')) {
    Dotenv\Dotenv::createImmutable($repoRoot)->safeLoad();
}

if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0o755, recursive: true);
}
if (!is_dir($storageDir)) {
    fwrite(STDERR, "stalk-refresh: cannot create storage dir {$storageDir}\n");
    exit(1);
}

$src = dirname(__DIR__) . '/src';
require_once $src . '/FriendStore.php';
require_once $src . '/PostCache.php';
require_once $src . '/Config.php';
require_once $src . '/HostGuard.php';
require_once $src . '/FeedFetcher.php';
require_once $src . '/FeedParser.php';
require_once $src . '/RefreshService.php';

use Plugins\Stalk\Config;
use Plugins\Stalk\FeedFetcher;
use Plugins\Stalk\FeedParser;
use Plugins\Stalk\FriendStore;
use Plugins\Stalk\PostCache;
use Plugins\Stalk\RefreshService;

$svc = new RefreshService(
    new FriendStore($storageDir),
    new PostCache($storageDir),
    new Config($storageDir),
    new FeedFetcher(),
    new FeedParser(),
);

$r = $svc->refreshStale();
$gated = ($r['gated'] ?? false) ? '1' : '0';
fwrite(
    STDOUT,
    sprintf(
        "[stalk] refreshed=%d errored=%d skipped=%d gated=%s\n",
        (int) $r['refreshed'],
        (int) $r['errored'],
        (int) $r['skipped'],
        $gated,
    ),
);
exit(0);
