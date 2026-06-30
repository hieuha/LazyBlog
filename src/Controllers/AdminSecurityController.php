<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Http;
use App\WebAuthn;
use App\WebAuthnCredentialStore;
use Throwable;

/**
 * Admin /security page: list registered FIDO2 keys, register a new one,
 * revoke existing ones. Lives next to AdminAboutController and
 * AdminSeriesController in spirit — single-operator admin surface.
 *
 * Last-key guard:
 *   When WEBAUTHN=true and the operator would be left with 0 keys, revoke
 *   is rejected at the controller layer. Server-side guard; the UI also
 *   disables the button as defense-in-depth.
 */
final class AdminSecurityController
{
    public function __construct(
        private readonly WebAuthnCredentialStore $store,
        private readonly WebAuthn $webAuthn,
    ) {
    }

    public function index(): void
    {
        Auth::requireAuth();
        $credentials = $this->store->all();
        Http::render('admin/security', [
            'title' => 'Admin · Security',
            'credentials' => $credentials,
            'webauthnEnabled' => Auth::webauthnEnabled(),
            'flash' => self::consumeFlash(),
        ]);
    }

    /** @param array<string,string> $params */
    public function revoke(array $params): void
    {
        Auth::requireAuth();
        Csrf::requireValid();
        $id = (string) ($params['id'] ?? '');
        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{1,256}$/', $id)) {
            self::flashError('Invalid credential id.');
            Http::redirect('/admin/security');
        }

        $cred = $this->store->findById($id);
        if ($cred === null) {
            self::flashError('Key not found.');
            Http::redirect('/admin/security');
        }

        // Last-key guard: WEBAUTHN=true + 1 key → block. Operator must flip
        // WEBAUTHN=false first OR register another key before pulling this.
        if (Auth::webauthnEnabled() && $this->store->count() <= 1) {
            self::flashError('Cannot revoke the last key while WEBAUTHN=true. Register another key or set WEBAUTHN=false first.');
            Http::redirect('/admin/security');
        }

