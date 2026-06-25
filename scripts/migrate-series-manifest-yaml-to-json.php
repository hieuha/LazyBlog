<?php

declare(strict_types=1);

/**
 * One-off migration: content/series/<slug>/manifest.yaml -> manifest.json.
 *
 * Run from the project root:
 *   php scripts/migrate-series-manifest-yaml-to-json.php
 *
 * Idempotent:
 *   - skips slugs that already have manifest.json
 *   - skips slugs with no manifest.yaml
 *   - prints a summary line per slug
 *   - exits 0 on success, 1 if any file failed to migrate
 *
 * Field changes:
 *   - drops `cover_ext` (dead — cover presence derives from is_file(cover.webp))
 *   - keeps `title`, `description`, `updated_at`
 *
 * The .yaml is unlinked only after the .json is durably renamed into place.
 * Safe to re-run after a partial failure.
 */

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$contentDir = __DIR__ . '/../content';
$seriesRoot = realpath($contentDir . '/series');
if ($seriesRoot === false) {
    fwrite(STDERR, "No content/series directory found at {$contentDir}/series — nothing to migrate.\n");
    exit(0);
}

$yamlFiles = glob($seriesRoot . '/*/manifest.yaml') ?: [];
if ($yamlFiles === []) {
    echo "No manifest.yaml files found under {$seriesRoot}. Already on JSON.\n";
    exit(0);
}

$migrated = 0;
$skipped = 0;
$failed = 0;

foreach ($yamlFiles as $yamlPath) {
    $dir = dirname($yamlPath);
    $slug = basename($dir);
    $jsonPath = $dir . '/manifest.json';

    if (is_file($jsonPath)) {
        echo "SKIP {$slug}: manifest.json already exists — leaving manifest.yaml in place for manual review.\n";
        $skipped++;
        continue;
    }

    try {
        $raw = Yaml::parseFile($yamlPath);
    } catch (\Throwable $e) {
        fwrite(STDERR, "FAIL {$slug}: yaml parse error: " . $e->getMessage() . "\n");
        $failed++;
        continue;
    }
    if (!is_array($raw)) {
        fwrite(STDERR, "FAIL {$slug}: yaml did not decode to a map.\n");
        $failed++;
        continue;
    }

    $payload = [];
    foreach (['title', 'description'] as $key) {
        if (isset($raw[$key]) && is_string($raw[$key]) && trim($raw[$key]) !== '') {
            $payload[$key] = trim($raw[$key]);
        }
    }
    $payload['updated_at'] = isset($raw['updated_at']) && is_string($raw['updated_at']) && $raw['updated_at'] !== ''
        ? $raw['updated_at']
        : (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        fwrite(STDERR, "FAIL {$slug}: json_encode failed.\n");
        $failed++;
        continue;
    }

    // Atomic write: tmp file in same dir, then rename. POSIX rename is atomic
    // within a single filesystem (always true for sibling files).
    $tmp = $jsonPath . '.tmp';
    if (@file_put_contents($tmp, $json . "\n") === false) {
        fwrite(STDERR, "FAIL {$slug}: cannot write {$tmp}\n");
        $failed++;
        continue;
    }
    if (!@rename($tmp, $jsonPath)) {
        @unlink($tmp);
        fwrite(STDERR, "FAIL {$slug}: rename {$tmp} → {$jsonPath} failed.\n");
        $failed++;
        continue;
    }

    // Json is now durable on disk — safe to remove the yaml.
    if (!@unlink($yamlPath)) {
        // Non-fatal: json wrote fine, leftover yaml just won't be read by the
        // new code path. Report it so the operator can clean up by hand.
        fwrite(STDERR, "WARN {$slug}: wrote manifest.json but failed to unlink manifest.yaml.\n");
    }

    echo "OK   {$slug}: migrated → manifest.json\n";
    $migrated++;
}

echo "\nDone. migrated={$migrated} skipped={$skipped} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
