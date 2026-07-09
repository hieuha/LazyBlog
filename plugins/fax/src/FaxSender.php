<?php

declare(strict_types=1);

namespace Plugins\Fax;

use App\Config;

/**
 * Thin curl wrapper that POSTs a fax to the FaxxMe inbound webhook.
 *
 * The webhook speaks `application/x-www-form-urlencoded` with a bearer token
 * — the server-side equivalent of the documented:
 *
 *   curl -X POST https://.../api/fax/inbound \
 *     -H "Authorization: Bearer fxwh_…" \
 *     --data-urlencode "body=…" --data-urlencode "name=…" \
 *     --data-urlencode "post=…"  --data-urlencode "url=…"
 *
 * HTTPS is required (the token is a secret in the header). Timeouts are kept
 * short so a slow webhook never hangs the reader's request. Returns a
 * normalised result so the caller can map HTTP status → reader-facing copy.
 *
 * @phpstan-type FaxResult array{status:int,body:string,error:?string,transport_failed:bool}
 */
final class FaxSender
{
    private const TOTAL_TIMEOUT   = 8;
    private const CONNECT_TIMEOUT = 4;

    /**
     * @param array{body:string,name:string,post:string,url:string} $fields
     * @return FaxResult
     */
    public function send(string $endpoint, string $token, array $fields): array
    {
        if (!self::isHttps($endpoint)) {
            return self::result(0, '', 'endpoint_not_https', true);
        }

        $ch = curl_init($endpoint);
        if ($ch === false) {
            return self::result(0, '', 'curl_init_failed', true);
        }

        // http_build_query with RFC3986 encodes spaces as %20 etc — the same
        // wire format as curl's --data-urlencode.
        $payload = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'User-Agent: LazyBlog-Fax/1.0 (+' . (Config::get('SITE_URL') ?: 'unknown') . ')',
            ],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($ch) ?: null;
        curl_close($ch);

        if ($body === false) {
            return self::result($status, '', $error ?? 'curl_failed', true);
        }
        return self::result($status, (string) $body, null, false);
    }

    private static function isHttps(string $url): bool
    {
        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['scheme'])) {
            return false;
        }
        return strtolower((string) $parsed['scheme']) === 'https';
    }

    /** @return FaxResult */
    private static function result(int $status, string $body, ?string $error, bool $transportFailed): array
    {
        return [
            'status'           => $status,
            'body'             => $body,
            'error'            => $error,
            'transport_failed' => $transportFailed,
        ];
    }
}