        $this->store->remove($id);
        self::flash("Revoked key: {$cred->name}");
        Http::redirect('/admin/security');
    }

    public function registerBegin(): void
    {
        Auth::requireAuth();
        Csrf::requireValid();
        if (Auth::loginThrottled()) {
            self::jsonError('Too many attempts. Try again in 15 minutes.', 429);
        }
        $payload = self::readJsonBody();
        $nickname = isset($payload['nickname']) && is_string($payload['nickname']) ? $payload['nickname'] : '';

        try {
            $opts = $this->webAuthn->beginRegister($nickname);
        } catch (Throwable $e) {
            Auth::recordFailedLogin();
            self::jsonError(self::publicErrorMessage($e), 400);
        }
        self::jsonOk($opts);
    }

    public function registerComplete(): void
    {
        Auth::requireAuth();
        Csrf::requireValid();
        if (Auth::loginThrottled()) {
            self::jsonError('Too many attempts. Try again in 15 minutes.', 429);
        }
        $raw = self::readRawJson();

        try {
            $cred = $this->webAuthn->completeRegister($raw);
        } catch (Throwable $e) {
            Auth::recordFailedLogin();
            self::jsonError(self::publicErrorMessage($e), 400);
        }
        self::flash("Registered key: {$cred->name}");
        self::jsonOk(['ok' => true, 'credential' => [
            'id' => $cred->id,
            'name' => $cred->name,
            'createdAt' => $cred->createdAt,
        ]]);
    }

    // ----- WebAuthn login (no Auth::requireAuth — this IS the auth) -----

    public function loginBegin(): void
    {
        Csrf::requireValid();
        if (Auth::loginThrottled()) {
            self::jsonError('Too many attempts. Try again in 15 minutes.', 429);
        }
        if ($this->store->count() === 0) {
            self::jsonError('No security keys registered.', 400);
        }
        try {
            $opts = $this->webAuthn->beginLogin();
        } catch (Throwable $e) {
            self::jsonError(self::publicErrorMessage($e), 400);
        }
        self::jsonOk($opts);
    }

    public function loginComplete(): void
    {
        Csrf::requireValid();
        if (Auth::loginThrottled()) {
            self::jsonError('Too many attempts. Try again in 15 minutes.', 429);
        }
        $raw = self::readRawJson();

        try {
            $this->webAuthn->completeLogin($raw);
        } catch (Throwable $e) {
            Auth::recordFailedLogin();
            self::jsonError(self::publicErrorMessage($e), 400);
        }

        Auth::clearFailedLogins();
        Auth::finalizeLogin();

        // Preserve `?next=` — JS POSTs it back in the JSON body.
        $payload = json_decode($raw, true);
        $next = is_array($payload) && isset($payload['next']) && is_string($payload['next']) ? $payload['next'] : '/admin';
        if (!self::safeRedirect($next)) {
            $next = '/admin';
        }
        self::jsonOk(['redirect' => $next]);
    }

    /** Mirror AdminController::safeRedirectTarget — internal-only redirects. */
    private static function safeRedirect(string $next): bool
    {
        if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//')) {
            return false;
        }
        return !preg_match('/[\r\n\t\0]/', $next);
    }

    // ----- helpers -----

    /**
     * Read php://input but reject bodies larger than 64KB. The legitimate
     * WebAuthn payloads (clientDataJSON + attestationObject + signature)
     * fit in a few KB. A hard cap defends against attackers spamming
     * megabyte-sized junk to exhaust memory before rate-limits kick in.
     */
    private const MAX_BODY_BYTES = 65536;

    private static function readRawJson(): string
    {
        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if ($raw === '') {
            self::jsonError('Empty body.', 400);
        }
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            self::jsonError('Request body too large.', 413);
        }
        return $raw;
    }

    /** @return array<string,mixed> */
    private static function readJsonBody(): array
    {
        $raw = self::readRawJsonOrEmpty();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function readRawJsonOrEmpty(): string
    {
        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            self::jsonError('Request body too large.', 413);
        }
        return $raw;
    }

    /**
     * Sanitize exception messages before returning them to the client.
     *
     * The WebAuthn library throws messages like "Invalid CBOR data at
     * offset 17" that leak internal state to attackers probing the
     * endpoint. Map to short, neutral messages keyed on coarse buckets;
     * the underlying exception still propagates to the PHP error log
     * (`error_log`) for the operator to debug.
     */
    private static function publicErrorMessage(Throwable $e): string
    {
        // Log the full message to the server error log for diagnosis.
        $line = '[' . gmdate('c') . '] ' . get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine();
        error_log('webauthn: ' . $line);
        // Also mirror to a project-local file so operators can `tail` it
        // without configuring php-fpm logging. Same dir as the credential
        // store; .gitignored at content/admin/.gitignore.
        $logPath = __DIR__ . '/../../content/admin/webauthn-error.log';
        @file_put_contents($logPath, $line . "\n", FILE_APPEND | LOCK_EX);

        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'challenge')) {
            return 'Session expired — reload the page and try again.';
        }
        if (str_contains($msg, 'counter') || str_contains($msg, 'replay')) {
            return 'Replay detected — refusing assertion.';
        }
        if (str_contains($msg, 'unknown credential') || str_contains($msg, 'not registered')) {
            return 'Unknown security key.';
        }
        if (str_contains($msg, 'nickname')) {
            return $e->getMessage(); // user-facing validation; safe to surface
        }
        if (str_contains($msg, 'malformed') || str_contains($msg, 'missing') || str_contains($msg, 'invalid')) {
            return 'Malformed request.';
        }
        return 'Authentication failed.';
    }

    /** @param array<string,mixed> $data */
    private static function jsonOk(array $data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data + ['ok' => true], JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function jsonError(string $message, int $code): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Flash messages reuse the same one-shot session slot pattern as
    // AdminController. Kept private/static here to avoid a circular
    // dependency on AdminController internals.
    private const FLASH_KEY = 'admin_security_flash';

    private static function flash(string $message): void
    {
        Auth::start();
        $_SESSION[self::FLASH_KEY] = ['type' => 'ok', 'message' => $message];
    }

    private static function flashError(string $message): void
    {
        Auth::start();
        $_SESSION[self::FLASH_KEY] = ['type' => 'error', 'message' => $message];
    }

    /** @return array{type:string,message:string}|null */
    private static function consumeFlash(): ?array
    {
        Auth::start();
        $flash = $_SESSION[self::FLASH_KEY] ?? null;
        unset($_SESSION[self::FLASH_KEY]);
        if (!is_array($flash) || !isset($flash['type'], $flash['message'])) {
            return null;
        }
        return ['type' => (string) $flash['type'], 'message' => (string) $flash['message']];
    }
}
