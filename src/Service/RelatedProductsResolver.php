<?php

namespace ProductImport\Service;

final class RelatedProductsResolver
{
    /**
     * Each entry is a feed identifier of the same source first, then a PrestaShop product reference (any product).
     * A product never relates to itself.
     *
     * @param list<string> $entries
     * @param callable(string): ?int $byFeedId
     * @param callable(string): ?int $byReference
     * @return array{ids: list<int>, missing: list<string>}
     */
    public static function resolve(array $entries, int $idProduct, callable $byFeedId, callable $byReference): array
    {
        $ids = [];
        $missing = [];
        foreach ($entries as $entry) {
            $entry = (string) $entry;
            $id = $byFeedId($entry) ?? $byReference($entry);
            if ($id === null) {
                $missing[] = $entry;
            } elseif ($id !== $idProduct) {
                $ids[] = $id;
            }
        }

        return ['ids' => array_values(array_unique($ids)), 'missing' => $missing];
    }
}
