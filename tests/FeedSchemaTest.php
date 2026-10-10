<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Service\FeedSchema;

class FeedSchemaTest extends TestCase
{
    private function columns(array $items): array
    {
        $schema = (new FeedSchema())->scan($items);
        $columns = [];
        foreach ($schema['columns'] as $column) {
            $columns[$column['key']] = $column;
        }

        return $columns;
    }

    public function testUnionAcrossItemsAndCoverage(): void
    {
        $items = [
            ['name' => 'A', 'price' => 10],
            ['name' => 'B', 'regular_price' => 12.5, 'note' => ''],
            ['name' => null, 'extra' => ['x' => 'deep']],
        ];
        $schema = (new FeedSchema())->scan($items);
        self::assertSame(3, $schema['total']);
        self::assertFalse($schema['truncated']);
        $columns = $this->columns($items);
        self::assertSame(['name', 'price', 'regular_price', 'note', 'extra', 'extra.x'], array_keys($columns));
        self::assertSame(2, $columns['name']['present']);
        self::assertSame(3, $columns['name']['total']);
        self::assertSame(1, $columns['regular_price']['present']);
        self::assertSame(0, $columns['note']['present']);
        self::assertSame(['A', 'B'], $columns['name']['samples']);
        self::assertSame(['string', 'null'], $columns['name']['types']);
        self::assertSame(['number'], $columns['regular_price']['types']);
        self::assertSame('object', $columns['extra']['kind']);
        self::assertSame('scalar', $columns['extra.x']['kind']);
    }

    public function testListsBecomeWildcardsAndReportMaxLength(): void
    {
        $columns = $this->columns([
            ['name' => 'A'],
            ['images' => ['a.jpg'], 'variants' => [['size' => 'S', 'sku' => 'S1'], ['size' => 'M']]],
            ['images' => ['b.jpg', 'c.jpg', 'a.jpg'], 'variants' => []],
        ]);
        self::assertSame(['name', 'images[*]', 'variants[*]', 'variants[*].size', 'variants[*].sku'], array_keys($columns));
        self::assertSame('list_of_scalars', $columns['images[*]']['kind']);
        self::assertSame(2, $columns['images[*]']['present']);
        self::assertSame(3, $columns['images[*]']['max_length']);
        self::assertSame(['a.jpg', 'b.jpg', 'c.jpg'], $columns['images[*]']['samples']);
        self::assertSame('list_of_objects', $columns['variants[*]']['kind']);
        self::assertSame(1, $columns['variants[*]']['present']);
        self::assertSame(2, $columns['variants[*]']['max_length']);
        self::assertSame(1, $columns['variants[*].size']['present']);
        self::assertSame(['S', 'M'], $columns['variants[*].size']['samples']);
        self::assertNull($columns['name']['max_length']);
        self::assertSame([null], array_slice($columns['images[*]']['parts'], 1));
    }

    public function testListFieldMissingFromFirstProductIsStillOffered(): void
    {
        $columns = $this->columns([['name' => 'A'], ['name' => 'B', 'images' => ['x.jpg']], ['name' => 'C']]);
        self::assertArrayHasKey('images[*]', $columns);
        self::assertSame('list_of_scalars', $columns['images[*]']['kind']);
        self::assertSame(1, $columns['images[*]']['present']);
        self::assertSame(3, $columns['images[*]']['total']);
    }

    public function testListMarkerIsDistinctFromNumericKey(): void
    {
        $columns = $this->columns([['a' => ['0' => 'x', '1' => 'y']], ['a' => [['k' => 1]]]]);
        self::assertSame(['a[*]', 'a[*].k'], array_keys($columns));
        self::assertSame(['a', null], $columns['a[*]']['parts']);
        self::assertSame('["0"]', FeedSchema::label(['0']));
        self::assertNotSame(FeedSchema::label(['a', '0']), FeedSchema::label(['a', null]));
    }

    public function testOddKeysRoundTripInLabels(): void
    {
        $columns = $this->columns([['a.b' => ['quoted"key' => 'Čolni'], 'sp ace' => 1, 'ok_1' => 2, '1st' => 3]]);
        self::assertSame(['["a.b"]', '["a.b"]["quoted\\"key"]', '["sp ace"]', 'ok_1', '["1st"]'], array_keys($columns));
        self::assertSame(['a.b', 'quoted"key'], $columns['["a.b"]["quoted\\"key"]']['parts']);
        self::assertSame(['Čolni'], $columns['["a.b"]["quoted\\"key"]']['samples']);
    }

