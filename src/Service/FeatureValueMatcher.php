<?php

namespace ProductImport\Service;

/** Pure matching of a feed value against the existing values of a feature: case and runs of whitespace do not matter. */
final class FeatureValueMatcher
{
    /** Edge whitespace removed, every run of whitespace (including no-break spaces) one space, lower case. */
    public static function normalize(string $value): string
    {
        $collapsed = preg_replace('/[\pZ\s]+/u', ' ', $value);

        return mb_strtolower(trim($collapsed ?? $value), 'UTF-8');
    }

    /**
     * Index for repeated lookups: normalised value => lowest id.
     *
     * @param array<int,string> $existing id_feature_value => value
     *
     * @return array<string,int>
     */
    public static function index(array $existing): array
    {
        $index = [];
        foreach ($existing as $id => $value) {
            $key = self::normalize((string) $value);
            if (!isset($index[$key]) || (int) $id < $index[$key]) {
                $index[$key] = (int) $id;
            }
        }

        return $index;
    }
}
