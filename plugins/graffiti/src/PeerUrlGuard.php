<?php

declare(strict_types=1);

namespace Plugins\Graffiti;

/**
 * SSRF guard for every outbound graffiti request (health probe, catalogue
 * fetch, outbox delivery).
 *
 * Peer URLs come from operator input and from peer-supplied invite blocks,
 * so the destination host is untrusted. Before cURL runs, the host is
 * resolved and EVERY address it maps to is checked against loopback /
 * private / link-local / CGNAT / multicast / reserved ranges. The vetted
 * addresses are returned as a `CURLOPT_RESOLVE` pin so cURL cannot
 * re-resolve to a different (internal) address between check and connect
 * (DNS rebinding).
 *
 * Mirrors the stalk plugin's `HostGuard` policy but also covers resolved
 * hostnames, not only literal IPs. Kept plugin-local because plugins load
 * independently — graffiti must not depend on stalk being enabled.
 *
 * Dev exception: with GRAFFITI_DEV=1 the documented two-blog docker loop
 * (`http://localhost:8081` → rewritten to `host.docker.internal`) must keep
 * working, so exactly those loopback names are allowed. Every other
 * internal target (docker service names, 172.x, 169.254.x) stays blocked
 * even in dev.
 */
final class PeerUrlGuard
{
    private const DEV_LOOPBACK_HOSTS = ['localhost', '127.0.0.1', 'host.docker.internal'];

    private const BLOCKED_CIDRS = [
        // IPv4
        '0.0.0.0/8',        // "this" network
        '10.0.0.0/8',       // private
        '100.64.0.0/10',    // CGNAT (also Tailscale)
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local incl. cloud metadata
        '172.16.0.0/12',    // private (docker default bridges)
        '192.0.0.0/24',     // IETF protocol assignments
        '192.168.0.0/16',   // private
        '198.18.0.0/15',    // benchmarking
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved + broadcast
        // IPv6
        '::/128',           // unspecified
        '::1/128',          // loopback
        '::ffff:0:0/96',    // IPv4-mapped — would smuggle any v4 target
        '64:ff9b::/96',     // NAT64 — same
        'fc00::/7',         // unique local
        'fe80::/10',        // link-local
        'ff00::/8',         // multicast
    ];

    /**
     * @param ?callable(string):list<string> $resolver host → list of IPs;
     *        injectable for tests, defaults to system DNS.
     * @return array{ok:bool, reason:?string, pin:?string}
     *         `pin` is a `host:port:addr[,addr]` CURLOPT_RESOLVE entry, or
     *         null when no pin applies (dev loopback).
     */
    public static function check(string $url, ?callable $resolver = null): array
    {
        $parsed = parse_url($url);
        $host = strtolower((string) ($parsed['host'] ?? ''));
        if ($parsed === false || $host === '') {
            return self::deny('host_missing');
        }
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        $port = (int) ($parsed['port'] ?? ($scheme === 'http' ? 80 : 443));

        $bare = trim($host, '[]');
        if (($_ENV['GRAFFITI_DEV'] ?? '') === '1' && in_array($bare, self::DEV_LOOPBACK_HOSTS, true)) {
            return ['ok' => true, 'reason' => null, 'pin' => null];
        }

        $ips = @inet_pton($bare) !== false
            ? [$bare]
            : ($resolver ?? [self::class, 'resolve'])($bare);
        if ($ips === []) {
            return self::deny('host_unresolvable');
        }
        foreach ($ips as $ip) {
            if (self::isBlockedIp($ip)) {
                return self::deny('host_forbidden');
            }
        }

        $addrs = array_map(
            static fn (string $ip): string => str_contains($ip, ':') ? "[{$ip}]" : $ip,
            $ips,
        );
        return ['ok' => true, 'reason' => null, 'pin' => "{$bare}:{$port}:" . implode(',', $addrs)];
    }

    /** True when the literal IP falls in any blocked range (unparseable → blocked). */
    public static function isBlockedIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true;
        }
        foreach (self::BLOCKED_CIDRS as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> every A + AAAA address for the host */
    private static function resolve(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        $aaaa = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($aaaa as $rec) {
            if (!empty($rec['ipv6'])) {
                $ips[] = (string) $rec['ipv6'];
            }
        }
        return array_values(array_unique($ips));
    }

    private static function inCidr(string $packed, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $netPacked = (string) inet_pton($net);
        if (strlen($netPacked) !== strlen($packed)) {
            return false;
        }
        $bits = (int) $bits;
        $whole = intdiv($bits, 8);
        if (strncmp($packed, $netPacked, $whole) !== 0) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($packed[$whole]) & $mask) === (ord($netPacked[$whole]) & $mask);
    }

    /** @return array{ok:false, reason:string, pin:null} */
    private static function deny(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason, 'pin' => null];
    }
}
