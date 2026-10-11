<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Service\CarrierResolver;
use ProductImport\Service\NativeFields;
use ProductImport\Service\RelatedProductsResolver;

class NativeFieldsTest extends TestCase
{
    public function testFeaturesFromMapSkipEmptyValuesAndKeepLastDuplicate(): void
    {
        $result = NativeFields::features(['Color' => ' Red ', 'Empty' => '', 'Null' => null, ' ' => 'x', 'Size' => 5, 'Color ' => 'Blue']);
        self::assertSame(['Size' => '5', 'Color' => 'Blue'], $result['pairs']);
        self::assertSame([], $result['warnings']);
    }

    public function testFeaturesFromListOfNameValue(): void
    {
        $result = NativeFields::features([['name' => 'Color', 'value' => 'Red'], ['name' => 'Gone', 'value' => null], ['name' => '', 'value' => 'x'], ['value' => 'orphan'], 'junk', ['name' => 'Size', 'value' => 'L']]);
        self::assertSame(['Color' => 'Red', 'Size' => 'L'], $result['pairs']);
    }

    public function testFeaturesEmptyInputIsAnEmptyReplacement(): void
    {
        self::assertSame([], NativeFields::features([])['pairs']);
    }

    public function testFeaturesRejectScalarInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NativeFields::features('Color=Red');
    }

    public function testFeatureLengthLimitsSkipWithWarnings(): void
    {
        $result = NativeFields::features([str_repeat('n', 129) => 'v', 'ok' => str_repeat('é', 255), 'long' => str_repeat('é', 256), 'nested' => ['x']]);
        self::assertSame(['ok' => str_repeat('é', 255)], $result['pairs']);
        self::assertCount(3, $result['warnings']);
        self::assertStringContainsString('feature name exceeds 128', $result['warnings'][0]);
        self::assertStringContainsString('"long": value exceeds 255', $result['warnings'][1]);
        self::assertStringContainsString('"nested": value must be text', $result['warnings'][2]);
    }

    public function testDeliveryTimeParsing(): void
    {
        foreach (['none' => 0, 'NONE' => 0, ' default ' => 1, 'Custom' => 2, '0' => 0, '1' => 1, '2' => 2, 0 => 0, 1 => 1, 2 => 2] as $input => $expected) {
            self::assertSame($expected, NativeFields::deliveryTime($input), (string) $input);
        }
        self::assertNull(NativeFields::deliveryTime(null));
        self::assertNull(NativeFields::deliveryTime(' '));
    }

    public function testDeliveryTimeRejectsUnknown(): void
    {
        foreach (['soon', '3', 3, 1.0, true, []] as $input) {
            try {
                NativeFields::deliveryTime($input);
                self::fail('expected rejection of ' . json_encode($input));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testLimitTextsTruncatesByCharactersAndWarns(): void
    {
        $result = NativeFields::limitTexts(['meta_description' => str_repeat('č', 600), 'meta_title' => 'ok', 'available_now' => ['x'], 'other' => 'untouched'], NativeFields::PRODUCT_TEXT_LIMITS);
        self::assertSame(512, mb_strlen($result['values']['meta_description']));
        self::assertSame('ok', $result['values']['meta_title']);
        self::assertArrayNotHasKey('available_now', $result['values']);
        self::assertSame('untouched', $result['values']['other']);
        self::assertSame(['meta_description: truncated to 512 characters', 'available_now: expected text, ignored'], $result['warnings']);
    }

    public function testStringList(): void
    {
        self::assertSame(['a', '5'], NativeFields::stringList([' a ', '', null, 5, 'a', ['x'], true]));
        self::assertSame(['a'], NativeFields::stringList('a'));
        self::assertSame([], NativeFields::stringList(null));
        self::assertSame([], NativeFields::stringList(' '));
    }

    public function testListFingerprintIgnoresOrder(): void
    {
        self::assertSame(NativeFields::listFingerprint([3, 12, 7]), NativeFields::listFingerprint(['7', '3', '12']));
        self::assertNotSame(NativeFields::listFingerprint([1, 2]), NativeFields::listFingerprint([1]));
    }

    public function testCarrierMatchingRules(): void
    {
        $carriers = [['id_reference' => 7, 'name' => 'DPD'], ['id_reference' => 9, 'name' => 'GLS'], ['id_reference' => 12, 'name' => '5']];
        $result = CarrierResolver::match(['DPD', '9', 'dpd', '77', '5', 'DPD', 'Pošta'], $carriers);
        // 'dpd' is not exact; '77' is no active reference; '5' is no reference, so it is tried as a name.
        self::assertSame([7, 9, 12], $result['ids']);
        self::assertSame(['dpd', '77', 'Pošta'], $result['unknown']);
    }

    public function testRelatedResolutionOrderAndSelfExclusion(): void
    {
        $byFeed = static fn (string $entry): ?int => ['A' => 1, 'SELF' => 5][$entry] ?? null;
        $byReference = static fn (string $entry): ?int => ['A' => 99, 'B' => 2, 'MINE' => 5][$entry] ?? null;
        $result = RelatedProductsResolver::resolve(['A', 'B', 'SELF', 'MINE', 'ghost', 'B'], 5, $byFeed, $byReference);
        self::assertSame([1, 2], $result['ids']);
        self::assertSame(['ghost'], $result['missing']);
    }
}
