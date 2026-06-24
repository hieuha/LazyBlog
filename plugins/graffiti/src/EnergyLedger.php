<?php

declare(strict_types=1);

namespace Plugins\Graffiti;

use App\FileWriter;
use App\PostRepository;

/**
 * Energy ledger — the spam-throttle currency for graffiti.
 *
 * Writing a published post mints `MINT_PER_POST` energy via the
 * `post.save` hook (see GraffitiPlugin::register). Sending graffiti
 * spends energy at the price target's catalogue declares. Owner can
 * inspect the running balance and full transaction history on
 * `/admin/graffiti/energy`.
 *
 * Storage shape (content/plugins/graffiti/energy.json):
 *
 *   {
 *     "balance": 42,
 *     "minted_slugs": ["2026-06-22-foo", ...],
 *     "ledger":      [ {"ts": ..., "delta": 10,  "reason": "post:..."},
 *                      {"ts": ..., "delta": -5,  "reason": "graffiti:o_..."} ]
 *   }
 *
 * Anti-game properties:
 *   - mint() is idempotent for `post:{slug}` reasons via `minted_slugs`
 *     set membership; resaving the same post does not double-credit.
 *   - reconcile() only mints `published=true` posts (drafts excluded).
 *   - Deleting a post and recreating with the same slug WILL NOT
 *     re-mint — the slug stays in `minted_slugs`. Treated as feature:
 *     prevents farm-by-delete-then-recreate.
 */
final class EnergyLedger
{
    public const MINT_PER_POST = 10;

    /** Display cap on the ledger list returned by `ledger()`. */
    private const LEDGER_DISPLAY_CAP = 200;

    private string $path;

    public function __construct(string $storagePath)
    {
        $this->path = $storagePath . '/energy.json';
    }

    public function balance(): int
    {
        return (int) ($this->load()['balance'] ?? 0);
    }

    public function canSpend(int $amount): bool
    {
        return $amount >= 0 && $this->balance() >= $amount;
    }

    /**
     * Mint energy with a stable reason string. Pass `"post:{slug}"` for the
     * post-saved trigger — the slug component dedups subsequent calls so
     * resaving the same post is a no-op. Other reasons (admin grant, debug)
     * always append.
     */
    public function mint(int $amount, string $reason): void
    {
        if ($amount <= 0 || $reason === '') {
            return;
        }
        $data = $this->load();

        if (str_starts_with($reason, 'post:')) {
            $slug = substr($reason, 5);
            $minted = (array) ($data['minted_slugs'] ?? []);
            if (in_array($slug, $minted, true)) {
                return;
            }
            $minted[] = $slug;
            $data['minted_slugs'] = array_values($minted);
        }

        $data['balance'] = (int) ($data['balance'] ?? 0) + $amount;
        $data['ledger'] = $this->appendEntry((array) ($data['ledger'] ?? []), $amount, $reason);

        $this->save($data);
    }

    /**
     * Attempt to spend. Returns false (and writes nothing) when balance
     * is insufficient — caller surfaces the rejection.
     */
    public function spend(int $amount, string $reason): bool
    {
        if ($amount <= 0 || $reason === '') {
            return false;
        }
        $data = $this->load();
        $current = (int) ($data['balance'] ?? 0);
        if ($current < $amount) {
            return false;
        }
        $data['balance'] = $current - $amount;
        $data['ledger'] = $this->appendEntry((array) ($data['ledger'] ?? []), -$amount, $reason);
        $this->save($data);
        return true;
    }

    /**
     * Direct debit — appends a negative entry regardless of current balance.
     * Used by the cross-blog notify-debit webhook where the friend's blog
     * is authoritative for the cost; we cannot refuse just because owner
     * has gone broke (the graffiti is already painted on the other end).
     */
    public function debit(int $amount, string $reason): void
    {
        if ($amount <= 0 || $reason === '') {
            return;
        }
        $data = $this->load();
        $data['balance'] = (int) ($data['balance'] ?? 0) - $amount;
        $data['ledger'] = $this->appendEntry((array) ($data['ledger'] ?? []), -$amount, $reason);
        $this->save($data);
    }

    /**
     * Catch any published post not yet minted — covers `.md` drops that
     * bypass the admin save flow (e.g. operator scp's a markdown file in).
     * Cheap to call; the index is already cached.
     */
    public function reconcile(PostRepository $repo): void
    {
        $data = $this->load();
        $minted = array_flip((array) ($data['minted_slugs'] ?? []));
        $touched = false;

        foreach ($repo->all() as $entry) {
            $slug = (string) ($entry['slug'] ?? '');
            $draft = (bool) ($entry['draft'] ?? false);
            if ($slug === '' || $draft || isset($minted[$slug])) {
                continue;
            }
            $minted[$slug] = true;
            $data['balance'] = (int) ($data['balance'] ?? 0) + self::MINT_PER_POST;
            $data['ledger'] = $this->appendEntry((array) ($data['ledger'] ?? []), self::MINT_PER_POST, "post:{$slug}");
            $touched = true;
        }

        if ($touched) {
            $data['minted_slugs'] = array_keys($minted);
            $this->save($data);
        }
    }

    /**
     * Most-recent first, capped at LEDGER_DISPLAY_CAP entries.
     *
     * @return list<array{ts:int,delta:int,reason:string}>
     */
    public function ledger(): array
    {
        $rows = (array) ($this->load()['ledger'] ?? []);
        $rows = array_reverse($rows);
        return array_slice($rows, 0, self::LEDGER_DISPLAY_CAP);
    }

    /** @return array<string,mixed> */
    private function load(): array
    {
        if (!is_file($this->path)) {
            return ['balance' => 0, 'minted_slugs' => [], 'ledger' => []];
        }
        $decoded = json_decode((string) file_get_contents($this->path), true);
        return is_array($decoded) ? $decoded : ['balance' => 0, 'minted_slugs' => [], 'ledger' => []];
    }

    /** @param array<string,mixed> $data */
    private function save(array $data): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o755, recursive: true);
        }
        FileWriter::writeAtomic(
            $this->path,
            (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            0o600,
        );
    }

    /** @param list<array<string,mixed>> $ledger @return list<array<string,mixed>> */
    private function appendEntry(array $ledger, int $delta, string $reason): array
    {
        $ledger[] = ['ts' => time(), 'delta' => $delta, 'reason' => $reason];
        return array_values($ledger);
    }
}
