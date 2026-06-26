<?php

declare(strict_types=1);

/**
 * Stalk plugin — HostGuard SSRF blocklist.
 *
 * Single source of truth for forbidden hosts; this test pins the policy.
 *
 * Run: php tests/test-stalk-host-guard.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../plugins/stalk/src/HostGuard.php';

use Plugins\Stalk\HostGuard;

$failures = 0;
function section(string $n): void { echo "==> {$n}\n"; }
function ok(string $m): void      { echo "  ok: {$m}\n"; }
function fail(string $m): void
{
    global $failures;
    $failures++;
    fwrite(STDERR, "  FAIL: {$m}\n");
}
function blocked(string $h): void
{
    HostGuard::isForbidden($h) ? ok("blocked: {$h}") : fail("should block: {$h}");
}
function allowed(string $h): void
{
    HostGuard::isForbidden($h) ? fail("should allow: {$h}") : ok("allowed: {$h}");
}

// ---------------------------------------------------------------------------
section('Empty / well-known loopback names');

blocked('');
blocked('localhost');
blocked('LOCALHOST');                // case-insensitive
blocked('localhost.localdomain');
blocked('ip6-localhost');
blocked('ip6-loopback');

// ---------------------------------------------------------------------------
section('IPv4 loopback / unspecified');

blocked('127.0.0.1');
blocked('127.1.2.3');                // anywhere in 127/8
blocked('0.0.0.0');
blocked('0.1.2.3');                  // anywhere in 0/8

// ---------------------------------------------------------------------------
section('IPv4 private + link-local + AWS metadata');

blocked('10.0.0.1');
blocked('10.255.255.255');
blocked('172.16.0.1');
blocked('172.20.0.1');
blocked('172.31.255.254');
blocked('192.168.1.1');
blocked('169.254.169.254');          // AWS metadata IP — must block
blocked('169.254.42.42');

// ---------------------------------------------------------------------------
section('IPv4 — outside the private/loopback ranges should be allowed');

allowed('8.8.8.8');
allowed('1.1.1.1');
allowed('203.0.113.42');             // TEST-NET-3 documentation range
allowed('172.15.0.1');               // just below 172.16
allowed('172.32.0.1');               // just above 172.31

// ---------------------------------------------------------------------------
section('IPv6 — loopback, unspecified, ULA, link-local');

blocked('::1');
blocked('[::1]');                    // bracketed form (as in URL host)
blocked('::');
blocked('[::]');
blocked('fc00::1');                  // ULA
blocked('fd12:3456:789a::1');        // ULA
blocked('fe80::1');                  // link-local
blocked('fe80::abcd:1234');

// ---------------------------------------------------------------------------
section('IPv6 — globally routable should be allowed');

allowed('2001:db8::1');              // documentation prefix — treated as global
allowed('[2606:4700:4700::1111]');   // bracketed cloudflare IPv6

// ---------------------------------------------------------------------------
section('Plain hostnames pass through (resolved at fetch time)');

allowed('example.com');
allowed('blog.friend.example');
allowed('CDN-Origin.example.org');

// ---------------------------------------------------------------------------
echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures}\n");
    exit(1);
}
echo "ALL OK\n";
