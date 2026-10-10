<?php

namespace ProductImport\Service;

/**
 * GS1 check-digit validation for the PrestaShop `ean13` field.
 *
 * Only exactly 13 digits with a correct check digit are valid. PrestaShop's `ean13` field accepts at most 13
 * digits (Validate::isEan13), but an EAN-8 or a 12-digit UPC-A is a different identifier: it is never zero-padded
 * (that would silently change the barcode) and a UPC belongs in PrestaShop's separate `upc` field, so both are
 * rejected here. A value that lost leading zeros (JSON number) or became scientific notation fails on length or
 * digits and is not written.
 */
final class Gtin
{
    public static function isValidEan13(string $value): bool
    {
        return self::problem($value) === null;
    }

    /**
     * Warning text for a mapped ean13 value, or null when it is empty or valid. Empty/null is not a problem.
     */
    public static function warning(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $text = is_scalar($value) ? (string) $value : (string) json_encode($value);
        $problem = self::problem(trim($text));

        return $problem === null ? null : 'invalid EAN-13 "' . $text . '" (' . $problem . ') — not written';
    }

    /** @return string|null reason, null when valid */
    private static function problem(string $value): ?string
    {
        if (!preg_match('/^[0-9]+$/D', $value)) {
            return 'not digits only';
        }
        $length = strlen($value);
        if ($length !== 13) {
            return 'length ' . $length;
        }
        $sum = 0;
        for ($i = 0; $i < $length - 1; ++$i) {
            // Weights 3,1,3,1… counted from the digit left of the check digit.
            $sum += (int) $value[$length - 2 - $i] * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === (int) $value[$length - 1] ? null : 'check digit';
    }
}
