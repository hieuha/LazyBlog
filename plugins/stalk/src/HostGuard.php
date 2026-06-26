<?php

declare(strict_types=1);

namespace Plugins\Stalk;

/**
 * Blocklist for friend blog hostnames — keeps SSRF-style requests off the
 * local network and cloud metadata endpoints.
 *
 * Used in two places:
 *   - `StalkPlugin::handleAdd` — pre-fetch check on operator-provided URL.
 *   - `FeedFetcher::guardEffectiveHost` — post-fetch check on the URL cURL
 *     actually landed on after following redirects (closes the
 *     "innocent.example 302 → 169.254.169.254" pivot).
 *
 * Single source of truth for the blocked-host policy.
 */
final class HostGuard
{
    /** Case-insensitive hostnames that always resolve to loopback. */
    private const FORBIDDEN_NAMES = [
        'localhost',
        'localhost.localdomain',
        'ip6-localhost',
        'ip6-loopback',
    ];

    /**
     * True if the host string points at loopback / private / link-local /
     * unspecified. Hostname strings that don't parse as literal IPs are
     * accepted here — DNS resolves at fetch time, and we re-check the
     * effective URL post-redirect.
     */
    public static function isForbidden(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return true;
        }
        if (in_array($host, self::FORBIDDEN_NAMES, true)) {
            return true;
        }

        // Strip IPv6 brackets if literal-IP host comes from a URL.
        $candidate = $host;
        if (str_starts_with($candidate, '[') && str_ends_with($candidate, ']')) {
            $candidate = substr($candidate, 1, -1);
        }

        $packed = @inet_pton($candidate);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return self::isIPv4Blocked($packed);
        }
        if (strlen($packed) === 16) {
            return self::isIPv6Blocked($packed);
        }
        return false;
    }

    /**
     * Blocks 0.0.0.0/8, 10/8, 127/8, 169.254/16 (link-local incl. AWS
     * metadata), 172.16/12 (private), 192.168/16 (private).
     */
    private static function isIPv4Blocked(string $packed): bool
    {
        $b = array_values(unpack('C4', $packed));
        return ($b[0] === 0)
            || ($b[0] === 10)
            || ($b[0] === 127)
            || ($b[0] === 169 && $b[1] === 254)
            || ($b[0] === 172 && $b[1] >= 16 && $b[1] <= 31)
            || ($b[0] === 192 && $b[1] === 168);
    }

    /**
     * Blocks ::, ::1, fc00::/7 (ULA), fe80::/10 (link-local).
     */
    private static function isIPv6Blocked(string $packed): bool
    {
        // :: (unspecified)
        if ($packed === str_repeat("\0", 16)) {
            return true;
        }
        // ::1 (loopback)
        if ($packed === str_repeat("\0", 15) . "\1") {
            return true;
        }
        $first = ord($packed[0]);
        // fc00::/7 (Unique Local Address — top 7 bits are 1111110)
        if ($first === 0xFC || $first === 0xFD) {
            return true;
        }
        // fe80::/10 (link-local — first byte 0xFE, top 2 bits of second = 10)
        if ($first === 0xFE && (ord($packed[1]) & 0xC0) === 0x80) {
            return true;
        }
        return false;
    }
}
