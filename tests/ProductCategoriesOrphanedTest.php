<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Service\ProductImporter;

class ProductCategoriesOrphanedTest extends TestCase
{
    public function testHealthyCategoriesAreNotOrphaned(): void
    {
        self::assertFalse(ProductImporter::categoriesAreOrphaned(true, 2));
    }

    public function testDeletedDefaultCategoryIsOrphaned(): void
    {
        self::assertTrue(ProductImporter::categoriesAreOrphaned(false, 1));
    }

    public function testProductWithoutAssociationsIsOrphaned(): void
    {
        self::assertTrue(ProductImporter::categoriesAreOrphaned(true, 0));
    }
}
