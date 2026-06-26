<?php

declare(strict_types=1);

namespace Plugins\Stalk;

use App\Config as AppConfig;
use RuntimeException;

/**
 * Single-purpose cURL wrapper for fetching friend feeds.
 *
 * Caller contract:
 *   - `fetch(string $url): string` — body bytes or throw.
 *   - `fetchMany(array $urls): array` — parallel multi-fetch, one result row
 *     per input label.
 *
 * Guarantees on every request:
 *   - HTTP/HTTPS only (scheme guard rejects pre-cURL).
 *   - 5s connect + 5s total timeout.
 *   - Body size hard-capped at 512KB via CURLOPT_PROGRESSFUNCTION abort.
 *   - Max 3 redirects followed.
 *   - Distinct UA: `LazyBlog-Stalk/0.1.0 (+{SITE_URL})`.
 *   - Only 200 OK passes; any other status throws.
 *
 * Failures bubble as RuntimeException so the caller (RefreshService) can
 * stash a friendly message in the friend's `last_error`.
 */
class FeedFetcher
{
    private const TIMEOUT_SECONDS    = 5;
    private const MAX_BODY_BYTES     = 512 * 1024;
    private const MAX_REDIRECTS      = 3;
    private const USER_AGENT_VERSION = '0.1.0';

    public function fetch(string $url): string
    {
        $this->guardScheme($url);
        $ch = $this->initHandle($url);
        $body = curl_exec($ch);
        $err  = curl_errno($ch) !== 0 ? curl_error($ch) : '';
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effective = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("fetch failed: " . ($err !== '' ? $err : 'transport error'));
        }
        if ($code !== 200) {
            throw new RuntimeException("unexpected HTTP {$code}");
        }
        $body = (string) $body;
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new RuntimeException('response too large');
        }
        $this->guardEffectiveHost($effective);
        return $body;
    }

    /**
     * Parallel multi-fetch via curl_multi_*.
     *
     * @param array<string,string> $urls label => url
     * @return array<string,array{ok:bool,body?:string,error?:string}>
     */
    public function fetchMany(array $urls): array
    {
        $mh = curl_multi_init();
        $handles = [];          // label => ch
        $bodies  = [];          // label => string (assembled in progress fn? — no, we just rely on RETURNTRANSFER)

        foreach ($urls as $label => $url) {
            try {
                $this->guardScheme($url);
            } catch (\Throwable $e) {
                $bodies[$label] = ['ok' => false, 'error' => $e->getMessage()];
                continue;
            }
            $ch = $this->initHandle($url);
            $handles[$label] = $ch;
            curl_multi_add_handle($mh, $ch);
        }

        // Drive the multi loop until all transfers complete (curl_multi_exec
        // returns CURLM_OK and $still_running drops to 0).
        do {
            $status = curl_multi_exec($mh, $stillRunning);
            if ($stillRunning) {
                // Block until something happens; -1ms means "use sensible default".
                curl_multi_select($mh, 1.0);
            }
        } while ($stillRunning && $status === CURLM_OK);

        foreach ($handles as $label => $ch) {
            $body = curl_multi_getcontent($ch);
            $err  = curl_errno($ch) !== 0 ? curl_error($ch) : '';
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $effective = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($body === null || $body === false || $body === '') {
                $bodies[$label] = ['ok' => false, 'http_code' => $code, 'error' => $err !== '' ? $err : "empty body (HTTP {$code})"];
                continue;
            }
            if ($code !== 200) {
                $bodies[$label] = ['ok' => false, 'http_code' => $code, 'error' => "unexpected HTTP {$code}"];
                continue;
            }
            if (strlen($body) > self::MAX_BODY_BYTES) {
                $bodies[$label] = ['ok' => false, 'http_code' => $code, 'error' => 'response too large'];
                continue;
            }
            try {
                $this->guardEffectiveHost($effective);
            } catch (\Throwable $e) {
                $bodies[$label] = ['ok' => false, 'http_code' => $code, 'error' => $e->getMessage()];
                continue;
            }
            $bodies[$label] = ['ok' => true, 'http_code' => $code, 'body' => $body];
        }

        curl_multi_close($mh);

        // Preserve original input order in the result map.
        $ordered = [];
        foreach (array_keys($urls) as $label) {
            $ordered[$label] = $bodies[$label] ?? ['ok' => false, 'http_code' => 0, 'error' => 'no result'];
        }
        return $ordered;
    }

    private function guardScheme(string $url): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException("unsupported URL scheme: " . (is_string($scheme) ? $scheme : 'null'));
        }
    }

    /**
     * After cURL follows redirects, re-check the final URL did NOT land on a
     * loopback / private / link-local host. Closes the
     * `innocent.example 302 → 169.254.169.254` pivot. Blocklist lives in
     * `HostGuard` — kept aligned with the pre-fetch admin check.
     */
    private function guardEffectiveHost(string $effectiveUrl): void
    {
        if ($effectiveUrl === '') {
            return;
        }
        $scheme = parse_url($effectiveUrl, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException("redirect to unsupported scheme: " . (is_string($scheme) ? $scheme : 'null'));
        }
        $host = strtolower((string) parse_url($effectiveUrl, PHP_URL_HOST));
        if (HostGuard::isForbidden($host)) {
            throw new RuntimeException('redirect to forbidden host: ' . $host);
        }
    }

    /** @return \CurlHandle */
    private function initHandle(string $url)
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_URL              => $url,
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_FOLLOWLOCATION   => true,
            CURLOPT_MAXREDIRS        => self::MAX_REDIRECTS,
            CURLOPT_CONNECTTIMEOUT   => self::TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT          => self::TIMEOUT_SECONDS,
            // _STR variants were added in PHP 8.3; we still ship on 8.2.
            // The bitmask form CURLOPT_PROTOCOLS / CURLOPT_REDIR_PROTOCOLS
            // is available on every supported PHP and gives the same guard.
            CURLOPT_PROTOCOLS        => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS  => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT        => $this->userAgent(),
            CURLOPT_NOPROGRESS       => false,
            CURLOPT_PROGRESSFUNCTION => static function ($_ch, int $dlTotal, int $dlNow): int {
                return $dlNow > self::MAX_BODY_BYTES ? 1 : 0;
            },
            // Defense-in-depth — do NOT advertise gzip so the server cannot
            // ship a compression-bomb that explodes after the progress check.
            CURLOPT_ENCODING         => 'identity',
        ]);
        return $ch;
    }

    private function userAgent(): string
    {
        $siteUrl = (string) (AppConfig::get('SITE_URL') ?: 'unknown');
        return 'LazyBlog-Stalk/' . self::USER_AGENT_VERSION . ' (+' . $siteUrl . ')';
    }
}
