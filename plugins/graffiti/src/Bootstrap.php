<?php

declare(strict_types=1);

namespace Plugins\Graffiti;

/**
 * First-boot bootstrap: copies the shipped default `stickers.json` from
 * `plugins/graffiti/content/` into the operator-writable storage
 * `content/plugins/graffiti/` only if the file is missing. Operator edits
 * to the storage copy survive plugin upgrades; the ship copy is the
 * fallback baseline.
 */
final class Bootstrap
{
    public static function ensureDefaults(string $storagePath, string $pluginRoot): void
    {
        $target = $storagePath . '/stickers.json';
        if (is_file($target)) {
            return;
        }
        $source = $pluginRoot . '/content/stickers.json';
        if (!is_file($source)) {
            return;
        }
        @copy($source, $target);
    }
}
