<?php

namespace ProductImport\Service;

class CarrierResolver
{
    private CatalogOptions $options;
    /** @var list<array{id_reference: int, name: string}>|null Loaded once per resolver (per run). */
    private ?array $carriers = null;

    public function __construct(?CatalogOptions $options = null)
    {
        $this->options = $options ?? new CatalogOptions();
    }

    /** @return array{ids: list<int>, unknown: list<string>} id_reference values; unknown entries are skipped */
    public function resolve(mixed $input): array
    {
        $entries = NativeFields::stringList($input);
        if ($entries === []) {
            return ['ids' => [], 'unknown' => []];
        }
        $this->carriers ??= $this->options->carriers();

        return self::match($entries, $this->carriers);
    }

    /**
     * All-digit entries are id_reference values, anything else (or a digit entry that is no active carrier) is an
     * exact name. Unknown entries are reported, never guessed.
     *
     * @param list<string> $entries
     * @param list<array{id_reference: int, name: string}> $carriers
     * @return array{ids: list<int>, unknown: list<string>}
     */
    public static function match(array $entries, array $carriers): array
    {
        $references = [];
        $byName = [];
        foreach ($carriers as $carrier) {
            $references[(int) $carrier['id_reference']] = true;
            $byName[(string) $carrier['name']] ??= (int) $carrier['id_reference'];
        }
        $ids = [];
        $unknown = [];
        foreach ($entries as $entry) {
            $entry = (string) $entry;
            if (ctype_digit($entry) && isset($references[(int) $entry])) {
                $ids[] = (int) $entry;
            } elseif (isset($byName[$entry])) {
                $ids[] = $byName[$entry];
            } else {
                $unknown[] = $entry;
            }
        }

        return ['ids' => array_values(array_unique($ids)), 'unknown' => $unknown];
    }
}