    public function testSamplesAreDistinctCappedAndTruncated(): void
    {
        $items = [];
        foreach (['a', 'a', 'b', 'c', 'd', 'e', 'f', 'g'] as $value) {
            $items[] = ['v' => $value];
        }
        $items[] = ['v' => str_repeat('é', 200), 'flag' => true];
        $columns = $this->columns($items);
        self::assertSame(['a', 'b', 'c', 'd', 'e'], $columns['v']['samples']);
        self::assertSame(['true'], $columns['flag']['samples']);
        $long = $this->columns([['v' => str_repeat('é', 200)]]);
        self::assertSame(80, mb_strlen($long['v']['samples'][0]));
    }

    public function testDepthCapMarksTruncated(): void
    {
        $item = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => 'hidden']]]]]]];
        $schema = (new FeedSchema())->scan([$item]);
        self::assertSame(['a', 'a.b', 'a.b.c', 'a.b.c.d', 'a.b.c.d.e', 'a.b.c.d.e.f'], array_column($schema['columns'], 'key'));
        self::assertTrue($schema['truncated']);
        $ok = (new FeedSchema())->scan([['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'visible']]]]]]]);
        self::assertSame('a.b.c.d.e.f', end($ok['columns'])['key']);
        self::assertFalse($ok['truncated']);
    }

    public function testColumnCap(): void
    {
        $item = [];
        for ($i = 0; $i < FeedSchema::MAX_COLUMNS; ++$i) {
            $item['f' . $i] = $i;
        }
        $schema = (new FeedSchema())->scan([$item]);
        self::assertCount(FeedSchema::MAX_COLUMNS, $schema['columns']);
        self::assertFalse($schema['truncated']);
        $schema = (new FeedSchema())->scan([$item + ['one_more' => 1]]);
        self::assertCount(FeedSchema::MAX_COLUMNS, $schema['columns']);
        self::assertTrue($schema['truncated']);
    }

    public function testEmptyAndInvalidItems(): void
    {
        $schema = (new FeedSchema())->scan([[], 'bad', [1, 2], ['a' => 1]]);
        self::assertSame(4, $schema['total']);
        self::assertSame(['a'], array_column($schema['columns'], 'key'));
        self::assertSame(1, $schema['columns'][0]['present']);
        self::assertSame(['columns' => [], 'total' => 0, 'truncated' => false], (new FeedSchema())->scan([]));
    }

    public function testDistinctCountsNonEmptyTopLevelScalars(): void
    {
        $columns = $this->columns([
            ['id' => 1, 'name' => 'A', 'tags' => ['x'], 'info' => ['sku' => 'S1']],
            ['id' => 2, 'name' => 'A', 'tags' => ['y'], 'info' => ['sku' => 'S1']],
            ['id' => '2', 'name' => ''],
        ]);
        self::assertSame(2, $columns['id']['distinct']);
        self::assertSame(3, $columns['id']['present']);
        self::assertSame(1, $columns['name']['distinct']);
        self::assertSame(2, $columns['name']['present']);
        self::assertNull($columns['tags[*]']['distinct']);
        self::assertNull($columns['info.sku']['distinct']);
        self::assertNull($columns['info']['distinct']);
    }

    public function testUniquenessWithMissingValues(): void
    {
        $columns = $this->columns([['sku' => 'a'], ['sku' => 'b'], ['sku' => null], ['other' => 1]]);
        $sku = $columns['sku'];
        // Distinct equals present (no duplicates) but not total: missing values mean it is not a full identifier.
        self::assertSame($sku['present'], $sku['distinct']);
        self::assertSame(2, $sku['distinct']);
        self::assertNotSame($sku['total'], $sku['present']);
    }

    public function testDistinctIsCapped(): void
    {
        $items = [];
        for ($i = 0; $i <= FeedSchema::MAX_DISTINCT; ++$i) {
            $items[] = ['id' => $i];
        }
        $id = $this->columns($items)['id'];
        self::assertSame(FeedSchema::MAX_DISTINCT + 1, $id['present']);
        self::assertSame(FeedSchema::MAX_DISTINCT, $id['distinct']);
    }
}
