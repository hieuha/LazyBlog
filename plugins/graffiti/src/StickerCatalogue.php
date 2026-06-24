<?php

declare(strict_types=1);

namespace Plugins\Graffiti;

/**
 * Merged sticker catalogue: ship-default file + operator overrides.
 *
 * The default JSON lives at `plugins/graffiti/content/stickers.json` and
 * gets copied to storage on first boot (see Bootstrap). Operator can edit
 * the storage copy directly OR through the Phase 7 admin tab to override
 * price / enabled. The SVG filename ALWAYS comes from the default ship —
 * we never let the override redefine an asset path, so visitors don't
 * end up loading attacker-controlled inline content.
 */
final class StickerCatalogue
{
    private string $shipPath;
    private string $storagePath;

    public function __construct(string $storagePath, string $pluginRoot)
    {
        $this->storagePath = $storagePath . '/stickers.json';
        $this->shipPath = $pluginRoot . '/content/stickers.json';
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $ship    = $this->readJson($this->shipPath);
        $storage = $this->readJson($this->storagePath);

        // Index overrides by id for O(1) merge.
        $overrideById = [];
        foreach ($storage as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id !== '') {
                $overrideById[$id] = $row;
            }
        }

        $merged = [];
        foreach ($ship as $base) {
            $id = (string) ($base['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $row = $base;
            if (isset($overrideById[$id])) {
                $o = $overrideById[$id];
                if (array_key_exists('price', $o)) {
                    $row['default_price'] = (int) $o['price'];
                }
                if (array_key_exists('enabled', $o)) {
                    $row['enabled'] = (bool) $o['enabled'];
                }
            }
            $merged[] = $row;
        }
        return $merged;
    }

    /** @return list<array<string,mixed>> */
    public function enabled(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (array $r): bool => (bool) ($r['enabled'] ?? false),
        ));
    }

    public function find(string $id): ?array
    {
        foreach ($this->all() as $row) {
            if (($row['id'] ?? null) === $id) {
                return $row;
            }
        }
        return null;
    }

    public function priceFor(string $id): ?int
    {
        $row = $this->find($id);
        if ($row === null) {
            return null;
        }
        return (int) ($row['default_price'] ?? 0);
    }

    /**
     * Persist an override for one sticker. Only `price` and `enabled` may
     * be overridden — the SVG filename stays fixed to whatever the ship
     * default declares, so an admin can't accidentally repoint a sticker
     * at attacker-controlled inline content via the override JSON.
     *
     * @param array{price?:int,enabled?:bool} $patch
     */
    public function setOverride(string $id, array $patch): void
    {
        $base = $this->find($id);
        if ($base === null) {
            return;
        }
        $current = $this->readJson($this->storagePath);
        $found = false;
        foreach ($current as &$row) {
            if (($row['id'] ?? null) === $id) {
                if (array_key_exists('price', $patch)) {
                    $row['price'] = max(0, (int) $patch['price']);
                }
                if (array_key_exists('enabled', $patch)) {
                    $row['enabled'] = (bool) $patch['enabled'];
                }
                $found = true;
                break;
            }
        }
        unset($row);
        if (!$found) {
            $newRow = ['id' => $id];
            if (array_key_exists('price', $patch))   $newRow['price'] = max(0, (int) $patch['price']);
            if (array_key_exists('enabled', $patch)) $newRow['enabled'] = (bool) $patch['enabled'];
            $current[] = $newRow;
        }
        $dir = dirname($this->storagePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o755, recursive: true);
        }
        \App\FileWriter::writeAtomic(
            $this->storagePath,
            (string) json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            0o644,
        );
    }

    /** @return list<array<string,mixed>> */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? array_values($decoded) : [];
    }
}
