<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Service\Format\XmlFormat;
use ProductImport\Service\Format\XmlItemPathDetector;

class XmlItemPathDetectorTest extends TestCase
{
    private static function paths(string $xml): array
    {
        return array_column((new XmlFormat())->detectItemPaths($xml), 'path');
    }

    public function testRechargeFixtureRanksIzdelekFirst(): void
    {
        $candidates = (new XmlFormat())->detectItemPaths((string) file_get_contents(__DIR__ . '/fixtures/recharge_products.xml'));

        self::assertSame('izdelki/izdelek', $candidates[0]['path']);
        self::assertGreaterThanOrEqual(2, $candidates[0]['count']);
        self::assertSame(['izdelekID', 'izdelekIme', 'url', 'opis', 'slikaVelika', 'PPC'], $candidates[0]['fields']);
        self::assertFalse($candidates[0]['approximate']);
        foreach ($candidates as $candidate) {
            self::assertStringNotContainsString('podjetje', $candidate['path']);
        }
    }

    public function testExampleFeedGivesProductsProduct(): void
    {
        $candidates = (new XmlFormat())->detectItemPaths((string) file_get_contents(__DIR__ . '/../examples/products.xml'));

        self::assertSame('products/product', $candidates[0]['path']);
    }

    public function testNestedRepeatingListsDoNotOutrankTheProduct(): void
    {
        $product = static fn (int $i) => '<product><id>' . $i . '</id><images>'
            . '<image><url>a' . $i . '</url></image><image><url>b' . $i . '</url></image><image><url>c' . $i . '</url></image>'
            . '</images></product>';
        $xml = '<shop><products>' . $product(1) . $product(2) . $product(3) . $product(4) . '</products></shop>';

        $candidates = (new XmlFormat())->detectItemPaths($xml);

        self::assertSame('products/product', $candidates[0]['path']);
        self::assertSame(['products/product', 'products/product/images/image', 'products/product/images'], array_column($candidates, 'path'));
        self::assertSame([4, 12, 4], array_column($candidates, 'count'));
    }

    public function testNestedListThatOutnumbersItsRepeatingParentStillWins(): void
    {
        $xml = '<feed><group><item><n>1</n></item><item><n>2</n></item><item><n>3</n></item></group>'
            . '<group><item><n>4</n></item><item><n>5</n></item><item><n>6</n></item></group></feed>';

        self::assertSame(['group/item', 'group'], self::paths($xml));
    }

    public function testSingleItemFeedFallsBackToRootChildren(): void
    {
        $candidates = (new XmlFormat())->detectItemPaths('<catalog><product><sku>1</sku><name>A</name></product><meta>text</meta></catalog>');

        self::assertSame([['path' => 'product', 'count' => 1, 'fields' => ['sku', 'name'], 'approximate' => false]], $candidates);
    }

    public function testFlatFeedWithoutChildrenHasNoCandidates(): void
    {
        self::assertSame([], self::paths('<catalog><a>1</a><b>2</b></catalog>'));
        self::assertSame([], self::paths('<catalog/>'));
    }

    public function testNamespacesAreIgnoredAndRootNameIsNotInThePath(): void
    {
        $xml = '<f:feed xmlns:f="urn:f" xmlns="urn:d"><f:items><item><name>A</name></item><d:item xmlns:d="urn:d"><name>B</name></d:item></f:items></f:feed>';

        $candidates = (new XmlFormat())->detectItemPaths($xml);

        self::assertSame('items/item', $candidates[0]['path']);
        self::assertSame(2, $candidates[0]['count']);
    }

    public function testFieldsAreDistinctAndCapped(): void
    {
        $item = '<item><a/><a/><b/><c/><d/><e/><f/><g/><h/></item>';

        self::assertSame(['a', 'b', 'c', 'd', 'e', 'f'], (new XmlFormat())->detectItemPaths('<r>' . $item . $item . '</r>')[0]['fields']);
    }

    public function testFieldsComeFromFirstOccurrenceWithChildren(): void
    {
        $candidates = (new XmlFormat())->detectItemPaths('<r><item/><item><x/></item><item><y/></item></r>');

        self::assertSame(['x'], $candidates[0]['fields']);
        self::assertSame(3, $candidates[0]['count']);
    }

    public function testRankingTieBreaksByDepth(): void
    {
        $xml = '<r><a><x><v/></x><x><v/></x></a><b><y><v/></y><y><v/></y></b><z><v/></z><z><v/></z></r>';

        self::assertSame(['z', 'a/x', 'b/y'], self::paths($xml));
    }

    public function testScanStopsAtElementCapAndMarksApproximate(): void
    {
        $item = '<item><a/></item>';
        $xml = '<r>' . str_repeat($item, (int) (XmlItemPathDetector::MAX_ELEMENTS / 2) + 10) . '</r>';

        $candidates = (new XmlFormat())->detectItemPaths($xml);

        self::assertTrue($candidates[0]['approximate']);
        self::assertLessThanOrEqual(XmlItemPathDetector::MAX_ELEMENTS, $candidates[0]['count'] * 2);
    }

    public function testMalformedXmlThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid XML');
        (new XmlFormat())->detectItemPaths('<r><item><a></item>');
    }

    public function testEmptyBodyThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new XmlFormat())->detectItemPaths('');
    }
}
