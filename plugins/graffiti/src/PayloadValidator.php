<?php

declare(strict_types=1);

namespace Plugins\Graffiti;

use RuntimeException;

/**
 * Schema check for the inbound graffiti payload, branched per `type`.
 *
 * Throws ValidationException with an explicit reason code so the inbox
 * handler can map directly to HTTP 422 + JSON body. Per-type rules:
 *
 *   text:    text (1..140 chars after trim)
 *   sticker: sticker_id ∈ enabled catalogue
 *   spray:   spray_id ∈ enabled catalogue
 *
 * Optional position object (any type) clamped to {x:0..1, y:0..1,
 * rotation:-180..180}.
 */
final class PayloadValidator
{
    public const TEXT_MAX_CHARS = 140;
    public const ALLOWED_TYPES = ['text', 'sticker', 'spray'];

    /** Allowlist of font tokens accepted for type=text payloads. */
    public const TEXT_FONTS = ['marker', 'spray', 'tag', 'block'];

    /** Allowlist of color tokens accepted for type=text payloads. */
    public const TEXT_COLORS = ['green', 'white', 'pink', 'yellow', 'orange', 'red', 'blue', 'purple'];

    /**
     * @param array<string,mixed> $payload
     * @throws RuntimeException with code matching response reason string
     */
    public static function validate(string $type, array $payload, StickerCatalogue $catalogue): void
    {
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new RuntimeException('invalid_type');
        }

        if (isset($payload['position'])) {
            self::validatePosition($payload['position']);
        }

        switch ($type) {
            case 'text':
                self::validateText($payload);
                return;
            case 'sticker':
                self::validateCatalogueRef($payload, 'sticker_id', $catalogue);
                return;
            case 'spray':
                self::validateCatalogueRef($payload, 'spray_id', $catalogue);
                return;
        }
    }

    /** @param array<string,mixed> $payload */
    private static function validateText(array $payload): void
    {
        $text = trim((string) ($payload['text'] ?? ''));
        if ($text === '' || mb_strlen($text) > self::TEXT_MAX_CHARS) {
            throw new RuntimeException('invalid_payload');
        }
        // Optional font + color tokens. Empty/missing → renderer uses defaults.
        // Any other non-allowlisted value → reject so a malicious sender can't
        // smuggle CSS through.
        if (isset($payload['font']) && $payload['font'] !== ''
            && !in_array($payload['font'], self::TEXT_FONTS, true)) {
            throw new RuntimeException('invalid_payload');
        }
        if (isset($payload['color']) && $payload['color'] !== ''
            && !in_array($payload['color'], self::TEXT_COLORS, true)) {
            throw new RuntimeException('invalid_payload');
        }
    }

    /** @param array<string,mixed> $payload */
    private static function validateCatalogueRef(array $payload, string $field, StickerCatalogue $catalogue): void
    {
        $id = (string) ($payload[$field] ?? '');
        if (!preg_match('/^[a-z0-9-]+$/', $id)) {
            throw new RuntimeException('invalid_payload');
        }
        $row = $catalogue->find($id);
        if ($row === null) {
            throw new RuntimeException('invalid_payload');
        }
        if (!(bool) ($row['enabled'] ?? false)) {
            throw new RuntimeException('sticker_disabled');
        }
    }

    private static function validatePosition(mixed $position): void
    {
        if (!is_array($position)) {
            throw new RuntimeException('invalid_payload');
        }
        $x = $position['x'] ?? 0;
        $y = $position['y'] ?? 0;
        $r = $position['rotation'] ?? 0;
        if (!is_numeric($x) || !is_numeric($y) || !is_numeric($r)) {
            throw new RuntimeException('invalid_payload');
        }
        if ((float) $x < 0 || (float) $x > 1 || (float) $y < 0 || (float) $y > 1) {
            throw new RuntimeException('invalid_payload');
        }
        if ((float) $r < -180 || (float) $r > 180) {
            throw new RuntimeException('invalid_payload');
        }
    }
}
