<?php

declare(strict_types=1);

namespace Plugins\Graffiti;

use App\PostRepository;
use Throwable;

/**
 * Webhook endpoint: `POST /graffiti/receive`.
 *
 * Handle pipeline (in order — earliest reject is cheapest):
 *   1. HTTPS gate (bypassed when GRAFFITI_DEV=1)
 *   2. JSON parse + top-level schema (shape + size cap)
 *   3. Token auth → friend lookup → blog_url match
 *   4. Post slug exists on this blog
 *   5. Nonce dedup (rolling 24h per friend)
 *      ── Phase 5 inserts rate-limit check here ──
 *   6. Payload schema validation per type
 *   7. Append to graffiti.json + record nonce
 *
 * Process step is split from handle() for testability — pass a raw body
 * + isHttps flag, get back [status, body]. handle() only owns the IO
 * (php://input read + header/echo emission).
 */
final class Inbox
{
    public const MAX_BODY_BYTES = 16 * 1024;

    private FriendStore $friends;
    private GraffitiStore $store;
    private NonceCache $nonces;
    private StickerCatalogue $catalogue;
    private PostRepository $repo;
    private RateLimiter $rateLimiter;

    public function __construct(
        FriendStore $friends,
        GraffitiStore $store,
        NonceCache $nonces,
        StickerCatalogue $catalogue,
        PostRepository $repo,
        ?RateLimiter $rateLimiter = null,
    ) {
        $this->friends = $friends;
        $this->store = $store;
        $this->nonces = $nonces;
        $this->catalogue = $catalogue;
        $this->repo = $repo;
        $this->rateLimiter = $rateLimiter ?? new RateLimiter($friends, $store);
    }

    public function handle(): void
    {
        // Stream-capped read of the request body. file_get_contents() with
        // an empty stream context still respects the maxlen parameter so a
        // 1 GiB body never lands in memory.
        $raw = (string) (file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1) ?: '');
        $isHttps = self::isHttps();

        [$status, $body] = $this->process($raw, $isHttps);

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        if (isset($body['retry_after']) && is_int($body['retry_after'])) {
            header('Retry-After: ' . $body['retry_after']);
        }
        echo json_encode($body, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Pure-logic core of handle(). Returns `[httpStatusCode, responseArray]`.
     * Phase 5 will wedge a rate-limit check between nonce dedup and
     * payload validation; keep the call shape stable.
     *
     * @return array{0:int,1:array<string,mixed>}
     */
    public function process(string $rawBody, bool $isHttps): array
    {
        try {
            $devMode = ($_ENV['GRAFFITI_DEV'] ?? '') === '1';
            if (!$isHttps && !$devMode) {
                return [403, ['status' => 'rejected', 'reason' => 'https_required']];
            }

            if (strlen($rawBody) > self::MAX_BODY_BYTES) {
                return [400, ['status' => 'rejected', 'reason' => 'body_too_large']];
            }
            if ($rawBody === '') {
                return [400, ['status' => 'rejected', 'reason' => 'empty_body']];
            }

            $body = json_decode($rawBody, true);
            if (!is_array($body)) {
                return [400, ['status' => 'rejected', 'reason' => 'invalid_json']];
            }

            // Top-level shape: fail fast on missing keys before touching storage.
            $missing = self::missingTopLevel($body);
            if ($missing !== null) {
                return [400, ['status' => 'rejected', 'reason' => "missing_field:{$missing}"]];
            }

            $token = (string) $body['token'];
            $friend = $this->friends->findByIncomingToken($token);
            if ($friend === null) {
                return [403, ['status' => 'rejected', 'reason' => 'invalid_token']];
            }

            $fromBlog = rtrim((string) ($body['from']['blog_url'] ?? ''), '/');
            $known    = rtrim((string) ($friend['blog_url'] ?? ''), '/');
            if ($fromBlog === '' || $fromBlog !== $known) {
                return [403, ['status' => 'rejected', 'reason' => 'blog_url_mismatch']];
            }

            $slug = (string) $body['post_slug'];
            if ($this->repo->bySlug($slug) === null) {
                return [404, ['status' => 'rejected', 'reason' => 'post_not_found']];
            }

            $nonce = (string) $body['nonce'];
            if ($this->nonces->seen((string) $friend['id'], $nonce)) {
                return [409, ['status' => 'rejected', 'reason' => 'replay']];
            }

            // Receiver-side rate limit: 24h sliding window per friend. Checked
            // AFTER cheap rejects (token/slug/nonce) so a misconfigured sender
            // can't poison the limit window with replays before we'd reject
            // them anyway.
            $rl = $this->rateLimiter->check((string) $friend['id']);
            if (!$rl['ok']) {
                return [402, [
                    'status' => 'rejected',
                    'reason' => 'rate_limit_exceeded',
                    'retry_after' => (int) $rl['retry_after'],
                ]];
            }

            $type    = (string) $body['type'];
            $payload = is_array($body['payload']) ? $body['payload'] : [];
            try {
                PayloadValidator::validate($type, $payload, $this->catalogue);
            } catch (Throwable $e) {
                return [422, ['status' => 'rejected', 'reason' => $e->getMessage()]];
            }

            $id = $this->store->append([
                'from_friend_id' => (string) $friend['id'],
                'post_slug'      => $slug,
                'type'           => $type,
                'payload'        => $payload,
                'nonce'          => $nonce,
            ]);
            $this->nonces->record((string) $friend['id'], $nonce);

            return [200, ['status' => 'accepted', 'id' => $id]];
        } catch (Throwable $e) {
            // Never leak details to the network — log + generic 500.
            error_log('[plugin:graffiti] inbox failure: ' . $e->getMessage());
            return [500, ['status' => 'rejected', 'reason' => 'internal_error']];
        }
    }

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        $fwd = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return $fwd === 'https';
    }

    /** @param array<string,mixed> $body */
    private static function missingTopLevel(array $body): ?string
    {
        foreach (['from', 'token', 'post_slug', 'type', 'payload', 'nonce'] as $key) {
            if (!array_key_exists($key, $body)) {
                return $key;
            }
        }
        if (!is_array($body['from']) || !isset($body['from']['blog_url'], $body['from']['handle'])) {
            return 'from.blog_url|handle';
        }
        return null;
    }
}
