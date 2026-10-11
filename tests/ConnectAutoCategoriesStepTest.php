<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Service\Upgrade\ConnectAutoCategoriesStep;
use ProductImport\Service\Upgrade\Migrations;

class ConnectAutoCategoriesStepTest extends TestCase
{
    public function testConvertsOnlyUnmappedRowsWithAnExistingRememberedCategoryAndNoSourceDefault(): void
    {
        self::assertTrue(ConnectAutoCategoriesStep::shouldConvert(null, 12, null, true));
        self::assertFalse(ConnectAutoCategoriesStep::shouldConvert(5, 12, null, true), 'an explicit mapping stays');
        self::assertFalse(ConnectAutoCategoriesStep::shouldConvert(null, 12, 3, true), 'the source default applied at runtime');
        self::assertFalse(ConnectAutoCategoriesStep::shouldConvert(null, 12, null, false), 'the category was deleted');
        self::assertFalse(ConnectAutoCategoriesStep::shouldConvert(null, null, null, false));
    }

    public function testAppliesOnlyWhileTheColumnExists(): void
    {
        $step = new ConnectAutoCategoriesStep();
        self::assertTrue($step->applies(['pi_category_mapping' => ['id_category', 'id_category_auto']]));
        self::assertFalse($step->applies(['pi_category_mapping' => ['id_category']]));
    }

    public function testIsRegisteredAfterDeleteMissingDays(): void
    {
        $ids = array_map(static fn ($step): string => $step->id(), Migrations::all());
        self::assertGreaterThan(array_search('0.18.0-delete-missing-days', $ids, true), array_search('0.19.0-connect-auto-categories', $ids, true));
    }
}
