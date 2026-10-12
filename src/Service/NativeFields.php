<?php

namespace ProductImport\Service;

/**
 * Pure normalisation of mapped values for native PrestaShop product fields (no store access).
 * Limits mirror Product::$definition, Combination::$definition, Feature::$definition and FeatureValueSettings.
 */
final class NativeFields
{
    /** Product lang text limits: classes/Product.php:439-449,504-505,520-521. */
    public const PRODUCT_TEXT_LIMITS = [
        'meta_title' => 255,
        'meta_description' => 512,
        'available_now' => 255,
        'available_later' => 255,
        'delivery_in_stock' => 255,
        'delivery_out_stock' => 255,
    ];

    /** Combination lang text limits: classes/Combination.php:93-94. */
    public const COMBINATION_TEXT_LIMITS = ['available_now' => 255, 'available_later' => 255];

    public const FEATURE_NAME_LIMIT = 128;
    public const FEATURE_VALUE_LIMIT = 255;

    private const DELIVERY_TIMES = ['none' => 0, 'default' => 1, 'custom' => 2, '0' => 0, '1' => 1, '2' => 2];

    /**
     * Truncates over-long text fields in place. Non-scalar values are dropped.
     *
     * @param array<string, int> $limits
     * @return array{values: array<string, mixed>, warnings: list<string>} each warning reads "field: message"
     */
    public static function limitTexts(array $values, array $limits): array
    {
        $warnings = [];
        foreach ($limits as $field => $limit) {
            if (!array_key_exists($field, $values) || $values[$field] === null) {
                continue;
            }
            if (!is_scalar($values[$field])) {
                unset($values[$field]);
                $warnings[] = $field . ': expected text, ignored';
                continue;
            }
            $text = (string) $values[$field];
            if (mb_strlen($text) > $limit) {
                $values[$field] = mb_substr($text, 0, $limit);
                $warnings[] = $field . ': truncated to ' . $limit . ' characters';
            } else {
                $values[$field] = $text;
            }
        }

        return ['values' => $values, 'warnings' => $warnings];
    }

    /** 'none' | 'default' | 'custom' (or 0/1/2) to Product::$additional_delivery_times; null for no value. */
    public static function deliveryTime(mixed $value): ?int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (is_float($value) && in_array($value, [0.0, 1.0, 2.0], true)) {
            return (int) $value;
        }
        if (is_int($value) || is_string($value)) {
            $key = is_string($value) ? strtolower(trim($value)) : (string) $value;
            if (isset(self::DELIVERY_TIMES[$key])) {
                return self::DELIVERY_TIMES[$key];
            }
        }
        $got = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        throw new \InvalidArgumentException('delivery_time must be none, default or custom (or 0, 1, 2); got ' . mb_substr((string) $got, 0, 60));
    }

    /**
     * Feature input: {name: value} or a list of {name, value}. Duplicate names keep the last value.
     *
     * @return array{pairs: array<string, string>, warnings: list<string>} warnings are messages about skipped entries
     */
    public static function features(mixed $input): array
    {
        if (!is_array($input)) {
            throw new \InvalidArgumentException('features must be a map of name to value, or a list of {name, value}.');
        }
        $candidates = [];
        if (array_is_list($input)) {
            foreach ($input as $entry) {
                if (is_array($entry) && array_key_exists('name', $entry)) {
                    $candidates[] = [$entry['name'], $entry['value'] ?? null];
                } else {
                    $candidates[] = [null, null];
                }
            }
        } else {
            foreach ($input as $name => $value) {
                $candidates[] = [$name, $value];
            }
        }
        $pairs = [];
        $warnings = [];
        foreach ($candidates as [$name, $value]) {
            if (!is_scalar($name) || self::trimText((string) $name) === '' || $value === null) {
                continue;
            }
            $name = self::trimText((string) $name);
            try {
                $text = self::featureValue($value);
            } catch (\InvalidArgumentException $error) {
                $warnings[] = '"' . $name . '": ' . $error->getMessage() . ', skipped';
                continue;
            }
            if ($text === null) {
                continue;
            }
            if (mb_strlen($name) > self::FEATURE_NAME_LIMIT) {
                $warnings[] = '"' . $name . '": feature name exceeds ' . self::FEATURE_NAME_LIMIT . ' characters, skipped';
                continue;
            }
            unset($pairs[$name]);
            $pairs[$name] = $text;
        }

        return ['pairs' => $pairs, 'warnings' => $warnings];
    }

    /**
     * One feature value as trimmed text: null for no value (null or empty), booleans as 1 / 0.
     *
     * @throws \InvalidArgumentException for a non-scalar value or one over PrestaShop's length limit
     */
    public static function featureValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value)) {
            throw new \InvalidArgumentException('value must be text or a number');
        }
        $text = self::trimText(is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > self::FEATURE_VALUE_LIMIT) {
            throw new \InvalidArgumentException('value exceeds ' . self::FEATURE_VALUE_LIMIT . ' characters');
        }

        return $text;
    }

    /** Pure twin of Validate::isGenericName: no < > = { }. */
    public static function isGenericName(string $name): bool
    {
        return preg_match('/^[^<>={}]*$/u', $name) === 1;
    }

    /** Trims Unicode whitespace too (no-break spaces). */
    public static function trimText(string $text): string
    {
        return preg_replace('/^[\\pZ\\s]+|[\\pZ\\s]+$/u', '', $text) ?? trim($text);
    }

    /** One string or a list of scalars as a de-duplicated list of trimmed non-empty strings. */
    public static function stringList(mixed $input): array
    {
        if ($input === null) {
            return [];
        }
        $items = is_array($input) ? $input : [$input];
        $list = [];
        foreach ($items as $item) {
            if (is_scalar($item) && !is_bool($item) && trim((string) $item) !== '') {
                $list[] = trim((string) $item);
            }
        }

        return array_values(array_unique($list));
    }

    /** @param list<array{id_feature: int|string, id_feature_value: int|string}> $rows feature_product rows */
    public static function featuresFingerprint(array $rows): string
    {
        return self::listFingerprint(array_map(static fn (array $row): string => (int) $row['id_feature'] . '=' . (int) $row['id_feature_value'], $rows));
    }

    /** The name-based form used before 0.20.0, kept only so the upgrade can recognise untouched products. @param list<array{name: string, value: string}> $rows */
    public static function legacyFeaturesFingerprint(array $rows): string
    {
        return self::listFingerprint(array_map(static fn (array $row): string => $row['name'] . '=' . $row['value'], $rows));
    }

    /** Sorted, normalised values joined for fingerprinting: order in the store or feed does not matter. */
    public static function listFingerprint(array $values): string
    {
        $values = array_map('strval', $values);
        sort($values, SORT_STRING);

        return implode("\n", $values);
    }
}
