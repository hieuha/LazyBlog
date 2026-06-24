<?php

declare(strict_types=1);

namespace Plugins\Graffiti;

/**
 * Cryptographically random per-friend tokens.
 *
 * 32 bytes of entropy → base64url encoded (no padding) = 43 chars. Safe to
 * embed in a JSON invite block, copy-paste through any chat channel, and
 * compare with `hash_equals`. Per-friend rotation: the operator can revoke
 * a single friend without rotating tokens for all other friendships.
 */
final class TokenGenerator
{
    public static function generate(): string
    {
        return self::base64Url(random_bytes(32));
    }

    public static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
