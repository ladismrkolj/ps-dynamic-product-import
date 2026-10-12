<?php

namespace ProductImport\Service;

/** Pure aggregation of the feature values of one product: its own features first, then its variants' in order. */
final class FeatureValues
{
    /**
     * For every feed name the distinct values over all sources, in first-seen order. Values equal ignoring case and
     * repeated or edge whitespace are one value; the first spelling is kept.
     *
     * @param list<array<string,string>> $sources {name: value} maps as normalised by NativeFields::features(): product-level first, then one per variant
     *
     * @return array<string, list<string>>
     */
    public static function combine(array $sources): array
    {
        $combined = [];
        $seen = [];
        foreach ($sources as $pairs) {
            foreach ($pairs as $name => $value) {
                $name = (string) $name;
                $key = FeatureValueMatcher::normalize((string) $value);
                if (isset($seen[$name][$key])) {
                    continue;
                }
                $seen[$name][$key] = true;
                $combined[$name][] = (string) $value;
            }
        }

        return $combined;
    }
}
