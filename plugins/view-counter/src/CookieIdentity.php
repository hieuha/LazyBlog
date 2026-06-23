<?php

declare(strict_types=1);

namespace Plugins\ViewCounter;

/**
 * Anonymous identity for view dedup.
 *
 * Reads `lz_uid` from the request cookie. If missing or malformed, mints a
 * fresh 32-hex-char random ID and emits a `Set-Cookie` header. The cookie
 * itself is opaque — no IP, no UA, no fingerprinting. Cleared cookies =
 * fresh identity = recounted view; documented behavior.
 *
 * MUST run before any output, i.e. before `Http::render()`. The post-view
 * event dispatch in PostController is placed accordingly.
 */
final class CookieIdentity
{
    public const COOKIE = 'lz_uid';

    /** 1 year in seconds. */
    private const TTL = 365 * 24 * 60 * 60;

    public static function getOrMint(): string
    {
        $existing = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/', $existing) === 1) {
            return $existing;
        }

        $id = bin2hex(random_bytes(16));

        // Mirror Auth.php's source of truth for the Secure flag so dev (HTTP)
        // and prod (HTTPS behind Caddy) both work without extra config.
        $secure = filter_var(
            $_ENV['SESSION_SECURE'] ?? 'false',
            FILTER_VALIDATE_BOOLEAN,
        );

        setcookie(self::COOKIE, $id, [
            'expires'  => time() + self::TTL,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // Reflect into $_COOKIE so any later read within this same request
        // sees the freshly-minted ID instead of triggering another mint.
        $_COOKIE[self::COOKIE] = $id;

        return $id;
    }
}
