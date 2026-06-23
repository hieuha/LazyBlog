<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;

/**
 * Immutable value object hydrated from a plugin's `manifest.json`.
 *
 * The `api_version` field is treated as a semver-major. PluginRegistry
 * refuses to boot a plugin whose declared api_version is not in its
 * supported list — bump major + ship a compat shim on breaking changes.
 */
final class PluginManifest
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $version,
        public readonly int $apiVersion,
        public readonly string $namespace,
        public readonly string $author = '',
        public readonly string $description = '',
        public readonly string $homepage = '',
    ) {
    }

    /**
     * Build from a decoded JSON array. Throws on missing or malformed fields
     * so callers can fail fast with a useful error.
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['slug', 'name', 'version', 'api_version', 'namespace'] as $required) {
            if (!isset($data[$required])) {
                throw new InvalidArgumentException("manifest missing required field: {$required}");
            }
        }

        $slug = (string) $data['slug'];
        if (!preg_match('/^[a-z][a-z0-9-]*$/', $slug)) {
            throw new InvalidArgumentException(
                "manifest slug must be lowercase kebab-case (got {$slug})",
            );
        }

        // Normalise namespace: strip wrapping backslashes, ensure trailing
        // separator so prefix matching in the autoloader is straightforward.
        $namespace = trim((string) $data['namespace'], '\\') . '\\';

        return new self(
            slug: $slug,
            name: (string) $data['name'],
            version: (string) $data['version'],
            apiVersion: (int) $data['api_version'],
            namespace: $namespace,
            author: (string) ($data['author'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            homepage: (string) ($data['homepage'] ?? ''),
        );
    }
}
