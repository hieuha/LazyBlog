<?php

declare(strict_types=1);

namespace Plugins\Graffiti;

use App\Config;

/**
 * HMAC-signed cookie that identifies the current visitor as a verified
 * friend of this blog. Set by /graffiti/visit when a valid incoming
 * token shows up in the URL; read by the spray controls + cross-spray
 * endpoint to gate friend-only surfaces.
 *
 * Cookie shape: `<friend_id>:<expires>:<sig>` where
 *   sig = HMAC-SHA256(friend_id . ':' . expires, per-blog secret).
 *
 * The per-blog secret derives from SITE_URL + ADMIN_PASSWORD_HASH so:
 *   - secret is deterministic across requests on the same install
 *   - rotating the admin password invalidates every friend session
 *   - two different blogs cannot forge each other's cookies even if
 *     they share infrastructure
 */
final class GraffitiSession
{
    private const COOKIE = 'gf_visit';
    private const TTL_SECONDS = 86400;

    public static function set(string $friendId): void
    {
        if ($friendId === '') {
            return;
        }
        $expires = time() + self::TTL_SECONDS;
        $payload = $friendId . ':' . $expires;
        $sig = hash_hmac('sha256', $payload, self::secret());
        $value = $payload . ':' . $sig;

        setcookie(self::COOKIE, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => self::isHttps(),
        ]);
        // Make the value available to the rest of the current request too,
        // so a magic-link visit can immediately render gated UI in the
        // same response without a fresh round-trip.
        $_COOKIE[self::COOKIE] = $value;
    }

    /** Returns the friend id when the visitor presents a valid cookie. */
    public static function current(): ?string
    {
        $raw = (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($raw === '') {
            return null;
        }
        $parts = explode(':', $raw, 3);
        if (count($parts) !== 3) {
            return null;
        }
        [$friendId, $expires, $sig] = $parts;
        if ((int) $expires < time()) {
            return null;
        }
        $expected = hash_hmac('sha256', $friendId . ':' . $expires, self::secret());
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        return $friendId;
    }

    public static function clear(): void
    {
        setcookie(self::COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => self::isHttps(),
        ]);
        unset($_COOKIE[self::COOKIE]);
    }

    private static function secret(): string
    {
        return hash(
            'sha256',
            (string) (Config::get('ADMIN_PASSWORD_HASH') ?? '')
            . '|' . (string) (Config::get('SITE_URL') ?? '')
            . '|graffiti-friend-session-v1'
        );
    }

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
}
