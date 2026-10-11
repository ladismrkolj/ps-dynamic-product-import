<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProductImport\Service\MissingLifecycle;
use ProductImport\Service\SchemaCheck;
use ProductImport\Service\Upgrade\DeleteMissingDaysStep;
use ProductImport\Service\Upgrade\Migrations;

class MissingLifecycleTest extends TestCase
{
    private const NOW = 1_800_000_000;

    #[DataProvider('redirects')]
    public function testCategoryRedirectOnlyReplacesDefaultOrEmpty(?string $current, bool $expected): void
    {
        self::assertSame($expected, MissingLifecycle::shouldSetCategoryRedirect($current));
    }

    public static function redirects(): array
    {
        return [
            'default' => ['default', true],
            'empty' => ['', true],
            'null' => [null, true],
            'user 404' => ['404', false],
            'user 301 product' => ['301-product', false],
            'user 302 category' => ['302-category', false],
            'already 301 category' => ['301-category', false],
        ];
    }

    public function testFirstMissStartsTheClockLaterMissesKeepIt(): void
    {
        self::assertSame('2026-01-02 03:04:05', MissingLifecycle::firstMissing(null, '2026-01-02 03:04:05'));
        self::assertSame('2026-01-02 03:04:05', MissingLifecycle::firstMissing('', '2026-01-02 03:04:05'));
        self::assertSame('2025-12-01 00:00:00', MissingLifecycle::firstMissing('2025-12-01 00:00:00', '2026-01-02 03:04:05'));
    }

    public function testDaysBoundaryIsStrictlyOlderThanN(): void
    {
        $at = static fn (int $secondsAgo): string => date('Y-m-d H:i:s', self::NOW - $secondsAgo);
        self::assertFalse(MissingLifecycle::isExpired($at(30 * 86400), 30, self::NOW), 'exactly N days is not older than N');
        self::assertTrue(MissingLifecycle::isExpired($at(30 * 86400 + 1), 30, self::NOW));
        self::assertFalse(MissingLifecycle::isExpired($at(29 * 86400), 30, self::NOW));
    }

    #[DataProvider('neverExpires')]
    public function testNoDaysOrNoDateNeverExpires(?string $date, ?int $days): void
    {
        self::assertFalse(MissingLifecycle::isExpired($date, $days, self::NOW));
        self::assertFalse(MissingLifecycle::shouldDelete($date, $days, self::NOW, true, false));
    }

    public static function neverExpires(): array
    {
        $old = date('Y-m-d H:i:s', self::NOW - 1000 * 86400);

        return ['null days' => [$old, null], 'zero days' => [$old, 0], 'no date' => [null, 30], 'empty date' => ['', 30]];
    }

    public function testDeleteNeedsExpiredInactiveAndNoOtherSourceLink(): void
    {
        $old = date('Y-m-d H:i:s', self::NOW - 40 * 86400);
        self::assertTrue(MissingLifecycle::shouldDelete($old, 30, self::NOW, true, false));
        self::assertFalse(MissingLifecycle::shouldDelete($old, 30, self::NOW, false, false), 'a product that is active again is kept');
        self::assertFalse(MissingLifecycle::shouldDelete($old, 30, self::NOW, true, true), 'another source still links to it');
        self::assertFalse(MissingLifecycle::shouldDelete(date('Y-m-d H:i:s', self::NOW - 10 * 86400), 30, self::NOW, true, false));
    }

    public function testDaysSettingNormalisation(): void
    {
        self::assertSame(90, MissingLifecycle::normalizeDays('90'));
        self::assertSame(1, MissingLifecycle::normalizeDays(1));
        self::assertNull(MissingLifecycle::normalizeDays(0));
        self::assertNull(MissingLifecycle::normalizeDays('0'));
        self::assertNull(MissingLifecycle::normalizeDays(''));
        self::assertNull(MissingLifecycle::normalizeDays(null));
        self::assertNull(MissingLifecycle::normalizeDays('abc'));
    }

    public function testMigrationGivesExistingSourcesTheDefaultAndRunsAfterTheColumnExists(): void
    {
        self::assertSame(90, DeleteMissingDaysStep::defaultDays());
        $step = new DeleteMissingDaysStep();
        self::assertTrue($step->applies(['pi_source' => ['id_source', 'delete_missing_days']]));
        self::assertFalse($step->applies(['pi_source' => ['id_source']]));
        self::assertContains($step->id(), array_map(static fn ($migration) => $migration->id(), Migrations::all()));
        self::assertSame(1, count(array_filter(array_map(static fn ($migration) => $migration->id(), Migrations::all()), static fn ($id) => $id === '0.18.0-delete-missing-days')));
    }

    public function testSchemaHasTheLifecycleColumns(): void
    {
        $columns = SchemaCheck::columnDefinitions();
        self::assertStringContainsString('NULL', $columns['pi_source']['delete_missing_days']);
        self::assertStringNotContainsString('NOT NULL', $columns['pi_source']['delete_missing_days']);
        foreach (['date_missing', 'date_deleted'] as $column) {
            self::assertStringStartsWith('DATETIME NULL', $columns['pi_external_product'][$column]);
        }
        self::assertStringContainsString('DEFAULT 0', $columns['pi_import_run']['deleted_count']);
    }
}
