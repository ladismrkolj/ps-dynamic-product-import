<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Service\StaleMappingRows;

class StaleMappingRowsTest extends TestCase
{
    public function testRowsSeenLongBeforeTheNewestScanAreStale(): void
    {
        $result = StaleMappingRows::flag([
            '2026-10-12 10:00:00',   // the newest scan
            '2026-10-12 09:59:00',   // same run
            '2026-10-11 22:00:01',   // 11h59m before: inside the window
            '2026-10-11 21:59:59',   // just over 12h before: stale
            '2026-09-01 08:00:00',   // long gone: stale
            null,                    // added by hand, never seen: never stale
            '',                      // same
        ]);
        self::assertSame('2026-10-11 22:00:00', $result['cutoff']);
        self::assertSame([false, false, false, true, true, false, false], $result['stale']);
    }

    public function testNothingIsStaleWhenNoRowWasEverSeen(): void
    {
        self::assertSame(['cutoff' => null, 'stale' => [false, false]], StaleMappingRows::flag([null, '']));
        self::assertSame(['cutoff' => null, 'stale' => []], StaleMappingRows::flag([]));
    }

    public function testASingleScanMarksNothing(): void
    {
        self::assertSame([false, false], StaleMappingRows::flag(['2026-10-12 10:00:00', '2026-10-12 10:05:00'])['stale']);
        self::assertSame(43200, StaleMappingRows::WINDOW_SECONDS);
    }
}
