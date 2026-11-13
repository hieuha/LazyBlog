<?php

declare(strict_types=1);

/**
 * Graffiti plugin — PeerUrlGuard SSRF policy for outbound peer requests.
 *
 * Run: php tests/test-graffiti-peer-url-guard.php
 *
 * Covers:
 *   - literal loopback / private / link-local / CGNAT / mapped-v6 IPs denied
 *   - hostnames resolving to ANY internal address denied (resolver injected)
 *   - public hosts allowed and pinned to the vetted IPs (CURLOPT_RESOLVE)
 *   - GRAFFITI_DEV=1 allows only the documented loopback names, still
 *     blocks docker-internal hosts / private IPs
 *   - HttpSender refuses forbidden hosts before any network I/O
 *
 * Exits non-zero on any failure; prints `ALL OK` on full pass.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../plugins/graffiti/src/PeerUrlGuard.php';
require __DIR__ . '/../plugins/graffiti/src/HttpSender.php';

use Plugins\Graffiti\HttpSender;
use Plugins\Graffiti\PeerUrlGuard;

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

/** Fake DNS: host → IPs. Unknown hosts resolve to nothing. */
function fakeDns(array $map): callable
{
    return static fn (string $host): array => $map[$host] ?? [];
}

unset($_ENV['GRAFFITI_DEV']);

// ---------------------------------------------------------------------------
section('Literal IPs in blocked ranges are denied');

foreach ([
    'https://127.0.0.1/', 'https://10.1.2.3/', 'https://172.25.1.1:18096/',
    'https://192.168.1.10/', 'https://169.254.169.254/latest/meta-data/',
    'https://100.112.31.50:18081/', 'https://0.0.0.0/', 'https://224.0.0.1/',
    'https://[::1]/', 'https://[::ffff:127.0.0.1]/', 'https://[fd00::1]/',
    'https://[fe80::1]/', 'https://[64:ff9b::a00:1]/',
] as $url) {
    assertEq('host_forbidden', PeerUrlGuard::check($url, fakeDns([]))['reason'], "deny {$url}");
}

// ---------------------------------------------------------------------------
section('Hostnames are resolved; any internal record denies');

$dns = fakeDns([
    'peer-b'            => ['172.25.1.3'],
    'rebind.example'    => ['93.184.216.34', '127.0.0.1'],
    'v6only.example'    => ['::1'],
    'public.example'    => ['93.184.216.34'],
    'dual.example'      => ['93.184.216.34', '2606:2800:220:1::1'],
]);
assertEq('host_forbidden', PeerUrlGuard::check('https://peer-b/graffiti/health', $dns)['reason'], 'docker service name → private IP denied');
assertEq('host_forbidden', PeerUrlGuard::check('https://rebind.example/', $dns)['reason'], 'mixed public+loopback records denied');
assertEq('host_forbidden', PeerUrlGuard::check('https://v6only.example/', $dns)['reason'], 'AAAA loopback denied');
assertEq('host_unresolvable', PeerUrlGuard::check('https://nx.example/', $dns)['reason'], 'unresolvable host denied');
assertEq('host_missing', PeerUrlGuard::check('not a url', $dns)['reason'], 'URL without host denied');
assertEq('host_forbidden', PeerUrlGuard::check('https://localhost/', fakeDns(['localhost' => ['127.0.0.1']]))['reason'], 'localhost denied outside dev');

// ---------------------------------------------------------------------------
section('Public hosts allowed and pinned');

$r = PeerUrlGuard::check('https://public.example/graffiti/health', $dns);
assertEq(true, $r['ok'], 'public host allowed');
assertEq('public.example:443:93.184.216.34', $r['pin'], 'pinned to vetted IPv4 on default https port');
$r = PeerUrlGuard::check('https://dual.example:8443/', $dns);
assertEq('dual.example:8443:93.184.216.34,[2606:2800:220:1::1]', $r['pin'], 'pin lists every vetted address, v6 bracketed');
$r = PeerUrlGuard::check('https://93.184.216.34/', $dns);
assertEq(true, $r['ok'], 'public literal IP allowed');

// ---------------------------------------------------------------------------
section('GRAFFITI_DEV=1 only relaxes the documented loopback names');

$_ENV['GRAFFITI_DEV'] = '1';
foreach (['http://localhost:8081', 'http://127.0.0.1:8081', 'http://host.docker.internal:8081'] as $url) {
    $r = PeerUrlGuard::check($url, $dns);
    assertEq([true, null], [$r['ok'], $r['pin']], "dev allows {$url} (no pin)");
}
assertEq('host_forbidden', PeerUrlGuard::check('http://peer-b:80/', $dns)['reason'], 'dev still denies docker service names');
assertEq('host_forbidden', PeerUrlGuard::check('http://172.25.1.1:18096/', $dns)['reason'], 'dev still denies private IPs');
assertEq('host_forbidden', PeerUrlGuard::check('http://169.254.169.254/', $dns)['reason'], 'dev still denies metadata');
unset($_ENV['GRAFFITI_DEV']);

// ---------------------------------------------------------------------------
section('HttpSender refuses forbidden targets without connecting');

$res = HttpSender::get('https://127.0.0.1:1/graffiti/health');
assertEq(['host_forbidden', true], [$res['error'], $res['transport_failed']], 'get() blocked by guard');
$res = HttpSender::postJson('https://169.254.169.254/graffiti/receive', ['x' => 1]);
assertEq(['host_forbidden', true], [$res['error'], $res['transport_failed']], 'postJson() blocked by guard');

echo $failures === 0 ? "\nALL OK\n" : "\n{$failures} FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
