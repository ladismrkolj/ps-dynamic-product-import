<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Service\ValueSampler;

class ValueSamplerTest extends TestCase
{
    public function testGroupsValuesAndIsolatesItemErrors(): void
    {
        $items = [
            ['availability' => 'in stock'],
            ['availability' => 'out of stock'],
            ['availability' => 'in stock'],
            ['other' => true],
            'invalid item',
        ];
        $result = (new ValueSampler())->sample($items, "path(fields, 'availability')");

        self::assertSame(5, $result['items_scanned']);
        self::assertSame(2, $result['distinct_total']);
        self::assertSame([['value' => 'in stock', 'count' => 2], ['value' => 'out of stock', 'count' => 1]], $result['values']);
        self::assertSame(1, $result['errors']['count']);
        self::assertCount(1, $result['errors']['messages']);
        self::assertFalse($result['truncated']);
    }

    public function testCapsDistinctResults(): void
    {
        $items = array_map(static function ($value) {
            return ['value' => $value];
        }, range(1, 51));
        $result = (new ValueSampler())->sample($items, "fields['value']");

        self::assertSame(51, $result['distinct_total']);
        self::assertCount(50, $result['values']);
        self::assertTrue($result['truncated']);
    }

    private function variantFeed(): array
    {
        return [
            ['brand' => 'A', 'variants' => [['size' => 'S'], ['size' => 'M']]],
            ['brand' => 'B', 'variants' => null],
            ['brand' => 'C', 'variants' => []],
            ['brand' => 'D', 'variants' => [['size' => 'M'], ['size' => 'L']]],
        ];
    }

    public function testSamplesValuesFromVariantsAndSkipsProductsWithoutThem(): void
    {
        $result = (new ValueSampler())->sample($this->variantFeed(), 'path(variant, "size")', 'path(fields, "variants")', 50);

        self::assertSame(4, $result['items_scanned']);
        self::assertSame(4, $result['variants_scanned']);
        self::assertSame(0, $result['errors']['count']);
        self::assertSame(3, $result['distinct_total']);
        self::assertSame(['value' => 'M', 'count' => 2], $result['values'][0]);
    }

    public function testLimitCountsVariantsNotProducts(): void
    {
        $result = (new ValueSampler())->sample($this->variantFeed(), 'path(variant, "size")', 'path(fields, "variants")', 3);

        self::assertSame(3, $result['variants_scanned']);
        self::assertSame(4, $result['items_scanned']);
        self::assertSame(3, array_sum(array_column($result['values'], 'count')));
    }

    public function testFieldsAreAvailableInsideVariantFormulas(): void
    {
        $result = (new ValueSampler())->sample($this->variantFeed(), 'path(fields, "brand") ~ "-" ~ path(variant, "size")', 'path(fields, "variants")', 50);

        self::assertSame(['A-S', 'A-M', 'D-M', 'D-L'], array_column($result['values'], 'value'));
    }

    public function testMalformedVariantListsAreItemErrors(): void
    {
        $items = [['variants' => [['size' => 'S'], 'bad']], ['variants' => 'not a list']];
        $result = (new ValueSampler())->sample($items, 'path(variant, "size")', 'path(fields, "variants")', 50);

        self::assertSame(2, $result['errors']['count']);
        self::assertStringStartsWith('Item 0:', $result['errors']['messages'][0]);
        self::assertStringStartsWith('Item 1:', $result['errors']['messages'][1]);
        self::assertSame([], $result['values']);
    }

    public function testBrokenVariantsExpressionIsOneErrorPerItem(): void
    {
        $result = (new ValueSampler())->sample($this->variantFeed(), 'path(variant, "size")', 'nonsense(fields)', 50);

        self::assertSame(4, $result['errors']['count']);
        self::assertSame(0, $result['variants_scanned']);
        self::assertStringStartsWith('Item 0:', $result['errors']['messages'][0]);
    }

    public function testFormulaErrorReportsVariantIndex(): void
    {
        $items = [['variants' => [['size' => 'S'], ['size' => 'M']]]];
        $result = (new ValueSampler())->sample($items, 'missing_fn(variant)', 'path(fields, "variants")', 50);

        self::assertSame(2, $result['errors']['count']);
        self::assertStringStartsWith('Item 0 variant 1:', $result['errors']['messages'][1]);
    }
}
