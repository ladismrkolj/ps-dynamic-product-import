<?php

namespace ProductImport\Service;

/** Which source decides the PrestaShop category of a feed category path. */
final class CategoryResolution
{
    public const OVERRIDE = 'override';
    public const DEFAULT = 'default';
    public const REMEMBERED = 'remembered';
    public const NAME_WALK = 'name_walk';

    /**
     * Order: explicit mapping, source default, remembered auto category (only while it still exists), name walk.
     *
     * @return string One of the class constants.
     */
    public static function decide(?int $override, ?int $default, ?int $remembered, bool $rememberedExists): string
    {
        if ($override !== null) {
            return self::OVERRIDE;
        }
        if ($default !== null) {
            return self::DEFAULT;
        }
        if ($remembered !== null && $rememberedExists) {
            return self::REMEMBERED;
        }

        return self::NAME_WALK;
    }
}
