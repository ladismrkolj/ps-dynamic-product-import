<?php

namespace ProductImport\Service;

/**
 * Which source decides the PrestaShop category or brand of a feed value, and what a committed run does to the mapping row.
 * Order: explicit mapping (while its target exists), source default, then the name walk / name lookup with auto-create.
 */
final class MappingResolution
{
    public const OVERRIDE = 'override';
    public const DEFAULT = 'default';
    public const NAME_WALK = 'name_walk';

    /**
     * @param int|null $mapped       the row's id_category / id_manufacturer
     * @param bool     $mappedExists whether that target still exists in the shop
     * @param int|null $default      the source default; applies to unmapped values and to values whose mapped target is gone
     * @param bool     $commit       false for the preview, which never writes
     *
     * @return array{decision:string,missing:bool,clear:bool,connect:bool} missing: a committed run found the mapped target gone (log it);
     *                                                                     clear: empty the mapping (the default decides, so nothing overwrites it);
     *                                                                     connect: write the resolved target into the mapping
     */
    public static function plan(?int $mapped, bool $mappedExists, ?int $default, bool $commit): array
    {
        if ($mapped !== null && $mappedExists) {
            return ['decision' => self::OVERRIDE, 'missing' => false, 'clear' => false, 'connect' => false];
        }
        $missing = $mapped !== null && $commit;
        if ($default !== null) {
            return ['decision' => self::DEFAULT, 'missing' => $missing, 'clear' => $missing, 'connect' => false];
        }

        return ['decision' => self::NAME_WALK, 'missing' => $missing, 'clear' => false, 'connect' => $commit];
    }
}
