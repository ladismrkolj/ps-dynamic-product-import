<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Service\CategoryResolution;

class CategoryResolutionTest extends TestCase
{
    public function testExplicitMappingWinsOverEverything(): void
    {
        self::assertSame(CategoryResolution::OVERRIDE, CategoryResolution::decide(5, 6, 7, true));
    }

    public function testDefaultBeatsRememberedCategory(): void
    {
        self::assertSame(CategoryResolution::DEFAULT, CategoryResolution::decide(null, 6, 7, true));
    }

    public function testExistingRememberedCategoryBeatsNameWalk(): void
    {
        self::assertSame(CategoryResolution::REMEMBERED, CategoryResolution::decide(null, null, 7, true));
    }

    public function testDeletedRememberedCategoryFallsBackToNameWalk(): void
    {
        self::assertSame(CategoryResolution::NAME_WALK, CategoryResolution::decide(null, null, 7, false));
    }

    public function testNothingKnownWalksByName(): void
    {
        self::assertSame(CategoryResolution::NAME_WALK, CategoryResolution::decide(null, null, null, false));
        self::assertSame(CategoryResolution::NAME_WALK, CategoryResolution::decide(null, null, null, true));
    }
}
