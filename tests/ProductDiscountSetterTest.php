<?php

use PHPUnit\Framework\TestCase;
use ProductImport\Service\ProductDiscountSetter;

class ProductDiscountSetterTest extends TestCase
{
    public function testImpactBaseWithoutDiscountIsTheProductPrice(): void
    {
        $this->assertSame(22.43, ProductDiscountSetter::impactBase(22.43, null));
    }

    public function testImpactBaseWithDiscountIsTheSalePrice(): void
    {
        $this->assertSame(22.43, ProductDiscountSetter::impactBase(29.9, 7.47));
    }

    public function testImpactBaseOfAProtectedBaseUsesTheStoreValues(): void
    {
        // A manual base price of 100 with the discount left at 10: PrestaShop sells at 90 before the impact.
        $this->assertSame(90.0, ProductDiscountSetter::impactBase(100.0, 10.0));
        $this->assertSame(100.0, ProductDiscountSetter::impactBase(100.0, null));
    }

    public function testEveryVariantShowsItsMappedPriceWhenDiscounted(): void
    {
        $regular = 1429.0;
        $sale = 928.85;
        $reduction = $regular - $sale;
        $base = ProductDiscountSetter::impactBase($regular, $reduction);
        $impact = round(928.85 - $base, 6);

        $this->assertSame(0.0, $impact);
        $this->assertEqualsWithDelta(928.85, $regular + $impact - $reduction, 1e-9);
    }
}
