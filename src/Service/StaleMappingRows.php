<?php

namespace ProductImport\Service;

/**
 * Which mapping rows a mapping tab offers to delete in bulk. The tab's latest scan is the newest `date_seen` of its
 * rows (imports and discovery both set it). A row is stale when it was seen before, but longer than WINDOW_SECONDS
 * before that latest scan. Rows never seen (`date_seen` empty: added by hand or by a YAML file, waiting for the feed)
 * are never stale. The window keeps the rows of one long import run, which see their values at different moments, together.
 */
final class StaleMappingRows
{
    public const WINDOW_SECONDS = 43200;

    /**
     * @param list<?string> $seenDates `date_seen` of every row of one tab (Y-m-d H:i:s or null), in row order
     *
     * @return array{cutoff: ?string, stale: list<bool>} cutoff: rows seen before it are stale, null when no row was ever seen
     */
    public static function flag(array $seenDates): array
    {
        $times = [];
        foreach ($seenDates as $date) {
            $time = $date === null || $date === '' ? false : strtotime($date);
            $times[] = $time === false ? null : $time;
        }
        $latest = max(array_filter($times, static fn (?int $time): bool => $time !== null) ?: [null]);
        if ($latest === null) {
            return ['cutoff' => null, 'stale' => array_fill(0, count($times), false)];
        }
        $cutoff = $latest - self::WINDOW_SECONDS;

        return [
            'cutoff' => date('Y-m-d H:i:s', $cutoff),
            'stale' => array_map(static fn (?int $time): bool => $time !== null && $time < $cutoff, $times),
        ];
    }
}
