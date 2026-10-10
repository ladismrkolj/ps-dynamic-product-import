<?php

namespace ProductImport\Service;

/**
 * Per-field fingerprints of what the import last wrote, used to keep manual edits.
 * A field is written only when the import never wrote it, or the store still holds the import's last value.
 */
final class ImportedValues
{
    /** @param array<string, string> $values store values normalized to strings */
    public static function fingerprint(array $values): array
    {
        return array_map(static fn (string $value): string => md5($value), $values);
    }

    public static function writable(array $imported, array $current, string $key): bool
    {
        return !isset($imported[$key]) || $imported[$key] === ($current[$key] ?? null);
    }

    public static function number($value): string
    {
        // Match DECIMAL(20,6) storage: six decimals, no negative zero.
        $number = round((float) $value, 6);

        return sprintf('%.6F', $number == 0 ? 0.0 : $number);
    }

    /** Visible text only: the back-office editor rewraps HTML on every product save without a real edit. */
    public static function text($html): string
    {
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function decode($json): array
    {
        $values = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return is_array($values) ? array_filter($values, 'is_string') : [];
    }

    public static function encode(array $values): string
    {
        return json_encode($values, JSON_THROW_ON_ERROR);
    }
}
