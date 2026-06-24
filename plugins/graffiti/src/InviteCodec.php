<?php

declare(strict_types=1);

namespace Plugins\Graffiti;

use RuntimeException;

/**
 * Friend-handshake invite block codec.
 *
 * An invite is a small JSON object base64url-encoded so the operator can
 * paste the whole payload through any chat channel without escaping
 * trouble. The shape is intentionally minimal:
 *
 *   { "v": 1, "blog_url": "...", "handle": "...",
 *     "endpoint": "...", "token": "..." }
 *
 * Decode applies strict schema + URL validation (https only) so a malformed
 * paste fails loudly instead of writing garbage into `friends.json`.
 */
final class InviteCodec
{
    public const VERSION = 1;

    /** @param array{blog_url:string,handle:string,endpoint:string,token:string} $data */
    public static function encode(array $data): string
    {
        $payload = [
            'v'        => self::VERSION,
            'blog_url' => $data['blog_url'],
            'handle'   => $data['handle'],
            'endpoint' => $data['endpoint'],
            'token'    => $data['token'],
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('invite encode: json_encode failed');
        }
        return TokenGenerator::base64Url($json);
    }

    /**
     * @return array{v:int,blog_url:string,handle:string,endpoint:string,token:string}
     * @throws RuntimeException on any schema / format violation
     */
    public static function decode(string $block): array
    {
        $block = trim($block);
        if ($block === '') {
            throw new RuntimeException('invite empty');
        }

        // Base64url → raw bytes. strtr undoes URL-safe substitutions; padding
        // restored to a multiple of 4 because base64_decode is strict about it.
        $padded = strtr($block, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $raw = base64_decode($padded, true);
        if ($raw === false) {
            throw new RuntimeException('invite not valid base64url');
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('invite payload not JSON');
        }

        foreach (['v', 'blog_url', 'handle', 'endpoint', 'token'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new RuntimeException("invite missing field: {$key}");
            }
        }
        if ((int) $data['v'] !== self::VERSION) {
            throw new RuntimeException('invite version unsupported: ' . $data['v']);
        }

        $blogUrl  = self::requireHttpsUrl((string) $data['blog_url'], 'blog_url');
        $endpoint = self::requireHttpsUrl((string) $data['endpoint'], 'endpoint');
        $handle   = trim((string) $data['handle']);
        $token    = (string) $data['token'];

        if ($handle === '' || strlen($handle) > 80) {
            throw new RuntimeException('invite handle empty or too long');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{20,}$/', $token)) {
            throw new RuntimeException('invite token malformed');
        }

        return [
            'v'        => self::VERSION,
            'blog_url' => $blogUrl,
            'handle'   => $handle,
            'endpoint' => $endpoint,
            'token'    => $token,
        ];
    }

    /**
     * Reject anything that is not a syntactically valid https URL. We allow
     * `http://` only when the GRAFFITI_DEV env flag is set so local
     * docker-compose loops between two instances on localhost still work.
     */
    private static function requireHttpsUrl(string $value, string $field): string
    {
        $parsed = parse_url($value);
        if ($parsed === false || empty($parsed['scheme']) || empty($parsed['host'])) {
            throw new RuntimeException("invite {$field} not a URL");
        }
        $scheme = strtolower((string) $parsed['scheme']);
        $devMode = ($_ENV['GRAFFITI_DEV'] ?? '') === '1';
        if ($scheme !== 'https' && !($devMode && $scheme === 'http')) {
            throw new RuntimeException("invite {$field} must use https");
        }
        return rtrim($value, '/');
    }
}
