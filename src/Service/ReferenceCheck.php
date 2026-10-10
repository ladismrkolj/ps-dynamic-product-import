<?php

namespace ProductImport\Service;

/**
 * Decisions that keep PrestaShop product references unique. A colliding reference is never written; the product
 * keeps its current reference (empty for a new product).
 */
final class ReferenceCheck
{
    public static function usedByProduct(string $reference, int $idProduct): string
    {
        return '"' . $reference . '" is already used by product #' . $idProduct . ' — not written';
    }

    /**
     * Drops a mapped `reference` that another product already holds. Empty or missing references are left alone.
     *
     * @param array<string, mixed> $values mapped product values
     * @param callable(string, ?int): ?int $ownerOf id of a product other than $linkedProduct holding the reference
     *
     * @return array{0: array<string, mixed>, 1: ?string} values and the warning, if the reference was dropped
     */
    public static function apply(array $values, ?int $linkedProduct, callable $ownerOf): array
    {
        $reference = $values['reference'] ?? null;
        if (!is_scalar($reference) || trim((string) $reference) === '') {
            return [$values, null];
        }
        $owner = $ownerOf((string) $reference, $linkedProduct);
        if ($owner === null) {
            return [$values, null];
        }
        unset($values['reference']);

        return [$values, self::usedByProduct((string) $reference, $owner)];
    }

    /**
     * In-feed duplicates: the first item holding a reference wins.
     *
     * @param array<int|string, mixed> $references mapped reference per item index
     *
     * @return array<int|string, int|string> later item index => index of the first item with the same reference
     */
    public static function feedDuplicates(array $references): array
    {
        $first = [];
        $duplicates = [];
        foreach ($references as $index => $reference) {
            if (!is_scalar($reference) || trim((string) $reference) === '') {
                continue;
            }
            $key = (string) $reference;
            if (isset($first[$key])) {
                $duplicates[$index] = $first[$key];
            } else {
                $first[$key] = $index;
            }
        }

        return $duplicates;
    }
}
