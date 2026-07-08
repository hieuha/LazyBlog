<?php

declare(strict_types=1);

namespace Plugins\Fax;

/**
 * Operator-configured settings, persisted to `content/plugins/fax/config.json`.
 *
 * Everything the plugin needs to talk to the FaxxMe inbound webhook lives
 * here so the token never has to sit in an env var / the repo:
 *
 *   - api_token : the `fxwh_…` bearer secret (write-only in the admin form)
 *   - endpoint  : full webhook URL (defaults to the public FaxxMe endpoint)
 *
 * There is deliberately no local send cap: the webhook already rate-limits
 * per author and per calling-site IP (5 / 300s), so a second counter here
 * would only double-count. A `429` from the webhook is what surfaces the
 * "out of faxes" message to the reader.
 *
 * Reads are cheap + uncached: register() runs every request and re-reads to
 * decide whether the reader-facing UI should even be injected, so a config
 * change takes effect on the next page load with no restart.
 */
final class FaxSettings
{
    private const DEFAULT_ENDPOINT = 'https://fax.hatrunghieu.com/api/fax/inbound';

    private string $file;

    public function __construct(string $storagePath)
    {
        $this->file = $storagePath . '/config.json';
    }

    public function apiToken(): string
    {
        return trim((string) ($this->read()['api_token'] ?? ''));
    }

    public function endpoint(): string
    {
        $endpoint = trim((string) ($this->read()['endpoint'] ?? ''));
        return $endpoint !== '' ? $endpoint : self::DEFAULT_ENDPOINT;
    }

    /** True once a token is set — otherwise there's nothing to fax with. */
    public function isReady(): bool
    {
        return $this->apiToken() !== '' && $this->endpoint() !== '';
    }

    /**
     * Persist the admin form. An empty endpoint falls back to the default so
     * the operator only has to paste a token to get going.
     */
    public function save(string $token, string $endpoint): void
    {
        $endpoint = trim($endpoint);
        $data = [
            'api_token' => trim($token),
            'endpoint'  => $endpoint !== '' ? $endpoint : self::DEFAULT_ENDPOINT,
        ];
        file_put_contents(
            $this->file,
            (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            LOCK_EX,
        );
    }

    /** @return array<string,mixed> */
    private function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $raw = file_get_contents($this->file);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
