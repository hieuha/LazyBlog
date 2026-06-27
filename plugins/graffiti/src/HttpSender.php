<?php

declare(strict_types=1);

namespace Plugins\Graffiti;

/**
 * Thin curl wrapper for outbound HTTP from the outbox + catalogue fetcher.
 *
 * - HTTPS enforced unless GRAFFITI_DEV=1 (consistent with Inbox HTTPS gate)
 * - Total timeout 5s, connect timeout 3s — never block an admin page render
 * - Returns a normalised result so callers don't have to parse curl errors
 *
 * Pure transport; no retry / backoff logic here. The Outbox owns retry state.
 *
 * @phpstan-type HttpResult array{
 *   status: int,
 *   body: string,
 *   error: ?string,
 *   transport_failed: bool,
 * }
 */
final class HttpSender
{
    private const TOTAL_TIMEOUT = 5;
    private const CONNECT_TIMEOUT = 3;

    /**
     * @param array<string,mixed> $jsonBody
     * @return HttpResult
     */
    public static function postJson(string $url, array $jsonBody, ?string $userAgent = null): array
    {
        if (!self::isHttpsOrDev($url)) {
            return self::result(0, '', 'url_not_https', true);
        }
        $url = self::rewriteForDocker($url);

        $payload = json_encode($jsonBody, JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return self::result(0, '', 'json_encode_failed', true);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return self::result(0, '', 'curl_init_failed', true);
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: ' . ($userAgent ?? self::defaultUserAgent()),
            ],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch) ?: null;
        curl_close($ch);

        if ($body === false) {
            return self::result($status, '', $error ?? 'curl_failed', true);
        }
        return self::result($status, (string) $body, null, false);
    }

    /** @return HttpResult */
    public static function get(string $url, ?string $userAgent = null): array
    {
        if (!self::isHttpsOrDev($url)) {
            return self::result(0, '', 'url_not_https', true);
        }
        $url = self::rewriteForDocker($url);

        $ch = curl_init($url);
        if ($ch === false) {
            return self::result(0, '', 'curl_init_failed', true);
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: ' . ($userAgent ?? self::defaultUserAgent()),
            ],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch) ?: null;
        curl_close($ch);

        if ($body === false) {
            return self::result($status, '', $error ?? 'curl_failed', true);
        }
        return self::result($status, (string) $body, null, false);
    }

    /**
     * In dev mode (GRAFFITI_DEV=1), rewrite outbound URLs pointing at
     * `localhost` / `127.0.0.1` to `host.docker.internal` so a container
     * actually reaches the host instead of looping back into itself. No-op
     * outside dev, and no-op for any non-loopback host.
     */
    private static function rewriteForDocker(string $url): string
    {
        if (($_ENV['GRAFFITI_DEV'] ?? '') !== '1') {
            return $url;
        }
        $parsed = parse_url($url);
        if ($parsed === false) {
            return $url;
        }
        $host = strtolower((string) ($parsed['host'] ?? ''));
        if ($host !== 'localhost' && $host !== '127.0.0.1') {
            return $url;
        }
        return (string) preg_replace(
            '#://(localhost|127\.0\.0\.1)#i',
            '://host.docker.internal',
            $url,
            1
        );
    }

    /**
     * Default outbound User-Agent — distinct prefix `LazyBlog-` so a
     * sysadmin can allowlist every LazyBlog cross-blog request with a
     * single regex (`^LazyBlog-`). Mirrors the Stalk feed fetcher's
     * RFC-style `name/version (+identifier)` shape so the receiving
     * blog can both filter and contact the source if needed.
     */
    private static function defaultUserAgent(): string
    {
        $siteUrl = (string) (\App\Config::get('SITE_URL') ?: 'unknown');
        return 'LazyBlog-Graffiti/0.1 (+' . $siteUrl . ')';
    }

    private static function isHttpsOrDev(string $url): bool
    {
        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['scheme'])) {
            return false;
        }
        $scheme = strtolower((string) $parsed['scheme']);
        if ($scheme === 'https') {
            return true;
        }
        return $scheme === 'http' && ($_ENV['GRAFFITI_DEV'] ?? '') === '1';
    }

    /** @return HttpResult */
    private static function result(int $status, string $body, ?string $error, bool $transportFailed): array
    {
        return [
            'status' => $status,
            'body' => $body,
            'error' => $error,
            'transport_failed' => $transportFailed,
        ];
    }
}
