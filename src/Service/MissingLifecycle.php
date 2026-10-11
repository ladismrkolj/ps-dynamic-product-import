<?php

namespace ProductImport\Service;

/** Pure decisions of the missing-product lifecycle; the runner and repositories do the I/O. */
class MissingLifecycle
{
    public const DEFAULT_DELETE_DAYS = 90;
    public const REDIRECT_CATEGORY = '301-category';

    /** A product hidden as missing gets a category redirect unless the user chose a redirect of their own. */
    public static function shouldSetCategoryRedirect(?string $currentRedirectType): bool
    {
        $current = trim((string) $currentRedirectType);

        return $current === '' || $current === 'default';
    }

    /** The first miss starts the clock; later misses keep the original date. Seeing the item again is cleared by the link repository. */
    public static function firstMissing(?string $current, string $now): string
    {
        return $current !== null && $current !== '' ? $current : $now;
    }

    /** Whether date_missing is more than $days days before $now. NULL or 0 days never expires. */
    public static function isExpired(?string $dateMissing, ?int $days, int $now): bool
    {
        if ($days === null || $days <= 0 || $dateMissing === null || $dateMissing === '') {
            return false;
        }
        $since = strtotime($dateMissing);

        return $since !== false && $now - $since > $days * 86400;
    }

    /** Delete only a long-missing product that is still hidden and that no other source links to. */
    public static function shouldDelete(?string $dateMissing, ?int $days, int $now, bool $inactive, bool $linkedBySourceElsewhere): bool
    {
        return self::isExpired($dateMissing, $days, $now) && $inactive && !$linkedBySourceElsewhere;
    }

    /** Normalises the stored/posted setting: NULL or 0 means never delete. */
    public static function normalizeDays(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $days = (int) $value;

        return $days > 0 ? $days : null;
    }
}
